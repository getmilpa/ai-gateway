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

    /**
     * The per-result budget that a single tool result may contribute to the model's context inside
     * ONE agentic turn — the inner-loop counterpart of `Session::MAX_TOOL_RESULT`, which bounds
     * results only in the CROSS-TURN history. Without it, a self-inspection op that returns a large
     * dump (`agent_observe` measured at ~53K tokens, greenhouse evidence/0440) is fed back verbatim
     * and overflows a small-window model in a single step — before any history compaction can apply.
     * The FULL result still reaches the session log through the channel observer; this bounds only
     * what returns to the window (greenhouse decisions/0040: the window derives from consequence,
     * not the raw). A generous default: big enough for a real file read or listing, small enough that
     * no one result can dominate a 32K window. A window-derived budget is the follow-up.
     */
    private const MAX_TOOL_RESULT_CHARS = 8000;

    private LlmService $llm;
    private McpClientService $mcpClient;
    private int $maxSteps;
    private ?LoggerInterface $logger;
    private ?RendererRegistry $rendererRegistry;
    private ?PlanBoard $planBoard;
    private ?ToolContext $toolContext;
    private ?ToolResult $lastToolResult = null;

    /** Whether the toolbox serves tool schemas on demand (small-window models) instead of inlining all. */
    private bool $lazyTools;

    public function __construct(
        LlmService $llm,
        McpClientService $mcpClient,
        int $maxSteps = 20,
        ?LoggerInterface $logger = null,
        ?RendererRegistry $rendererRegistry = null,
        ?PlanBoard $planBoard = null,
        bool $lazyTools = false
    ) {
        $this->llm = $llm;
        $this->mcpClient = $mcpClient;
        $this->maxSteps = $maxSteps;
        $this->logger = $logger;
        $this->rendererRegistry = $rendererRegistry;
        $this->planBoard = $planBoard;
        $this->toolContext = null;
        $this->lazyTools = $lazyTools;
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

    /**
     * Bound a single tool result to what may return to the model's window in one turn. Over the
     * budget, the head is kept and the tail is elided with an honest marker naming how much was cut
     * and where the whole result lives — never a silent truncation that would read as the full
     * answer (greenhouse evidence/0440, decisions/0040). Multibyte-safe: it cuts on character
     * boundaries so a truncated result is never a broken byte sequence.
     */
    private function boundToolResult(string $output): string
    {
        if (mb_strlen($output) <= self::MAX_TOOL_RESULT_CHARS) {
            return $output;
        }

        $elided = mb_strlen($output) - self::MAX_TOOL_RESULT_CHARS;

        return mb_substr($output, 0, self::MAX_TOOL_RESULT_CHARS)
            . "\n…[tool result truncated: {$elided} characters elided to fit the model window;"
            . ' the full result is in the session log]';
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
    /**
     * Lean the catalogue for the toolbox: every tool becomes name + description with an empty schema,
     * except the ones the model has already described (their full schema stays), plus a `describe_tool`
     * meta-tool that serves any tool's schema on demand. This is what keeps 43 full schemas off every
     * request — the model pulls the one it needs (greenhouse evidence/0436).
     *
     * @param list<array<string, mixed>> $full     the app's full tool summaries (name, description, inputSchema)
     * @param list<string>               $unlocked names the model has described this run
     *
     * @return list<array<string, mixed>>
     */
    private function lazyCatalogue(array $full, array $unlocked): array
    {
        $lean = [];
        foreach ($full as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if (in_array($name, $unlocked, true)) {
                $lean[] = $tool;

                continue;
            }
            $lean[] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? ''),
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ];
        }
        $lean[] = [
            'name' => 'describe_tool',
            'description' => 'Return the full input schema of a tool by name. Call this before using a tool whose parameters you do not know.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string', 'description' => 'the tool to describe']],
                'required' => ['name'],
            ],
        ];

        return $lean;
    }

    /**
     * Drive the agent loop until it returns a final answer or pauses on a gate.
     *
     * @param list<array<string, mixed>> $history prior turns, each a role/content message
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

        // The toolbox: tools arrive as name + description only, so 43 full schemas do not ride every
        // request. The model asks for a tool's parameters on demand, and a tool it touches without its
        // schema is auto-described (the system resolves it) rather than failing.
        if ($this->lazyTools) {
            $systemPrompt .= "\n\nTOOLBOX: each tool is listed by name and description only. Before you call "
                . "a tool whose parameters you do not know, call `describe_tool` with its name to get its "
                . "input schema, then call the tool with the arguments the schema declares.";
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

        // The toolbox's memory: a tool stays lean (name + description) until the model describes it or
        // touches it, then its full schema joins the table for the rest of the run (greenhouse evidence/0436).
        $unlockedTools = [];

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
            $tools = $this->lazyTools
                ? $this->lazyCatalogue($this->mcpClient->getToolSummaries(), $unlockedTools)
                : $this->mcpClient->getToolSummaries();
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

                    // The toolbox door: `describe_tool` returns a tool's schema on demand, and a tool the
                    // model touches without having described it is auto-described here (the system resolves
                    // the schema) instead of executing with arguments the model could not have known. Either
                    // way the tool is unlocked, so its full schema is on the table from the next step.
                    $describing = $functionName === 'describe_tool';
                    if ($this->lazyTools && ($describing || !in_array($functionName, $unlockedTools, true))) {
                        $wanted = $describing
                            ? (is_array($functionArgs) ? (string) ($functionArgs['name'] ?? '') : '')
                            : $functionName;
                        $schema = null;
                        foreach ($this->mcpClient->getToolSummaries() as $summary) {
                            if ($summary['name'] === $wanted) {
                                $schema = $summary['inputSchema'];

                                break;
                            }
                        }
                        if ($schema === null) {
                            $output = json_encode(['error' => "no tool named «{$wanted}» — use the exact name shown"], JSON_UNESCAPED_UNICODE);
                        } else {
                            if (!in_array($wanted, $unlockedTools, true)) {
                                $unlockedTools[] = $wanted;
                            }
                            $output = json_encode([
                                'tool' => $wanted,
                                'inputSchema' => $schema,
                                'note' => "now call {$wanted} with the arguments this schema declares",
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $this->log("Step $i: 📖 described + unlocked '$wanted'");
                        }
                        $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCall['id'], 'name' => $functionName, 'content' => $output];

                        continue;
                    }

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

                    // 4. Feed result back — BOUNDED to the model's window (evidence/0440). The full
                    // result already reached the session log; what returns to the window is derived,
                    // never the raw dump that would overflow a small-window model in one step.
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $functionName,
                        'content' => $this->boundToolResult($output),
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
