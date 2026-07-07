<?php

/**
 * This file is part of Milpa AI Gateway — the dual-provider LLM client and agentic
 * tool-use runtime for the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Milpa\ToolRuntime\Contracts\LlmServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Dual-provider LLM client (OpenAI + Anthropic chat-completions), translating each
 * provider's tool-call wire format to and from a single OpenAI-shaped message array.
 */
class LlmService implements LlmServiceInterface
{
    private Client $client;
    private string $apiKey;
    private string $model;
    private string $provider;
    private ?LoggerInterface $logger;

    public function __construct(string $apiKey, string $model = 'gpt-4o', string $provider = 'openai', ?LoggerInterface $logger = null)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->provider = strtolower($provider);
        $this->logger = $logger;
        $this->client = new Client([
            'timeout' => 60.0,
        ]);
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

        try {
            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            return $body['choices'][0]['message'] ?? [];

        } catch (GuzzleException $e) {
            throw new \RuntimeException("OpenAI API Error: " . $e->getMessage(), 0, $e);
        }
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
            $response = $this->client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 600.0,
            ]);

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

        } catch (GuzzleException $e) {
            throw new \RuntimeException("Anthropic API Error: " . $e->getMessage(), 0, $e);
        }
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
