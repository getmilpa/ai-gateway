<?php

/**
 * This file is part of Milpa AI Gateway — the LLM transport and tool loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/**
 * Se avisa DESPUÉS de cada llamada a herramienta, con lo que contestó.
 *
 * ── POR QUÉ NO ALCANZABA CON {@see ToolCallGate} ────────────────────────────────────────────────
 *
 * La compuerta ve la INTENCIÓN: qué se va a llamar y con qué. Eso sirve para decidir y no para
 * registrar, porque una intención no dice si funcionó — y una bitácora que apunta lo que se iba a
 * hacer en lugar de lo que pasó es peor que ninguna: se lee igual de segura y miente.
 *
 * Con las dos, quien retoma una sesión mañana sabe qué se intentó Y cómo salió, que es lo que evita
 * la falla más cara de una jornada larga: repetir el trabajo que el turno anterior ya hizo.
 *
 * Es opcional, como la compuerta. Sin nadie escuchando, el bucle corre igual.
 */
interface ToolCallRecorder
{
    /**
     * Apunta que esta herramienta corrió y qué contestó.
     *
     * @param array<string, mixed> $arguments
     * @param bool                 $ok        si la herramienta contestó en vez de tronar. Un fallo se
     *                                        registra igual: saber qué se intentó y no funcionó es
     *                                        justo lo que impide intentarlo otra vez
     */
    public function recorded(string $tool, array $arguments, string $result, bool $ok): void;
}
