<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\DejarDeSeguir;
use App\Application\UseCase\ListarSeguimientos;
use App\Application\UseCase\Seguir;
use App\Controllers\FollowController;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\PrivacidadFalsa;
use Tests\Unit\Doubles\SeguimientosFalsos;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * El contrato HTTP de las tres acciones de seguir: el request que arma
 * `ActionRouter` —el payload bajo `data` y el `user_id` que puso
 * `AuthMiddleware`— y la respuesta con su `http_code`.
 *
 * **El `http_code` no es un detalle**: `Application::run()` solo lee esa clave y
 * no `code`, y escribirlo al revés convierte todos los 422 de aquí en 400. Los
 * dos 422 de este controller son los dos noes del hito —seguirte a ti mismo, y
 * seguir un perfil sin ninguna sección en `everyone`— y un 400 genérico no
 * dejaría al frontend del M5 decir cuál de los dos ha pasado.
 *
 * Los tres que más importan:
 *
 *  1. **`testSeguirUnPerfilSinNadaEnEveryoneDevuelve422`** — la condición
 *     literal del *Hecho cuando:* del M3, vista desde donde la vería el cliente.
 *  2. **`testSeguirDosVecesDevuelve200YNo409`** — la diferencia deliberada con
 *     `friend_request`: aquí no hay conflicto que resolver.
 *  3. **`testElUserIdSaleDelRequestYNuncaDelPayload`** — un `follower_id` que
 *     viniera del cuerpo dejaría a cualquiera inflar el contador de seguidores
 *     de otro y llenarle la lista de seguidos.
 */
final class FollowControllerTest extends TestCase
{
    private UsuariosFalsos $usuarios;

    private SeguimientosFalsos $seguimientos;

    private PrivacidadFalsa $privacidad;

    private FollowController $controller;

    private int $yo;

    private int $otro;

    protected function setUp(): void
    {
        $this->usuarios     = new UsuariosFalsos();
        $this->seguimientos = new SeguimientosFalsos();
        $this->privacidad   = new PrivacidadFalsa();

        $this->yo   = $this->usuarios->alta('henyckma', 'David');
        $this->otro = $this->usuarios->alta('vecina', 'La Vecina');

        $this->seguimientos
            ->persona($this->yo, 'henyckma', 'David')
            ->persona($this->otro, 'vecina', 'La Vecina');

        $this->controller = new FollowController(
            new Seguir($this->seguimientos, $this->usuarios, $this->privacidad),
            new DejarDeSeguir($this->seguimientos, $this->usuarios),
            new ListarSeguimientos($this->seguimientos),
            new NullLogger()
        );
    }

    /**
     * La forma exacta que arma `ActionRouter`: el payload entero bajo `data` y
     * el `user_id` que puso `AuthMiddleware`, jamás el del cuerpo.
     *
     * @param  array<string, mixed> $datos
     * @return array<string, mixed>
     */
    private function peticion(string $accion, array $datos = [], ?int $userId = null): array
    {
        return ['action' => $accion, 'user_id' => $userId ?? $this->yo, 'data' => $datos];
    }

    public function testSeguirDevuelve200YElEstadoResultante(): void
    {
        $respuesta = $this->controller->add($this->peticion('follow_add', ['username' => 'vecina']));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertTrue($respuesta['data']['following']);
        self::assertSame('vecina', $respuesta['data']['user']['username']);
        self::assertTrue($this->seguimientos->hayMarcador($this->yo, $this->otro));
    }

    /**
     * **La condición del hito.** Sin ninguna sección en `everyone` no hay página
     * pública que visitar, así que el marcador no tendría a dónde apuntar.
     */
    public function testSeguirUnPerfilSinNadaEnEveryoneDevuelve422(): void
    {
        $this->privacidad->todasEn($this->otro, Nivel::Nadie);

        $respuesta = $this->controller->add($this->peticion('follow_add', ['username' => 'vecina']));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(422, $respuesta['http_code']);
        self::assertStringContainsString('no enseña nada públicamente', $respuesta['message']);
        self::assertSame([], $this->seguimientos->filas);
    }

    /** Y con todo en `friends` tampoco: sigue sin haber cara pública. */
    public function testSeguirUnPerfilEnteroEnFriendsTambienDevuelve422(): void
    {
        $this->privacidad->todasEn($this->otro, Nivel::Amigos);

        $respuesta = $this->controller->add($this->peticion('follow_add', ['username' => 'vecina']));

        self::assertSame(422, $respuesta['http_code']);
    }

    /** Con una sola sección abierta ya hay algo que ver. */
    public function testConUnaSeccionEnEveryoneElSeguimientoEntra(): void
    {
        $this->privacidad->todasEn($this->otro, Nivel::Nadie);
        $this->privacidad->pon($this->otro, Seccion::Coleccion, Nivel::Todos);

        $respuesta = $this->controller->add($this->peticion('follow_add', ['username' => 'vecina']));

        self::assertSame(200, $respuesta['http_code']);
    }

