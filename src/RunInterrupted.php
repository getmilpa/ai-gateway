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
 * Alguien de afuera pidió que la vuelta se detenga.
 *
 * ── POR QUÉ UNA EXCEPCIÓN PROPIA Y NO UN `return false` ─────────────────────────────────────────
 *
 * El bucle envuelve `onStep` en un `catch (\Throwable)` que registra y sigue, a propósito: una
 * superficie que truena pintando un spinner no tiene por qué matar el trabajo del agente. Pero eso
 * hace que un fallo y una interrupción se vean igual desde adentro, y son lo contrario — uno es un
 * accidente que hay que sobrevivir, el otro es una orden que hay que obedecer.
 *
 * Con un tipo propio, el bucle puede tragarse los accidentes y dejar pasar la orden. Un booleano de
 * retorno también serviría, y se descartó porque una superficie que se olvida de devolver algo diría
 * «detente» sin querer: el default de un tipo nuevo es no existir, y el de un booleano es `false`.
 *
 * ── DÓNDE SE DETIENE, Y POR QUÉ AHÍ ─────────────────────────────────────────────────────────────
 *
 * **Entre pasos, nunca a media herramienta.** Cortar una llamada en vuelo dejaría el mundo a medias
 * —un archivo escrito y su registro no— y el stream diría algo que el disco no. El costo es que una
 * herramienta lenta se termina antes de que la interrupción se note; ese costo es visible y el otro
 * no, y entre un sistema que tarda y uno que miente, tarda.
 */
final class RunInterrupted extends \RuntimeException
{
    /**
     * La interrupción que pidió el humano, con el paso en el que llegó.
     *
     * El número no es decorativo: quien lee «se interrumpió» necesita saber CUÁNTO alcanzó a hacerse
     * antes, porque de eso depende si retoma o rehace. Sin él, la misma frase describe una vuelta que
     * no empezó y una que estaba por terminar.
     */
    public static function porElHumano(int $paso): self
    {
        return new self("la vuelta se interrumpió en el paso {$paso}");
    }
}
