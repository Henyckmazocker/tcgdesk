<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * El índice de descriptores ORB por impresión y cara (`mtg_printing_orb`).
 *
 * Tres ideas mandan aquí, y las tres son del M1 del
 * Plan - Reconocimiento de la Impresión por su Arte:
 *
 *  - **Este puerto no guarda bytes, guarda filas.** El bloque binario vive en
 *    `storage/vision/orb/` y lo escribe `OrbDescriptorStore`; lo que llega aquí
 *    es su ruta relativa, igual que `mtg_image_cache.local_path`. La BD ya pesa
 *    3,28 GB con un `innodb_buffer_pool_size` de 128 MB: 28.000 B por cara
 *    dentro de una fila es exactamente lo que no cabe.
 *  - **Se siembra, nunca se sobrescribe.** `sembrar()` es un `INSERT IGNORE` y
 *    devuelve si de verdad insertó. El binario lo sube un cliente y acaba en una
 *    tabla compartida del catálogo: el primero que siembra una impresión la
 *    siembra, y ningún cliente posterior puede reemplazar unos descriptores
 *    buenos por otros malos.
 *  - **`estadoDe()` existe porque `INSERT IGNORE` se traga demasiado.** También
 *    ignora la violación de clave ajena, así que un `printing_uuid` que no está
 *    en el catálogo devolvería «no inserté» —indistinguible de «ya estaba»— y esa
 *    carta no se sembraría nunca, en silencio. La comprobación de que la
 *    impresión existe va **antes y explícita**, y por eso es un método de este
 *    puerto y no un comentario en el controller.
 *
 * **La tabla es reconstruible y prescindible**, como `mtg_image_cache`: si
 * alguna vez se sospecha que está envenenada se vacía y se vuelve a sembrar
 * escaneando, y mientras tanto el escáner sigue identificando por nombre.
 *
 * ## EL IDIOMA ES LA TERCERA COLUMNA DE LA CLAVE (M6, 2026-09-16)
 *
 * La clave era `(printing_uuid, face)` y ahora es `(printing_uuid, face,
 * language)`, con el **nombre largo de MTGJSON** (`'Spanish'`, nunca `'es'`), el
 * mismo vocabulario de `mtg_collection_item.language`. No es una etiqueta:
 * sembrar la imagen inglesa para una carta española tira el **70,9 %** de sus
 * keypoints —los de la caja de reglas— y ORB acaba decidiendo solo por la
 * ilustración, que es la misma en todas las reimpresiones. Medido sobre
 * `RTR 226`: margen 1,34 contra la referencia inglesa y **2,19** contra la
 * española.
 *
 * Las 2.638 filas sembradas antes de ese día quedaron etiquetadas `'English'`,
 * que es literalmente lo que son, y **sus rutas no se renombraron**.
 */
