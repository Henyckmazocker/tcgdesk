<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Repository\UserPrivacyRepositoryInterface;

/**
 * Quién puede ver qué de quién. **Toda la seguridad del Plan - Perfil Público y
 * Mazos Compartibles cabe aquí, y por eso vive en un solo sitio.**
 *
 * La regla de oro del plan, literal: *ningún use case de este plan consulta
 * `user_privacy_settings` por su cuenta; todos pasan por aquí*. No es estética
 * de capas. Cinco comprobaciones repartidas por cinco use cases es cómo se acaba
 * filtrando una sección: basta con que una de ellas olvide el caso `friends`, o
 * lo interprete al revés, para publicar la colección de alguien — y el fallo es
 * silencioso, porque una respuesta con datos de más parece perfectamente
 * correcta.
 *
 * Desde el M2 del Plan - Amigos y Seguimiento, el nivel `friends` **ya no es
 * inerte**: lo resuelve `FriendshipRepositoryInterface::sonAmigos()`, y esa es
 * la única línea de lógica que aquel plan tocó en todo el backend. La otra tabla
 * que ese plan crea, `user_follow`, **no aparece en esta clase y no puede
 * aparecer**: seguir un perfil es un marcador unilateral que no da ningún
 * acceso.
 *
 * Lo que **no** decide esta clase: el mazo compartido por enlace. Un
 * `share_token` es un acto explícito sobre ese mazo concreto y no pregunta por
 * la privacidad del perfil (M4 del plan). Aquí solo se resuelven las cinco
 * secciones de `Seccion`.
 */
class Visibilidad
{
    /**
     * Los niveles ya leídos en esta petición, por `user_id`.
     *
     * La ruta del perfil pregunta por las cinco secciones del mismo dueño para
     * saber cuáles enseñar; sin esto serían cinco consultas idénticas en una
     * ruta abierta a internet y con rate limit. El objeto lo construye el
     * contenedor por petición, así que la caché muere con ella: un `privacy_set`
     * es otra petición y no puede leer una privacidad rancia.
     *
     * @var array<int, array<string, Nivel>>
     */
    private array $cache = [];

    /**
     * `$amistades` entró aquí en el M0 del Plan - Amigos y Seguimiento, sin
     * usarse, para poder escribir la tabla de verdad de `puedeVer()` antes que
     * el código. Desde el M2 es quien responde la regla 4.
     *
     * **Y es OBLIGATORIO, sin `?` y sin `= null`. Eso no es estilo: es la
     * diferencia entre que la amistad funcione y que parezca funcionar.**
     * `Visibilidad` no está declarada en `config/container.php`; la construye el
     * autowiring de PHP-DI cuando monta `PublicHttpRouter`. Y PHP-DI **se salta
     * los parámetros opcionales** al autoinyectar
     * (`ReflectionBasedAutowiring::getParametersDefinition()`, «Skip optional
     * parameters»): con un defecto aquí, el contenedor no inyectaría nada,
     * `$this->amistades` sería `null` **en producción** y la regla 4 no
     * consultaría la tabla — mientras la suite sigue en verde, porque en los
     * tests el doble se pasa a mano. Un fallo que sale verde.
     *
     * En el M0 y el M1 tuvo que ser opcional por el motivo contrario: la
     * interfaz no tenía implementación registrada, así que un parámetro
     * obligatorio habría hecho que PHP-DI intentara instanciar una interfaz y
     * toda ruta pública muriera. Eso se acabó cuando el M2 registró
     * `FriendshipRepositoryInterface => MySqlFriendshipRepository` en el
     * contenedor — las dos cosas van juntas y quitar una sin la otra rompe algo,
     * en un sentido o en el otro.
     */
    public function __construct(
        private readonly UserPrivacyRepositoryInterface $privacidad,
        private readonly FriendshipRepositoryInterface $amistades
    ) {
    }