    /**
     * La diferencia deliberada con `friend_request`, que sí devuelve 409: aquí
     * el estado pedido ya es el estado actual y no hay nada que resolver.
     */
    public function testSeguirDosVecesDevuelve200YNo409(): void
    {
        $this->controller->add($this->peticion('follow_add', ['username' => 'vecina']));

        $respuesta = $this->controller->add($this->peticion('follow_add', ['username' => 'vecina']));

        self::assertSame(200, $respuesta['http_code']);
        self::assertTrue($respuesta['data']['following']);
        self::assertCount(1, $this->seguimientos->filas);
    }

    public function testSeguirseAUnoMismoDevuelve422(): void
    {
        $respuesta = $this->controller->add($this->peticion('follow_add', ['username' => 'henyckma']));

        self::assertSame(422, $respuesta['http_code']);
        self::assertSame([], $this->seguimientos->filas);
    }

    public function testUnUsernameQueNoExisteDevuelve404(): void
    {
        $respuesta = $this->controller->add($this->peticion('follow_add', ['username' => 'fantasma']));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(404, $respuesta['http_code']);
    }

    public function testSinUsernameDevuelve422(): void
    {
        $respuesta = $this->controller->add($this->peticion('follow_add'));

        self::assertSame(422, $respuesta['http_code']);
    }

    public function testDejarDeSeguirDevuelve200YQuitaElMarcador(): void
    {
        $this->seguimientos->siguiendo($this->yo, $this->otro);

        $respuesta = $this->controller->remove($this->peticion('follow_remove', ['username' => 'vecina']));

        self::assertSame(200, $respuesta['http_code']);
        self::assertFalse($respuesta['data']['following']);
        self::assertFalse($this->seguimientos->hayMarcador($this->yo, $this->otro));
    }

    /**
     * El caso que `follow_add` no tiene: la vecina cerró su perfil DESPUÉS de
     * que la siguieras. Exigir aquí la condición de entrada dejaría ese marcador
     * imposible de soltar.
     */
    public function testSePuedeDejarDeSeguirUnPerfilYaCerradoYSigueSiendo200(): void
    {
        $this->seguimientos->siguiendo($this->yo, $this->otro);
        $this->privacidad->todasEn($this->otro, Nivel::Nadie);

        $respuesta = $this->controller->remove($this->peticion('follow_remove', ['username' => 'vecina']));

        self::assertSame(200, $respuesta['http_code']);
        self::assertFalse($this->seguimientos->hayMarcador($this->yo, $this->otro));
    }

    public function testDejarDeSeguirAQuienNoSiguesTambienEs200(): void
    {
        $respuesta = $this->controller->remove($this->peticion('follow_remove', ['username' => 'vecina']));

        self::assertSame(200, $respuesta['http_code']);
        self::assertFalse($respuesta['data']['following']);
    }

    public function testDejarDeSeguirAUnUsernameQueNoExisteDevuelve404(): void
    {
        $respuesta = $this->controller->remove($this->peticion('follow_remove', ['username' => 'fantasma']));

        self::assertSame(404, $respuesta['http_code']);
    }

    public function testListarDevuelveLosSeguidosYElContadorDeSeguidores(): void
    {
        $this->seguimientos
            ->siguiendo($this->yo, $this->otro)
            ->siguiendo($this->otro, $this->yo);

        $respuesta = $this->controller->list($this->peticion('follow_list'));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame(['following', 'followerCount'], array_keys($respuesta['data']));
        self::assertCount(1, $respuesta['data']['following']);
        self::assertSame('vecina', $respuesta['data']['following'][0]['username']);
        self::assertSame(1, $respuesta['data']['followerCount']);
    }

    /**
     * El `user_id` sale del request —donde lo dejó `AuthMiddleware`— y **jamás**
     * del cuerpo. Aquí la consecuencia de fallar es menor que en la amistad
     * (nadie ve nada de nadie por un marcador), pero un `follower_id` del
     * payload dejaría a cualquiera llenarle a otro la lista de seguidos.
     */
    public function testElUserIdSaleDelRequestYNuncaDelPayload(): void
    {
        $peticion = $this->peticion('follow_add', [
            'username' => 'vecina',
            // Lo que un atacante escribiría. Se ignora entero.
            'user_id'  => $this->otro,
        ]);

        $this->controller->add($peticion);

        self::assertTrue($this->seguimientos->hayMarcador($this->yo, $this->otro));
        self::assertFalse(
            $this->seguimientos->hayMarcador($this->otro, $this->yo),
            'El marcador se pone SIEMPRE en nombre del usuario autenticado.'
        );
    }

    /** El `email` viaja dentro del `User` del repositorio y no sale por aquí. */
    public function testNingunaDeLasTresRespuestasLlevaUnCorreo(): void
    {
        $this->seguimientos->siguiendo($this->yo, $this->otro);

        $respuestas = [
            $this->controller->add($this->peticion('follow_add', ['username' => 'vecina'])),
            $this->controller->list($this->peticion('follow_list')),
            $this->controller->remove($this->peticion('follow_remove', ['username' => 'vecina'])),
        ];

        self::assertStringNotContainsString('@', json_encode($respuestas, JSON_THROW_ON_ERROR));
    }
}
