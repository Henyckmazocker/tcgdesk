<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\UserPrivacyRepositoryInterface;
use App\Domain\Social\Descubrimiento;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use InvalidArgumentException;

/**
 * Cambiar lo que se ve de ti, **sección a sección**.
 *
 * **La edición es parcial a propósito**, igual que `UpdateDeck`: el panel del M6
 * manda el selector que el usuario acaba de mover y ninguno más. Si esto
 * construyera las cinco secciones con los defectos que faltan, tocar `value`
 * devolvería `collection` a `everyone` y volvería a publicar una colección que
 * su dueño había cerrado — sin un solo error, sin un solo aviso, y solo se
 * notaría abriendo el perfil en incógnito.
 *
 * Tres decisiones que no son obvias:
 *
 *  - **Se recorren las cinco secciones conocidas, no las claves del payload.**
 *    El `data` que arma `ActionRouter` trae también `action` y `csrf_token`, y
 *    ni son secciones ni deben provocar un error. Al preguntar por lo que se
 *    conoce, lo demás se ignora solo.
 *  - **`Nivel::desde()` y no `Nivel::intentar()`**: un nivel inventado revienta
 *    con un 422 en vez de caer a un defecto. En un modelo de permisos, caer a un
 *    defecto en silencio es exactamente cómo se publica lo que no se quería
 *    publicar — y el defecto de `collection` es `everyone`.
 *  - **`friends` se acepta y se guarda.** Que hoy `Visibilidad` no se lo conceda
 *    a nadie es cosa de resolverlo, no de almacenarlo: es el nivel que el Plan -
 *    Amigos y Seguimiento activará sin tener que migrar ni una fila.
 *
 * Y lo que este use case **no** hace: decidir quién ve qué. No pregunta por
 * `Visibilidad` ni la necesita — aquí el espectador es siempre el dueño, porque
 * el `user_id` lo pone `AuthMiddleware` y nadie edita la privacidad de otro.
 *
 * ## El sexto selector: `search`, y por qué es OTRA escritura
 *
 * `show_in_search` (M6 del Plan - Amigos y Seguimiento) entra por esta misma
 * acción —es el mismo panel y la misma fila— pero por su propia clave y su
 * propio método del repositorio, porque **no es un `Nivel`**: dos valores y no
 * tres, y no la resuelve `Visibilidad`. El porqué completo está en
 * `App\Domain\Social\Descubrimiento`; lo que importa aquí es la consecuencia
 * práctica: mover el sexto selector **no toca las otras cinco columnas**, y
 * mover cualquiera de las cinco **no toca la sexta**, exactamente igual que
 * mover una sección no mueve las otras cuatro. Es la misma edición parcial, un
 * escalón más arriba.
 *
 * Puede venir **solo el sexto**, **solo secciones**, o **las dos cosas a la
 * vez**; lo único que sigue siendo un 422 es una petición que no cambia nada.
 */
class GuardarPrivacidad
{
    public function __construct(
        private readonly UserPrivacyRepositoryInterface $privacidad
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{privacy: array<string, string>, search: string} las seis, tal como quedaron
     * @throws InvalidArgumentException si un nivel no existe, si `search` no es
     *         uno de sus dos valores, o si no viene nada que cambiar
     */
    public function __invoke(int $userId, array $peticion): array
    {
        $cambios = $this->cambiosDesdePeticion($peticion);

        // `Descubrimiento::desde()` y no `intentar()`, por lo mismo que
        // `Nivel::desde()`: un valor inventado —`friends`, por ejemplo, que es
        // un nivel legítimo de las otras cinco columnas y NO existe en esta— es
        // un 422 y no una caída silenciosa a un defecto. Caer a `everyone`
        // publicaría a quien pidió no salir; caer a `nobody` escondería a quien
        // no lo pidió. Las dos mienten, así que ninguna.
        $busqueda = $this->descubrimientoDesdePeticion($peticion);

        // Una petición que no cambia nada no es un no-op inofensivo: es un
        // cliente que cree haber guardado algo. Mejor un 422 que le diga el
        // nombre de lo que se puede cambiar que un 200 que le mienta.
        if ($cambios === [] && $busqueda === null) {
            throw new InvalidArgumentException(
                'privacy_set necesita al menos una sección: '
                . implode(', ', array_column(Seccion::cases(), 'value'))
                . '; o «' . Descubrimiento::CLAVE . '».'
            );
        }

        // Dos escrituras y no una, y solo la que haga falta. Son dos columnas
        // distintas de la misma fila y las dos consultas son
        // `INSERT … ON DUPLICATE KEY UPDATE`, así que la fila se crea la
        // primera vez venga por donde venga. Fundirlas en un método único
        // habría obligado a que el bucle sobre `Seccion::cases()` de
        // `guardarNiveles()` —lo único que separa esa consulta de una
        // inyección, porque un nombre de columna no se parametriza— hiciera un
        // hueco para una columna que no es una sección. No se toca.
        $niveles = $cambios === []
            ? $this->privacidad->nivelesDe($userId)
            : $this->privacidad->guardarNiveles($userId, $cambios);

        $descubrimiento = $busqueda === null
            ? $this->privacidad->descubrimientoDe($userId)
            : $this->privacidad->guardarDescubrimiento($userId, $busqueda);

        // Se responde el estado COMPLETO y no solo lo que cambió, por lo mismo
        // que `deck_update` devuelve el mazo entero: el panel repinta con esto
        // sin tener que recomponer nada, y así el usuario ve de una vez que lo
        // que no tocó sigue donde estaba.
        return [
            'privacy' => array_map(
                static fn (Nivel $nivel): string => $nivel->value,
                $niveles
            ),
            Descubrimiento::CLAVE => $descubrimiento->value,
        ];
    }

    /**
     * El sexto selector, si vino en el payload. `null` si no vino.
     *
     * Se acepta tanto la clave pública (`search`) como el nombre de la columna
     * (`show_in_search`), por lo mismo que las cinco de abajo: el panel manda lo
     * primero, pero copiar el nombre de la columna al formulario es el error más
     * fácil de cometer y no merece un 422 silencioso.
     *
     * @param  array<string, mixed> $peticion
     * @throws InvalidArgumentException si vino con un valor que no existe
     */
    private function descubrimientoDesdePeticion(array $peticion): ?Descubrimiento
    {
        foreach ([Descubrimiento::CLAVE, Descubrimiento::COLUMNA] as $clave) {
            if (array_key_exists($clave, $peticion)) {
                return Descubrimiento::desde($peticion[$clave]);
            }
        }

        return null;
    }

    /**
     * Las secciones que vienen en el payload, ya convertidas a `Nivel`.
     *
     * Se acepta tanto el nombre de la sección (`value`) como el de la columna
     * (`show_value`), que es lo que `Seccion::desde()` ya sabe traducir: el
     * panel manda lo primero, pero copiar el nombre de la columna al formulario
     * es el error más fácil de cometer y no merece un 422 silencioso.
     *
     * @param  array<string, mixed> $peticion
     * @return array<string, Nivel> sección → nivel, solo las que vinieron
     */
    private function cambiosDesdePeticion(array $peticion): array
    {
        $cambios = [];

        foreach (Seccion::cases() as $seccion) {
            foreach ([$seccion->value, $seccion->columna()] as $clave) {
                if (array_key_exists($clave, $peticion)) {
                    $cambios[$seccion->value] = Nivel::desde($peticion[$clave]);
                }
            }
        }

        return $cambios;
    }
}
