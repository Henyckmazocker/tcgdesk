<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\Commands\CatalogNormalizeCommand;
use App\Domain\Import\NameNormalizer;
use App\Domain\Repository\CardNameIndexRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Índice de nombres de mentira, en memoria y paginado igual que el de verdad:
 * por `oracle_id` las cartas, y por la PK compuesta `(printing_uuid, language)`
 * los nombres localizados.
 *
 * Lo que hace falta reproducir es el bucle: el comando pide lotes hasta que uno
 * viene vacío, y si la paginación no avanza el bucle no termina nunca sobre las
 * 34.992 filas reales —ni sobre las 410.604 de la tabla localizada—.
 */
class IndiceDeNombresFalso implements CardNameIndexRepositoryInterface
{
    /** @var array<string, array{name: string, clave: string|null}> oracle_id → carta */
    public array $cartas = [];

    /**
     * Nombres localizados, indexados por 'uuid|idioma' para que el orden de
     * iteración sea el mismo que el `ORDER BY printing_uuid, language` real.
     *
     * @var array<string, array{printingUuid: string, language: string, name: string, clave: string|null}>
     */
    public array $localizados = [];

    /** @var list<int> Tamaño de cada lote servido, para ver que se pagina */
    public array $lotesServidos = [];

    /** @var list<int> Lo mismo, para los localizados */
    public array $lotesLocalizadosServidos = [];

    /** @param array<string, string> $nombresPorOracleId */
    public function __construct(array $nombresPorOracleId)
    {
        foreach ($nombresPorOracleId as $oracleId => $name) {
            $this->cartas[(string) $oracleId] = ['name' => $name, 'clave' => null];
        }

        ksort($this->cartas);
    }

    /** @param list<array{printingUuid: string, language: string, name: string}> $filas */
    public function conLocalizados(array $filas): self
    {
        foreach ($filas as $fila) {
            $this->localizados[$fila['printingUuid'] . '|' . $fila['language']] = [
                'printingUuid' => $fila['printingUuid'],
                'language'     => $fila['language'],
                'name'         => $fila['name'],
                'clave'        => null,
            ];
        }

        ksort($this->localizados);

        return $this;
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

    public function contarLocalizadosSinNormalizar(): int
    {
        return count(array_filter($this->localizados, static fn (array $l): bool => $l['clave'] === null));
    }

    public function loteLocalizadoPorNormalizar(
        string $desdeUuid,
        string $desdeIdioma,
        int $limite,
        bool $todas
    ): array {
        $lote   = [];
        $cursor = $desdeUuid . '|' . $desdeIdioma;

        foreach ($this->localizados as $clave => $fila) {
            // La comparación de tuplas del repositorio real, con la misma forma:
            // 'uuid|idioma' ordena igual que `(printing_uuid, language)`.
            if ($cursor !== '|' && $clave <= $cursor) {
                continue;
            }
            if (!$todas && $fila['clave'] !== null) {
                continue;
            }

            $lote[] = [
                'printingUuid' => $fila['printingUuid'],
                'language'     => $fila['language'],
                'name'         => $fila['name'],
            ];

            if (count($lote) >= $limite) {
                break;
            }
        }

        $this->lotesLocalizadosServidos[] = count($lote);

        return $lote;
    }

    public function escribirClavesLocalizadas(array $filas): int
    {
        foreach ($filas as $fila) {
            $this->localizados[$fila['printingUuid'] . '|' . $fila['language']]['clave'] = $fila['clave'];
        }

        return count($filas);
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
