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
    /**
     * Lo que devuelve una vuelta que se quedó sin pasos.
     *
     * No es una respuesta y no debe pintarse como tal. Es pública para que la superficie la reconozca
     * sin repetir el literal.
     */
    public const STEPS_EXHAUSTED = 'Error: Maximum agent steps reached.';

    private LlmService $llm;
    private McpClientService $mcpClient;
    private int $maxSteps;
    private ?LoggerInterface $logger;
    private ?RendererRegistry $rendererRegistry;
    private ?PlanBoard $planBoard;
    private ?ToolContext $toolContext;
    private ?ToolResult $lastToolResult = null;

    public function __construct(
        LlmService $llm,
        McpClientService $mcpClient,
        int $maxSteps = 20,
        ?LoggerInterface $logger = null,
        ?RendererRegistry $rendererRegistry = null,
        ?PlanBoard $planBoard = null
    ) {
        $this->llm = $llm;
        $this->mcpClient = $mcpClient;
        $this->maxSteps = $maxSteps;
        $this->logger = $logger;
        $this->rendererRegistry = $rendererRegistry;
        $this->planBoard = $planBoard;
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
    /**
     * ¿Este texto es una llamada a herramienta que el canal no parseó?
     *
     * Se reconoce por su FORMA, no adivinando intención: los formatos que los modelos emiten cuando el
     * canal de `tool_calls` falla son marcadores literales —`<function=…>`, `<tool_call>`,
     * `<|tool_call|>`— y ninguno aparece en una respuesta en prosa por accidente.
     *
     * Deliberadamente NO se buscan frases como «voy a llamar a plugins_disable»: eso es prosa legítima,
     * y confundirla con una llamada fallida volvería a este guardián el que se come respuestas buenas.
     * Un detector con falsos positivos sobre la conducta normal se apaga a la semana.
     */
    private function looksLikeAnUnparsedToolCall(string $texto): bool
    {
        foreach (['<function=', '<tool_call', '</tool_call', '<|tool_call', '<invoke name='] as $marca) {
            if (str_contains($texto, $marca)) {
                return true;
            }
        }

        return false;
    }

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
                } catch (RunInterrupted $e) {
                    // LA ORDEN SÍ PASA. El `catch` de abajo existe para que una superficie que truena
                    // pintando no mate el trabajo del agente; una interrupción es lo contrario, y
                    // tragársela dejaría al humano viendo cómo su «para» no hace nada.
                    $this->log("Step $i: interrupted by the surface");

                    throw $e;
                } catch (\Throwable $e) {
                    $this->log("Step $i: onStep callback error: " . $e->getMessage());
                }
            }

            $this->log("Step $i: Calling LLM...");

            // LA MESA SE REPROYECTA EN CADA PASO, y no es una optimización al revés.
            //
            // Esto se pedía UNA sola vez antes del bucle, y por eso el catálogo era una foto: una
            // opción retirada a media corrida seguía enfrente del modelo hasta el final. Medido y
            // dicho — mientras esto no se releyera, la pregunta «¿el agente volvió a mirar el mundo?»
            // no se podía contestar, porque el mundo nunca cambiaba.
            //
            // No se le pregunta al agente si releyó. Se le da un mundo distinto.
            $tools = $this->mcpClient->getToolSummaries();
            $this->log("Step $i: tools on the table: " . count($tools) . " - " . implode(', ', array_column($tools, 'name')));

            // EL PLAN SE REPROYECTA EN CADA PASO, POR LA MISMA RAZÓN QUE LA MESA.
            //
            // El plan se sacó del prompt y se puso en el stream (P16.3) para que sobreviviera a la
            // compactación. Sobrevive para el humano y para `agent:timeline`; NO sobrevivía para el
            // agente, porque nada lo devolvía al contexto. Al segundo paso su propio plan ya había
            // quedado atrás en la conversación.
            //
            // NO SE ACUMULA. Se arma una copia de los mensajes para esta llamada y el plan viaja al
            // final; `$messages` queda limpio. Apendarlo sería dejar veinte fotos del plan en el
            // contexto —diecinueve de ellas mintiendo— y volvería el estado más viejo indistinguible
            // del vigente, que es exactamente el defecto que esto arregla.
            //
            // Q-P20-B mide si esto sostiene la continuidad. Mientras no cierre, el default es `null`.
            $paraElModelo = $messages;
            $plan = $this->planBoard?->current();
            if ($plan !== null && trim($plan) !== '') {
                $paraElModelo[] = ['role' => 'system', 'content' => $plan];
                $this->log("Step $i: plan reprojected (" . \strlen($plan) . " bytes)");
            }

            // 1. Ask LLM
            $response = $this->llm->generateResponse($prompt, $tools, $paraElModelo);
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
                    } catch (ToolCallRefusedException $e) {
                        // UNA NEGATIVA TERMINA LA VUELTA — no se le devuelve al modelo.
                        //
                        // Si cayera en el catch de abajo, el modelo leería «no puedes hacer eso» como
                        // el resultado de una herramienta y probaría otra cosa: seguiría trabajando
                        // alrededor de la compuerta en vez de detenerse ante ella. Una compuerta que se
                        // puede rodear intentando por otro lado no es una compuerta, y el humano al que
                        // se le iba a preguntar se enteraría cuando ya se hizo algo distinto.
                        //
                        // SALVO QUE LA OPCIÓN YA NO EXISTA. Entonces no hay nada que rodear: la
                        // herramienta sale del catálogo en el paso siguiente, así que devolverle el
                        // motivo no le abre una vía — le dice por qué el mundo cambió. Terminar aquí es
                        // lo que Q-P19-D midió: el agente toma el `no` como final del trabajo y contesta
                        // repitiendo el veredicto del verificador, 0 de 32 sin volver a mirar nada.
                        if (!$e->optionRemoved) {
                            $this->log("Step $i: 🚧 TOOL REFUSED '$functionName': " . $e->getMessage());

                            return $e->getMessage();
                        }

                        $this->log("Step $i: 🚧➖ OPTION REMOVED '$functionName': " . $e->getMessage());
                        $output = $e->getMessage();
                    } catch (\Exception $e) {
                        // Cualquier OTRO fallo sí vuelve al modelo: una herramienta que truena por un
                        // argumento malo es algo que el modelo puede corregir, y devolvérselo es lo que
                        // le permite hacerlo.
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

                // ── UNA LLAMADA MAL FORMADA NO ES UNA RESPUESTA ─────────────────────────────────
                //
                // Visto en el TUI el 2026-08-04 con qwen3-coder:30b y una petición de una línea. El
                // modelo quiso llamar `plugins_disable` y la emitió COMO TEXTO —`<function=…>`— con el
                // canal `tool_calls` vacío, así que entraba por aquí como respuesta final: se guardó en
                // el stream como lo que el agente contestó, la pantalla le quitó las etiquetas y enseñó
                // «HelloPlugin», y arriba decía «listo». **La herramienta nunca corrió, no hubo pregunta
                // de permiso, y el humano se quedó creyendo que sí.**
                //
                // Es la sustitución de siempre en el peor lugar posible: el INTENTO presentado como el
                // HECHO, y con cara de éxito. Un agente que reporta hecho lo que no hizo es peor que uno
                // que falla.
                //
                // NO SE INTERPRETA Y NO SE EJECUTA. Parsear ese texto sería inventar un contrato de
                // llamada que nadie declaró y que cambia con cada versión del modelo — y ejecutar lo que
                // salga de ahí es correr lo que el modelo escribió en prosa, sin pasar por el esquema
                // que valida argumentos ni por la compuerta que pide permiso.
                if ($this->looksLikeAnUnparsedToolCall($finalResponse)) {
                    $this->log("Step {$i}: respuesta descartada — trae una llamada sin parsear");

                    return 'El modelo intentó llamar una herramienta y la escribió como texto en vez de usar '
                        . "el canal de llamadas, así que **no se ejecutó nada**. Nada cambió en esta app.\n\n"
                        . "Lo que devolvió, tal cual:\n\n```\n" . trim($finalResponse) . "\n```";
                }

                // EL RESULTADO CRUDO YA NO SE ANEXA A LA RESPUESTA.
                //
                // Esto pegaba el JSON de cada herramienta bajo un «📊 Datos:» cuando no había
                // renderer, con una heurística de duplicados que buscaba identificadores numéricos
                // de tres a seis dígitos en el texto. Contra datos reales no acertaba —un
                // `plugins_list` devuelve nombres y versiones, no ids— así que el bloque salía SIEMPRE,
                // y quien lo miraba veía dos veces lo mismo: la prosa del modelo y su fuente cruda.
                //
                // Se retira por tres razones, y la tercera es la que decide:
                //
                //   1. El modelo YA transcribe los datos en su respuesta. Anexarlos otra vez no
                //      informa: repite.
                //   2. La heurística no se podía sostener. Setenta líneas de adivinación sin una sola
                //      prueba que las cubriera — se descubrió al ir a quitarlas.
                //   3. **Pintar el dato es trabajo de la superficie, no del modelo.** El resultado
                //      viaja en la proyección de la sesión (`activity.result`), y quien sepa pintarlo
                //      lo arma: el TUI ya construye una tabla con las columnas que la herramienta
                //      devolvió. Una superficie que no sepa, muestra la prosa — que es lo que había
                //      antes de este bloque, sin la duplicación.
                //
                // Lo que el agente contesta vuelve a ser lo que el agente dijo.

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

        // AGOTAR EL TECHO NO ES CONTESTAR, y devolverlo como texto lo volvía indistinguible de una
        // respuesta: el TUI lo pintaba con la voz del agente, como si eso fuera lo que dijo. Se
        // conserva la cadena por compatibilidad y se nombra, para que una superficie pueda
        // reconocerla en vez de compararla contra un literal suyo que puede envejecer aparte.
        return self::STEPS_EXHAUSTED;
    }
}
