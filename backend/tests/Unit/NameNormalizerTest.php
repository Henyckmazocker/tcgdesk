<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Import\NameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El normalizador es lo único que separa «resolver por nombre» de «adivinar».
 *
 * Se protegen aquí las tres reglas que M0 midió y que no son evidentes leyendo
 * el código: los blancos `_____` se conservan, el separador ` // ` sobrevive, y
 * la transliteración es una tabla propia porque no hay `ext/intl`.
 */
final class NameNormalizerTest extends TestCase
{
    private NameNormalizer $normalizador;

    protected function setUp(): void
    {
        $this->normalizador = new NameNormalizer();
    }

    public function testPasaAMinusculasYQuitaLaPuntuacion(): void
    {
        self::assertSame('lightning bolt', $this->normalizador->normalizar('Lightning Bolt'));
        self::assertSame('jace the mind sculptor', $this->normalizador->normalizar('Jace, the Mind Sculptor'));
        self::assertSame('sakura tribe elder', $this->normalizador->normalizar('Sakura-Tribe Elder'));
    }

    /**
     * El apóstrofe se BORRA en vez de pasar a espacio: `Thrór's Map` y
     * `Thrors Map` tienen que dar la misma clave, porque nadie teclea el
     * apóstrofe igual dos veces.
     */
    public function testElApostrofeSeBorraNoSeConvierteEnEspacio(): void
    {
        self::assertSame('thrors map', $this->normalizador->normalizar("Thrór's Map"));
        self::assertSame(
            $this->normalizador->normalizar("Lim-Dûl's Vault"),
            $this->normalizador->normalizar("Lim-Dul's Vault")
        );
    }

    /**
     * La tabla de transliteración propia. No hay `ext/intl` en el contenedor, así
     * que esto no lo hace nadie más.
     */
    #[DataProvider('diacriticos')]
    public function testTransliteraLosDiacriticosDelCatalogo(string $conAcento, string $esperado): void
    {
        self::assertSame($esperado, $this->normalizador->normalizar($conAcento));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function diacriticos(): array
    {
        return [
            'û de Lim-Dûl'      => ['Lim-Dûl', 'lim dul'],
            'ö de Jötun'        => ['Jötun Grunt', 'jotun grunt'],
            'á de Juzám'        => ['Juzám Djinn', 'juzam djinn'],
            'í de Palantír'     => ['Palantír of Orthanc', 'palantir of orthanc'],
            'â de Dandân'       => ['Dandân', 'dandan'],
            'é de Séance'       => ['Séance', 'seance'],
            'Æ de la grafía antigua' => ['Æther Vial', 'aether vial'],
        ];
    }

    /**
     * LA regla del M2. Sin ella, `_____ Goblin` (Unfinity) ocupa la clave
     * `goblin` y se apropia de lo que el usuario teclea al escribir «Goblin»:
     * el único fallo silencioso que midió M0.
     */
    public function testConservaLosBlancosDeLasUnSets(): void
    {
        self::assertSame('_____ goblin', $this->normalizador->normalizar('_____ Goblin'));
        self::assertNotSame(
            $this->normalizador->normalizar('Goblin'),
            $this->normalizador->normalizar('_____ Goblin')
        );
        self::assertSame('_____ o saurus', $this->normalizador->normalizar('_____-o-saurus'));
        self::assertSame('_____', $this->normalizador->normalizar('_____'));
    }

    /**
     * Cinco y seis blancos son dos cartas distintas del catálogo. Borrándolos
     * colapsaban las dos en la clave vacía; conservándolos, cada una tiene la
     * suya y las dos son importables tecleando su nombre.
     */
    public function testCincoBlancosYSeisNoSonLaMismaClave(): void
    {
        self::assertNotSame(
            $this->normalizador->normalizar('_____'),
            $this->normalizador->normalizar('______')
        );
    }

    public function testElSeparadorDeCarasSobrevive(): void
    {
        self::assertSame(
            'delver of secrets // insectile aberration',
            $this->normalizador->normalizar('Delver of Secrets // Insectile Aberration')
        );
        self::assertTrue($this->normalizador->tieneVariasCaras(
            $this->normalizador->normalizar('Fast // Furious')
        ));
    }

    /** Teclear sin espacios alrededor de las barras da la misma clave. */
    public function testTolerarQueElUsuarioNoPongaEspaciosEnLasBarras(): void
    {
        self::assertSame(
            $this->normalizador->normalizar('Fast // Furious'),
            $this->normalizador->normalizar('Fast//Furious')
        );
    }

    public function testLaCaraFrontalEsLaMitadIzquierda(): void
    {
        self::assertSame(
            'delver of secrets',
            $this->normalizador->caraFrontal('Delver of Secrets // Insectile Aberration')
        );

        // De un nombre de una sola cara, la clave entera.
        self::assertSame('lightning bolt', $this->normalizador->caraFrontal('Lightning Bolt'));
    }

    public function testUnNombreQueEsSoloPuntuacionDaClaveVacia(): void
    {
        self::assertSame('', $this->normalizador->normalizar('!!! ,,, ...'));
        self::assertSame('', $this->normalizador->normalizar('   '));
    }
}
