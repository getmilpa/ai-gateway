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
 *
 * ── Y HAY DOS NEGATIVAS, NO UNA ─────────────────────────────────────────────────────────────────
 *
 * La distinción no es de grado, es de clase, y decide si el bucle sigue:
 *
 *   · **Una PAUSA.** El piso pidió permiso o exigió firma. Hay una pregunta abierta esperando a un
 *     humano, y seguir sería contestarla por él. Termina la vuelta. Es el caso por default.
 *
 *   · **UN CAMINO QUE DEJÓ DE EXISTIR.** El segundo juicio negó y la opción SALIÓ DE LA MESA. Aquí
 *     terminar la vuelta es lo que mide Q-P19-D: el agente recibe el `no`, se detiene, y devuelve el
 *     veredicto del verificador como si fuera su respuesta —0 de 32 volvieron a llamar una
 *     herramienta—. Lo que hace falta es lo contrario: que siga, con una mesa distinta.
 *
 * El argumento de seguridad que sostiene la primera NO aplica a la segunda. «Una compuerta que se
 * puede rodear intentando por otro lado no es una compuerta» es cierto mientras la herramienta siga
 * ofrecida; cuando ya no está en el catálogo, no hay nada que rodear. El riel no le pide al operador
 * que no tome el camino: hace que ese camino no exista.
 */
final class ToolCallRefusedException extends \RuntimeException
{
    /**
     * @param bool $optionRemoved si además de negar, la opción se retiró de la mesa — y entonces el
     *                            bucle puede seguir, porque el modelo ya no la tiene enfrente
     */
    public function __construct(string $message, public readonly bool $optionRemoved = false)
    {
        parent::__construct($message);
    }
}
