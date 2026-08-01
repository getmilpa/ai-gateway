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
 * Una {@see ToolCallGate} negó esta llamada.
 *
 * Es un tipo propio y no una `\Exception` cualquiera porque el orquestador tiene que poder
 * DISTINGUIRLA: cualquier otra excepción de una herramienta se le devuelve al modelo como texto y el
 * bucle sigue —que es lo correcto para un fallo, porque el modelo puede corregir— mientras que una
 * negativa tiene que terminar la vuelta. Compartir el tipo haría que una compuerta se leyera como una
 * sugerencia.
 */
final class ToolCallRefusedException extends \RuntimeException
{
}
