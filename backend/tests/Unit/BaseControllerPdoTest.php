<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\BaseController;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * El traductor de errores de PDO que `CollectionController` y `DeckController`
 * tenían duplicado y que desde el 2026-09-12 vive una sola vez en
 * `BaseController`.
 *
 * Se prueba aquí y no a través de los dos controllers porque lo que hay que
 * fijar es **el criterio de los dos SQLSTATE**, no el camino que lleva hasta
 * él: los cuatro contratos de colección y los tres de mazo ya están cubiertos
 * por `CollectionControllerTest` y `DeckControllerTest`.
 *
 * El assert que más importa es el del relanzado: si un SQLSTATE desconocido se
 * tradujera a 422, un fallo real de base de datos saldría al cliente como
 * «revisa lo que has mandado» y nadie miraría el servidor.
 */
final class BaseControllerPdoTest extends TestCase
{
    private const USUARIO = 7;

    public function testLaViolacionDeClaveForaneaEsUn422(): void
    {
        $respuesta = $this->traducir($this->errorDePdo('23000'));

        self::assertSame(422, $respuesta['http_code']);
        self::assertSame('error', $respuesta['status']);
        self::assertSame('Esa carta no existe en el catálogo.', $respuesta['message']);
    }

    public function testElDesbordamientoDeLaCantidadEsUn422(): void
    {
        $respuesta = $this->traducir($this->errorDePdo('22003'));

        self::assertSame(422, $respuesta['http_code']);
        self::assertSame('error', $respuesta['status']);
        self::assertSame('Esa cantidad se sale del máximo por línea.', $respuesta['message']);
    }

    /**
     * Cualquier otro código se relanza tal cual: un error que no sabemos
     * traducir es un 500 de verdad y tiene que llegar entero al manejador de
     * arriba, no convertirse en un 422 que miente.
     */
    public function testCualquierOtroCodigoSeRelanza(): void
    {
        $original = $this->errorDePdo('42S02');

        try {
            $this->traducir($original);
            self::fail('Un SQLSTATE desconocido tenía que relanzarse, no traducirse.');
        } catch (PDOException $e) {
            self::assertSame($original, $e);
            self::assertSame('42S02', $e->getCode());
        }
    }

    /**
     * El mensaje del log es parámetro porque es lo único en lo que diferían las
     * dos copias que había; si dejara de viajar, los dos controllers escribirían
     * la misma línea y el log dejaría de distinguir colección de mazo.
     */
    public function testElMensajeDeLogViajaPorParametroYSoloConLaClaveForanea(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Carta rechazada: el printing no existe', ['user_id' => self::USUARIO]);

        $this->traducir($this->errorDePdo('23000'), $logger, 'Carta rechazada: el printing no existe');
    }

    public function testElDesbordamientoNoSeLoguea(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $respuesta = $this->traducir($this->errorDePdo('22003'), $logger);

        self::assertSame(422, $respuesta['http_code']);
    }

    // =====================================================================
    // El quinto parámetro, del M2 del Plan - Amigos y Seguimiento
    // =====================================================================
    // `23000` no es «clave foránea»: es violación de restricción de integridad,
    // y agrupa la foránea rota (`1452`) con la clave DUPLICADA (`1062`), que es
    // la que devuelve el `UNIQUE (user_low, user_high)` de `friendships`. Ese
    // caso es un 409 y no un 422, y «esa carta no existe en el catálogo» no
    // significa nada en él. Lo que NO se podía hacer era romper a los siete
    // llamadores que ya había, y eso es lo que fija el primer test.

    /**
     * Los cuatro `CollectionController` y los tres `DeckController` no pasan el
     * quinto parámetro, y su comportamiento tiene que seguir siendo EXACTAMENTE
     * el de antes, `1062` incluido.
     */
    public function testSinMensajeDeDuplicadoTodoElVeintitresMilSigueSiendoElCatalogo(): void
    {
        foreach ([null, 1452, 1062] as $codigoDeDriver) {
            $respuesta = $this->traducir($this->errorDePdo('23000', $codigoDeDriver));

            self::assertSame(422, $respuesta['http_code']);
            self::assertSame('Esa carta no existe en el catálogo.', $respuesta['message']);
        }
    }

    public function testConMensajeDeDuplicadoUn1062EsUn409(): void
    {
        $respuesta = $this->traducir(
            $this->errorDePdo('23000', 1062),
            null,
            'Solicitud de amistad rechazada: ya hay fila entre esos dos',
            'Ya existe una solicitud con esa persona.'
        );

        self::assertSame(409, $respuesta['http_code']);
        self::assertSame('error', $respuesta['status']);
        self::assertSame('Ya existe una solicitud con esa persona.', $respuesta['message']);
    }

    /**
     * Quien declara «mi 23000 es un duplicado» no puede recibir el mensaje del
     * catálogo de cartas por un `1452`: en `friendships` no hay ningún
     * `printing_uuid` que pudiera no existir, así que una foránea rota ahí es un
     * fallo del servidor y tiene que llegar como tal.
     */
    public function testConMensajeDeDuplicadoUnaForaneaRotaSeRelanza(): void
    {
        $original = $this->errorDePdo('23000', 1452);

        try {
            $this->traducir($original, null, 'da igual', 'Ya existe una solicitud con esa persona.');
            self::fail('Un 23000 que no es un duplicado tenía que relanzarse.');
        } catch (PDOException $e) {
            self::assertSame($original, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function traducir(
        PDOException $e,
        ?LoggerInterface $logger = null,
        string $mensaje = 'Alta rechazada: el printing no existe',
        ?string $mensajeDeDuplicado = null
    ): array {
        $controller = new class extends BaseController {
            /**
             * @return array<string, mixed>
             */
            public function traducir(
                PDOException $e,
                int $userId,
                LoggerInterface $logger,
                string $mensajeDeLog,
                ?string $mensajeDeDuplicado
            ): array {
                return $this->traducirErrorDeBaseDeDatos($e, $userId, $logger, $mensajeDeLog, $mensajeDeDuplicado);
            }
        };

        return $controller->traducir(
            $e,
            self::USUARIO,
            $logger ?? new NullLogger(),
            $mensaje,
            $mensajeDeDuplicado
        );
    }

    /**
     * El SQLSTATE de PDO es una **cadena** (`'23000'`), y el constructor de
     * `Exception` solo acepta enteros: la única forma de reproducir el error tal
     * y como lo lanza el driver es escribir la propiedad `code`, que es lo que
     * hace PDO por dentro.
     *
     * Va por subclase y no por `Closure::call()`: PHP **no deja** vincular una
     * closure al ámbito de una clase interna, y el intento solo produce un
     * warning —que con `failOnWarning` es un test en rojo— y un `code` sin
     * tocar.
     */
    private function errorDePdo(string $sqlState, ?int $codigoDeDriver = null): PDOException
    {
        return new class($sqlState, $codigoDeDriver) extends PDOException {
            public function __construct(string $sqlState, ?int $codigoDeDriver)
            {
                parent::__construct('SQLSTATE[' . $sqlState . ']');

                $this->code = $sqlState;

                // `errorInfo` es lo ÚNICO que distingue un `1062` (clave
                // duplicada) de un `1452` (foránea rota) dentro del mismo
                // SQLSTATE 23000, y por eso hay que poderlo simular. Cuando no
                // se pide, se deja sin tocar a propósito: así queda probado que
                // el helper no revienta con un PDOException que no lo traiga.
                if ($codigoDeDriver !== null) {
                    $this->errorInfo = [$sqlState, $codigoDeDriver, 'simulado'];
                }
            }
        };
    }
}
