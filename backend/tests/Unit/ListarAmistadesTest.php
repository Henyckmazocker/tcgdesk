<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ListarAmistades;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\AmistadesFalsas;

/**
 * El listado de `/friends`: tres listas y tres contadores salidos de un solo
 * conjunto de filas.
 *
 * Lo que se prueba, por orden de daño:
 *
 *  1. **Que `pending` y `sent` no se mezclan.** Son la misma fila vista desde
 *     los dos lados y solo las distingue quién pidió — que es exactamente lo
 *     mismo que decide quién puede aceptar. Si se mezclaran, la vista pintaría
 *     el botón de aceptar sobre una solicitud propia y el servidor respondería
 *     un 403 que el usuario no entendería.
 *  2. **Que una solicitud pendiente NO aparece entre los amigos.** Es el mismo
 *     fallo que `sonAmigos()` puede tener, un piso más arriba: aquí no publica
 *     datos, pero le diría a alguien que ya es amigo de quien no le ha
 *     contestado.
 *  3. **Que de cada persona salen tres campos y ni uno más.** Sin `email` —el
 *     `JOIN` ni lo selecciona— y **sin el `id` numérico**: el `username` es la
 *     clave pública del proyecto, y repartir ids reales daría por dónde empezar
 *     a recorrer.
 *  4. **Que sale la persona del OTRO lado**, la haya pedido ella o yo. Es lo que
 *     hace el `IF` del `JOIN`, y si se invirtiera el listado sería un espejo.
 */
final class ListarAmistadesTest extends TestCase
{
    private const YO = 7;

    private const AMIGA      = 11;
    private const ME_PIDIO   = 13;
    private const LE_PEDI    = 19;
    private const DE_OTROS_A = 90;
    private const DE_OTROS_B = 91;

    private ListarAmistades $listar;

    protected function setUp(): void
    {
        $amistades = (new AmistadesFalsas())
            ->acepta(self::YO, self::AMIGA)
            ->pide(self::ME_PIDIO, self::YO)
            ->pide(self::YO, self::LE_PEDI)
            // Una amistad entre terceros: no puede aparecer en mi listado.
            ->acepta(self::DE_OTROS_A, self::DE_OTROS_B)
            ->sigue(self::YO, self::DE_OTROS_A)
            ->persona(self::AMIGA, 'amiga', 'La Amiga', 'https://example.test/a.png')
            ->persona(self::ME_PIDIO, 'quien-me-pidio')
            ->persona(self::LE_PEDI, 'a-quien-pedi');

        $this->listar = new ListarAmistades($amistades);
    }

    public function testLasTresListasSeparanPorEstadoYPorQuienPidio(): void
    {
        $resultado = ($this->listar)(self::YO);

        self::assertSame(['amiga'], array_column(array_column($resultado['friends'], 'user'), 'username'));
        self::assertSame(['quien-me-pidio'], array_column(array_column($resultado['pending'], 'user'), 'username'));
        self::assertSame(['a-quien-pedi'], array_column(array_column($resultado['sent'], 'user'), 'username'));
    }

    /** El contador de la barra del M4 es `counts.pending`, y cuenta lo recibido. */
    public function testLosContadoresCuadranConLasListas(): void
    {
        $resultado = ($this->listar)(self::YO);

        self::assertSame(['friends' => 1, 'pending' => 1, 'sent' => 1], $resultado['counts']);
    }

    public function testUnaSolicitudPendienteNoCuentaComoAmistad(): void
    {
        $resultado = ($this->listar)(self::YO);

        self::assertCount(1, $resultado['friends']);
        self::assertNotContains(
            'quien-me-pidio',
            array_column(array_column($resultado['friends'], 'user'), 'username')
        );
    }

    public function testLasAmistadesDeTercerosNoAparecen(): void
    {
        $resultado = ($this->listar)(self::YO);

        $todo = json_encode($resultado, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('usuario' . self::DE_OTROS_A, $todo);
        self::assertStringNotContainsString('usuario' . self::DE_OTROS_B, $todo);
    }

    /** Seguir a alguien no le mete en ninguna de las tres listas. */
    public function testSeguirNoAparecePorNingunLado(): void
    {
        $resultado = ($this->listar)(self::YO);

        self::assertSame(3, count($resultado['friends']) + count($resultado['pending']) + count($resultado['sent']));
    }

    public function testDeCadaPersonaSalenTresCamposYNiUnoMas(): void
    {
        $resultado = ($this->listar)(self::YO);

        foreach (['friends', 'pending', 'sent'] as $lista) {
            foreach ($resultado[$lista] as $entrada) {
                self::assertSame(['friendshipId', 'since', 'user'], array_keys($entrada));
                self::assertSame(['username', 'displayName', 'avatarUrl'], array_keys($entrada['user']));
            }
        }
    }

    /** Ni `email` ni el `id` numérico de nadie salen del servidor. */
    public function testNoViajaNiElEmailNiElIdDeLasPersonas(): void
    {
        $json = json_encode(($this->listar)(self::YO), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('@', $json);
        self::assertStringNotContainsString('"id"', $json);
    }

    public function testElFriendshipIdEsElQueHayQueMandarDeVuelta(): void
    {
        $resultado = ($this->listar)(self::YO);

        foreach ($resultado['pending'] as $entrada) {
            self::assertIsInt($entrada['friendshipId']);
            self::assertGreaterThan(0, $entrada['friendshipId']);
        }
    }

    public function testQuienNoTieneNadaRecibeTresListasVaciasYNoUnError(): void
    {
        $resultado = ($this->listar)(self::DE_OTROS_A + 1000);

        self::assertSame([], $resultado['friends']);
        self::assertSame([], $resultado['pending']);
        self::assertSame([], $resultado['sent']);
        self::assertSame(['friends' => 0, 'pending' => 0, 'sent' => 0], $resultado['counts']);
    }
}
