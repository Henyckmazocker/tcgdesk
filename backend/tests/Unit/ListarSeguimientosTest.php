<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ListarSeguimientos;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\SeguimientosFalsos;

/**
 * `follow_list`: a quién sigues y cuánta gente te sigue.
 *
 * Lo que se protege aquí es la **asimetría de la respuesta**, que es la decisión
 * del plan y lo primero que alguien «uniformizaría»:
 *
 *  - `following` son personas, porque son tus marcadores y los pusiste tú.
 *  - `followerCount` es un **número**, y nunca una lista. Seguir es unilateral y
 *    no se pide permiso: publicar los nombres de quienes te siguen convertiría
 *    un acto privado del que sigue en algo que el seguido audita. El plan lo
 *    cierra: el dueño ve el número, no puede impedir que suba, y la herramienta
 *    para no ser seguido es bajar las secciones a `friends` o `nobody`.
 *
 * Y la otra mitad: que las dos direcciones no se mezclan. Seguir a alguien no te
 * suma un seguidor, y que alguien te siga no te lo pone en tus seguidos.
 */
final class ListarSeguimientosTest extends TestCase
{
    private const YO      = 1;
    private const VECINA  = 2;
    private const TERCERO = 3;

    private SeguimientosFalsos $seguimientos;

    private ListarSeguimientos $listar;

    protected function setUp(): void
    {
        $this->seguimientos = new SeguimientosFalsos();
        $this->listar       = new ListarSeguimientos($this->seguimientos);

        $this->seguimientos
            ->persona(self::YO, 'henyckma', 'David')
            ->persona(self::VECINA, 'vecina', 'La Vecina', 'https://example.test/v.png')
            ->persona(self::TERCERO, 'tercero', 'El Tercero');
    }

    public function testDevuelveLosSeguidosConNombreYFechaYElContadorDeSeguidores(): void
    {
        $this->seguimientos
            ->siguiendo(self::YO, self::VECINA)
            ->siguiendo(self::YO, self::TERCERO)
            ->siguiendo(self::VECINA, self::YO)
            ->siguiendo(self::TERCERO, self::YO);

        $respuesta = ($this->listar)(self::YO);

        self::assertSame(['following', 'followerCount'], array_keys($respuesta));
        self::assertCount(2, $respuesta['following']);
        self::assertSame(2, $respuesta['followerCount']);

        // El más reciente primero, como el `ORDER BY created_at DESC`.
        self::assertSame('tercero', $respuesta['following'][0]['username']);
        self::assertSame('vecina', $respuesta['following'][1]['username']);
        self::assertSame('La Vecina', $respuesta['following'][1]['displayName']);
        self::assertSame('https://example.test/v.png', $respuesta['following'][1]['avatarUrl']);
        self::assertNotNull($respuesta['following'][1]['since']);
    }

    /** Las dos direcciones son dos hechos distintos y no se suman entre sí. */
    public function testSeguirNoTeSumaUnSeguidorYSerSeguidoNoTeSumaUnSeguido(): void
    {
        $this->seguimientos->siguiendo(self::YO, self::VECINA);

        $mio = ($this->listar)(self::YO);

        self::assertCount(1, $mio['following']);
        self::assertSame(0, $mio['followerCount'], 'Seguir a alguien no te hace seguidores.');

        $suyo = ($this->listar)(self::VECINA);

        self::assertSame([], $suyo['following'], 'Que la sigas no le pone a ti en SUS seguidos.');
        self::assertSame(1, $suyo['followerCount']);
    }

    public function testSinNingunMarcadorLaListaEstaVaciaYElContadorEsCero(): void
    {
        $respuesta = ($this->listar)(self::YO);

        self::assertSame([], $respuesta['following']);
        self::assertSame(0, $respuesta['followerCount']);
    }

    /**
     * Ni el `email` ni el `id` numérico: `username` es la clave pública con la
     * que se llega al perfil, y el entero de `users.id` solo serviría para
     * recorrer. La consulta real ni siquiera los selecciona.
     */
    public function testDeCadaPersonaSalenCuatroCamposYNingunoEsElCorreoNiElId(): void
    {
        $this->seguimientos->siguiendo(self::YO, self::VECINA);

        $respuesta = ($this->listar)(self::YO);

        self::assertSame(
            ['username', 'displayName', 'avatarUrl', 'since'],
            array_keys($respuesta['following'][0])
        );
        self::assertStringNotContainsString('@', json_encode($respuesta, JSON_THROW_ON_ERROR));
    }

    /**
     * El contador cuenta filas, no nombres: la respuesta no da ninguna forma de
     * saber QUIÉN te sigue. Si algún día alguien devuelve aquí una lista, este
     * test se pone rojo.
     */
    public function testElContadorDeSeguidoresNoTraeNiUnNombre(): void
    {
        $this->seguimientos->siguiendo(self::VECINA, self::YO);

        $respuesta = ($this->listar)(self::YO);

        self::assertIsInt($respuesta['followerCount']);
        self::assertSame(1, $respuesta['followerCount']);
        self::assertStringNotContainsString(
            'vecina',
            json_encode($respuesta, JSON_THROW_ON_ERROR),
            'Quién te sigue no se publica: seguir es un acto que el seguido no autoriza.'
        );
    }
}
