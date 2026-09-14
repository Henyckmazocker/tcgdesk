<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\GuardarPrivacidad;
use App\Application\UseCase\ObtenerPrivacidad;
use App\Application\UseCase\Seguir;
use App\Domain\Social\Descubrimiento;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use App\Infrastructure\Persistence\MySqlUserRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Unit\Doubles\PrivacidadFalsa;
use Tests\Unit\Doubles\SeguimientosFalsos;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * Los DOS fallos del M6 que saldrían verdes, cazados aquí.
 *
 * Ninguno de los dos lo detecta un test de use case, porque los dos consisten en
 * que algo funcione *demasiado bien* en el sitio equivocado:
 *
 * ## 1. Que `show_in_search` acabe siendo una `Seccion`
 *
 * Es la tentación obvia: la columna vive en `user_privacy_settings`, el panel la
 * pinta al lado de las otras cinco y añadir un `case` al enum son dos líneas.
 * **Y rompería el M3 en silencio**: `MySqlUserPrivacyRepository::nivelesDe()`
 * recorre `Seccion::cases()` y promete «siempre todas», y
 * `Seguir::tieneCaraPublica()` recorre justo ese array buscando **un**
 * `Nivel::Todos` para decidir si un perfil se puede seguir. Con una sexta
 * entrada que nace en `everyone`, un perfil con las cinco secciones en `nobody`
 * habría pasado a «tener cara pública» y el 422 que el M3 fijó como su *Hecho
 * cuando:* se habría caído sin un solo error.
 *
 * Y `guardarNiveles()` habría intentado escribir `friends` en un ENUM de dos
 * valores, porque su bucle construye el `INSERT` sobre esas mismas `cases()`.
 *
 * ## 2. Que el `JOIN` del buscador sea `INNER` y no `LEFT`
 *
 * **La ausencia de fila en `user_privacy_settings` SIGNIFICA «los defectos»**, y
 * hoy no hay ni una fila en toda la tabla. Con un `INNER JOIN`, el buscador
 * devolvería **cero resultados siempre** —lo contrario del defecto `everyone`
 * que el hito eligió— y lo haría sin ningún error: la consulta es válida y no se
 * queja nadie. Un test contra el doble tampoco lo vería, porque el doble no es
 * quien escribe el SQL. Así que se mira el SQL.
 */
final class LaSextaColumnaNoEsUnaSeccionTest extends TestCase
{
    // ========================================================================
    // 1. La sexta columna no se cuela entre las cinco secciones
    // ========================================================================

    public function testSeccionSigueTeniendoCincoCasosYNingunoEsLaBusqueda(): void
    {
        self::assertCount(5, Seccion::cases());

        foreach (Seccion::cases() as $seccion) {
            self::assertNotSame(Descubrimiento::COLUMNA, $seccion->columna());
            self::assertNotSame(Descubrimiento::CLAVE, $seccion->value);
        }
    }

    public function testLosNivelesPorDefectoSiguenSiendoCincoYNoSeis(): void
    {
        self::assertCount(5, Seccion::nivelesPorDefecto());
        self::assertArrayNotHasKey(Descubrimiento::CLAVE, Seccion::nivelesPorDefecto());
    }

    /** Dos valores y no tres: `friends` no existe aquí, y pedirlo es un error. */
    public function testElDescubrimientoTieneDosValoresYFriendsNoEsUnoDeEllos(): void
    {
        self::assertCount(2, Descubrimiento::cases());
        self::assertNull(Descubrimiento::intentar('friends'));

        $this->expectException(InvalidArgumentException::class);

        Descubrimiento::desde('friends');
    }

    /**
     * **El defecto es `everyone`**, la única de las seis columnas que nace
     * abierta sin ser una sección de contenido. Está duplicado en la migración
     * `20260914_180000_show_in_search.sql` y en el `COALESCE` del buscador: si
     * se cambia uno, se cambian los tres.
     */
    public function testElDefectoEsEveryoneYNoNobody(): void
    {
        self::assertSame(Descubrimiento::Todos, Descubrimiento::porDefecto());
        self::assertSame('everyone', Descubrimiento::porDefecto()->value);
    }

