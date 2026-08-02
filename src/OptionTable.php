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
 * La mesa: qué opciones tiene enfrente el agente, y quién las quita.
 *
 * ── POR QUÉ ESTO EXISTE, CON NÚMERO ─────────────────────────────────────────────────────────────
 *
 * Cuatro tandas y ~200 corridas midieron que **decirle que no no lo redirige**: 0 de 32 volvieron a
 * llamar una herramienta después de una negativa (Q-P19-D), y tampoco cuando la negativa le nombraba
 * la alternativa (Q-P19-E). Lo que sí lo movió fue **quitar la opción del catálogo**: 16 de 16
 * observaron (Q-P19-F).
 *
 * La conclusión que este puerto encarna: **lo que redirige no es el mensaje, es la mesa.** Un riel no
 * convence al operador de no tomar el camino equivocado — hace que ese camino deje de existir.
 *
 * ── UNA SOLA AUTORIDAD, Y LA PROYECCIÓN SE DERIVA ───────────────────────────────────────────────
 *
 * Quitar y decir-qué-está-quitado son el MISMO puerto a propósito. Si fueran dos, el catálogo sería
 * una segunda copia del estado, y este repositorio ya encontró cuatro comparadores de identidad de
 * capacidad que no coincidían (Q-P17): dos sitios que deciden lo mismo divergen, la única pregunta es
 * cuándo.
 *
 * Por eso {@see removed()} se consulta **cada vez** en vez de guardarse: el catálogo de herramientas
 * es una PROYECCIÓN del espacio de decisión, igual que un tablero es una proyección de su stream
 * (ADR-0035). Quien implemente esto no debe devolver un arreglo que capturó al construirse — debe
 * volver a leer el mundo.
 *
 * ── LO QUE ESTE PUERTO NO ES ────────────────────────────────────────────────────────────────────
 *
 * No es un `DecisionSpace` general sobre herramientas, endpoints, botones y rutas. Hay **un** caso
 * medido —quitar una herramienta— y generalizar antes del segundo es la forma exacta del defecto que
 * este repositorio cazó once veces en un día: una capacidad declarada que no le llega a nadie. Se
 * nombra `option` y no `tool` porque la abstracción correcta es la OPCIÓN; se implementa el único caso
 * que se puede medir, y la costura queda donde iría la generalización (ADR-0037).
 *
 * Es opcional, como {@see ToolCallGate} y {@see ToolCallRecorder}. Sin mesa, el bucle corre como
 * corría: la ausencia de política no puede ser una política nueva.
 */
interface OptionTable
{
    /**
     * Quita una opción de la mesa. Es una MUTACIÓN DEL ENTORNO, no un mensaje.
     *
     * Se llama cuando una autoridad ya negó esa llamada por ir más allá de lo que se pidió. La
     * diferencia con devolver un motivo es entera: un motivo le pide al agente que se resista, y
     * Q-P19-E midió que resistirse no se puede pedir. Quitarla convierte una petición de conducta en
     * un hecho del entorno.
     *
     * @param string      $code    por qué se fue, como CÓDIGO. El mensaje cambia —se reescribe, se
     *                             traduce, se afina—; el código no, y una proyección que quiera
     *                             agrupar o contar motivos tiene que poder hacerlo sin parsear prosa
     * @param string|null $message la frase para quien lo lea, si la hay
     */
    public function remove(string $option, string $code, ?string $message = null): void;

    /**
     * Si alguna autoridad ya declaró ida esta opción. **Es el HECHO, no la proyección.**
     *
     * Las dos preguntas parecen la misma y no lo son, y separarlas es lo que permite medir:
     *
     *   · {@see removed()} contesta **qué ve el modelo** — de ahí se deriva el catálogo.
     *   · esto contesta **qué pasó** — de ahí se deriva si la vuelta puede seguir.
     *
     * En una app las dos coinciden siempre. Divergen sólo en el brazo de laboratorio que apenda el
     * hecho sin cambiar la mesa, que es justo el que separa «la vuelta siguió» de «la mesa cambió» —
     * la atribución que el cierre de Q-P19-H no pudo hacer.
     *
     * Que la recuperabilidad cuelgue del HECHO y no de la proyección también es lo correcto fuera del
     * laboratorio: quien negó ya decidió, y una proyección filtrada por otra razón no debería poder
     * convertir esa decisión en un final de corrida.
     */
    public function wasRemoved(string $option): bool;

    /**
     * Las opciones que hoy NO están en la mesa.
     *
     * Se vuelve a preguntar en cada paso, y ésa es la razón de que exista: una implementación que
     * conteste con lo que capturó al construirse deja el catálogo congelado en una foto, y entonces
     * quitar una opción no cambia nada de lo que el modelo ve.
     *
     * @return list<string>
     */
    public function removed(): array;
}
