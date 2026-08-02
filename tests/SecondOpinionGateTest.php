<?php

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\SecondOpinionGate;
use Milpa\AiGateway\ToolCallGate;
use Milpa\ToolRuntime\Contracts\LlmServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * El segundo juicio: qué puede hacer, y sobre todo qué NO puede.
 *
 * Estas pruebas fijan el mecanismo, no su eficacia. Si un segundo lector baja la tasa con que el
 * agente ejecuta lo destructivo es una pregunta medida aparte —[Q-P19-D], pre-registrada antes de que
 * esta clase existiera, con cinco falsificadores— y ninguna prueba unitaria puede contestarla.
 */
final class SecondOpinionGateTest extends TestCase
{
    /**
     * El `no` del piso NO se apela, y el modelo ni siquiera se consulta.
     *
     * Es la propiedad que hace que esto siga siendo una compuerta. A un modelo se le puede persuadir;
     * un verificador que pudiera revertir un `no` sintáctico sería una vía de escape con forma de
     * mejora.
     */
    public function testTheFloorsRefusalIsFinalAndTheModelIsNotEvenAsked(): void
    {
        $modelo = new ModeloEspia('ALLOW');
        $puerta = new SecondOpinionGate(new PisoQueNiega('exige firma'), $modelo, 'lo que sea');

        self::assertSame('exige firma', $puerta->refuse('plugins_disable', []));
        self::assertSame(0, $modelo->llamadas, 'no se le pregunta al modelo lo que ya se nego');
    }

    /** Cuando el segundo lector niega, vuelve SU motivo — no un booleano ni el del piso. */
    public function testWhenTheSecondReaderDeniesItsOwnReasonComesBack(): void
    {
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('DENY: la pregunta era qué pasaría, no que lo hicieras'),
            '¿Qué deja de funcionar si deshabilito el plugin X?',
            ['plugins_disable'],
        );

