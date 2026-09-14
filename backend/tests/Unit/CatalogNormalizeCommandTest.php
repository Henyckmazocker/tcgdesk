<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\Commands\CatalogNormalizeCommand;
use App\Domain\Import\NameNormalizer;
use App\Domain\Repository\CardNameIndexRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Índice de nombres de mentira, en memoria y paginado por `oracle_id` igual que
 * el de verdad.
 *
 * Lo que hace falta reproducir es el bucle: el comando pide lotes hasta que uno
 * viene vacío, y si la paginación no avanza el bucle no termina nunca sobre las
 * 34.992 filas reales.
 */
class IndiceDeNombresFalso implements CardNameIndexRepositoryInterface
{
    /** @var array<string, array{name: string, clave: string|null}> oracle_id → carta */
    public array $cartas = [];

    /** @var list<int> Tamaño de cada lote servido, para ver que se pagina */
    public array $lotesServidos = [];

    /** @param array<string, string> $nombresPorOracleId */
    public function __construct(array $nombresPorOracleId)
    {
        foreach ($nombresPorOracleId as $oracleId => $name) {
            $this->cartas[(string) $oracleId] = ['name' => $name, 'clave' => null];
        }

        ksort($this->cartas);
    }

    public function contarSinNormalizar(): int
    {
        return count(array_filter($this->cartas, static fn (array $c): bool => $c['clave'] === null));
    }

    public function lotePorNormalizar(string $desdeOracleId, int $limite, bool $todas): array
    {
        $lote = [];

        foreach ($this->cartas as $oracleId => $carta) {
            if ($oracleId <= $desdeOracleId) {
                continue;
            }
            if (!$todas && $carta['clave'] !== null) {
                continue;
            }

            $lote[] = ['oracleId' => $oracleId, 'name' => $carta['name']];

            if (count($lote) >= $limite) {
                break;
            }
        }

        $this->lotesServidos[] = count($lote);

        return $lote;
    }

    public function escribirClaves(array $porOracleId): int
    {
        foreach ($porOracleId as $oracleId => $clave) {
            $this->cartas[(string) $oracleId]['clave'] = $clave;
        }

        return count($porOracleId);
    }
}

/**
 * El backfill de `mtg_card.name_normalized`.
 *
 * Se protegen las dos cosas por las que este comando existe: que la clave la
 * calcule **el mismo `NameNormalizer` que usa el resolvedor** —una clave parecida
 * y distinta sería peor que no tenerla— y que el **código de salida** diga la
 * verdad, porque una carta sin clave no se resuelve por nombre y no protesta
 * nadie.
 */
final class CatalogNormalizeCommandTest extends TestCase
{
    private function comando(IndiceDeNombresFalso $indice): CatalogNormalizeCommand
    {
        return new CatalogNormalizeCommand($indice, new NameNormalizer(), new NullLogger());
    }

    /** @param string[] $args */
    private function ejecutar(CatalogNormalizeCommand $comando, array $args = []): array
    {
        ob_start();
        $codigo = $comando->run($args);
        $salida = (string) ob_get_clean();

        return [$codigo, $salida];
    }

    public function testNormalizaTodasLasCartasPendientesYDevuelveCero(): void
    {
        $indice = new IndiceDeNombresFalso([
            'o-1' => "Lim-Dûl's Vault",
            'o-2' => 'Delver of Secrets // Insectile Aberration',
            'o-3' => '_____ Goblin',
        ]);

        [$codigo, $salida] = $this->ejecutar($this->comando($indice), ['--batch=2']);

        self::assertSame(0, $codigo);
        self::assertSame(0, $indice->contarSinNormalizar());
        self::assertStringContainsString('Normalizadas 3 cartas', $salida);

        self::assertSame('lim duls vault', $indice->cartas['o-1']['clave']);
        self::assertSame('delver of secrets // insectile aberration', $indice->cartas['o-2']['clave']);
        // La razón de ser de todo el M2: el blanco se conserva.
        self::assertSame('_____ goblin', $indice->cartas['o-3']['clave']);
    }

    /** Pagina por cursor: con --batch=2 y 3 cartas son lotes de 2, 1 y 0. */
    public function testPaginaEnLotesYTerminaCuandoUnoVieneVacio(): void
    {
        $indice = new IndiceDeNombresFalso(['o-1' => 'Sol Ring', 'o-2' => 'Ponder', 'o-3' => 'Cultivate']);

        $this->ejecutar($this->comando($indice), ['--batch=2']);

        self::assertSame([2, 1, 0], $indice->lotesServidos);
    }

    /** Relanzarlo sin `--all` no vuelve a tocar nada: el primer lote ya viene vacío. */
    public function testRelanzarloEsBaratoPorqueSoloMiraLasQueEstanANull(): void
    {
        $indice = new IndiceDeNombresFalso(['o-1' => 'Sol Ring']);
        $this->ejecutar($this->comando($indice), []);

        $indice->lotesServidos = [];
        [$codigo, $salida]     = $this->ejecutar($this->comando($indice), []);

        self::assertSame(0, $codigo);
        self::assertSame([0], $indice->lotesServidos);
        self::assertStringContainsString('Normalizadas 0 cartas', $salida);
    }

    /** Con `--all` sí las recalcula todas: es lo que hay que lanzar si cambia el normalizador. */
    public function testConAllRecalculaTambienLasQueYaTenianClave(): void
    {
        $indice = new IndiceDeNombresFalso(['o-1' => 'Sol Ring']);
        $this->ejecutar($this->comando($indice), []);

        $indice->lotesServidos = [];
        $this->ejecutar($this->comando($indice), ['--all']);

        self::assertSame([1, 0], $indice->lotesServidos);
    }

    /**
     * El código de salida es lo que mira quien lanza el comando. Si el índice se
     * queda incompleto, decir 0 sería exactamente el bug que este proyecto
     * persigue: fallar en silencio.
     */
    public function testSiQuedaAlgunaCartaFueraDelIndiceDevuelveUno(): void
    {
        $indice = new class (['o-1' => 'Sol Ring']) extends IndiceDeNombresFalso {
            public function contarSinNormalizar(): int
            {
                return 7; // Como si otra ingesta hubiese metido cartas por detrás.
            }
        };

        [$codigo] = $this->ejecutar($this->comando($indice), []);

        self::assertSame(1, $codigo);
    }

    public function testLaAyudaNoTocaNada(): void
    {
        $indice = new IndiceDeNombresFalso(['o-1' => 'Sol Ring']);

        [$codigo, $salida] = $this->ejecutar($this->comando($indice), ['--help']);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('--all', $salida);
        self::assertSame([], $indice->lotesServidos);
        self::assertSame(1, $indice->contarSinNormalizar());
    }
}
