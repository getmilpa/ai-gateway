<?php

/**
 * This file is part of Milpa AI Gateway — the dual-provider LLM client and agentic
 * tool-use runtime for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Milpa\ToolRuntime\Contracts\LlmServiceInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Dual-provider LLM client (OpenAI + Anthropic chat-completions), translating each
 * provider's tool-call wire format to and from a single OpenAI-shaped message array.
 */
class LlmService implements LlmServiceInterface
{
    /**
     * Shared client-level timeout (seconds) for the default Guzzle client.
     *
     * Before the PSR-18 seam, the Anthropic call overrode the client's 60s default with a
     * per-request 600s timeout (`GuzzleHttp\Client::post()`'s `timeout` option) — Claude's
     * tool-use responses can run long. `ClientInterface::sendRequest()` takes only a
     * `RequestInterface`, with no per-call options bag, so that override has no PSR-18
     * equivalent. Rather than force one in, the default client now uses 600s uniformly for
     * both providers — a strictly looser ceiling for OpenAI (never fires sooner than the old
     * 60s did) at the cost of failing slower if OpenAI itself hangs. A caller who wants the
     * old per-provider split back can inject their own `ClientInterface`.
     */
    private const DEFAULT_TIMEOUT_SECONDS = 600.0;

    /**
     * Max characters of a provider's error response body kept in an exception message.
     * The body can carry the same prompt/tool-argument content the {@see SECURITY.md}
     * debug-logging warning covers, so error messages get a bounded excerpt rather than
     * the raw body — enough to diagnose, not a mechanism to bulk-exfiltrate into logs.
     */
    private const MAX_ERROR_BODY_LENGTH = 500;

    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private string $apiKey;
    private string $model;
    private string $provider;
    private ?string $baseUrl;

    /** @var array<string,string> */
    private array $extraHeaders;
    private ?LoggerInterface $logger;
    private ?ChannelObserver $channelObserver;

    /**
     * Fires ONCE PER REAL SSE CHUNK while the model is answering, so a live surface (the TUI
     * spinner) can advance one frame per fact rather than per clock — honest motion that STOPS
     * if the model stalls (greenhouse evidence/0307, promise `tui-says-what-it-is-doing`). Its
     * presence is also the switch: set → the OpenAI-compatible call streams; null → the call
     * stays the single buffered request it was, byte for byte.
     *
     * @var (\Closure(string): void)|null
     */
    private ?\Closure $onStreamChunk;