interface OrbDescriptorRepositoryInterface
{
    /**
     * Las referencias de todas las impresiones de una carta, sembradas o no.
     *
     * **Devuelve una fila por impresión aunque no haya descriptores**, y ese es
     * el punto entero del método: el móvil necesita saber qué impresiones le
     * faltan para ir a sembrarlas. Una impresión sin sembrar viene con
     * `localPath`, `keypoints` y `nfeatures` a `null`, que es lo que el
     * controller traduce a `orb: null`.
     *
     * Una impresión con las **dos caras** sembradas devuelve **dos filas**, una
     * por cara: las 1.652 impresiones de doble cara del catálogo tienen dos
     * ilustraciones y cada una necesita sus descriptores.
     *
     * El orden es por `uuid` y luego por cara (`front` antes que `back`, que es
     * el orden del ENUM) para que la respuesta sea estable entre llamadas.
     *
     * ## `$language` y la CAÍDA AL INGLÉS
     *
     * Sirve la fila **del idioma pedido** y, si esa impresión no está sembrada
     * en él, **la inglesa**; el `language` que devuelve dice cuál de las dos
     * mandó. Es lo que hace que este cambio **nunca empeore** lo de hoy: una
     * carta inglesa dentro de una colección española sigue certificando igual
     * que antes, y una carta sin sembrar en ningún idioma sale con `localPath` a
     * `null` y el idioma **pedido**, que es en el que hay que sembrarla.
     *
     * Y `scryfallId` es el de la traducción (`mtg_printing_localized`) cuando
     * MTGJSON lo publica, o el inglés de `mtg_printing` cuando no. Va aparte del
     * `language` de la fila a propósito: el id dice qué imagen hay que bajar
     * para sembrar, y el `language` qué referencia se está sirviendo mientras
     * tanto.
     *
     * ## `scryfallLanguage`: EL IDIOMA DE LA IMAGEN, QUE NO ES EL PEDIDO
     *
     * **Son TRES idiomas distintos en la misma fila y conviene no confundirlos**:
     * el pedido (`$language`), el de la referencia que se sirve (`language`) y el
     * de la imagen que hay que bajar (`scryfallLanguage`). Este último es el
     * pedido cuando `mtg_printing_localized` tiene fila, y **`'English'` cuando el
     * `scryfallId` cae al de `mtg_printing`**.
     *
     * No es decoración: **57.341 de las 110.384 impresiones (el 52 %) no tienen
     * fila en español**, así que sin este campo el cliente bajaría la imagen
     * inglesa y sellaría los descriptores como `'Spanish'`. Con `INSERT IGNORE`
     * —que existe para que nadie sobrescriba descriptores buenos— esa fila
     * **bloquearía para siempre** la siembra correcta. Es el envenenamiento del
     * índice entrando por la puerta de nuestro propio cliente. El cliente siembra
     * con ESTE valor, nunca con el que pidió.
     *
     * @return list<array{
     *     printingUuid: string,
     *     scryfallId: string|null,
     *     scryfallLanguage: string,
     *     face: string,
     *     language: string,
     *     nfeatures: int|null,
     *     keypoints: int|null,
     *     localPath: string|null
     * }>
     */
    public function refsDe(string $oracleId, string $language): array;

    /**
     * ¿Existe esa impresión, y está ya sembrada esa cara?
     *
     * Las dos preguntas van juntas en una sola consulta porque son la misma
     * decisión —¿se puede sembrar esto?— y porque separarlas invitaría a
     * saltarse la primera, que es justo la que `INSERT IGNORE` no hace.
     *
     * **`sembrada` es por idioma**: una impresión con su cara frontal ya sembrada
     * en inglés sigue sin sembrar en español, y tiene que poder sembrarse. Lo
     * contrario —mirar solo `(uuid, face)`— devolvería 409 y dejaría el índice
     * congelado en el idioma del primero que pasó por ahí.
     *
     * @return array{existe: bool, sembrada: bool}
     */
    public function estadoDe(string $printingUuid, string $face, string $language): array;

    /**
     * Anota una cara sembrada. **`INSERT IGNORE`: no sobrescribe jamás.**
     *
     * @param  string $rutaRelativa Relativa a `storage/`, como `mtg_image_cache.local_path`
     * @return bool `true` si insertó; `false` si esa cara ya estaba sembrada
     *              (el 409 de `vision_orb_store`)
     */
    public function sembrar(
        string $printingUuid,
        string $face,
        string $language,
        int $nfeatures,
        int $keypoints,
        string $rutaRelativa
    ): bool;

    /**
     * Deshace una siembra. **Solo para la vuelta atrás, no es una operación de
     * catálogo.**
     *
     * La fila se escribe antes de que el fichero esté en su sitio definitivo, y
     * si el renombrado final falla hay que quitarla: una fila que apunta a un
     * fichero que no existe es una impresión que el móvil cree sembrada y que
     * `vision_orb_store` se negaría a volver a sembrar con un 409, para siempre.
     */
    public function olvidar(string $printingUuid, string $face, string $language): void;
}
