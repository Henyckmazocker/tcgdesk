<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AceptarAmistad;
use App\Application\UseCase\DeshacerAmistad;
use App\Application\UseCase\ListarAmistades;
use App\Application\UseCase\PedirAmistad;
use App\Application\UseCase\RechazarAmistad;
use App\Controllers\FriendController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\AmistadesFalsas;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * El contrato HTTP de las cinco acciones de amistad: el request que arma
 * `ActionRouter` —el payload bajo `data` y el `user_id` que puso
 * `AuthMiddleware`— y la respuesta con su `http_code`.
 *
 * **El `http_code` no es un detalle.** Las middlewares y los controllers de este
 * proyecto devuelven `http_code` y no `code`, porque `Application::run()` solo
 * lee lo primero; en libraryVue todos los 401 y 403 salen al cliente convertidos
 * en 400 por haberlo escrito al revés. Aquí eso se llevaría por delante la
 * diferencia entre «no es tuya» (403) y «ya hay una» (409), que es justo lo que
 * el cliente necesita para saber qué decirle al usuario.
 *
 * Los tres que más importan:
 *
 *  1. **`testAceptarSiendoElRequesterDevuelve403`** — el *Hecho cuando:* del M2,
 *     visto desde donde lo vería el cliente.
 *  2. **`testElUserIdSaleDelRequestYNuncaDelPayload`** — aquí pesa más que en
 *     ningún otro controller: un `user_id` tomado del cuerpo no dejaría leer la
 *     amistad de otro, dejaría **aceptarla**, y aceptar es lo único que abre el
 *     nivel `friends` de la privacidad de esa persona.
 *  3. **`testPedirDosVecesDevuelve409`** — el `1062` del `UNIQUE` simétrico
 *     traducido, que es el único sitio del plan donde la corrección la garantiza
 *     la base de datos y no un `if`.
 *
 * Existen por lo mismo que `PrivacyControllerTest`: el login de este proyecto es
 * el de Google y no se puede hacer con `curl`, así que la cobertura de los
 * contratos vive aquí.
 */
final class FriendControllerTest extends TestCase
{
    private UsuariosFalsos $usuarios;

    private AmistadesFalsas $amistades;

    private FriendController $controller;

    private int $yo;

    private int $otro;

