<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\Visibilidad;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * **El fallo del M2 que sale VERDE, cazado.**
 *
 * `Visibilidad` no está declarada en `config/container.php`: la autoinyecta
 * PHP-DI cuando monta `PublicHttpRouter`. Y `ReflectionBasedAutowiring` **se
 * salta los parámetros opcionales** («Skip optional parameters»). De ahí sale un
 * fallo que ninguna otra prueba de esta suite podría ver:
 *
 *  - Si el puerto de amistades vuelve a ser opcional (`?Interfaz $x = null`),
 *    PHP-DI no inyectará nada, `$this->amistades` será `null` **en producción**
 *    y el nivel `friends` dejará de consultar la tabla… mientras
 *    `VisibilidadTest` sigue en verde, porque allí el doble se pasa a mano.
 *  - Y si el puerto es obligatorio pero **no está registrado**, PHP-DI intenta
 *    instanciar una interfaz y **toda ruta pública muere** — que es exactamente
 *    por lo que en el M0 y el M1 tuvo que ser opcional, mientras no hubo
 *    implementación.
 *
 * Las dos condiciones van juntas y por eso van en el mismo fichero: cualquiera
 * de las dos sin la otra rompe algo, en un sentido o en el otro.
 */
final class AmistadEnElContenedorTest extends TestCase
{
    /**
     * El puerto de amistades del constructor de `Visibilidad`: obligatorio, sin
     * defecto y sin `?`. Un defecto aquí es el fallo silencioso de arriba.
     */
    public function testElPuertoDeAmistadesDeVisibilidadNoEsOpcional(): void
    {
        $constructor = (new ReflectionClass(Visibilidad::class))->getConstructor();

        self::assertNotNull($constructor);

        $parametros = array_values(array_filter(
            $constructor->getParameters(),
            static function ($parametro): bool {
                $tipo = $parametro->getType();

                return $tipo instanceof ReflectionNamedType
                    && $tipo->getName() === FriendshipRepositoryInterface::class;
            }
        ));

        self::assertCount(1, $parametros, 'Visibilidad tiene que seguir recibiendo el puerto de amistades.');

        $puerto = $parametros[0];

        self::assertFalse(
            $puerto->isOptional(),
            'Con un defecto, PHP-DI se salta el parámetro y $this->amistades sería null en producción.'
        );
        self::assertFalse(
            $puerto->allowsNull(),
            'Un `?` invita a volver a ponerle el defecto que rompe la inyección.'
        );
    }

    /**
     * Y la otra mitad: el puerto tiene que estar registrado, o el autowiring de
     * `PublicHttpRouter` intentará instanciar una interfaz y tumbará las rutas
     * públicas.
     */
    public function testElPuertoDeAmistadesEstaRegistradoEnElContenedor(): void
    {
        $contenedor = (require __DIR__ . '/../../config/container.php')();

        self::assertTrue(
            $contenedor->has(FriendshipRepositoryInterface::class),
            'Sin este registro, montar PublicHttpRouter revienta: PHP-DI no puede instanciar una interfaz.'
        );
    }
}
