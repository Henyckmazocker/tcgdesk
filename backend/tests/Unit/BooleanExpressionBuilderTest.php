<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Persistence\Search\BooleanExpressionBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Lo que protege este test es el fallo que M0 midió sobre el catálogo real: un
 * token que no está en el índice, exigido con '+', hace que la consulta no
 * devuelva NADA. No falla, no avisa: devuelve cero resultados.
 */
final class BooleanExpressionBuilderTest extends TestCase
{
    private function builder(): BooleanExpressionBuilder
    {
        // Un extracto de la lista real de InnoDB; en producción se lee del servidor.
        return new BooleanExpressionBuilder(
            ['the' => true, 'of' => true, 'to' => true, 'and' => true, 'for' => true],
            3
        );
    }

    public function testCadaPalabraEsObligatoriaYConComodinDeCola(): void
    {
        self::assertSame('+lightning* +bolt*', $this->builder()->construir('lightning bolt'));
    }

    /**
     * El caso que devolvía cero resultados: 'the' mide 3 caracteres, así que el
     * filtro por longitud no la pilla, pero es stopword y no está indexada.
     */
    public function testLasStopwordsSeDescartan(): void
    {
        // Las mayúsculas se conservan: MySQL busca con la colación de la columna
        // (utf8mb4_unicode_ci), que ya ignora caja y acentos. Bajar a minúsculas
        // aquí no cambiaría ningún resultado y solo escondería lo que se envía.
        self::assertSame(
            '+Jace* +Mind* +Sculptor*',
            $this->builder()->construir('Jace, the Mind Sculptor')
        );
    }

    public function testLosTokensMasCortosQueElMinimoSeDescartan(): void
    {
        self::assertSame('+wall* +air*', $this->builder()->construir('wall of air'));
    }

    public function testLaPuntuacionSeTira(): void
    {
        // La coma y las barras son operadores en BOOLEAN MODE: dejarlas pasar da
        // error de sintaxis o resultados absurdos.
        self::assertSame(
            '+Delver* +Secrets* +Insectile* +Aberration*',
            $this->builder()->construir('Delver of Secrets // Insectile Aberration')
        );
    }

    /**
     * Si se descartara todo, la búsqueda devolvería el catálogo entero o nada.
     * Más vale un resultado dudoso que ninguno: el desempate por coincidencia
     * exacta del repositorio lo recoloca.
     */
    public function testSiTodoSonStopwordsOPalabrasCortasSeBuscanIgualmente(): void
    {
        self::assertSame('+the* +of*', $this->builder()->construir('the of'));
        self::assertSame('+Ire*', $this->builder()->construir('Ire'));
    }

    public function testElTextoVacioNoDaExpresion(): void
    {
        self::assertSame('', $this->builder()->construir('   '));
        self::assertSame('', $this->builder()->construir('...'));
    }

    public function testDetectaJaponesChinoYCoreano(): void
    {
        $builder = $this->builder();

        self::assertTrue($builder->esCjk('太陽の指輪'));   // japonés
        self::assertTrue($builder->esCjk('幽禁少女'));      // chino
        self::assertTrue($builder->esCjk('격리된 아이'));   // coreano
        self::assertFalse($builder->esCjk('Sol Ring'));
        self::assertFalse($builder->esCjk('Luz de destierro'));
    }

    public function testElMinimoDeTokenEsConfigurable(): void
    {
        // Si el servidor tuviera innodb_ft_min_token_size = 2, 'of' sí entraría.
        $builder = new BooleanExpressionBuilder([], 2);

        self::assertSame('+wall* +of* +air*', $builder->construir('wall of air'));
    }
}