    protected function setUp(): void
    {
        $this->usuarios  = new UsuariosFalsos();
        $this->amistades = new AmistadesFalsas();

        $this->yo   = $this->usuarios->alta('henyckma', 'David');
        $this->otro = $this->usuarios->alta('vecina', 'La Vecina');

        $this->controller = new FriendController(
            new PedirAmistad($this->amistades, $this->usuarios),
            new AceptarAmistad($this->amistades),
            new RechazarAmistad($this->amistades),
            new DeshacerAmistad($this->amistades),
            new ListarAmistades($this->amistades),
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

    public function testPedirAmistadDevuelve201YLaSolicitudPendiente(): void
    {
        $respuesta = $this->controller->request($this->peticion('friend_request', ['username' => 'vecina']));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(201, $respuesta['http_code']);
        self::assertSame('pending', $respuesta['data']['status']);
    }

    /** El `1062` del `UNIQUE` simétrico, traducido por `traducirErrorDeBaseDeDatos()`. */
    public function testPedirDosVecesDevuelve409ConUnMensajeUtil(): void
    {
        $this->controller->request($this->peticion('friend_request', ['username' => 'vecina']));

        $respuesta = $this->controller->request($this->peticion('friend_request', ['username' => 'vecina']));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(409, $respuesta['http_code']);
        self::assertSame('Ya existe una solicitud con esa persona.', $respuesta['message']);
    }

    /** Y da igual quién pidiera primero: el `UNIQUE` es simétrico. */
    public function testPedirAQuienYaTePidioTambienDevuelve409(): void
    {
        $this->amistades->pide($this->otro, $this->yo);

        $respuesta = $this->controller->request($this->peticion('friend_request', ['username' => 'vecina']));

        self::assertSame(409, $respuesta['http_code']);
    }

    public function testPedirseAmistadAUnoMismoDevuelve422(): void
    {
        $respuesta = $this->controller->request($this->peticion('friend_request', ['username' => 'henyckma']));

        self::assertSame(422, $respuesta['http_code']);
        self::assertSame([], $this->amistades->filas);
    }

    public function testUnUsernameQueNoExisteDevuelve404(): void
    {
        $respuesta = $this->controller->request($this->peticion('friend_request', ['username' => 'fantasma']));

        self::assertSame(404, $respuesta['http_code']);
    }

    /** **El *Hecho cuando:* del hito, en HTTP.** */
    public function testAceptarSiendoElRequesterDevuelve403(): void
    {
        $id = $this->amistades->pide($this->yo, $this->otro)->idDe($this->yo, $this->otro);

        $respuesta = $this->controller->accept($this->peticion('friend_accept', ['friendship_id' => $id]));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(403, $respuesta['http_code']);
        self::assertFalse(
            $this->amistades->sonAmigos($this->yo, $this->otro),
            'Un 403 que aun así hubiera aceptado sería peor que un 200.'
        );
    }

    public function testAceptarSiendoElAddresseeDevuelve200YAbreLaAmistad(): void
    {
        $id = $this->amistades->pide($this->otro, $this->yo)->idDe($this->otro, $this->yo);

        $respuesta = $this->controller->accept($this->peticion('friend_accept', ['friendship_id' => $id]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertTrue($this->amistades->sonAmigos($this->yo, $this->otro));
    }

    /**
     * Lo que pasaría si el usuario viniera del cuerpo: `friendships.id` es un
     * autoincremental global, así que bastaría con cambiar un número para
     * aceptar la solicitud de otra persona.
     */
    public function testElUserIdSaleDelRequestYNuncaDelPayload(): void
    {
        $id = $this->amistades->pide($this->yo, $this->otro)->idDe($this->yo, $this->otro);

        $respuesta = $this->controller->accept([
            'action'  => 'friend_accept',
            'user_id' => $this->yo,
            // El atacante se declara el destinatario en el payload.
            'data'    => ['friendship_id' => $id, 'user_id' => $this->otro],
        ]);

        self::assertSame(403, $respuesta['http_code']);
        self::assertFalse($this->amistades->sonAmigos($this->yo, $this->otro));
    }

    public function testUnFriendshipIdAjenoDevuelve404YNoUn403(): void
    {
        $this->amistades->pide($this->otro, $this->yo);
        $ajeno = $this->amistades->crearSolicitud(900, 901);

        $respuesta = $this->controller->accept($this->peticion('friend_accept', ['friendship_id' => $ajeno]));

        self::assertSame(404, $respuesta['http_code'], 'Un 403 confirmaría que esa amistad existe.');
    }

    public function testRechazarBorraLaSolicitudYDevuelve200(): void
    {
        $id = $this->amistades->pide($this->otro, $this->yo)->idDe($this->otro, $this->yo);

        $respuesta = $this->controller->reject($this->peticion('friend_reject', ['friendship_id' => $id]));

        self::assertSame(200, $respuesta['http_code']);
        self::assertNull($this->amistades->buscar($id));
    }

    public function testRechazarSiendoElRequesterDevuelve403(): void
    {
        $id = $this->amistades->pide($this->yo, $this->otro)->idDe($this->yo, $this->otro);

        $respuesta = $this->controller->reject($this->peticion('friend_reject', ['friendship_id' => $id]));

        self::assertSame(403, $respuesta['http_code']);
        self::assertNotNull($this->amistades->buscar($id));
    }

    public function testDeshacerLoPuedenLosDosLados(): void
    {
        $id = $this->amistades->acepta($this->yo, $this->otro)->idDe($this->yo, $this->otro);

        // El que pidió.
        $respuesta = $this->controller->remove($this->peticion('friend_remove', ['friendship_id' => $id]));
        self::assertSame(200, $respuesta['http_code']);

        // Y el que aceptó, sobre una amistad nueva.
        $otroId = $this->amistades->acepta($this->yo, $this->otro)->idDe($this->yo, $this->otro);

        $respuesta = $this->controller->remove(
            $this->peticion('friend_remove', ['friendship_id' => $otroId], $this->otro)
        );
        self::assertSame(200, $respuesta['http_code']);
        self::assertFalse($this->amistades->sonAmigos($this->yo, $this->otro));
    }

    /**
     * `friend_remove` **retira una solicitud enviada** (enmienda del 2026-09-14,
     * decidida por David; ver el Log del plan). Antes daba 409 y eso dejaba al
     * solicitante sin ninguna forma de echarse atrás — `friend_reject` es solo
     * del destinatario—, con el agravante de que M5 pinta ese botón.
     */
    public function testDeshacerRetiraUnaSolicitudPendientePropia(): void
    {
        $id = $this->amistades->pide($this->yo, $this->otro)->idDe($this->yo, $this->otro);

        $respuesta = $this->controller->remove($this->peticion('friend_remove', ['friendship_id' => $id]));

        self::assertSame(200, $respuesta['http_code']);
        self::assertNull($this->amistades->buscar($id), 'La solicitud retirada sigue en la tabla');
    }

    public function testSinFriendshipIdDevuelve422(): void
    {
        $respuesta = $this->controller->accept($this->peticion('friend_accept'));

        self::assertSame(422, $respuesta['http_code']);
    }

    public function testFriendListDevuelve200ConLasTresListasYLosContadores(): void
    {
        $this->amistades
            ->acepta($this->yo, $this->otro)
            ->persona($this->otro, 'vecina', 'La Vecina');

        $respuesta = $this->controller->list($this->peticion('friend_list'));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame(['friends', 'pending', 'sent', 'counts'], array_keys($respuesta['data']));
        self::assertSame(['friends' => 1, 'pending' => 0, 'sent' => 0], $respuesta['data']['counts']);
    }
}
