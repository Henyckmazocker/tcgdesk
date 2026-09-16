<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\Commands\CatalogLocalizedIdsCommand;
use App\Domain\Repository\CatalogRepositoryInterface;
use App\Infrastructure\Mtgjson\MtgJsonDownloader;
use App\Infrastructure\Mtgjson\MtgJsonMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Catálogo de mentira para el backfill de `scryfall_id`.
 *
 * Solo reproduce las dos cosas que el comando le pide, y las reproduce con la
 * regla que MySQL aplicaría: **`escribirIdsLocalizados()` actualiza y nunca
 * crea**, así que una clave que no está en la tabla se descarta en silencio —
 * que es exactamente lo que hace un `UPDATE ... WHERE (uuid, language) IN (…)`.
 */
class CatalogoDeIdsFalso implements CatalogRepositoryInterface
{
    /**
     * Las filas que «ya están ingeridas», por `<uuid>|<idioma>` → id o null.
     *
     * @var array<string, string|null>
     */
    public array $filas = [];

    /** @var list<array{printingUuid: string, language: string, scryfallId: string}> */
    public array $recibidas = [];

    /** Los tamaños de cada lote que le llegó, para ver que no manda 410.000 de golpe. */
    /** @var list<int> */
    public array $lotes = [];

    public function escribirIdsLocalizados(array $filas): int
    {
        $this->lotes[] = count($filas);

        foreach ($filas as $fila) {
            $this->recibidas[] = $fila;

            $clave = $fila['printingUuid'] . '|' . $fila['language'];

            // Solo actualiza: una clave que no existe no crea fila. Es la regla
            // que separa este backfill de `upsertLocalized()`.
            if (array_key_exists($clave, $this->filas)) {
                $this->filas[$clave] = $fila['scryfallId'];
            }
        }

        return count($filas);
    }

    public function contarLocalizadosSinId(array $claves): int
    {
        $pendientes = 0;

        foreach ($claves as $clave) {
            $k = $clave['printingUuid'] . '|' . $clave['language'];

            if (array_key_exists($k, $this->filas) && $this->filas[$k] === null) {
                $pendientes++;
            }
        }

        return $pendientes;
    }

    // ------------------------------------------------------------------
    // El resto del puerto: este comando no escribe catálogo.
    // ------------------------------------------------------------------

    public function upsertSet(array $set): void
    {
    }

    public function upsertCards(array $filas): int
    {
        return 0;
    }

    public function upsertPrintings(array $filas): int
    {
        return 0;
    }

    public function upsertLocalized(array $filas): int
    {
        return 0;
    }

    public function upsertLegalities(array $filas): int
    {
        return 0;
    }

    public function refrescarFormatos(): int
    {
        return 0;
    }

    public function contadores(): array
    {
        return [];
    }
}

/**
 * El backfill de `mtg_printing_localized.scryfall_id` — M6 del
 * Plan - Reconocimiento de la Impresión por su Arte.
 *
 * Lo que este test protege es lo que el `*Hecho cuando:*` del hito pide y lo que
 * nadie vería fallar:
 *
 *  - que el id que escribe es el de **cada traducción**, no el de la impresión;
 *  - que una traducción sin `scryfallId` en MTGJSON **no cuenta como pendiente**
 *    —es un NULL legítimo— y por lo tanto no tiñe de rojo un backfill correcto;
 *  - que **devuelve 1** cuando una fila que la fuente sí trae con id se queda
 *    sin escribir, que es el fallo silencioso que el código de salida existe
 *    para gritar.
 */
final class CatalogLocalizedIdsCommandTest extends TestCase
{
    private string $fichero;

