<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\BuscarUsuarios;
use App\Controllers\UserSearchController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * El contrato HTTP de `user_search`: el request que arma `ActionRouter` —el
 * payload bajo `data` y el `user_id` que puso `AuthMiddleware`— y la respuesta
 * con su `http_code`.
 *
 * **El `http_code` no es un detalle**: `Application::run()` solo lee esa clave y
 * no `code`, y escribirlo al revés convertiría el 422 del hito en un 400. El
 * *Hecho cuando:* dice **422** con todas las letras —«buscar dos letras devuelve
 * 422 y no resultados»—, así que ese código es contrato y no una elección
 * interna.
 *
 * Los tres que más importan:
 *
 *  1. **`testDosLetrasSonUn422YNoUnaListaVacia`** — el primer *Hecho cuando:*
 *     del M6, visto desde donde lo vería el cliente.
 *  2. **`testSinCoincidenciasEs200YNo404`** — un 404 haría distinguible «no hay
 *     nadie» de «hay alguien que no quiere salir», que es justo lo que la sexta
 *     columna existe para que no se pueda saber.
 *  3. **`testElUserIdSaleDelRequestYNuncaDelPayload`** — buscar «como si fuera
 *     otro» es, en una acción con rate limit, una forma de no gastar el suyo.
 */
final class UserSearchControllerTest extends TestCase
{
    private UsuariosFalsos $usuarios;

    private UserSearchController $controller;

    private int $yo;

    protected function setUp(): void
    {
        $this->usuarios = new UsuariosFalsos();

        $this->yo = $this->usuarios->alta('juanito', 'Yo El Que Busca');

        $this->controller = new UserSearchController(
            new BuscarUsuarios($this->usuarios),
            new NullLogger()
        );
    }

    /** El request tal como lo arma `ActionRouter`: el payload bajo `data`. */
    private function peticion(array $data): array
    {
        return ['action' => 'user_search', 'user_id' => $this->yo, 'data' => $data];
    }

    public function testDosLetrasSonUn422YNoUnaListaVacia(): void
    {
        $this->usuarios->alta('juana');

        $respuesta = $this->controller->search($this->peticion(['q' => 'ju']));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(422, $respuesta['http_code']);
        self::assertArrayNotHasKey('users', (array) ($respuesta['data'] ?? []));
    }

    public function testTresLetrasDevuelven200ConLaLista(): void
    {
        $this->usuarios->alta('juana', 'Juana', 'https://example.invalid/juana.png');

        $respuesta = $this->controller->search($this->peticion(['q' => 'jua']));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame([
            ['username' => 'juana', 'displayName' => 'Juana', 'avatarUrl' => 'https://example.invalid/juana.png'],
        ], $respuesta['data']['users']);
    }

    /**
     * Cero resultados es un 200 con la lista vacía. Un 404 diría «aquí no hay
     * nadie», y lo que puede estar pasando es que sí lo haya y no quiera salir:
     * distinguir las dos cosas es exactamente lo que `show_in_search` impide.
     */
    public function testSinCoincidenciasEs200YNo404(): void
    {
        $this->usuarios->alta('vecina');

        $respuesta = $this->controller->search($this->peticion(['q' => 'jua']));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame([], $respuesta['data']['users']);
    }

    /**
     * Y no se distingue de «hay alguien, pero está en `nobody`»: las dos
     * respuestas son **idénticas**. Si alguna vez difirieran, el buscador
     * contestaría «existe pero no sale», que es la mitad de lo que se pidió no
     * publicar.
     */
    public function testEsconderseYNoExistirSeVenExactamenteIgual(): void
    {
        $vacia = $this->controller->search($this->peticion(['q' => 'jua']));

        $oculta = $this->usuarios->alta('juana', 'Juana La Oculta');
        $this->usuarios->escondeDelBuscador($oculta);

        $conAlguienEscondido = $this->controller->search($this->peticion(['q' => 'jua']));

        self::assertSame($vacia, $conAlguienEscondido);
    }

    /**
     * El `user_id` sale del request —donde lo dejó `AuthMiddleware`— y nunca del
     * cuerpo. Aquí decide a quién se excluye del resultado: si viniera del
     * payload, cualquiera buscaría «como si fuera otro».
     */
    public function testElUserIdSaleDelRequestYNuncaDelPayload(): void
    {
        $otro = $this->usuarios->alta('juanota', 'La Otra');

        // El payload intenta hacerse pasar por `$otro`; el request dice `$this->yo`.
        $respuesta = $this->controller->search([
            'action'  => 'user_search',
            'user_id' => $this->yo,
            'data'    => ['q' => 'jua', 'user_id' => $otro],
        ]);

        // Sale `juanota` (no soy yo) y NO sale `juanito` (soy yo). Si el id
        // hubiera salido del payload, sería exactamente al revés.
        self::assertSame(['juanota'], array_column($respuesta['data']['users'], 'username'));
    }

    /** Sin `AuthMiddleware` en la pila, esto es un fallo de configuración y revienta. */
    public function testSinUserIdRevientaEnVezDeAsumirUnUsuario(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->controller->search(['action' => 'user_search', 'data' => ['q' => 'jua']]);
    }

    /** Sin `q` es 422 igualmente: el 400 de «no mandaste nada» lo da el middleware. */
    public function testSinQEs422(): void
    {
        $respuesta = $this->controller->search($this->peticion([]));

        self::assertSame(422, $respuesta['http_code']);
    }
}
