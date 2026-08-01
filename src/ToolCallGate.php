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
 * Se consulta ANTES de cada llamada a herramienta, y puede negarla.
 *
 * ── POR QUÉ EXISTE, Y POR QUÉ ES UNA INTERFAZ VACÍA DE POLÍTICA ─────────────────────────────────
 *
 * Este paquete sabe hablarle a un modelo y alternar con herramientas. NO sabe —ni tiene por qué— qué
 * es una sesión, un permiso, un modo de autonomía o una firma. Eso vive en `milpa/agent` y en
 * `milpa/tool-runtime`, y meterlo aquí ataría el transporte a una política concreta.
 *
 * Entonces el bucle pregunta y obedece: quien quiera decidir implementa esto. Sin compuerta cableada,
 * el bucle corre exactamente como corría — la ausencia de política no puede ser una política nueva.
 *
 * ── NEGAR DETIENE EL BUCLE ──────────────────────────────────────────────────────────────────────
 *
 * Una negativa NO es un error de herramienta. Si se le devolviera al modelo como texto —que es lo que
 * pasa con cualquier excepción— el modelo leería «no puedes hacer eso» y probaría otra cosa: exactamente
 * lo que no se quiere de una compuerta. Por eso {@see McpClientService} lanza
 * {@see ToolCallRefusedException} y el orquestador la atrapa aparte, ANTES del catch genérico, y
 * termina la vuelta. La compuerta detiene; no sugiere.
 */
interface ToolCallGate
{
    /**
     * El motivo por el que esta llamada no procede, o `null` si procede.
     *
     * Devuelve el MOTIVO y no un booleano por lo mismo que el resto de esta familia: quien niega sabe
     * por qué, y quien recibe la negativa necesita esa frase para hacer algo con ella. Un `false` deja
     * a quien llamó inventando la explicación.
     *
     * @param array<string, mixed> $arguments
     */
    public function refuse(string $tool, array $arguments): ?string;
}
