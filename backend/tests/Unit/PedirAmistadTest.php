<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\PedirAmistad;
use App\Domain\Social\EstadoAmistad;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\AmistadesFalsas;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * Pedir amistad, que es la única de las cinco acciones del M2 que crea una fila.
 *
 * Lo que se prueba aquí, por orden de daño si se rompe:
 *
 *  1. **Que nace `pending` y no `accepted`.** Si naciera aceptada, pedir amistad
 *     sería concedérsela a uno mismo sobre la privacidad del otro, y el nivel
 *     `friends` se abriría entero sin que nadie dijera que sí a nada.
 *  2. **Que pedírtela a ti mismo revienta.** El esquema NO lo impide: el
 *     `UNIQUE` simétrico sobre la pareja (A,A) da `user_low = user_high` y la
 *     fila entra tan campante la primera vez. Es un `if`, y si se cae nadie se
 *     entera hasta que alguien se ve a sí mismo en su lista de amigos.
 *  3. **Que el duplicado lo detecta la BASE DE DATOS y no un `SELECT` previo.**
 *     Comprobar antes de insertar es la carrera que el `UNIQUE` simétrico existe
 *     para hacer imposible — dos personas que se piden amistad a la vez pasan
 *     las dos comprobaciones y acaban con dos solicitudes cruzadas.
 *  4. **Que va por `username` y que ese nombre no distingue mayúsculas**, porque
 *     la colación de la columna es `utf8mb4_unicode_ci`.
 */
final class PedirAmistadTest extends TestCase
{
    private UsuariosFalsos $usuarios;

    private AmistadesFalsas $amistades;

    private PedirAmistad $pedir;

    private int $yo;

    private int $otro;

    protected function setUp(): void
    {
        $this->usuarios  = new UsuariosFalsos();
        $this->amistades = new AmistadesFalsas();
        $this->pedir     = new PedirAmistad($this->amistades, $this->usuarios);

        $this->yo   = $this->usuarios->alta('henyckma', 'David');
        $this->otro = $this->usuarios->alta('vecina', 'La Vecina', 'https://example.test/v.png');
    }

    public function testLaSolicitudNaceEnPendingYNoEnAccepted(): void
    {
        $resultado = ($this->pedir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertSame('pending', $resultado['status']);

        $amistad = $this->amistades->buscar($resultado['friendshipId']);

        self::assertNotNull($amistad);
        self::assertSame(EstadoAmistad::Pendiente, $amistad->estado);
        self::assertSame($this->yo, $amistad->requesterId, 'Quién pidió importa: es quien NO puede aceptar.');
        self::assertSame($this->otro, $amistad->addresseeId);
    }

    /** Y lo más importante: pedir no es ser amigo. */
    public function testPedirNoHaceAmigoANadie(): void
    {
        ($this->pedir)($this->yo, ['username' => 'vecina']);

        self::assertFalse($this->amistades->sonAmigos($this->yo, $this->otro));
        self::assertFalse($this->amistades->sonAmigos($this->otro, $this->yo));
    }

    /**
     * El `UNIQUE (user_low, user_high)` no lo impide —(A,A) da `user_low =
     * user_high` y la fila entra—, así que este `if` es lo único que hay.
     */
    public function testPedirseAmistadAUnoMismoEsUn422YNoEscribeNada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            ($this->pedir)($this->yo, ['username' => 'henyckma']);
        } finally {
            self::assertSame([], $this->amistades->filas, 'No puede quedar ninguna fila (A,A).');
        }
    }

    /** Ni siquiera escribiéndolo en mayúsculas: la colación no distingue. */
    public function testNiConOtrasMayusculas(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->pedir)($this->yo, ['username' => 'HENYCKMA']);
    }

    public function testUnNombreQueNoExisteDevuelveNull(): void
    {
        self::assertNull(($this->pedir)($this->yo, ['username' => 'nadie-de-estos']));
        self::assertSame([], $this->amistades->filas);
    }

    /** El `username` es la clave pública, y se compara sin distinguir mayúsculas. */
    public function testElNombreNoDistingueMayusculasNiEspaciosAlrededor(): void
    {
        $resultado = ($this->pedir)($this->yo, ['username' => '  VeCiNa  ']);

        self::assertNotNull($resultado);
        self::assertSame('vecina', $resultado['user']['username']);
    }

    /**
     * Ya hay fila entre los dos —la pidió el OTRO, y da igual: el `UNIQUE` es
     * simétrico—. El error lo da la base de datos y sube tal cual.
     */
    public function testLaSegundaSolicitudEntreLosMismosDosRevientaConUn1062(): void
    {
        $this->amistades->pide($this->otro, $this->yo);

        try {
            ($this->pedir)($this->yo, ['username' => 'vecina']);
            self::fail('El UNIQUE simétrico tenía que rechazar la fila cruzada.');
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode());
            self::assertSame(1062, $e->errorInfo[1] ?? null, 'Es un duplicado, no una foránea rota.');
        }
    }

    public function testSinUsernameEsUn422(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->pedir)($this->yo, []);
    }

    /** El `email` está dentro del `User` que devuelve el repositorio y NO sale. */
    public function testLaRespuestaNoLlevaElEmailNiElIdDeNadie(): void
    {
        $resultado = ($this->pedir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertSame(['username', 'displayName', 'avatarUrl'], array_keys($resultado['user']));
        self::assertStringNotContainsString('@', json_encode($resultado, JSON_THROW_ON_ERROR));
    }
}
