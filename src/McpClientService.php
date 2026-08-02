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

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * Facade over a {@see ToolRegistry} that shapes it for {@see AgentOrchestrator}:
 * summaries for the LLM's tool list and context-aware invocation.
 */
class McpClientService
{
    private ToolRegistry $internalRegistry;
    private ?ToolContext $context = null;

    private ?ToolCallGate $gate;
    private ?ToolCallRecorder $recorder;
    private ?OptionTable $mesa;

    /**
     * @param ToolCallGate|null     $gate     se consulta antes de cada llamada y puede negarla. Sin
     *                                        compuerta, el bucle corre como corría: la ausencia de
     *                                        política no puede ser una política nueva.
     * @param ToolCallRecorder|null $recorder se le avisa después, con lo que la herramienta contestó
     * @param OptionTable|null      $mesa     qué opciones siguen enfrente. Sin mesa, el catálogo es el
     *                                        registro entero, que es como corría antes
     */
    public function __construct(
        ToolRegistry $internalRegistry,
        ?ToolCallGate $gate = null,
        ?ToolCallRecorder $recorder = null,
        ?OptionTable $mesa = null,
    ) {
        $this->internalRegistry = $internalRegistry;
        $this->gate = $gate;
        $this->recorder = $recorder;
        $this->mesa = $mesa;
    }

    /**
     * Set the execution context for tool calls.
     *
     * This should be called before processing a request to set
     * the user's identity and scopes.
     */
    public function setContext(ToolContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the current context.
     */
    public function getContext(): ?ToolContext
    {
        return $this->context;
    }

    /**
     * Get the plain-array tool summaries for LLM/MCP exposure.
     *
     * Delegates to {@see ToolRegistry::getToolSummaries()} — named to match that
     * vocabulary since tool-runtime 0.2 (was `getTools()` through 0.1).
     *
     * In the future, merge with external tools fetched via HTTP/SSE.
     *
     * ── ESTO ES UNA PROYECCIÓN, Y POR ESO SE VUELVE A PEDIR ─────────────────────────────────────
     *
     * El catálogo no es un dato: se DERIVA del registro menos lo que ya salió de la mesa. Por eso
     * {@see OptionTable::removed()} se consulta en cada llamada y no en el constructor — y por eso
     * {@see AgentOrchestrator} pregunta esto en cada paso del bucle en vez de una sola vez al empezar.
     *
     * Sin esas dos cosas juntas, quitar una opción no cambia nada de lo que el modelo ve: el hecho se
     * apenda, el fold lo refleja, y el catálogo sigue siendo la foto que se tomó antes de empezar.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, version?: string, outputSchema?: array<string, mixed>}>
     */
    public function getToolSummaries(): array
    {
        $catalogo = $this->internalRegistry->getToolSummaries();

        $fuera = $this->mesa?->removed() ?? [];
        if ($fuera === []) {
            return $catalogo;
        }

        return array_values(array_filter(
            $catalogo,
            static fn (array $t): bool => !\in_array($t['name'], $fuera, true),
        ));
    }

    /**
     * Execute a tool by name through the underlying registry pipeline.
     *
     * @param array<string, mixed> $args
     */
    public function callTool(string $name, array $args): mixed
    {
        // LA COMPUERTA VA PRIMERO, antes de tocar el registro. Preguntar después sería preguntar
        // cuando el archivo ya se escribió: un permiso que se consulta tarde no es un permiso.
        if ($this->gate !== null) {
            $motivo = $this->gate->refuse($name, $args);
            if ($motivo !== null) {
                // SE LE PREGUNTA A LA MESA, no se inventa un canal para avisar. Si la compuerta retiró
                // esta opción al negar, la mesa ya lo sabe — y leerlo de ahí evita que quien niega y
                // quien informa sean dos fuentes que puedan discrepar.
                throw new ToolCallRefusedException(
                    $motivo,
                    optionRemoved: $this->mesa?->wasRemoved($name) ?? false,
                );
            }
        }

        // Call with context if available - ToolRegistry.call() accepts context as 3rd param
        $result = $this->internalRegistry->call($name, $args, $this->context);

        // ToolRegistry.call() returns ToolResult
        // Return raw data for backward compatibility (AgentOrchestrator expects string/array)
        if ($result->success) {
            $this->recorder?->recorded($name, $args, $this->rendered($result->data), true);

            return $result->data;
        }

        // El fallo se registra ANTES de propagarse, por lo mismo que en el resto de esta familia: un
        // error que no deja rastro es el que nadie encuentra al día siguiente — y aquí además es lo
        // que evita que el siguiente turno vuelva a intentar lo que ya no funcionó.
        $error = $result->error ?? 'Tool execution failed';
        $this->recorder?->recorded($name, $args, $error, false);

        throw new \Exception($error);
    }

    /** Lo que devolvió una herramienta, como texto — un arreglo se guarda en JSON, no como «Array». */
    private function rendered(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
