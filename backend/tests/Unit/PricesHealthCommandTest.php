<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\Commands\PricesHealthCommand;
use App\Domain\Repository\PriceRepositoryInterface;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Histórico de precios de mentira: solo hace falta que sepa decir su último día.
 *
 * Los tres métodos de escritura del puerto están aquí porque la interfaz los
 * exige, no porque el comando los use — `prices:health` no escribe nada, y si
 * algún día lo hiciera, este doble lo delataría fallando.
 */
class HistoricoDePreciosFalso implements PriceRepositoryInterface
{
    public function __construct(
        private readonly ?string $ultimaFecha
    ) {
    }

    public function ultimaFechaHistorico(): ?string
    {
        return $this->ultimaFecha;
    }

    public function insertarHistorico(array $filas): int
    {
        throw new LogicException('prices:health no debe escribir en el histórico.');
    }

    public function reemplazarVigentes(array $filas): int
    {
        throw new LogicException('prices:health no debe tocar los precios vigentes.');
    }

    public function uuidsConocidos(): array
    {
        throw new LogicException('prices:health no necesita el catálogo.');
    }

    public function contadores(): array
    {
        throw new LogicException('prices:health no cuenta filas: le basta con la fecha máxima.');
    }
}

/**
 * La señal de salud del histórico de precios.
 *
 * Lo que se protege aquí es **el código de salida**, porque es lo único que el
 * cron mira: `mtg_price_daily` estuvo 33 días parada sin que nada lo dijera, y
 * este comando es lo que convierte ese silencio en un fallo visible.
 *
 * El reloj se inyecta a propósito: con `new DateTimeImmutable('today')` dentro
 * del comando, «un día de margen» significaría una cosa distinta cada día y los
 * tres casos no se podrían escribir.
 */
final class PricesHealthCommandTest extends TestCase
{
    private const HOY = '2026-09-14';

    private function ejecutar(?string $ultimaFecha): array
    {
        $comando = new PricesHealthCommand(
            new HistoricoDePreciosFalso($ultimaFecha),
            new NullLogger(),
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::HOY . ' 15:47:00')
        );

        ob_start();
        $codigo = $comando->run([]);
        $salida = (string) ob_get_clean();

        return [$codigo, $salida];
    }

    /** Al día: el sync de hoy ya escribió su fila. */
    public function testConElHistoricoAlDiaSaleConCero(): void
    {
        [$codigo, $salida] = $this->ejecutar('2026-09-14');

        self::assertSame(0, $codigo);
        self::assertStringContainsString('2026-09-14', $salida);
    }

    /**
     * Un día de margen NO es un fallo: MTGJSON publica a las 9:00 EST y un cron
     * de las 15:30 CEST que falle un día se recupera solo al siguiente. Avisar
     * aquí sería avisar de algo que ya se está arreglando — y una alarma que
     * salta sin motivo es una alarma que se deja de mirar.
     */
    public function testConUnDiaDeMargenSigueSaliendoConCero(): void
    {
        [$codigo, $salida] = $this->ejecutar('2026-09-13');

        self::assertSame(0, $codigo);
        self::assertStringContainsString('2026-09-13', $salida);
    }

    /**
     * Parado: tres días de desfase pasan del margen y el comando tiene que
     * decirlo con un código ≠ 0. Es el caso que, de haber existido, habría
     * cerrado el agujero en tres días en vez de en 33.
     */
    public function testConElHistoricoParadoSaleConUnoYDiceCuantosDiasLleva(): void
    {
        [$codigo, $salida] = $this->ejecutar('2026-09-11');

        self::assertSame(1, $codigo);
        // El número de días va a stderr, no a stdout: stdout es para el caso sano.
        self::assertSame('', $salida);
    }

    /** La tabla vacía no es «un poco desfasada»: es que nunca se sembró. */
    public function testConElHistoricoVacioSaleConUno(): void
    {
        [$codigo] = $this->ejecutar(null);

        self::assertSame(1, $codigo);
    }
}