        self::assertSame(
            'la pregunta era qué pasaría, no que lo hicieras',
            $puerta->refuse('plugins_disable', ['name' => 'X']),
        );
    }

    /**
     * La negativa dice QUÉ SÍ, y en la misma frase.
     *
     * Q-P19-D midió que un `no` correcto y estéril apaga al agente: 0 de 32 corridas volvieron a
     * llamar una herramienta. La alternativa la declara el sistema —no la inventa el modelo— porque
     * pedirle que la adivine sería mover la adivinación de lugar, no quitarla.
     */
    public function testTheRefusalNamesTheObservableAlternative(): void
    {
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('DENY: la pregunta era qué pasaría'),
            '¿Qué deja de funcionar si deshabilito X?',
            ['plugins_disable'],
            ['plugins_disable' => 'plugins_simulate'],
        );

        $motivo = (string) $puerta->refuse('plugins_disable', ['name' => 'X']);

        self::assertStringContainsString('la pregunta era qué pasaría', $motivo);
        self::assertStringContainsString('plugins_simulate', $motivo);
    }

    /** Sin alternativa declarada, la negativa es exactamente la de antes. */
    public function testWithoutADeclaredAlternativeTheRefusalIsUnchanged(): void
    {
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('DENY: no hacía falta'),
            'x',
            ['plugins_disable'],
        );

        self::assertSame('no hacía falta', $puerta->refuse('plugins_disable', []));
    }

    /** Y cuando aprueba, deja pasar. */
    public function testWhenItAllowsTheCallGoesThrough(): void
    {
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('ALLOW'),
            'apaga el plugin X',
            ['plugins_disable'],
        );

        self::assertNull($puerta->refuse('plugins_disable', ['name' => 'X']));
    }

    /**
     * Sólo se paga un segundo juicio por lo que lo amerita.
     *
     * Preguntarle al modelo por cada lectura duplicaría las peticiones para confirmar lo obvio, y ese
     * costo se paga en cada corrida.
     */
    public function testReadsThatDoNotWarrantItAreNotEvenSentToTheModel(): void
    {
        $modelo = new ModeloEspia('DENY: no');
        $puerta = new SecondOpinionGate(new PisoQueDejaPasar(), $modelo, 'x', ['plugins_disable']);

        self::assertNull($puerta->refuse('plugins_architecture', []));
        self::assertSame(0, $modelo->llamadas);
    }

    /**
     * Si el modelo no contesta, la llamada pasa — y el fallo QUEDA DICHO.
     *
     * Callarlo haría que un verificador caído se viera exactamente igual que uno que aprueba todo, que
     * es la confusión que arruinaría la medición: el falsificador 1 de Q-P19-D dice que un verificador
     * que aprueba todo no hace nada, y no se puede distinguir de uno roto sin esta línea.
     */
    public function testWhenTheModelCannotAnswerTheCallPassesAndTheFailureIsSaid(): void
    {
        $bitacora = new BitacoraEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloQueRevienta(),
            'x',
            ['plugins_disable'],
            logger: $bitacora,
        );

        self::assertNull($puerta->refuse('plugins_disable', []));
        self::assertNotEmpty($bitacora->lineas);
    }

    /**
     * Un párrafo sin veredicto NO es una aprobación.
     *
     * Tratar el silencio como un `sí` sería inventarle una opinión al segundo lector — y la llamada
     * pasaría con el piso solo, que es distinto de haber sido aprobada. Por eso también se dice.
     */
    public function testAnAnswerWithoutAVerdictIsNotAnApprovalItIsASilence(): void
    {
        $bitacora = new BitacoraEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('Pues depende de varias cosas, habría que ver el contexto…'),
            'x',
            ['plugins_disable'],
            logger: $bitacora,
        );

        self::assertNull($puerta->refuse('plugins_disable', []));
        self::assertNotEmpty($bitacora->lineas, 'un silencio que pasa por aprobación tiene que quedar escrito');
    }

    /** Lo que se le manda al modelo trae la PETICIÓN y la llamada — sin eso no hay contra qué juzgar. */
    public function testTheModelSeesTheRequestAndTheProposedCall(): void
    {
        $modelo = new ModeloEspia('ALLOW');
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            $modelo,
            '¿Qué deja de funcionar si deshabilito X?',
            ['plugins_disable'],
        );

        $puerta->refuse('plugins_disable', ['name' => 'X']);

        self::assertStringContainsString('¿Qué deja de funcionar si deshabilito X?', $modelo->ultimoPrompt);
        self::assertStringContainsString('plugins_disable', $modelo->ultimoPrompt);
        self::assertStringContainsString('"name": "X"', $modelo->ultimoPrompt);
    }
}

/** @internal */
final class PisoQueNiega implements ToolCallGate
{
    public function __construct(private readonly string $motivo)
    {
    }

    public function refuse(string $tool, array $arguments): ?string
    {
        return $this->motivo;
    }
}

/** @internal */
final class PisoQueDejaPasar implements ToolCallGate
{
    public function refuse(string $tool, array $arguments): ?string
    {
        return null;
    }
}

/** @internal */
final class ModeloEspia implements LlmServiceInterface
{
    public int $llamadas = 0;

    public string $ultimoPrompt = '';

    public function __construct(private readonly string $respuesta)
    {
    }

    public function generateResponse(string $prompt, array $tools = [], array $messages = [], int $maxTokens = 4096): array
    {
        ++$this->llamadas;
        $this->ultimoPrompt = $prompt;

        return ['content' => $this->respuesta];
    }
}

/** @internal */
final class ModeloQueRevienta implements LlmServiceInterface
{
    public function generateResponse(string $prompt, array $tools = [], array $messages = [], int $maxTokens = 4096): array
    {
        throw new \RuntimeException('sin red');
    }
}

/** @internal */
final class BitacoraEspia extends AbstractLogger
{
    /** @var list<string> */
    public array $lineas = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lineas[] = (string) $message;
    }
}
