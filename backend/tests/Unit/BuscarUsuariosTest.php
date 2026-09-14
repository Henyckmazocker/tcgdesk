<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\BuscarUsuarios;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * El buscador de usuarios del M6, con los cuatro casos del *Hecho cuando:*.
 *
 * Tres de ellos se prueban aquí y el cuarto —«desde el resultado se puede pedir
 * amistad sin salir de `/friends`»— es de la vista y vive en
 * `frontend/tests/unit/views/FriendsView.spec.js`.
 *
 * **El que más importa es `testUnUsuarioSinFilaDeprivacidadSaleIgualmente`**, y
 * es también el más fácil de no probar: hoy no hay **ni una fila** en
 * `user_privacy_settings`, así que ese no es el caso raro sino el de absolutamente
 * todo el mundo. Es el que un `INNER JOIN` rompería en silencio —cero resultados
 * siempre, sin ningún error— y por eso `UsuariosFalsos` modela la ausencia de
 * valor como `everyone` y no como «no configurado».
 *
 * Y el segundo en importancia es `testLosComodinesDeLikeNoSonComodines`: sin el
 * escapado de `%` y `_`, el mínimo de tres caracteres no significa nada porque
 * `q = '%%%'` mide tres y devolvería el censo entero.
 */
final class BuscarUsuariosTest extends TestCase
{
    private UsuariosFalsos $usuarios;

    private BuscarUsuarios $buscar;

    /** Quien busca. Nunca sale en su propio resultado. */
    private int $yo;

    protected function setUp(): void
    {
        $this->usuarios = new UsuariosFalsos();
        $this->buscar   = new BuscarUsuarios($this->usuarios);

        $this->yo = $this->usuarios->alta('juanito', 'Yo El Que Busca');
    }

    /** @return list<string> los `username` del resultado, en orden */
    private function nombres(array $resultado): array
    {
        return array_column($resultado['users'], 'username');
    }

    // ========================================================================
    // El mínimo de tres caracteres: el primer *Hecho cuando:* del hito
    // ========================================================================

    public function testBuscarDosLetrasEsUnErrorYNoUnaListaVacia(): void
    {
        $this->usuarios->alta('juana');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('al menos 3 caracteres');

        ($this->buscar)($this->yo, ['q' => 'ju']);
    }

