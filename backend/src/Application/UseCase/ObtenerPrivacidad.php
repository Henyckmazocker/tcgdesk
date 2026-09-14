<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\UserPrivacyRepositoryInterface;
use App\Domain\Social\Descubrimiento;
use App\Domain\Social\Nivel;

/**
 * Qué tiene puesto el usuario en sus cinco secciones **y en el sexto selector**:
 * lo que pinta el panel de privacidad.
 *
 * **Devuelve siempre las cinco**, tenga fila o no. Hoy no la tiene nadie —la
 * escribe `GuardarPrivacidad` la primera vez que se toca un selector—, así que
 * el caso «sin fila» no es el raro sino el único, y el panel no puede necesitar
 * saberlo: cinco selectores vacíos y cinco con los defectos se ven distinto, y
 * el segundo es el que dice la verdad.
 *
 * **No decide nada.** Esto no responde «¿lo ve fulano?» sino «¿qué hay puesto?»;
 * la decisión es de `App\Domain\Social\Visibilidad` y de nadie más. Es la regla
 * de oro del plan y por eso este use case es tan corto: si algún día creciera
 * con un `if` sobre el nivel, sería que se está resolviendo un permiso aquí.
 *
 * El nombre en español sigue al de `Visibilidad`, `Seccion` y `Nivel`: el plan
 * los bautizó así y este es el use case que el propio plan llama
 * «ObtenerPrivacidad».
 *
 * ## El sexto valor viaja APARTE de los cinco, y no dentro de `privacy`
 *
 * `search` (la columna `show_in_search`, M6 del Plan - Amigos y Seguimiento) es
 * una clave hermana de `privacy` en la respuesta, no una sexta entrada dentro de
 * ella. El motivo está entero en `App\Domain\Social\Descubrimiento`, y el
 * resumen es que **no es un `Nivel`**: tiene dos valores y no tres, no la
 * resuelve `Visibilidad`, y colarla en el mapa de secciones habría roto
 * `Seguir::tieneCaraPublica()` —que recorre ese mapa buscando un `everyone`—
 * dejando que se siguiera un perfil con las cinco secciones cerradas.
 *
 * Un cliente viejo que solo lea `privacy` sigue funcionando exactamente igual, y
 * eso también es consecuencia de sacarla fuera.
 */
class ObtenerPrivacidad
{
    public function __construct(
        private readonly UserPrivacyRepositoryInterface $privacidad
    ) {
    }

    /**
     * Las claves son `Seccion->value` (`collection`, `value`, `decks`, `sets`,
     * `wishlist`) y **no** los nombres de columna: el prefijo `show_` es cosa
     * del esquema, y dejarlo salir aquí ataría el contrato del cliente al nombre
     * físico de la tabla. Los valores son los del ENUM, en texto, porque lo que
     * viaja es JSON.
     *
     * @return array{privacy: array<string, string>, search: string}
     */
    public function __invoke(int $userId): array
    {
        $niveles = $this->privacidad->nivelesDe($userId);

        return [
            'privacy' => array_map(
                static fn (Nivel $nivel): string => $nivel->value,
                $niveles
            ),
            // La clave es `search` y no `show_in_search`, por lo mismo que
            // `collection` y no `show_collection`: el prefijo `show_` es cosa
            // del esquema. Y sale en texto porque lo que viaja es JSON.
            Descubrimiento::CLAVE => $this->privacidad->descubrimientoDe($userId)->value,
        ];
    }
}