    protected function setUp(): void
    {
        $datos = [
            'meta' => ['date' => '2026-09-16', 'version' => '5.3.0'],
            'data' => [
                'RTR' => [
                    'code'         => 'RTR',
                    'name'         => 'Return to Ravnica',
                    'releaseDate'  => '2012-10-05',
                    'type'         => 'expansion',
                    'totalSetSize' => 2,
                    'cards'        => [
                        [
                            'uuid'        => 'uuid-rtr-226',
                            'name'        => 'Deathrite Shaman',
                            'number'      => '226',
                            'rarity'      => 'rare',
                            'identifiers' => [
                                'scryfallId'       => 'sf-rtr-226-en',
                                'scryfallOracleId' => 'oracle-deathrite',
                            ],
                            'foreignData' => [
                                [
                                    'language'    => 'Spanish',
                                    'name'        => 'Chamán del rito de muerte',
                                    'identifiers' => ['scryfallId' => 'sf-rtr-226-es'],
                                ],
                                [
                                    'language'    => 'Japanese',
                                    'name' => '死儀礼のシャーマン',
                                    // Sin `identifiers`: el NULL legítimo.
                                ],
                                [
                                    'language'    => 'German',
                                    'name'        => 'Todesritus-Schamanin',
                                    'identifiers' => ['scryfallId' => 'sf-rtr-226-de'],
                                ],
                            ],
                        ],
                        [
                            'uuid'        => 'uuid-rtr-227',
                            'name'        => 'Sin traducciones',
                            'number'      => '227',
                            'rarity'      => 'common',
                            'identifiers' => [
                                'scryfallId'       => 'sf-rtr-227-en',
                                'scryfallOracleId' => 'oracle-227',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->fichero = tempnam(sys_get_temp_dir(), 'allprintings') . '.json';
        file_put_contents($this->fichero, json_encode($datos, JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        @unlink($this->fichero);
    }

    /**
     * El caso sano: **cada traducción con su propio id**, y código 0.
     *
     * El japonés se queda a NULL y eso no es un fallo: MTGJSON no publica id para
     * esa traducción y el escáner cae al inglés por ella.
     */
    public function testEscribeElIdDeCadaTraduccionYDevuelveCero(): void
    {
        $catalogo = new CatalogoDeIdsFalso();
        $catalogo->filas = [
            'uuid-rtr-226|Spanish'  => null,
            'uuid-rtr-226|Japanese' => null,
            'uuid-rtr-226|German'   => null,
        ];

        self::assertSame(0, $this->ejecutar($catalogo));

        self::assertSame('sf-rtr-226-es', $catalogo->filas['uuid-rtr-226|Spanish']);
        self::assertSame('sf-rtr-226-de', $catalogo->filas['uuid-rtr-226|German']);
        self::assertNull(
            $catalogo->filas['uuid-rtr-226|Japanese'],
            'Sin id en MTGJSON se queda a NULL, y eso no pone el comando en rojo.'
        );
    }

    /** El id localizado **no es el de la impresión**: si lo fuera, no serviría de nada. */
    public function testElIdEscritoNoEsElDeLaImpresion(): void
    {
        $catalogo = new CatalogoDeIdsFalso();
        $catalogo->filas = ['uuid-rtr-226|Spanish' => null];

        $this->ejecutar($catalogo);

        foreach ($catalogo->recibidas as $fila) {
            self::assertNotSame('sf-rtr-226-en', $fila['scryfallId']);
        }
    }

    /**
     * **Devuelve 1 si queda una fila que la fuente trae con id y que sigue a
     * NULL.**
     *
     * Aquí se simula escribiendo en una tabla que ignora el alemán: es el mismo
     * síntoma que dejaría un `UPDATE` que no case la clave completa, y sin este
     * código de salida el backfill diría que fue bien y el escáner seguiría
     * sembrando en inglés.
     */
    public function testDevuelveUnoSiQuedaAlgunaFilaQueDebiaTenerId(): void
    {
        $catalogo = new class () extends CatalogoDeIdsFalso {
            public function escribirIdsLocalizados(array $filas): int
            {
                // El alemán se pierde por el camino, en silencio.
                return parent::escribirIdsLocalizados(
                    array_values(array_filter($filas, static fn (array $f): bool => $f['language'] !== 'German'))
                );
            }
        };

        $catalogo->filas = [
            'uuid-rtr-226|Spanish' => null,
            'uuid-rtr-226|German'  => null,
        ];

        self::assertSame(1, $this->ejecutar($catalogo));
    }

    /**
     * Una traducción que MTGJSON publica y que **no está ingerida** no es una
     * fila pendiente: es una fila que no está.
     *
     * Contarla daría un backfill eternamente en rojo sobre una BD sana, y
     * crearla —que es lo que haría `upsertLocalized()`— dejaría una fila sin
     * `name_normalized` y a `catalog:normalize` devolviendo 1.
     */
    public function testUnaTraduccionQueNoEstaIngeridaNiSeCreaNiCuenta(): void
    {
        $catalogo = new CatalogoDeIdsFalso();
        $catalogo->filas = ['uuid-rtr-226|Spanish' => null];

        self::assertSame(0, $this->ejecutar($catalogo));
        self::assertArrayNotHasKey('uuid-rtr-226|German', $catalogo->filas, 'El backfill no crea filas.');
    }

    /** Relanzarlo es seguro: la segunda pasada escribe lo mismo y sigue en 0. */
    public function testRelanzarloDejaLoMismo(): void
    {
        $catalogo = new CatalogoDeIdsFalso();
        $catalogo->filas = ['uuid-rtr-226|Spanish' => null];

        $this->ejecutar($catalogo);
        $primera = $catalogo->filas;

        self::assertSame(0, $this->ejecutar($catalogo));
        self::assertSame($primera, $catalogo->filas);
    }

    /** Un set que no existe no se traga un 0: no hay nada que rellenar. */
    public function testUnSetQueNoExisteDevuelveUno(): void
    {
        $catalogo = new CatalogoDeIdsFalso();

        self::assertSame(1, $this->ejecutar($catalogo, ['--set=NOPE']));
        self::assertSame([], $catalogo->recibidas);
    }

    /** Con `--batch` pequeño, las filas salen en varias sentencias y no en una. */
    public function testElLoteTroceaLasEscrituras(): void
    {
        $catalogo = new CatalogoDeIdsFalso();
        $catalogo->filas = [
            'uuid-rtr-226|Spanish' => null,
            'uuid-rtr-226|German'  => null,
        ];

        $this->ejecutar($catalogo, ['--batch=1']);

        self::assertSame([1, 1], $catalogo->lotes);
    }

    /** @param list<string> $args */
    private function ejecutar(CatalogoDeIdsFalso $catalogo, array $args = []): int
    {
        $comando = new CatalogLocalizedIdsCommand(
            $catalogo,
            new MtgJsonMapper(),
            new MtgJsonDownloader(new NullLogger(), sys_get_temp_dir()),
            new NullLogger()
        );

        ob_start();
        $codigo = $comando->run(array_merge(["--file={$this->fichero}"], $args));
        ob_end_clean();

        return $codigo;
    }
}