    /**
     * ¿Puede $espectador (o nadie, si es null) ver esta sección de $duenyo?
     *
     * Las cuatro reglas, y el orden importa:
     *
     *  1. **Eres tú** → true, pase lo que pase. Tu propio perfil se ve entero, y
     *     va la primera porque si no, poner una sección en `nobody` te la
     *     escondería a ti mismo y parecería que se han borrado los datos.
     *  2. `nobody` → false. Ni con sesión ni sin ella.
     *  3. `everyone` → true, **también sin sesión**: quien abre el enlace no
     *     tiene cuenta, y ese es el punto entero del plan del perfil público.
     *  4. `friends` → **amistad `accepted`, y nada más**. Es la única línea que
     *     el Plan - Amigos y Seguimiento cambió aquí, y donde se concentra todo
     *     lo que ese plan podía romper: una solicitud `pending` NO cuenta, y
     *     seguir a alguien tampoco —`user_follow` no aparece en esta clase, en
     *     ningún sitio; si apareciera, `friends` pasaría a significar
     *     «cualquiera que pulse seguir», o sea, `everyone`—.
     *
     * Un espectador `null` es «nadie, sin sesión». Nunca casa con la regla 1: el
     * `id` de un usuario es siempre un entero, así que no hay forma de que un
     * anónimo se cuele como dueño de nada. Y por eso la regla 4 lo comprueba
     * antes de preguntar: sin sesión no hay amistad posible, y llamar al
     * repositorio con un `null` no tendría ni tipo.
     */
    public function puedeVer(int $duenyoId, ?int $espectadorId, Seccion $seccion): bool
    {
        // 1. Tú siempre te ves a ti mismo.
        if ($espectadorId !== null && $espectadorId === $duenyoId) {
            return true;
        }

        $nivel = $this->nivelDe($duenyoId, $seccion);

        return match (true) {
            // 2. Nadie es nadie.
            $nivel === Nivel::Nadie => false,

            // 4. Amigos: SOLO una amistad `accepted`. Sigue yendo ANTES que el
            //    caso de `everyone` para que no haya forma de llegar a un `true`
            //    por defecto — lo único que devuelve true aquí es el nivel que
            //    lo dice explícitamente, o esta consulta.
            //
            //    Hasta el M2 esta rama era `$nivel->esInerte() => false`, con
            //    `Nivel::esInerte()` diciendo «friends no puede decir que sí a
            //    nadie todavía». Ese método **se borró al enchufar la amistad**,
            //    y tenía que borrarse: dejarlo en pie habría cortado el
            //    `match(true)` aquí mismo y la consulta de abajo no se habría
            //    ejecutado nunca. Habría fallado cerrado —nadie vería de más—
            //    pero el nivel `friends` habría seguido sin servir para nada, y
            //    la suite habría salido verde igual.
            $nivel === Nivel::Amigos => $espectadorId !== null
                && $this->amistades->sonAmigos($duenyoId, $espectadorId),

            // 3. Todos es todos, con sesión o sin ella.
            $nivel === Nivel::Todos => true,

            // Un nivel nuevo que nadie haya enseñado a responder cae aquí, y
            // cae a false. Es la diferencia entre añadir una columna al ENUM y
            // publicar una sección sin querer.
            default                 => false,
        };
    }

    /** El nivel que el dueño tiene puesto en esa sección, leído una sola vez. */
    private function nivelDe(int $duenyoId, Seccion $seccion): Nivel
    {
        $niveles = $this->cache[$duenyoId] ??= $this->privacidad->nivelesDe($duenyoId);

        // El repositorio promete las cinco secciones siempre; el defecto de la
        // sección es la red de seguridad por si algún día promete mal, y para
        // `Valor` y `Deseos` ese defecto es `friends`, o sea, cerrado.
        return $niveles[$seccion->value] ?? $seccion->nivelPorDefecto();
    }
}