    /**
     * Dos letras con espacios alrededor siguen siendo dos letras. El `trim()` va
     * **antes** de contar, porque si no `'  ju  '` mediría seis y pasaría.
     */
    public function testElMinimoSeMideDespuesDeRecortarLosEspacios(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->buscar)($this->yo, ['q' => '   ju   ']);
    }

    public function testTresLetrasYaBusca(): void
    {
        $this->usuarios->alta('juana', 'Juana');

        self::assertSame(['juana'], $this->nombres(($this->buscar)($this->yo, ['q' => 'jua'])));
    }

    /**
     * Se cuentan CARACTERES y no bytes: `ñu` son tres bytes en UTF-8 y dos
     * letras, y contar bytes lo dejaría pasar.
     */
    public function testElMinimoCuentaCaracteresYNoBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->buscar)($this->yo, ['q' => 'ñu']);
    }

    public function testSinQEsUnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->buscar)($this->yo, []);
    }

    /**
     * `users.username` es `VARCHAR(32)`, así que un prefijo más largo no puede
     * casar nada. Se dice, en vez de devolver cero resultados: «no hay nadie con
     * ese nombre» y «ese nombre no cabe en la columna» no son lo mismo.
     */
    public function testUnPrefijoMasLargoQueLaColumnaEsUnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('32 caracteres');

        ($this->buscar)($this->yo, ['q' => str_repeat('a', 33)]);
    }

    // ========================================================================
    // Prefijo, no subcadena
    // ========================================================================

    public function testBuscaPorElPrincipioYNoPorDentro(): void
    {
        $this->usuarios->alta('juana');
        $this->usuarios->alta('mariajuana');

        // `LIKE 'jua%'` y no `LIKE '%jua%'`: el segundo es un full scan y además
        // haría el directorio enumerable desde cualquier letra interior.
        self::assertSame(['juana'], $this->nombres(($this->buscar)($this->yo, ['q' => 'jua'])));
    }

    /** La colación es `utf8mb4_unicode_ci`: buscar `JUA` encuentra a `juana`. */
    public function testNoDistingueMayusculas(): void
    {
        $this->usuarios->alta('juana');

        self::assertSame(['juana'], $this->nombres(($this->buscar)($this->yo, ['q' => 'JUA'])));
    }

    // ========================================================================
    // Los comodines de LIKE, que es lo que hace que el mínimo signifique algo
    // ========================================================================

    /**
     * `%%%` mide tres caracteres y pasa el mínimo. Si no se escapara, el patrón
     * `LIKE '%%%%'` devolvería **la tabla `users` entera**, que es exactamente el
     * censo que este hito existe para no repartir.
     */
    public function testLosComodinesDeLikeNoSonComodines(): void
    {
        $this->usuarios->alta('juana');
        $this->usuarios->alta('vecina');
        $this->usuarios->alta('otro');

        self::assertSame([], $this->nombres(($this->buscar)($this->yo, ['q' => '%%%'])));
    }

    /** Y lo mismo con el comodín de un carácter: `___` no es «tres cualesquiera». */
    public function testElGuionBajoTampocoEsComodin(): void
    {
        $this->usuarios->alta('ana');
        $this->usuarios->alta('eva');

        self::assertSame([], $this->nombres(($this->buscar)($this->yo, ['q' => '___'])));
    }

    /**
     * Un nombre que de verdad lleve un guion bajo se sigue encontrando: escapar
     * no es «prohibir el carácter», es «tratarlo como lo que es».
     */
    public function testUnNombreConGuionBajoSeEncuentraLiteralmente(): void
    {
        $this->usuarios->alta('la_vecina');
        $this->usuarios->alta('lavecina');

        self::assertSame(['la_vecina'], $this->nombres(($this->buscar)($this->yo, ['q' => 'la_'])));
    }

    /**
     * La barra invertida es el carácter de escape de `LIKE`, así que tiene que
     * escaparse **la primera** o duplicaría las que ponen los otros dos
     * reemplazos. Si el orden estuviera al revés, esto reventaría el patrón.
     */
    public function testLaBarraInvertidaSeEscapaAntesQueLoDemas(): void
    {
        $this->usuarios->alta('a\\bc');

        self::assertSame(['a\\bc'], $this->nombres(($this->buscar)($this->yo, ['q' => 'a\\b'])));
    }

    // ========================================================================
    // La privacidad: el segundo y el tercer *Hecho cuando:* del hito
    // ========================================================================

    /**
     * **El caso de todo el mundo hoy.** `user_privacy_settings` está vacía: la
     * fila la escribe `privacy_set` la primera vez que alguien toca el panel. Si
     * esto devolviera lista vacía, el buscador no encontraría a nadie nunca —que
     * es lo que haría un `INNER JOIN`, y sin un solo error—.
     */
    public function testUnUsuarioSinFilaDePrivacidadSaleIgualmente(): void
    {
        $this->usuarios->alta('juana', 'Juana Sin Fila');

        self::assertSame(['juana'], $this->nombres(($this->buscar)($this->yo, ['q' => 'jua'])));
    }

    /** El segundo *Hecho cuando:*: con `nobody` no sale ni tecleando el nombre entero. */
    public function testQuienEstaEnNobodyNoSaleNiConElNombreEntero(): void
    {
        $oculta = $this->usuarios->alta('juana', 'Juana La Oculta');
        $this->usuarios->escondeDelBuscador($oculta);

        self::assertSame([], $this->nombres(($this->buscar)($this->yo, ['q' => 'juana'])));
    }

    /** Y quien tiene fila con `everyone` sale igual que quien no tiene fila. */
    public function testConFilaEnEveryoneSaleIgualQueSinFila(): void
    {
        $this->usuarios->alta('juana');
        $conFila = $this->usuarios->alta('juanjo');
        $this->usuarios->busqueda[$conFila] = \App\Domain\Social\Descubrimiento::Todos;

        self::assertSame(['juana', 'juanjo'], $this->nombres(($this->buscar)($this->yo, ['q' => 'jua'])));
    }

    /**
     * Los tres estados conviven en el mismo resultado, que es el escenario real:
     * sin fila sale, con `everyone` sale, con `nobody` no.
     */
    public function testLosTresEstadosConvivenEnUnaSolaBusqueda(): void
    {
        $this->usuarios->alta('juana');                              // sin fila
        $conFila = $this->usuarios->alta('juanjo');                  // fila en everyone
        $this->usuarios->busqueda[$conFila] = \App\Domain\Social\Descubrimiento::Todos;
        $oculta = $this->usuarios->alta('juanita');                  // fila en nobody
        $this->usuarios->escondeDelBuscador($oculta);

        self::assertSame(['juana', 'juanjo'], $this->nombres(($this->buscar)($this->yo, ['q' => 'jua'])));
    }

    // ========================================================================
    // Lo que no sale
    // ========================================================================

    public function testNoTeEncuentrasATiMismo(): void
    {
        // `juanito` empieza por `jua` igual que los demás, y aun así no sale:
        // ofrecerse «pedirse amistad a uno mismo» es un botón que el backend
        // contesta con un 422.
        $this->usuarios->alta('juana');

        self::assertSame(['juana'], $this->nombres(($this->buscar)($this->yo, ['q' => 'jua'])));
    }

    /**
     * **Ni el `email` ni el `id`.** El primero lo tiene `UsuariosFalsos` puesto
     * siempre a propósito, y el segundo no viaja porque a un perfil se llega por
     * `username`.
     */
    public function testNiElEmailNiElIdViajanEnElResultado(): void
    {
        $this->usuarios->alta('juana', 'Juana', 'https://example.invalid/a.png');

        $fila = ($this->buscar)($this->yo, ['q' => 'jua'])['users'][0];

        self::assertSame(['username', 'displayName', 'avatarUrl'], array_keys($fila));
        self::assertArrayNotHasKey('email', $fila);
        self::assertArrayNotHasKey('id', $fila);
    }

    /** Sin nadie que case, 200 con lista vacía. No es un 404: ver el controller. */
    public function testSinCoincidenciasDevuelveListaVaciaYNoUnError(): void
    {
        $this->usuarios->alta('vecina');

        self::assertSame([], ($this->buscar)($this->yo, ['q' => 'jua'])['users']);
    }

    /**
     * El tope lo fija el servidor y **no se acepta del cliente**: un `limit` por
     * el cuerpo sería el otro extremo del agujero que el mínimo de tres
     * caracteres cierra.
     */
    public function testElTopeDeResultadosLoPoneElServidorYNoElCliente(): void
    {
        for ($i = 0; $i < BuscarUsuarios::LIMITE + 5; $i++) {
            $this->usuarios->alta(sprintf('juana%02d', $i));
        }

        $resultado = ($this->buscar)($this->yo, ['q' => 'jua', 'limit' => 500, 'limite' => 500]);

        self::assertCount(BuscarUsuarios::LIMITE, $resultado['users']);
    }
}
