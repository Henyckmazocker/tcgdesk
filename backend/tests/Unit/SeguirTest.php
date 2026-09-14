<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\Seguir;
use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use App\Domain\Social\Visibilidad;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\Unit\Doubles\AmistadesFalsas;
use Tests\Unit\Doubles\PrivacidadFalsa;
use Tests\Unit\Doubles\SeguimientosFalsos;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * Seguir, que es la única acción del M3 que crea una fila.
 *
 * Lo que se prueba aquí, por orden de daño si se rompe:
 *
 *  1. **Que seguir no hace amigo a nadie ni abre nada.** Lo fija a lo grande
 *     `SeguirNoCambiaLaRespuestaPublicaTest`; aquí se fija lo que este use case
 *     puede garantizar por sí solo y que ningún test de comportamiento vería:
 *     que **no tiene por dónde llegar** al puerto de amistades ni a
 *     `Visibilidad`. Un fallo así saldría verde en cualquier otra prueba.
 *  2. **Que un perfil sin ninguna sección en `everyone` da 422.** Es la
 *     condición literal del *Hecho cuando:* del hito.
 *  3. **Y que ese 422 se decide sobre el NIVEL configurado y no sobre
 *     `puedeVer()`**, que es el matiz que cuesta una tarde: para un amigo del
 *     dueño, `puedeVer()` dice que SÍ a un perfil entero en `friends`, y aun así
 *     no se puede seguir. El test monta las dos cosas a la vez y las compara.
 *  4. **Que seguirte a ti mismo revienta.** La `PRIMARY KEY` no lo impide.
 *  5. **Que seguir dos veces NO es un error**, al revés que pedir amistad dos
 *     veces: no hay conflicto que resolver.
 */
final class SeguirTest extends TestCase
{
    private UsuariosFalsos $usuarios;

    private SeguimientosFalsos $seguimientos;

    private PrivacidadFalsa $privacidad;

    private Seguir $seguir;

    private int $yo;

    private int $otro;

    protected function setUp(): void
    {
        $this->usuarios     = new UsuariosFalsos();
        $this->seguimientos = new SeguimientosFalsos();
        $this->privacidad   = new PrivacidadFalsa();

        $this->seguir = new Seguir($this->seguimientos, $this->usuarios, $this->privacidad);

        $this->yo   = $this->usuarios->alta('henyckma', 'David');
        $this->otro = $this->usuarios->alta('vecina', 'La Vecina', 'https://example.test/v.png');
    }

    // =====================================================================
    // 1. Seguir no toca la amistad, y no puede tocarla
    // =====================================================================

    /**
     * **El fallo que saldría verde.** Este use case no recibe el puerto de
     * amistades ni `Visibilidad`, así que no hay ninguna forma de que seguir
     * consulte —o modifique— la relación que sí da acceso. Si alguien se los
     * añade al constructor «para comprobar una cosa», esto se pone rojo antes
     * de que la comprobación llegue a hacer daño.
     */
    public function testSeguirNoTienePorDondeLlegarALaAmistadNiAVisibilidad(): void
    {
        $constructor = (new ReflectionClass(Seguir::class))->getConstructor();

        self::assertNotNull($constructor);

        $tipos = array_map(
            static function ($parametro): string {
                $tipo = $parametro->getType();

                return $tipo instanceof ReflectionNamedType ? $tipo->getName() : '';
            },
            $constructor->getParameters()
        );

        self::assertNotContains(
            FriendshipRepositoryInterface::class,
            $tipos,
            'Seguir con el puerto de amistades a mano es cómo `friends` acaba significando «cualquiera que pulse seguir».'
        );
        self::assertNotContains(
            Visibilidad::class,
            $tipos,
            'El 422 se decide sobre el nivel configurado, no sobre quién ve qué: ver la cabecera de Seguir.'
        );
    }

    // =====================================================================
    // 2 y 3. El 422 del perfil cerrado, que es el *Hecho cuando:* del hito
    // =====================================================================

