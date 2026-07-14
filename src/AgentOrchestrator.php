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

use Psr\Log\LoggerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Rendering\RendererRegistry;
use Milpa\ToolRuntime\ToolResult;

/**
 * Agentic tool-use loop: alternates between asking an {@see LlmService} for the
 * next step and executing any tool calls it requests via {@see McpClientService},
 * until the LLM returns a final response or {@see self::$maxSteps} is reached.
 */
class AgentOrchestrator
{
    private LlmService $llm;
    private McpClientService $mcpClient;
    private int $maxSteps;
    private ?LoggerInterface $logger;
    private ?RendererRegistry $rendererRegistry;
    private ?ToolContext $toolContext;
    private ?ToolResult $lastToolResult = null;

    public function __construct(
        LlmService $llm,
        McpClientService $mcpClient,
        int $maxSteps = 20,
        ?LoggerInterface $logger = null,
        ?RendererRegistry $rendererRegistry = null
    ) {
        $this->llm = $llm;
        $this->mcpClient = $mcpClient;
        $this->maxSteps = $maxSteps;
        $this->logger = $logger;
        $this->rendererRegistry = $rendererRegistry;
        $this->toolContext = null;
    }

    /**
     * Get the last ToolResult from tool execution.
     * Used by ProcessTelegramMessageJob to build keyboard from metadata.
     */
    public function getLastToolResult(): ?ToolResult
    {
        return $this->lastToolResult;
    }