    /**
     * **El test que fija el fallo del M3.** Un perfil con las cinco secciones en
     * `nobody` no se puede seguir, y eso tiene que seguir siendo verdad aunque
     * su `show_in_search` esté —como nace— en `everyone`.
     */
    public function testUnPerfilCerradoSigueSinPoderSeguirseAunqueSalgaEnElBuscador(): void
    {
        $usuarios     = new UsuariosFalsos();
        $seguimientos = new SeguimientosFalsos();
        $privacidad   = new PrivacidadFalsa();

        $yo      = $usuarios->alta('juanito');
        $cerrada = $usuarios->alta('cerrada');

        $privacidad->todasEn($cerrada, Nivel::Nadie);
        // Y su sexta columna en `everyone`, que es como nace todo el mundo.
        $privacidad->ponBusqueda($cerrada, Descubrimiento::Todos);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no enseña nada públicamente');

        (new Seguir($seguimientos, $usuarios, $privacidad))($yo, ['username' => 'cerrada']);
    }

    // ========================================================================
    // La sexta columna en el contrato de `privacy_get` / `privacy_set`
    // ========================================================================

    /**
     * Viaja como clave HERMANA de `privacy`, no como sexta entrada dentro. Un
     * cliente viejo que solo lea `privacy` sigue funcionando igual, y esa es
     * otra consecuencia de sacarla fuera.
     */
    public function testPrivacyGetDevuelveLasCincoSeccionesYLaBusquedaAparte(): void
    {
        $privacidad = new PrivacidadFalsa();

        $resultado = (new ObtenerPrivacidad($privacidad))(7);

        self::assertCount(5, $resultado['privacy']);
        self::assertArrayNotHasKey(Descubrimiento::CLAVE, $resultado['privacy']);
        self::assertSame('everyone', $resultado[Descubrimiento::CLAVE]);
    }

    /** La clave es `search` y nunca `show_in_search`: el prefijo es cosa del esquema. */
    public function testLaClavePublicaNoEsElNombreDeLaColumna(): void
    {
        $resultado = (new ObtenerPrivacidad(new PrivacidadFalsa()))(7);

        self::assertArrayHasKey('search', $resultado);
        self::assertArrayNotHasKey('show_in_search', $resultado);
    }

    /**
     * **Mover el sexto selector no toca las cinco secciones**, que es la misma
     * edición parcial de siempre un escalón más arriba.
     */
    public function testGuardarSoloLaBusquedaNoMueveNingunaSeccion(): void
    {
        $privacidad = new PrivacidadFalsa();
        $guardar    = new GuardarPrivacidad($privacidad);

        $antes = (new ObtenerPrivacidad($privacidad))(7)['privacy'];

        $resultado = $guardar(7, ['search' => 'nobody']);

        self::assertSame('nobody', $resultado[Descubrimiento::CLAVE]);
        self::assertSame($antes, $resultado['privacy']);
        self::assertSame(0, $privacidad->escrituras, 'Tocar la sexta columna no escribe las otras cinco');
        self::assertSame(1, $privacidad->escriturasDeBusqueda);
    }

    /** Y al revés: mover una sección no toca el sexto selector. */
    public function testGuardarSoloUnaSeccionNoMueveLaBusqueda(): void
    {
        $privacidad = new PrivacidadFalsa();
        $privacidad->ponBusqueda(7, Descubrimiento::Nadie);

        $resultado = (new GuardarPrivacidad($privacidad))(7, ['value' => 'nobody']);

        self::assertSame('nobody', $resultado['privacy']['value']);
        self::assertSame('nobody', $resultado[Descubrimiento::CLAVE]);
        self::assertSame(0, $privacidad->escriturasDeBusqueda, 'Tocar una sección no escribe la sexta columna');
    }

    /** Las dos a la vez también valen: el panel puede mandar lo que quiera. */
    public function testSePuedenGuardarLasDosCosasEnLaMismaPeticion(): void
    {
        $privacidad = new PrivacidadFalsa();

        $resultado = (new GuardarPrivacidad($privacidad))(7, ['collection' => 'nobody', 'search' => 'nobody']);

        self::assertSame('nobody', $resultado['privacy']['collection']);
        self::assertSame('nobody', $resultado[Descubrimiento::CLAVE]);
    }

