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

    /**
     * Una respuesta VACÍA también se dice — el docblock promete «no calla» y esta vía callaba.
     *
     * Lo encontró una revisión adversaria comparando la promesa contra el código: timeout y párrafo
     * sin veredicto ya se decían, la respuesta vacía no. Las tres son la misma confusión — un juez que
     * no opinó y se ve idéntico a uno que aprobó.
     */
    public function testAnEmptyAnswerIsSaidNotSwallowed(): void
    {
        $bitacora = new BitacoraEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia(''),
            'x',
            ['plugins_disable'],
            logger: $bitacora,
        );

        self::assertNull($puerta->refuse('plugins_disable', []));
        self::assertNotEmpty($bitacora->lineas, 'una respuesta vacía que pasa en silencio se lee como aprobación');
    }

    /**
     * La negativa deja un testigo FUERA del stream.
     *
     * Un falsificador «negó y el hecho no está en el stream» sólo se puede operacionalizar si la
     * negativa tiene un canal independiente del propio stream que se está verificando. Ese canal es
     * esta línea.
     */
    public function testADenialLeavesAWitnessOutsideTheStream(): void
    {
        $bitacora = new BitacoraEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('DENY: va más allá'),
            'x',
            ['plugins_disable'],
            logger: $bitacora,
        );

        $puerta->refuse('plugins_disable', []);

        self::assertNotEmpty($bitacora->lineas);
        self::assertStringContainsString('negó', implode(' ', $bitacora->lineas));
    }

    /**
     * Una APROBACIÓN no deja línea — el silencio del juez que aprobó no es un fallo.
     *
     * El warning de refuse() se disparaba también en los ALLOW (juzgar devuelve null en los dos
     * casos), y en la tanda de Q-P19-K produjo 61 «no pudo opinar» con cero caídas reales: la métrica
     * de juez-caído salió 16/16 hasta decodificarla a mano. Quien informa es quien sabe la causa.
     */
    public function testAnApprovalLeavesNoLineBecauseItIsNotAFailure(): void
    {
        $bitacora = new BitacoraEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('ALLOW'),
            'deshabilita X',
            ['plugins_disable'],
            logger: $bitacora,
        );

        self::assertNull($puerta->refuse('plugins_disable', []));
        self::assertSame([], $bitacora->lineas, 'un ALLOW que se loguea como caída fabrica una métrica de fallos');
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

    /**
     * Cuando ESTE juicio niega, la opción sale de la mesa — la negativa deja de ser un mensaje.
     *
     * Q-P19-D/E midieron que un `no`, incluso nombrando la alternativa, no redirige: 0 de 32 volvieron
     * a llamar una herramienta. Quitarla convierte una petición de conducta en un hecho del entorno,
     * que es lo único que Q-P19-F midió que sí mueve al agente.
     */
    public function testWhenTheSecondReaderDeniesTheOptionLeavesTheTable(): void
    {
        $mesa = new MesaEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('DENY: la pregunta era qué pasaría'),
            '¿Qué deja de funcionar si deshabilito X?',
            ['plugins_disable'],
            mesa: $mesa,
        );

        $puerta->refuse('plugins_disable', ['name' => 'X']);

        self::assertSame([['plugins_disable', 'beyond_request']], $mesa->quitadas);
    }

    /**
     * EL `no` DEL PISO NO RETIRA LA OPCIÓN, y ésta es la prueba que sostiene la distinción entera.
     *
     * El piso niega con `AskPermission` o `RequireSignature`: las dos son **una pausa**, no un
     * imposible. Quien la recibe puede conceder el permiso o firmar, y la opción tiene que seguir
     * estando cuando la sesión vuelva. Retirarla aquí convertiría «todavía no» en «nunca», y el agente
     * se encontraría la mesa sin la herramienta que le acaban de autorizar.
     */
    public function testTheFloorsRefusalIsAPauseAndDoesNotTakeTheOptionOffTheTable(): void
    {
        $mesa = new MesaEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueNiega('hace falta permiso para esto'),
            new ModeloEspia('DENY: da igual, ni se le pregunta'),
            'lo que sea',
            ['plugins_disable'],
            mesa: $mesa,
        );

        self::assertSame('hace falta permiso para esto', $puerta->refuse('plugins_disable', []));
        self::assertSame([], $mesa->quitadas, 'una pausa no es un imposible');
    }

    /** Si el segundo lector aprueba, la mesa no se toca. */
    public function testAnApprovedCallLeavesTheTableAlone(): void
    {
        $mesa = new MesaEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloEspia('ALLOW'),
            'deshabilita el plugin X',
            ['plugins_disable'],
            mesa: $mesa,
        );

        self::assertNull($puerta->refuse('plugins_disable', ['name' => 'X']));
        self::assertSame([], $mesa->quitadas);
    }

    /**
     * Un verificador que no pudo opinar TAMPOCO retira.
     *
     * Sin esto, una caída de red vaciaría la mesa: cada llamada que el modelo no alcanzó a juzgar se
     * llevaría su opción, y el agente terminaría sin herramientas por un problema de conectividad. Es
     * la misma razón por la que un fallo deja pasar en vez de negar.
     */
    public function testAJudgeThatCouldNotAnswerRemovesNothing(): void
    {
        $mesa = new MesaEspia();
        $puerta = new SecondOpinionGate(
            new PisoQueDejaPasar(),
            new ModeloQueRevienta(),
            'x',
            ['plugins_disable'],
            mesa: $mesa,
        );

        self::assertNull($puerta->refuse('plugins_disable', []));
        self::assertSame([], $mesa->quitadas);
    }
}

/** @internal */
final class MesaEspia implements \Milpa\AiGateway\OptionTable
{
    /** @var list<array{0: string, 1: string}> */
    public array $quitadas = [];

    public function remove(string $option, string $code, ?string $message = null): void
    {
        $this->quitadas[] = [$option, $code];
    }

    public function removed(): array
    {
        return array_map(static fn (array $q): string => $q[0], $this->quitadas);
    }

    public function wasRemoved(string $option): bool
    {
        return \in_array($option, $this->removed(), true);
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