    /**
     * @param string|null          $baseUrl      Dónde vive el modelo. `null` es el proveedor público —
     *                                           `api.openai.com` o `api.anthropic.com`— y cualquier otra cosa
     *                                           es un endpoint compatible: un Ollama en la LAN, un vLLM, un
     *                                           proxy corporativo. Sin esto, la única forma de probar el bucle
     *                                           del agente era gastarle tokens a un proveedor público, y la
     *                                           única forma de correrlo con datos que no pueden salir de la
     *                                           casa era no correrlo.
     * @param array<string,string> $extraHeaders Encabezados adicionales para cada llamada — un `Authorization:
     *                                           Basic …` cuando el endpoint local está detrás de auth básica,
     *                                           por ejemplo. Se aplican DESPUÉS de los propios, así que pueden
     *                                           reemplazar el `Authorization` del proveedor a propósito.
     */
    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        string $provider = 'openai',
        ?LoggerInterface $logger = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?string $baseUrl = null,
        array $extraHeaders = [],
        ?ChannelObserver $channelObserver = null,
        ?\Closure $onStreamChunk = null,
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->provider = strtolower($provider);
        $this->baseUrl = $baseUrl === null ? null : rtrim($baseUrl, '/');
        $this->extraHeaders = $extraHeaders;
        $this->logger = $logger;
        $this->channelObserver = $channelObserver;
        $this->onStreamChunk = $onStreamChunk;
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
        ]);

        $psr17Factory = new HttpFactory();
        $this->requestFactory = $requestFactory ?? $psr17Factory;
        $this->streamFactory = $streamFactory ?? $psr17Factory;
    }

    private function log(string $message): void
    {
        $this->logger?->debug("[LlmService] " . $message);
    }

    /**
     * Send a prompt (or a full message history) to the configured provider and
     * return a single OpenAI-shaped assistant message, translating request and
     * response tool-call formats to and from Anthropic's shape when needed.
     *
     * @param list<array<string, mixed>> $tools    Tool summaries in MCP/OpenAI shape
     *                                             (`name`, `description`, `inputSchema`)
     * @param list<array<string, mixed>> $messages Full conversation so far; when empty,
     *                                             a single `user` message is built from
     *                                             `$prompt`
     *
     * @return array<string, mixed> An OpenAI-shaped assistant message (`role`, `content`,
     *                              and optionally `tool_calls`)
     */
    public function generateResponse(string $prompt, array $tools = [], array $messages = [], int $maxTokens = 4096): array
    {
        if (empty($messages)) {
            $messages = [
                ['role' => 'user', 'content' => $prompt],
            ];
        }

        if ($this->provider === 'anthropic' || str_contains($this->model, 'claude')) {
            return $this->callAnthropic($tools, $messages, $maxTokens);
        }

        return $this->callOpenAi($tools, $messages);
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    private function callOpenAi(array $tools, array $messages): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->formatToolsForOpenAi($tools);
            $payload['tool_choice'] = 'auto';
        }

        // STREAMING SÓLO CUANDO ALGUIEN MIRA. Con un `onStreamChunk` la respuesta llega por SSE y
        // el callback late una vez por delta real — así el spinner de una superficie viva avanza por
        // hecho, no por reloj (evidence/0307). Sin él, el camino queda IDÉNTICO: una sola petición
        // buffereada. Pedir `stream` sin consumir en vivo no gana nada, así que sólo se pide aquí.
        if ($this->onStreamChunk !== null) {
            $payload['stream'] = true;

            try {
                $request = $this->buildJsonRequest($this->uri('https://api.openai.com', '/v1/chat/completions'), [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ], $payload);

                // PSR-18 `sendRequest()` NO PUEDE STREAMEAR EN VIVO: Guzzle buffea el cuerpo entero
                // antes de volver (su bolsa de opciones no viaja por PSR-18). El streaming vivo exige
                // `GuzzleHttp\ClientInterface::send($req, ['stream' => true])`. Un cliente inyectado
                // que no sea Guzzle (las pruebas) cae al `sendRequest` buffereado: el parser corre
                // igual sobre el cuerpo ya descargado —correcto, sólo que no en vivo—.
                $response = $this->httpClient instanceof \GuzzleHttp\ClientInterface
                    ? $this->httpClient->send($request, ['stream' => true])
                    : $this->httpClient->sendRequest($request);

                $this->assertSuccessStatus($response, 'OpenAI');

                return $this->consumeOpenAiStream($response->getBody(), $this->onStreamChunk);
            } catch (\Throwable $e) {
                // `send()` lanza `GuzzleException`, que NO es `ClientExceptionInterface`; se atrapa
                // ancho para conservar el contrato del mensaje que los llamadores esperan.
                throw new \RuntimeException("OpenAI API Error: " . $e->getMessage(), 0, $e);
            }
        }

        try {
            $request = $this->buildJsonRequest($this->uri('https://api.openai.com', '/v1/chat/completions'), [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ], $payload);

            $response = $this->httpClient->sendRequest($request);

            $this->assertSuccessStatus($response, 'OpenAI');

            $body = json_decode((string) $response->getBody(), true);
            return $body['choices'][0]['message'] ?? [];

        } catch (ClientExceptionInterface $e) {
            throw new \RuntimeException("OpenAI API Error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Reads an OpenAI-compatible SSE body incrementally, firing `$onChunk` once per delta that
     * carries a real fragment, and assembles the SAME `{role, content, tool_calls?}` array the
     * buffered path returns. Tool-call fragments are grouped by their `index`: `function.name`
     * arrives once, `function.arguments` in pieces to concatenate.
     *
     * @param \Closure(string): void $onChunk
     *
     * @return array<string, mixed>
     */
    private function consumeOpenAiStream(StreamInterface $body, \Closure $onChunk): array
    {
        $content = '';
        /** @var array<int, array{id: string, type: string, function: array{name: string, arguments: string}}> $toolCalls */
        $toolCalls = [];
        $buffer = '';

        $handle = static function (string $line) use (&$content, &$toolCalls, $onChunk): void {
            if (!str_starts_with($line, 'data:')) {
                return; // comments (':' keep-alive) and blank separators
            }
            $data = trim(substr($line, \strlen('data:')));
            if ($data === '' || $data === '[DONE]') {
                return;
            }
            $json = json_decode($data, true);
            if (!\is_array($json)) {
                return;
            }
            $delta = $json['choices'][0]['delta'] ?? null;
            if (!\is_array($delta)) {
                return;
            }

            $fired = false;
            if (isset($delta['content']) && \is_string($delta['content']) && $delta['content'] !== '') {
                $content .= $delta['content'];
                $onChunk($delta['content']);
                $fired = true;
            }

            foreach ($delta['tool_calls'] ?? [] as $tc) {
                $i = (int) ($tc['index'] ?? 0);
                $toolCalls[$i] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                if (isset($tc['id'])) {
                    $toolCalls[$i]['id'] = (string) $tc['id'];
                }
                if (isset($tc['type'])) {
                    $toolCalls[$i]['type'] = (string) $tc['type'];
                }
                if (isset($tc['function']['name'])) {
                    $toolCalls[$i]['function']['name'] .= (string) $tc['function']['name'];
                }
                if (isset($tc['function']['arguments'])) {
                    $toolCalls[$i]['function']['arguments'] .= (string) $tc['function']['arguments'];
                }
                if (!$fired) {
                    $onChunk('');
                    $fired = true;
                }
            }
        };

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (($nl = strpos($buffer, "\n")) !== false) {
                $handle(rtrim(substr($buffer, 0, $nl), "\r"));
                $buffer = substr($buffer, $nl + 1);
            }
        }
        if ($buffer !== '') {
            $handle(rtrim($buffer, "\r"));
        }

        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== []) {
            $message['tool_calls'] = array_values($toolCalls);
        }

        return $message;
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    private function callAnthropic(array $tools, array $messages, int $maxTokens = 4096): array
    {
        // Adapt messages for Anthropic
        // 1. System prompt is a top-level parameter, not in messages
        // 2. 'tool' role messages must be converted to 'user' with tool_result content blocks
        // 3. Anthropic requires alternating user/assistant messages

        $systemPrompt = '';
        $filteredMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt .= $msg['content'] . "\n";
            } elseif ($msg['role'] === 'tool') {
                // Convert OpenAI tool response to Anthropic format
                // Anthropic expects: {role: 'user', content: [{type: 'tool_result', tool_use_id: '...', content: '...'}]}
                $filteredMessages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $msg['tool_call_id'] ?? 'unknown',
                            'content' => $msg['content'] ?? '',
                        ],
                    ],
                ];
            } elseif ($msg['role'] === 'assistant' && isset($msg['tool_calls'])) {
                // Assistant message with tool_calls - convert to Anthropic format
                $content = [];
                if (!empty($msg['content'])) {
                    $content[] = ['type' => 'text', 'text' => $msg['content']];
                }
                foreach ($msg['tool_calls'] as $toolCall) {
                    $input = json_decode($toolCall['function']['arguments'], true);
                    if (empty($input) || !is_array($input)) {
                        $input = new \stdClass(); // Empty object {} for Anthropic
                    }
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall['id'],
                        'name' => $toolCall['function']['name'],
                        'input' => $input,
                    ];
                }
                $filteredMessages[] = ['role' => 'assistant', 'content' => $content];
            } else {
                // Regular user/assistant message
                $filteredMessages[] = $msg;
            }
        }

        $payload = [
            'model' => $this->model,
            'messages' => $filteredMessages,
            'max_tokens' => $maxTokens,
        ];

        if (!empty($systemPrompt)) {
            $payload['system'] = trim($systemPrompt);
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatToolsForAnthropic($tools);
        }

        try {
            $request = $this->buildJsonRequest($this->uri('https://api.anthropic.com', '/v1/messages'), [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ], $payload);

            $response = $this->httpClient->sendRequest($request);

            $this->assertSuccessStatus($response, 'Anthropic');

            $rawBody = (string) $response->getBody();
            $body = json_decode($rawBody, true);

            // DEBUG: Log raw Anthropic response
            $this->log("RAW ANTHROPIC RESPONSE: " . substr($rawBody, 0, 5000));

            // Map Anthropic response to OpenAI format for consistency in Orchestrator
            // Anthropic returns content: [{type: text, text: ...}, {type: tool_use, ...}]

            $content = '';
            $toolCalls = [];

            foreach ($body['content'] as $block) {
                if ($block['type'] === 'text') {
                    $content .= $block['text'];
                } elseif ($block['type'] === 'tool_use') {
                    // DEBUG: Log the raw input from Anthropic
                    $inputJson = json_encode($block['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->log("TOOL_USE block: name={$block['name']}, input_length=" . strlen($inputJson));
                    $this->log("TOOL_USE input keys: " . implode(', ', array_keys($block['input'] ?? [])));
                    $this->log("TOOL_USE full input: " . $inputJson);

                    $toolCalls[] = [
                        'id' => $block['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'],
                            'arguments' => $inputJson,
                        ],
                    ];
                }
            }

            $message = ['role' => 'assistant', 'content' => $content];
            if (!empty($toolCalls)) {
                $message['tool_calls'] = $toolCalls;
            }

            return $message;

        } catch (ClientExceptionInterface $e) {
            throw new \RuntimeException("Anthropic API Error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Guard shared by {@see callOpenAi()} and {@see callAnthropic()} against a PSR-18 gap the
     * Guzzle `->post()` call this replaced didn't have: `ClientInterface::sendRequest()` never
     * throws on a 4xx/5xx status — Guzzle's own PSR-18 adapter hardcodes `http_errors => false`
     * for that method — so an HTTP error comes back as an ordinary `ResponseInterface` instead
     * of a `ClientExceptionInterface`. Left unchecked, callers silently got a malformed/empty
     * completion instead of a failure. Mirrors the status check in the sibling transport
     * {@see \Milpa\McpClient\Transports\HttpSseTransport::request()}, adapted to preserve the
     * `"$provider API Error: ..."` message contract this class's callers depend on (see
     * `callOpenAi()`'s and `callAnthropic()`'s `ClientExceptionInterface` catches).
     *
     * @throws \RuntimeException when the response status is >= 400
     */
    private function assertSuccessStatus(ResponseInterface $response, string $provider): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode < 400) {
            return;
        }

        $reason = trim($response->getReasonPhrase());
        $status = $reason !== '' ? "{$statusCode} {$reason}" : (string) $statusCode;

        throw new \RuntimeException(sprintf(
            '%s API Error: HTTP %s - %s',
            $provider,
            $status,
            $this->truncateErrorBody((string) $response->getBody())
        ));
    }

    /**
     * Bound an HTTP error body to {@see MAX_ERROR_BODY_LENGTH} characters for inclusion in an
     * exception message — enough to diagnose the failure without dumping a potentially large
     * (or, per `SECURITY.md`, sensitive) response body wholesale into whatever catches and logs
     * the exception.
     */
    private function truncateErrorBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '(empty response body)';
        }

        if (mb_strlen($body) > self::MAX_ERROR_BODY_LENGTH) {
            return mb_substr($body, 0, self::MAX_ERROR_BODY_LENGTH) . '... (truncated)';
        }

        return $body;
    }

    /**
     * Build a PSR-7 JSON POST request shared by {@see callOpenAi()} and {@see callAnthropic()}.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $payload
     */
    /** El endpoint: el del proveedor, o el que el host haya dicho. */
    private function uri(string $porDefecto, string $ruta): string
    {
        return ($this->baseUrl ?? $porDefecto) . $ruta;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $payload
     */
    private function buildJsonRequest(string $uri, array $headers, array $payload): RequestInterface
    {
        // AQUI, y no donde el llamador arma las cosas. Las dos ramas de proveedor convergen en esta
        // funcion para serializar, y `callAnthropic` REESCRIBE la conversacion antes de llegar:
        // saca el `system` de los mensajes y convierte los roles `tool`. Observar rio arriba
        // ensenaria mensajes que nunca viajaron con esa forma — que es la propiedad dura fallando en
        // el primer intento, y con la vista creyendose fiel.
        //
        // Los encabezados NO se pasan. Ahi vive el `Authorization`, y una superficie de depuracion
        // que graba credenciales deja de ser una superficie de depuracion.
        $this->channelObserver?->observe($uri, $payload);

        $request = $this->requestFactory
            ->createRequest('POST', $uri)
            ->withBody($this->streamFactory->createStream((string) json_encode($payload)));

        // Los propios primero y los del host después, para que un `Authorization` explícito gane: un
        // endpoint local detrás de auth básica no acepta el Bearer del proveedor, y quien lo cableó
        // sabe cuál de los dos vale.
        foreach ([...$headers, ...$this->extraHeaders] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * @param list<array<string, mixed>> $mcpTools
     *
     * @return list<array<string, mixed>>
     */
    private function formatToolsForOpenAi(array $mcpTools): array
    {
        $formatted = [];
        foreach ($mcpTools as $tool) {
            $formatted[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['inputSchema'],
                ],
            ];
        }
        return $formatted;
    }

    /**
     * @param list<array<string, mixed>> $mcpTools
     *
     * @return list<array<string, mixed>>
     */
    private function formatToolsForAnthropic(array $mcpTools): array
    {
        // Anthropic tools format: { name, description, input_schema }
        // Anthropic requires input_schema to be a valid JSON Schema with type and properties
        $formatted = [];
        foreach ($mcpTools as $tool) {
            $inputSchema = $tool['inputSchema'] ?? [];

            // Ensure inputSchema has required fields for Anthropic
            if (!isset($inputSchema['type'])) {
                $inputSchema['type'] = 'object';
            }
            if (!isset($inputSchema['properties']) || empty($inputSchema['properties'])) {
                // Anthropic requires properties, even if empty it should be a proper object
                // For tools with no parameters, we still need a valid schema
                $inputSchema['properties'] = new \stdClass(); // Empty object {}, not empty array []
            }

            $formatted[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $inputSchema,
            ];
        }
        return $formatted;
    }
}