    /** Una petición que no cambia nada sigue siendo un 422, y ahora lo dice mejor. */
    public function testUnaPeticionVaciaSigueSiendoUnErrorYNombraLaSextaOpcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Descubrimiento::CLAVE);

        (new GuardarPrivacidad(new PrivacidadFalsa()))(7, ['csrf_token' => 'x']);
    }

    /** Un valor inventado es un 422 y no una caída silenciosa a ningún lado. */
    public function testUnValorQueNoExisteEsUnErrorYNoUnDefecto(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GuardarPrivacidad(new PrivacidadFalsa()))(7, ['search' => 'friends']);
    }

    // ========================================================================
    // 2. El `JOIN` del buscador, leído del SQL de verdad
    // ========================================================================

    /** El cuerpo de `MySqlUserRepository::buscarPorPrefijo()`, tal cual está escrito. */
    private function fuenteDelBuscador(): string
    {
        $metodo = new ReflectionMethod(MySqlUserRepository::class, 'buscarPorPrefijo');
        $lineas = file((string) $metodo->getFileName());

        return implode('', array_slice(
            (array) $lineas,
            $metodo->getStartLine() - 1,
            $metodo->getEndLine() - $metodo->getStartLine() + 1
        ));
    }

    /**
     * **El test que impide el cero silencioso.** Con `JOIN` a secas, esta
     * consulta devolvería lista vacía para todo el mundo mientras
     * `user_privacy_settings` siga sin filas, que es hoy y que es el caso normal.
     */
    public function testElJoinDelBuscadorEsLeftYNoInner(): void
    {
        $fuente = $this->fuenteDelBuscador();

        self::assertMatchesRegularExpression(
            '/LEFT\s+JOIN\s+user_privacy_settings/i',
            $fuente,
            'El JOIN con user_privacy_settings tiene que ser LEFT: sin fila = los defectos, y hoy no hay ni una fila'
        );

        // Y que no haya quedado un JOIN interno suelto contra esa tabla.
        self::assertDoesNotMatchRegularExpression(
            '/(?<!LEFT\s)(?<!OUTER\s)\bJOIN\s+user_privacy_settings/i',
            preg_replace('/LEFT\s+JOIN/i', 'LEFT_JOIN', $fuente) ?? '',
            'Hay un JOIN interno contra user_privacy_settings: devolvería cero resultados siempre'
        );
    }

    /** La otra mitad: sin `COALESCE`, la fila ausente da `NULL` y el `=` falla. */
    public function testElFiltroDePrivacidadUsaCoalesce(): void
    {
        // El nombre de la columna se concatena desde la constante del enum —para
        // que no haya dos sitios donde escribirlo mal— así que lo que se busca
        // en la fuente es esa concatenación y no el literal `show_in_search`.
        self::assertMatchesRegularExpression(
            '/COALESCE\(p\.\x27 \. Descubrimiento::COLUMNA \. \x27, :defecto\) = :visible/',
            $this->fuenteDelBuscador(),
            'Sin COALESCE, la fila ausente da NULL y la comparación falla para todo el mundo'
        );
    }

    /**
     * **Prefijo y no subcadena.** `LIKE '%juan%'` es un full scan y hace el
     * directorio enumerable desde cualquier letra interior; el `%` va donde lo
     * pone el repositorio y en ningún otro sitio.
     */
    public function testElLikeNoLlevaComodinPorDelante(): void
    {
        $fuente = $this->fuenteDelBuscador();

        self::assertStringContainsString("\$prefijo . '%'", $fuente);
        self::assertStringNotContainsString("'%' . \$prefijo", $fuente);
    }

    /** Lista blanca: el `email` no se selecciona siquiera, y el `id` tampoco. */
    public function testElSelectDelBuscadorNoTraeElEmailNiElId(): void
    {
        $fuente = $this->fuenteDelBuscador();

        self::assertStringNotContainsString('u.email', $fuente);
        self::assertStringNotContainsString('u.id AS', $fuente);
    }
}