    public function testUnPerfilSinNingunaSeccionEnEveryoneNoSePuedeSeguir(): void
    {
        $this->privacidad->todasEn($this->otro, Nivel::Nadie);

        try {
            ($this->seguir)($this->yo, ['username' => 'vecina']);
            self::fail('Seguir un perfil cerrado es un marcador a una página vacía: tenía que ser un 422.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('no enseña nada públicamente', $e->getMessage());
        }

        self::assertSame([], $this->seguimientos->filas, 'No puede quedar ningún marcador.');
    }

    /** Con `friends` tampoco: sigue sin haber una página pública que visitar. */
    public function testUnPerfilEnteroEnFriendsTampocoSePuedeSeguir(): void
    {
        $this->privacidad->todasEn($this->otro, Nivel::Amigos);

        $this->expectException(InvalidArgumentException::class);

        try {
            ($this->seguir)($this->yo, ['username' => 'vecina']);
        } finally {
            self::assertSame([], $this->seguimientos->filas);
        }
    }

    /**
     * **El matiz del hito, montado contra la alternativa que habría estado
     * mal.** El dueño lo tiene todo en `friends` y los dos SON amigos aceptados,
     * así que `Visibilidad::puedeVer()` responde que sí a las cinco secciones —y
     * el test lo comprueba, para que no quepa duda de que la diferencia es
     * real—. Aun así, `Seguir` dice que no: la pregunta que responde es «¿tiene
     * este perfil cara pública?», que no depende de quién la haga. Si alguien
     * cambia la comprobación a `puedeVer($duenyo, $seguidor, ...)`, este test
     * se pone rojo.
     */
    public function testSerAmigoDelDuenyoNoDejaSeguirSuPerfilCerrado(): void
    {
        $this->privacidad->todasEn($this->otro, Nivel::Amigos);

        $amistades = (new AmistadesFalsas())->acepta($this->yo, $this->otro);
        $visibilidad = new Visibilidad($this->privacidad, $amistades);

        foreach (Seccion::cases() as $seccion) {
            self::assertTrue(
                $visibilidad->puedeVer($this->otro, $this->yo, $seccion),
                'Por ser su amigo, este espectador SÍ ve ' . $seccion->value . ': por eso no se puede preguntar así.'
            );
        }

        $this->expectException(InvalidArgumentException::class);

        ($this->seguir)($this->yo, ['username' => 'vecina']);
    }

    /** Basta UNA sección abierta: hay una página que visitar y el marcador sirve. */
    public function testConUnaSolaSeccionEnEveryoneYaSePuedeSeguir(): void
    {
        $this->privacidad->todasEn($this->otro, Nivel::Nadie);
        $this->privacidad->pon($this->otro, Seccion::Mazos, Nivel::Todos);

        $resultado = ($this->seguir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertTrue($resultado['following']);
        self::assertTrue($this->seguimientos->hayMarcador($this->yo, $this->otro));
    }

    /**
     * Y quien no tiene fila en `user_privacy_settings` —que hoy es todo el
     * mundo— se puede seguir: sus defectos traen `collection`, `decks` y `sets`
     * en `everyone`.
     */
    public function testUnUsuarioSinFilaDePrivacidadSeSiguePorSusDefectos(): void
    {
        self::assertSame([], $this->privacidad->niveles, 'El punto del test es que NO haya fila.');

        $resultado = ($this->seguir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertTrue($this->seguimientos->hayMarcador($this->yo, $this->otro));
    }

    // =====================================================================
    // 4 y 5. Los otros noes, y el sí repetido
    // =====================================================================

    /**
     * La `PRIMARY KEY (follower_id, followed_id)` acepta (A,A) sin rechistar, así
     * que este `if` es lo único que hay. Sin él, cualquiera se sube su propio
     * contador de seguidores.
     */
    public function testSeguirseAUnoMismoEsUn422YNoEscribeNada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            ($this->seguir)($this->yo, ['username' => 'henyckma']);
        } finally {
            self::assertSame([], $this->seguimientos->filas);
        }
    }

    /** Ni escribiéndolo en mayúsculas: la colación no distingue. */
    public function testNiConOtrasMayusculas(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->seguir)($this->yo, ['username' => 'HENYCKMA']);
    }

    /**
     * Al revés que `friend_request`, donde la segunda solicitud es un 409: aquí
     * el estado pedido ya es el estado actual y no hay conflicto que resolver.
     */
    public function testSeguirDosVecesNoRevientaYDejaUnSoloMarcador(): void
    {
        ($this->seguir)($this->yo, ['username' => 'vecina']);
        $segunda = ($this->seguir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($segunda);
        self::assertTrue($segunda['following']);
        self::assertCount(1, $this->seguimientos->filas);
    }

    /**
     * A→B y B→A son dos hechos legítimos a la vez: la clave es asimétrica **a
     * propósito**, al revés que el `UNIQUE` simétrico de `friendships`.
     */
    public function testSeguirseMutuamenteSonDosMarcadoresYNoUno(): void
    {
        ($this->seguir)($this->yo, ['username' => 'vecina']);
        ($this->seguir)($this->otro, ['username' => 'henyckma']);

        self::assertCount(2, $this->seguimientos->filas);
        self::assertTrue($this->seguimientos->hayMarcador($this->yo, $this->otro));
        self::assertTrue($this->seguimientos->hayMarcador($this->otro, $this->yo));
    }

    public function testUnNombreQueNoExisteDevuelveNull(): void
    {
        self::assertNull(($this->seguir)($this->yo, ['username' => 'nadie-de-estos']));
        self::assertSame([], $this->seguimientos->filas);
    }

    public function testSinUsernameEsUn422(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->seguir)($this->yo, []);
    }

    public function testElNombreNoDistingueMayusculasNiEspaciosAlrededor(): void
    {
        $resultado = ($this->seguir)($this->yo, ['username' => '  VeCiNa  ']);

        self::assertNotNull($resultado);
        self::assertSame('vecina', $resultado['user']['username']);
        self::assertTrue($this->seguimientos->hayMarcador($this->yo, $this->otro));
    }

    /** El `email` está dentro del `User` que devuelve el repositorio y NO sale. */
    public function testLaRespuestaNoLlevaElEmailNiElIdDeNadie(): void
    {
        $resultado = ($this->seguir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertSame(['username', 'displayName', 'avatarUrl'], array_keys($resultado['user']));
        self::assertStringNotContainsString('@', json_encode($resultado, JSON_THROW_ON_ERROR));
    }
}
