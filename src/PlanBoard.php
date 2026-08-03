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
 * El estado vigente del plan, para volver a ponerlo delante del agente.
 *
 * ── POR QUÉ ESTO ES UN PUERTO Y NO UNA DEPENDENCIA ──────────────────────────────────────────────
 *
 * El plan es de `milpa/agent` —`Todo`, `TodoStatus`, el stream de la sesión— y este paquete es el
 * transporte al modelo. Que el bucle sepa qué es una tarjeta lo ataría a un substrato de sesión que
 * hoy es opcional: hay superficies que corren el bucle sin sesión ninguna, y para ésas la respuesta
 * correcta es `null`, no un plan vacío que ocupe contexto diciendo nada.
 *
 * Es el mismo trato que {@see OptionTable}: el bucle sabe que existe una mesa y no de qué está hecha.
 *
 * ── LO QUE ESTE PUERTO NO HACE ──────────────────────────────────────────────────────────────────
 *
 * No mueve tarjetas. Ésa es la confusión que Q-P20-B
 * existe para no cometer: reproyectar el plan **no reporta avance**, hace que el agente vuelva a
 * observar el estado vigente. Mover es el síntoma; lo que este puerto sostiene es la continuidad.
 *
 * Tampoco decide cuándo se muestra. El bucle lo pide en cada paso, igual que pide el catálogo de
 * herramientas, porque un estado que se pide una sola vez es una foto — y una foto no es un estado
 * vigente, es el estado de cuando empezó todo.
 */
interface PlanBoard
{
    /**
     * El plan como el agente tiene que verlo AHORA, o `null` si no hay plan que mostrar.
     *
     * Se llama en cada paso del bucle, así que la implementación tiene que leer el estado de verdad y
     * no memorizarlo: si esto devolviera lo mismo que hace tres pasos, sería exactamente el defecto
     * que se está midiendo, sólo que un nivel más abajo y más difícil de ver.
     *
     * `null` y cadena vacía significan lo mismo para el bucle —no se inyecta nada— pero no significan
     * lo mismo para quien lea el código: `null` es «esta sesión no lleva plan», y por eso es el que
     * devuelve una implementación sin sesión.
     */
    public function current(): ?string;
}
