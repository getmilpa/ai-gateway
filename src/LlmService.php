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

    /**
     * Backoff before the single transport retry, in microseconds (~2s). Long enough for a
     * connection blip to the model host to pass, short enough that a surface watching the
     * run reads it as a pause, not a death. There is deliberately no exponential machinery
     * behind it: one retry, one backoff, then the truth surfaces.
     */
    private const TRANSPORT_RETRY_BACKOFF_MICROSECONDS = 2_000_000;

    /**
     * The narrow body signature of a provider 500 whose CAUSE is the model's own malformed
     * tool-call output (llama.cpp failing to parse what its model just generated) — measured
     * verbatim on run 10: «Failed to parse tool call arguments as JSON ... missing closing
     * quote» at column 5389 of an inline file body. That flake earns exactly ONE retry; any
     * other 5xx stays final on the first answer.
     */
    private const PROVIDER_FLAKE_SIGNATURE = 'Failed to parse tool call arguments';

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
                // igual sobre el cuerpo ya descargado —correcto, sólo que no en vivo—. La división
                // vive en `transmit()`, detrás del reintento de transporte.
                $transportRetried = false;
                $flakeRetried = false;
                $response = $this->sendWithRetries($request, 'OpenAI', $transportRetried, $flakeRetried, stream: true);

                $this->assertSuccessStatus($response, 'OpenAI');

                $streamUsage = null;
                $streamUri = $this->uri('https://api.openai.com', '/v1/chat/completions');
                $streamMessage = $this->consumeOpenAiStream($response->getBody(), $this->onStreamChunk, $streamUsage);
                $this->emitReturn($streamUri, $streamUsage);
                $this->emitReasoning($streamUri, $streamMessage);

                if ($transportRetried) {
                    $streamMessage['transport_retried'] = true;
                }
                if ($flakeRetried) {
                    $streamMessage['provider_flake_retried'] = true;
                }

                return $streamMessage;
            } catch (TransportRetryExhaustedException $e) {
                // Already carries the provider prefix AND the attempt count; wrapping it again
                // would stutter the prefix and bury the count.
                throw $e;
            } catch (ContextExceededException $e) {
                // The type IS the contract: the orchestrator heals on it. Wrapping it in a plain
                // RuntimeException here would blind the streaming path to the one governable 4xx.
                throw $e;
            } catch (\Throwable $e) {
                // `send()` lanza `GuzzleException`, que NO es `ClientExceptionInterface`; se atrapa
                // ancho para conservar el contrato del mensaje que los llamadores esperan.
                throw new \RuntimeException("OpenAI API Error: " . $e->getMessage(), 0, $e);
            }
        }

        try {
            $openAiUri = $this->uri('https://api.openai.com', '/v1/chat/completions');
            $request = $this->buildJsonRequest($openAiUri, [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ], $payload);

            $transportRetried = false;
            $flakeRetried = false;
            $response = $this->sendWithRetries($request, 'OpenAI', $transportRetried, $flakeRetried);

            $this->assertSuccessStatus($response, 'OpenAI');

            $body = json_decode((string) $response->getBody(), true);
            $this->emitReturn($openAiUri, \is_array($body) ? ($body['usage'] ?? null) : null);
            $message = \is_array($body) ? ($body['choices'][0]['message'] ?? []) : [];
            $this->emitReasoning($openAiUri, \is_array($message) ? $message : []);

            if (!\is_array($message)) {
                return [];
            }
            if ($transportRetried) {
                $message['transport_retried'] = true;
            }
            if ($flakeRetried) {
                $message['provider_flake_retried'] = true;
            }

            return $message;

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
     * @param \Closure(string): void    $onChunk
     * @param array<string, mixed>|null $usage   Out-param: the provider's own usage block from the
     *                                           final chunk, when one carried it, else left null.
     *
     * @return array<string, mixed>
     */
    private function consumeOpenAiStream(StreamInterface $body, \Closure $onChunk, ?array &$usage = null): array
    {
        $usage = null;
        $content = '';
        $reasoning = '';
        /** @var array<int, array{id: string, type: string, function: array{name: string, arguments: string}}> $toolCalls */
        $toolCalls = [];
        $buffer = '';

        $handle = static function (string $line) use (&$content, &$reasoning, &$toolCalls, &$usage, $onChunk): void {
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
            // The usage-only final chunk (OpenAI's `stream_options.include_usage`, and what
            // llama.cpp emits unprompted) carries an empty `choices` and a top-level `usage`. It
            // reaches here before the delta guard would drop it for having no delta.
            if (isset($json['usage']) && \is_array($json['usage'])) {
                $usage = $json['usage'];
            }

            $delta = $json['choices'][0]['delta'] ?? null;
            if (!\is_array($delta)) {
                return;
            }

            $fired = false;
            if (isset($delta['reasoning_content']) && \is_string($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                $reasoning .= $delta['reasoning_content'];
            }
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
        if ($reasoning !== '') {
            $message['reasoning_content'] = $reasoning;
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
            $anthropicUri = $this->uri('https://api.anthropic.com', '/v1/messages');
            $request = $this->buildJsonRequest($anthropicUri, [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ], $payload);

            $transportRetried = false;
            $flakeRetried = false;
            $response = $this->sendWithRetries($request, 'Anthropic', $transportRetried, $flakeRetried);

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
            if ($transportRetried) {
                $message['transport_retried'] = true;
            }
            if ($flakeRetried) {
                $message['provider_flake_retried'] = true;
            }

            $this->emitReturn($anthropicUri, \is_array($body) ? ($body['usage'] ?? null) : null);

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
     * @throws ContextExceededException when the status is 400 and the body carries the narrow
     *                                  exceed-context signature — same message, typed so the
     *                                  orchestrator can heal it
     * @throws \RuntimeException        for every other response status >= 400
     */
    private function assertSuccessStatus(ResponseInterface $response, string $provider): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode < 400) {
            return;
        }

        $reason = trim($response->getReasonPhrase());
        $status = $reason !== '' ? "{$statusCode} {$reason}" : (string) $statusCode;
        $body = (string) $response->getBody();

        $message = sprintf(
            '%s API Error: HTTP %s - %s',
            $provider,
            $status,
            $this->truncateErrorBody($body)
        );

        // THE ONE GOVERNABLE 4xx GETS A TYPE, NOTHING ELSE CHANGES. A 400 whose body carries
        // the exceed-context signature is the provider MEASURING the request — the orchestrator
        // owns re-projection and can act on those numbers (greenhouse fixture run 12). The
        // message is byte-identical to the untyped path, and every other status keeps the plain
        // RuntimeException, so consumers catching the base class see zero change.
        if ($statusCode === 400) {
            $exceeded = $this->parseContextExceeded($body);
            if ($exceeded !== null) {
                throw new ContextExceededException($message, $exceeded['n_prompt_tokens'], $exceeded['n_ctx']);
            }
        }

        throw new \RuntimeException($message);
    }

    /**
     * Parse a 400 body for the NARROW exceed-context signature and nothing wider: the decoded
     * error object (nested under `error`, or the body itself) must declare
     * `"type":"exceed_context_size_error"` — the discriminator llama.cpp's OpenAI-compat layer
     * speaks, measured verbatim on run 12. `n_prompt_tokens`/`n_ctx` are carried when present
     * and integer; anything the provider did not say stays `null`, never a fabricated number.
     * Any other body — a different type, a non-JSON error page — is not this failure.
     *
     * @return array{n_prompt_tokens: int|null, n_ctx: int|null}|null the provider's numbers, or
     *                                                                `null` when the body is not
     *                                                                the exceed-context error
     */
    private function parseContextExceeded(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            return null;
        }

        $error = \is_array($decoded['error'] ?? null) ? $decoded['error'] : $decoded;
        if (($error['type'] ?? null) !== 'exceed_context_size_error') {
            return null;
        }

        $nPrompt = $error['n_prompt_tokens'] ?? null;
        $nCtx = $error['n_ctx'] ?? null;

        return [
            'n_prompt_tokens' => \is_int($nPrompt) ? $nPrompt : null,
            'n_ctx' => \is_int($nCtx) ? $nCtx : null,
        ];
    }

    /**
     * Put one request on the wire, honoring the streaming split {@see callOpenAi()} needs:
     * live streaming requires Guzzle's own `send($request, ['stream' => true])` (PSR-18 has
     * no options bag), and any other client falls back to the buffered `sendRequest()`.
     */
    private function transmit(RequestInterface $request, bool $stream): ResponseInterface
    {
        if ($stream && $this->httpClient instanceof \GuzzleHttp\ClientInterface) {
            return $this->httpClient->send($request, ['stream' => true]);
        }

        return $this->httpClient->sendRequest($request);
    }

    /**
     * Did this throwable happen on the WIRE, before any HTTP response existed?
     *
     * Only that shape is retryable: PSR-18 reserves {@see \Psr\Http\Client\NetworkExceptionInterface}
     * for it, and Guzzle's default client surfaces connection refused/reset, timeout and DNS
     * failures as `ConnectException` (which implements that interface). The streaming path's
     * `send()` speaks Guzzle's own hierarchy, so a Guzzle exception that never carried a
     * response counts too. Anything carrying an HTTP response — a `BadResponseException`, or
     * the plain `ResponseInterface` the buffered path checks via {@see assertSuccessStatus()} —
     * is the provider SPEAKING, not the wire failing, and is never classified as transport:
     * retrying a 400 context-exceeded is waste that masks truth.
     */
    private function isTransportFailure(\Throwable $e): bool
    {
        if ($e instanceof \Psr\Http\Client\NetworkExceptionInterface) {
            return true;
        }
        if ($e instanceof \GuzzleHttp\Exception\BadResponseException) {
            return false; // an HTTP response arrived; its status is the provider's answer
        }
        if ($e instanceof \GuzzleHttp\Exception\RequestException) {
            return $e->getResponse() === null;
        }

        return $e instanceof \GuzzleHttp\Exception\TransferException;
    }

    /**
     * Send one request through the transport retry AND the provider-flake retry: a 5xx whose
     * body carries {@see self::PROVIDER_FLAKE_SIGNATURE} (the model's own malformed tool-call
     * output) is retried exactly once — new sampling usually yields well-formed output — while
     * any other 5xx throws immediately in the same format {@see assertSuccessStatus()} uses,
     * and every 4xx returns untouched for the caller's own status guard (a context-exceeded
     * 400 must surface verbatim, never retried). Out-params report both retries so the caller
     * can note them on the result and the record stays honest.
     *
     * @param bool $transportRetried out-param: the single transport retry was used
     * @param bool $flakeRetried     out-param: the single provider-flake retry was used
     */
    private function sendWithRetries(RequestInterface $request, string $provider, bool &$transportRetried, bool &$flakeRetried, bool $stream = false): ResponseInterface
    {
        $flakeRetried = false;
        $response = $this->sendWithTransportRetry($request, $provider, $transportRetried, $stream);
        if ($response->getStatusCode() < 500) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (str_contains($body, self::PROVIDER_FLAKE_SIGNATURE)) {
            $this->log("provider output flake ({$provider} 5xx: malformed model tool-call), retrying once after backoff");
            usleep(self::TRANSPORT_RETRY_BACKOFF_MICROSECONDS);
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }
            $flakeRetried = true;

            $secondTransport = false;
            $response = $this->sendWithTransportRetry($request, $provider, $secondTransport, $stream);
            $transportRetried = $transportRetried || $secondTransport;
            if ($response->getStatusCode() < 500) {
                return $response;
            }
            $body = (string) $response->getBody();
        }

        $reason = trim($response->getReasonPhrase());
        $status = $reason !== '' ? "{$response->getStatusCode()} {$reason}" : (string) $response->getStatusCode();

        throw new \RuntimeException(sprintf(
            '%s API Error: HTTP %s - %s',
            $provider,
            $status,
            $this->truncateErrorBody($body)
        ));
    }

    /**
     * Send one request, retrying the SAME request exactly once after a short backoff when the
     * first attempt failed at the TRANSPORT level — the measured Desktop death where a
     * connection blip mid-call surfaced as a dead stop although the session resumed fine
     * seconds later. `$retried` reports (out-param) whether the retry happened, so the caller
     * can note it on the result and the record stays honest. Never more than one retry: both
     * attempts failing raises {@see TransportRetryExhaustedException}, whose message names
     * that two attempts were made. A non-transport throwable is rethrown untouched on either
     * attempt, and an HTTP response of any status returns as-is for the caller's own status
     * guard — this seam never eats a provider's answer.
     *
     * @param bool $retried out-param: true when the single transport retry was used
     *
     * @throws TransportRetryExhaustedException when both attempts fail at the transport level
     */
    private function sendWithTransportRetry(RequestInterface $request, string $provider, bool &$retried, bool $stream = false): ResponseInterface
    {
        $retried = false;

        try {
            return $this->transmit($request, $stream);
        } catch (\Throwable $first) {
            if (!$this->isTransportFailure($first)) {
                throw $first;
            }

            $this->log("transport failure ({$first->getMessage()}), retrying once after backoff");
            usleep(self::TRANSPORT_RETRY_BACKOFF_MICROSECONDS);

            // The same request goes out again; a consumed body stream is rewound so the
            // retry carries the same bytes, not an empty POST.
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }
            $retried = true;

            try {
                return $this->transmit($request, $stream);
            } catch (\Throwable $second) {
                if (!$this->isTransportFailure($second)) {
                    throw $second;
                }

                throw new TransportRetryExhaustedException(sprintf(
                    '%s API Error: transport failed on both of 2 attempts (one retry after a transient failure): %s',
                    $provider,
                    $second->getMessage()
                ), 0, $second);
            }
        }
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
     * Reports one call's cost to a return-aware observer, once — and only what the provider spoke.
     *
     * Silent when nobody opted into {@see ReturnObserver} or the provider reported no usage: a return
     * this seam cannot substantiate is not one it invents. The observer contract forbids throwing,
     * but a broken implementation must not take the run down with it either, so this stays defensive.
     *
     * @param array<string, mixed>|null $rawUsage The provider's own usage block, unnormalized.
     */
    private function emitReturn(string $uri, ?array $rawUsage): void
    {
        if (!$this->channelObserver instanceof ReturnObserver) {
            return;
        }

        $usage = $this->normalizeUsage($rawUsage);
        if ($usage === null) {
            return;
        }

        try {
            $this->channelObserver->observeReturn($uri, ['model' => $this->model, 'usage' => $usage]);
        } catch (\Throwable) {
            // Observing a channel may not change it — and that includes not being able to fell it.
        }
    }

    /**
     * Reports one call's reasoning to a reasoning-aware observer, once — and only what the provider spoke.
     *
     * Silent when nobody opted into {@see ReasoningObserver} or the message carries no `reasoning_content`:
     * a model that reasons silently produces no call here, never an empty one. The observer contract forbids
     * throwing, but a broken implementation must not take the run down with it either, so this stays defensive.
     *
     * @param array<string, mixed> $message The decoded assistant message, which on a reasoning model carries
     *                                      `reasoning_content` alongside `content`/`tool_calls`.
     */
    private function emitReasoning(string $uri, array $message): void
    {
        if (!$this->channelObserver instanceof ReasoningObserver) {
            return;
        }

        $reasoning = $message['reasoning_content'] ?? null;
        if (!\is_string($reasoning) || $reasoning === '') {
            return;
        }

        try {
            $this->channelObserver->observeReasoning($uri, $reasoning);
        } catch (\Throwable) {
            // Observing a channel may not change it — and that includes not being able to fell it.
        }
    }

    /**
     * Collapses either provider's usage block to one shape, or `null` when neither is recognizable.
     *
     * OpenAI counts `prompt_tokens`/`completion_tokens`/`total_tokens` and nests any cache hit under
     * `prompt_tokens_details.cached_tokens`; Anthropic counts `input_tokens`/`output_tokens` with no
     * total and names its cache read `cache_read_input_tokens`. Both become
     * `prompt_tokens`/`completion_tokens`/`total_tokens`, plus `cached_tokens` only when the provider
     * declared one — an absent cache figure is «not said», never a fabricated zero. The total is
     * taken as reported and only summed when the provider omitted it.
     *
     * @param array<string, mixed>|null $raw
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens?: int}|null
     */
    private function normalizeUsage(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (isset($raw['prompt_tokens']) || isset($raw['completion_tokens'])) {
            $prompt = (int) ($raw['prompt_tokens'] ?? 0);
            $completion = (int) ($raw['completion_tokens'] ?? 0);
            $usage = [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => (int) ($raw['total_tokens'] ?? ($prompt + $completion)),
            ];
            $cached = $raw['prompt_tokens_details']['cached_tokens'] ?? null;
            if (\is_int($cached)) {
                $usage['cached_tokens'] = $cached;
            }

            return $usage;
        }

        if (isset($raw['input_tokens']) || isset($raw['output_tokens'])) {
            $prompt = (int) ($raw['input_tokens'] ?? 0);
            $completion = (int) ($raw['output_tokens'] ?? 0);
            $usage = [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => $prompt + $completion,
            ];
            $cached = $raw['cache_read_input_tokens'] ?? null;
            if (\is_int($cached)) {
                $usage['cached_tokens'] = $cached;
            }

            return $usage;
        }

        return null;
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
