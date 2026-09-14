<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\GuardarPrivacidad;
use App\Application\UseCase\ObtenerPrivacidad;
use App\Controllers\PrivacyController;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\PrivacidadFalsa;

/**
 * Los dos contratos del M2 por donde entran de verdad: el request que arma
 * `ActionRouter` —el payload bajo `data` y el `user_id` que puso
 * `AuthMiddleware`— y la respuesta con su `http_code`.
 *
 * Existen por lo mismo que `DeckControllerTest`: el login de este proyecto es el
 * de Google y no se puede hacer con `curl`, así que la cobertura de los
 * contratos vive aquí. Lo que `curl` sí prueba, y se hizo, son las dos cosas que
 * ocurren *antes* del controller y que estos tests no pueden ver: el **401** sin
 * sesión y el **403** de `privacy_set` sin token CSRF.
 *
 * El assert que más importa no es ninguno de los dos contratos sino
 * `testElUserIdSaleDelRequestYNuncaDelPayload`: aquí eso pesa más que en los
 * mazos, porque un `user_id` tomado del cuerpo no dejaría leer la privacidad de
 * otro — dejaría **cambiársela**, y abrirle la colección de par en par.
 */
final class PrivacyControllerTest extends TestCase
{
    private const USUARIO = 7;

    private const OTRO = 99;

    private PrivacidadFalsa $privacidad;

    private PrivacyController $controller;

    protected function setUp(): void
    {
        $this->privacidad = new PrivacidadFalsa();

        $this->controller = new PrivacyController(
            new ObtenerPrivacidad($this->privacidad),
            new GuardarPrivacidad($this->privacidad),
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
    private function peticion(string $accion, array $datos = [], int $userId = self::USUARIO): array
    {
        return ['action' => $accion, 'user_id' => $userId, 'data' => $datos];
    }

    public function testPrivacyGetDevuelve200ConLosCincoNiveles(): void
    {
        $respuesta = $this->controller->get($this->peticion('privacy_get'));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame([
            'collection' => 'everyone',
            'value'      => 'friends',
            'decks'      => 'everyone',
            'sets'       => 'everyone',
            'wishlist'   => 'friends',
        ], $respuesta['data']['privacy']);
    }

    public function testPrivacySetDevuelve200YElEstadoCompleto(): void
    {
        $respuesta = $this->controller->set($this->peticion('privacy_set', ['collection' => 'nobody']));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame('nobody', $respuesta['data']['privacy']['collection']);
        self::assertCount(5, $respuesta['data']['privacy']);
    }

    /** Lo escrito se lee después: es la secuencia que hace el panel del M6. */
    public function testLoEscritoPorPrivacySetLoDevuelvePrivacyGet(): void
    {
        $this->controller->set($this->peticion('privacy_set', ['value' => 'everyone']));

        $respuesta = $this->controller->get($this->peticion('privacy_get'));

        self::assertSame('everyone', $respuesta['data']['privacy']['value']);
        self::assertSame('everyone', $respuesta['data']['privacy']['collection'], 'Escribir `value` ha movido `collection`');
        self::assertSame('friends', $respuesta['data']['privacy']['wishlist']);
    }

    /**
     * Un nivel que no existe es un fallo del cliente: **422, no 500 y no 200**.
     * `http_code` y no `code`: `Application::run()` solo lee el primero, y con
     * el otro nombre este 422 saldría al cliente convertido en 400.
     */
    public function testUnNivelInventadoEs422(): void
    {
        $respuesta = $this->controller->set($this->peticion('privacy_set', ['collection' => 'solo-yo']));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(422, $respuesta['http_code']);
        self::assertArrayHasKey('http_code', $respuesta);
    }

    public function testUnaPeticionSinSeccionesEs422(): void
    {
        $respuesta = $this->controller->set($this->peticion('privacy_set', []));

        self::assertSame(422, $respuesta['http_code']);
        self::assertSame(0, $this->privacidad->escrituras);
    }

    /**
     * El `user_id` del cuerpo se ignora. Si no fuera así, `privacy_set` sería el
     * endpoint con el que abrirle la colección a cualquiera.
     */
    public function testElUserIdSaleDelRequestYNuncaDelPayload(): void
    {
        $this->privacidad->todasEn(self::OTRO, Nivel::Nadie);

        $this->controller->set($this->peticion('privacy_set', [
            'user_id'    => self::OTRO,
            'collection' => 'everyone',
        ]));

        self::assertSame(
            Nivel::Nadie,
            $this->privacidad->niveles[self::OTRO]['collection'],
            'El user_id del payload ha cambiado la privacidad de otro usuario'
        );
        self::assertSame(Nivel::Todos, $this->privacidad->niveles[self::USUARIO]['collection']);
    }

    /** Leer la privacidad de otro tampoco: `privacy_get` es siempre sobre uno mismo. */
    public function testPrivacyGetSoloDevuelveLaDelUsuarioAutenticado(): void
    {
        $this->privacidad->todasEn(self::OTRO, Nivel::Nadie);

        $respuesta = $this->controller->get($this->peticion('privacy_get', ['user_id' => self::OTRO]));

        foreach (Seccion::cases() as $seccion) {
            self::assertSame(
                $seccion->nivelPorDefecto()->value,
                $respuesta['data']['privacy'][$seccion->value]
            );
        }
    }
}