    /**
     * Set the tool context for channel-specific rendering.
     */
    public function setToolContext(ToolContext $ctx): self
    {
        $this->toolContext = $ctx;
        $this->mcpClient->setContext($ctx);
        return $this;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            $this->logger->debug("[AgentOrchestrator] " . $message);
        }
    }

    /**
     * Render a ToolResult based on current context.
     */
    private function renderToolResult(ToolResult $result): string
    {
        if ($this->rendererRegistry && $this->toolContext) {
            $rendered = $this->rendererRegistry->render($result, $this->toolContext);
            return is_string($rendered) ? $rendered : json_encode($rendered, JSON_UNESCAPED_UNICODE);
        }
        // Fallback to JSON if no renderer
        return $result->toJson();
    }

    /**
     * Run the agent orchestrator loop.
     *
     * @param string                     $prompt       User prompt
     * @param string                     $systemPrompt System instructions
     * @param list<array<string, mixed>> $history      Conversation history
     * @param callable|null              $onStep       Optional callback called at each step: fn(int $step, string $status) => void
     */
    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        $tools = $this->mcpClient->getToolSummaries();
        $this->log("Tools available: " . count($tools) . " - " . implode(', ', array_column($tools, 'name')));

        // Track tool results to append them to final response
        $toolResults = [];

        // Check for /force command to bypass history
        $forceRefresh = false;
        if (str_starts_with(strtolower(trim($prompt)), '/force')) {
            $forceRefresh = true;
            $prompt = trim(substr(trim($prompt), 6)); // Remove /force
            $history = []; // Clear history
            $this->log("⚠️ FORCE REFRESH: History cleared, forcing tool usage.");
            $systemPrompt .= " IMPORTANT: You must use tools to answer this request to ensure up-to-date data. Do not rely on previous context.";
        }

        // Build initial messages array: System -> History -> Current User Prompt
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        // Merge history
        foreach ($history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }

        // Add current user prompt
        $messages[] = ['role' => 'user', 'content' => $prompt];
        $this->log("Starting agent loop with " . count($messages) . " messages");

        // Track used tools for footer
        $usedToolNames = [];

        for ($i = 0; $i < $this->maxSteps; $i++) {
            // Invoke onStep callback to refresh typing indicator or other status
            if ($onStep !== null) {
                try {
                    $onStep($i, 'processing');
                } catch (\Throwable $e) {
                    $this->log("Step $i: onStep callback error: " . $e->getMessage());
                }
            }

            $this->log("Step $i: Calling LLM...");

            // 1. Ask LLM
            $response = $this->llm->generateResponse($prompt, $tools, $messages);
            $this->log("Step $i: LLM response - role=" . ($response['role'] ?? 'unknown') .
                ", has_content=" . (!empty($response['content']) ? 'yes' : 'no') .
                ", has_tool_calls=" . (isset($response['tool_calls']) ? count($response['tool_calls']) : '0'));

            $messages[] = $response;

            // 2. Check if tool call
            if (isset($response['tool_calls']) && !empty($response['tool_calls'])) {
                $toolNames = array_map(fn ($tc) => $tc['function']['name'], $response['tool_calls']);
                $this->log("Step $i: 🔧 TOOL CALLS DETECTED (" . count($response['tool_calls']) . "): " . implode(', ', $toolNames));

                foreach ($toolNames as $name) {
                    if (!in_array($name, $usedToolNames)) {
                        $usedToolNames[] = $name;
                    }
                }

                foreach ($response['tool_calls'] as $toolCall) {
                    $functionName = $toolCall['function']['name'];
                    $rawArguments = $toolCall['function']['arguments'] ?? '';

                    // DEBUG: Log raw arguments before parsing
                    $this->log("Step $i: 🔧 RAW ARGUMENTS (length=" . strlen($rawArguments) . "): " . substr($rawArguments, 0, 2000));

                    $functionArgs = json_decode($rawArguments, true);
                    $jsonError = json_last_error();

                    // DEBUG: Log parsing result
                    if ($jsonError !== JSON_ERROR_NONE) {
                        $this->log("Step $i: ❌ JSON DECODE ERROR: " . json_last_error_msg());
                    }
                    $this->log("Step $i: 🔧 PARSED ARGS keys: " . implode(', ', array_keys($functionArgs ?? [])));

                    $argsJson = json_encode($functionArgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->log("Step $i: 🔧 EXECUTING tool='$functionName' args=$argsJson");

                    // 3. Execute Tool
                    try {
                        $toolResult = $this->mcpClient->callTool($functionName, $functionArgs);

                        // Handle ToolResult objects
                        if ($toolResult instanceof ToolResult) {
                            // Store for later use (keyboard building)
                            $this->lastToolResult = $toolResult;

                            // Check for confirmation or blocked
                            if ($toolResult->requiresConfirmation()) {
                                $this->log("Step $i: ⚠️ CONFIRMATION REQUIRED - stopping loop");
                                $rendered = $this->renderToolResult($toolResult);
                                return $rendered . "\n\n_Responde **CONFIRMAR** para proceder o **CANCELAR** para abortar._";
                            }

                            if ($toolResult->isBlocked()) {
                                $this->log("Step $i: ⛔ BLOCKED BY RULE - stopping loop");
                                return $this->renderToolResult($toolResult);
                            }

                            // Render for storage
                            $output = $this->renderToolResult($toolResult);
                            $this->log("Step $i: ✅ TOOL RESULT (ToolResult) '$functionName': " . substr($output, 0, 500));
                        } else {
                            // Legacy string/array handling
                            $output = is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE);
                            $this->log("Step $i: ✅ TOOL RESULT '$functionName': " . substr($output, 0, 500));

                            // Legacy confirmation check
                            $requiresConfirmation = false;
                            if (is_string($toolResult)) {
                                $decoded = json_decode($toolResult, true);
                                if (is_array($decoded) && ($decoded['requires_confirmation'] ?? false) === true) {
                                    $requiresConfirmation = true;
                                    $this->log("Step $i: ⚠️ CONFIRMATION REQUIRED - stopping loop");
                                }
                            } elseif (is_array($toolResult) && ($toolResult['requires_confirmation'] ?? false) === true) {
                                $requiresConfirmation = true;
                                $this->log("Step $i: ⚠️ CONFIRMATION REQUIRED - stopping loop");
                            }

                            if ($requiresConfirmation) {
                                $message = is_array($toolResult) ? ($toolResult['message'] ?? '') :
                                    (json_decode($toolResult, true)['message'] ?? $output);
                                return $message . "\n\n_Responde **CONFIRMAR** para proceder o **CANCELAR** para abortar._";
                            }
                        }

                        // Store tool result for appending to final response
                        $toolResults[] = $output;
                    } catch (\Exception $e) {
                        $output = "Error executing tool: " . $e->getMessage();
                        $this->log("Step $i: ❌ TOOL ERROR '$functionName': " . $e->getMessage());
                    }

                    // 4. Feed result back
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $functionName,
                        'content' => $output,
                    ];
                }
                // Loop continues to let LLM process tool output
            } else {
                // No tool call, final response
                $this->log("Step $i: Final response (no tool calls)");

                $finalResponse = $response['content'] ?? '';

                // For ToolResult-based responses, the LLM already includes the data
                // Only append tool results for legacy string responses that might be missed
                // Skip if most results came from ToolResult (rendered format)
                if (!empty($toolResults) && $this->rendererRegistry === null) {
                    // Legacy mode: append tool results only if no renderer is active
                    $this->log("Legacy mode: checking for non-duplicate tool results to append");
                    $nonDuplicateResults = [];

                    // Extract numeric IDs from text (commonly used as identifiers in results)
                    $extractIds = function (string $text): array {
                        // Match patterns like "1817:", "#1817", "ID: 1817", "💰 1817:", etc.
                        preg_match_all('/(?:^|[^\d])(\d{3,6})(?:[\:\s\]\)]|$)/m', $text, $matches);
                        return array_unique($matches[1]);
                    };

                    $responseIds = $extractIds($finalResponse);
                    $this->log("Response contains " . count($responseIds) . " IDs");

                    foreach ($toolResults as $result) {
                        $resultIds = $extractIds($result);

                        // If the result has no IDs, check by text length comparison
                        if (empty($resultIds)) {
                            // For non-ID results (like time, simple text), use simpler check
                            $resultNormalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($result)));
                            $responseNormalized = preg_replace('/\s+/', ' ', mb_strtolower($finalResponse));

                            if (
                                mb_strlen($resultNormalized) < 50 ||
                                mb_strpos($responseNormalized, mb_substr($resultNormalized, 0, 50)) !== false
                            ) {
                                $this->log("Non-ID result likely duplicate, skipping");
                                continue;
                            }
                            $nonDuplicateResults[] = $result;
                            continue;
                        }

                        // Count how many IDs from result are already in response
                        $matchCount = 0;
                        foreach ($resultIds as $id) {
                            if (in_array($id, $responseIds)) {
                                $matchCount++;
                            }
                        }

                        // $resultIds is non-empty here — the empty() branch above already
                        // continued the loop, so count($resultIds) > 0 always holds.
                        $matchPercentage = ($matchCount / count($resultIds)) * 100;
                        $this->log("Result has " . count($resultIds) . " IDs, {$matchCount} found in response ({$matchPercentage}%)");

                        // If more than 40% of IDs are already in response, it's a duplicate
                        if ($matchPercentage > 40) {
                            $this->log("Duplicate detected: {$matchPercentage}% of IDs already present");
                            continue;
                        }

                        $nonDuplicateResults[] = $result;
                    }

                    if (!empty($nonDuplicateResults)) {
                        $this->log("Appending " . count($nonDuplicateResults) . " non-duplicate tool results");
                        $finalResponse .= "\n\n---\n📊 **Datos:**\n" . implode("\n\n", $nonDuplicateResults);
                    } else {
                        $this->log("All " . count($toolResults) . " tool results already included in response, skipping append");
                    }
                }

                // Prepend tool usage emoji if tools were used (more subtle)
                if (!empty($usedToolNames)) {
                    $finalResponse = "🔧 " . $finalResponse;
                } elseif ($forceRefresh) {
                    $finalResponse = "🔧 " . $finalResponse;
                }

                return $finalResponse;
            }
        }

        $this->log("Max steps ($this->maxSteps) reached");
        return "Error: Maximum agent steps reached.";
    }
}
