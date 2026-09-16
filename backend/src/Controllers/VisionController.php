<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Collection\CardLanguage;
use App\Domain\Repository\OrbDescriptorRepositoryInterface;
use App\Infrastructure\Vision\OrbDescriptorStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Las dos acciones del índice ORB: **`scan_orb_refs`** y **`vision_orb_store`**.
 *
 * ```
 * MÓVIL (opencv.js, la ÚNICA visión del proyecto)     BACKEND (aquí, CERO visión)
 * ¿tengo referencias de esta carta?  ───────────────▶ scan_orb_refs (oracleId)
 *    └─ NO: GET /api/images/{scryfallId}  ◀─────────    └─ una fila por impresión,
 *           → ORB sobre CADA referencia                    con `orb` o con null
 *           → vision_orb_store  ────────────────────▶ INSERT IGNORE + fichero a disco
 * ```
 *
 * **PHP no ejecuta ORB en ningún punto, y eso no es una comodidad: es la única
 * garantía fuerte de que la consulta y la referencia salen del mismo código.**
 * Este proyecto ya pagó la lección contraria — cambiar solo el algoritmo de
 * reescalado movía el dHash **7 bits** contra un umbral de decisión de 4, y por
 * eso la reducción a 9×8 acabó escrita a mano dos veces. Aquí hay una
 * implementación, `frontend/src/services/cardOrb.js`, y las dos puntas la usan.
 *
 * ## EL BINARIO NO SON SOLO DESCRIPTORES
 *
 * Son `keypoints * 40` bytes, **no `keypoints * 32`**: `keypoints*32` de
 * descriptores seguidos de `keypoints*8` de coordenadas (2 float32
 * little-endian por keypoint). El contrato original mandaba «descriptors» a
 * secas y con eso **no se puede calcular el margen** — la tubería es BFMatcher →
 * Lowe → `findHomography(puntosConsulta, puntosReferencia, RANSAC)` → inliers, y
 * `MARGEN_MINIMO` está definido sobre los inliers. Sin las coordenadas de la
 * referencia no hay homografía y no hay nada que comparar con 1,5.
 *
 * El campo se llama **`orb` y no `descriptors` a propósito**: un campo llamado
 * «descriptors» que además lleva coordenadas es exactamente la clase de mentira
 * que cuesta una tarde.
 *
 * ## EL ENVENENAMIENTO DEL ÍNDICE, Y QUÉ LO CONTIENE
 *
 * Que un cliente suba binarios que acaban en una tabla **compartida** del
 * catálogo es un riesgo real. Tres contenciones, todas baratas y las tres aquí:
 *
 *  1. **`vision_orb_store` es una escritura**, así que su ruta va con
 *     `AuthMiddleware` **y** `CsrfMiddleware`, como las 23 escrituras que ya
 *     existen.
 *  2. **Se inserta, nunca se sobrescribe.** El primero que siembra una impresión
 *     la siembra; el segundo recibe un 409 y **el fichero no se toca**.
 *  3. **Validación de forma antes de escribir.** El bloque mide exactamente
 *     `keypoints * 40` bytes, con `0 < keypoints <= nfeatures`. No prueba que
 *     los descriptores sean correctos —nada en PHP puede probarlo— pero descarta
 *     el relleno y acota el tamaño.
 *
 * Y la red de fondo: **la tabla es reconstruible y prescindible**. Si alguna vez
 * se sospecha, se vacía y se vuelve a sembrar escaneando.
 *
 * ## EL IDIOMA, DESDE EL M6 (2026-09-16)
 *
 * Las dos acciones aceptan `language`, **opcional**, con el nombre largo de
 * MTGJSON (`'Spanish'`, nunca `'es'`). **Su ausencia significa `'English'`, que
 * es exactamente el comportamiento de antes**: un cliente viejo sigue
 * funcionando igual, y eso no es cortesía sino la única forma de que el APK que
 * ya está instalado no se quede sin escáner.
 *
 * El motivo está medido: sembrar la imagen inglesa para una carta española tira
 * el **70,9 %** de sus keypoints —los de la caja de reglas— y ORB acaba
 * decidiendo solo por la ilustración, que es la misma en todas las
 * reimpresiones. Sobre `RTR 226`, margen 1,34 con la referencia inglesa y
 * **2,19** con la española.
 *
 * ## `scryfallLanguage`: EL IDIOMA QUE SE PIDE NO ES EL QUE SE RECIBE
 *
 * *(Enmienda del 2026-09-16.)* `scan_orb_refs` devuelve, por ref, **el idioma
 * real de la imagen que manda**: el pedido cuando existe la fila localizada,
 * `'English'` cuando el `scryfallId` cae al de `mtg_printing`. **El cliente
 * sella `vision_orb_store` con ESE valor, jamás con el que pidió.**
 *
 * Sin él, pedir en español una impresión de las **57.341 de 110.384 (el 52 %)**
 * que no tienen fila en español hace que el móvil baje la imagen **inglesa**, le
 * saque descriptores y los suba como **`Spanish`**. Y el daño sería permanente
 * por diseño: con el `INSERT IGNORE` de arriba —que existe justamente para que
 * nadie sobrescriba descriptores buenos— esa fila **bloquea para siempre** la
 * siembra correcta del día que MTGJSON publique la carta en español. Es el
 * envenenamiento que esta misma cabecera dice contener, entrando por la puerta
 * que no miraba: no un cliente malicioso, **el nuestro**.
 */
class VisionController extends BaseController
{
    /**
     * Techo de `nfeatures`, que es también el techo de `keypoints`.
     *
     * Las dos columnas son `SMALLINT UNSIGNED`, así que este es su rango entero
     * y no una política: un valor por encima no se guardaría, reventaría con un
     * `SQLSTATE[22003]` de desbordamiento. Se acota aquí para que salga como 400
     * —la petición es la que está mal— y no como 500.
     *
     * No se acota a los **700** del M0(b) a propósito: `nfeatures` se guarda por
     * fila justamente para que dos calibraciones no se mezclen en silencio, y
     * congelar el valor aquí obligaría a una migración el día que se recalibre.
     */
    public const NFEATURES_MAXIMO = 65535;

    public function __construct(
        private readonly OrbDescriptorRepositoryInterface $orb,
        private readonly OrbDescriptorStore $almacen,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Las referencias ORB de todas las impresiones de una carta.
     *
     * **Devuelve una fila por impresión aunque no tenga descriptores**, con
     * `orb: null`, y eso es el motivo entero de la acción: el móvil necesita
     * saber qué le falta para ir a sembrarlo. La siembra va **fuera del camino
     * de la respuesta** —la carta que la dispara se resuelve por nombre, como se
     * resolvería sin ORB— así que una carta nueva nunca hace esperar al usuario:
     * solo tarda en volverse rápida.
     *
     * **Va inline y no por una GET nueva.** Con `nfeatures=700` son 28.000 B por
     * cara y ~118 KB de base64 para las 3,15 impresiones de media; el repo ya
     * manda contenido grande como string dentro del JSON (`ImportController`,
     * `data.content`) y una quinta divergencia GET cuesta más que 118 KB.
     *
     * @param  array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function orbRefs(array $request): array
    {
        $datos    = is_array($request['data'] ?? null) ? $request['data'] : [];
        $oracleId = $datos['oracleId'] ?? null;

        if (!is_string($oracleId) || trim($oracleId) === '') {
            return $this->errorResponse('Falta `oracleId`.', 422);
        }

        $oracleId = trim($oracleId);

        // El `oracle_id` es un uuid del catálogo y aquí no compone ninguna ruta,
        // pero se filtra igualmente: una cadena arbitraria contra la columna solo
        // puede devolver cero filas, y decir 422 es más honesto que devolver una
        // lista vacía que el móvil leería como «esta carta no tiene impresiones».
        if (!OrbDescriptorStore::esUuidValido($oracleId)) {
            return $this->errorResponse('`oracleId` no tiene forma de uuid.', 422);
        }

        // **Opcional, y su ausencia es `English`**: es lo que hace que el APK ya
        // instalado siga funcionando exactamente igual que antes del M6.
        $idioma = $this->idioma($datos['language'] ?? null);

        if ($idioma === null) {
            return $this->errorResponse('`language` no es un idioma del catálogo.', 422);
        }

        $refs      = [];
        $sembradas = 0;

        foreach ($this->orb->refsDe($oracleId, $idioma->value) as $fila) {
            $bloque = $fila['localPath'] !== null ? $this->almacen->leer($fila['localPath']) : null;

            if ($fila['localPath'] !== null && $bloque === null) {
                // Fila sin fichero: el índice está incompleto, no roto. Se sirve
                // como no sembrada para que el móvil la vuelva a sembrar, y se
                // avisa porque un `storage/` vaciado a mano deja la tabla entera
                // en este estado y nadie lo notaría por la respuesta.
                $this->logger->warning('ORB: fila sin fichero en disco', [
                    'printing_uuid' => $fila['printingUuid'],
                    'face'          => $fila['face'],
                    'language'      => $fila['language'],
                    'local_path'    => $fila['localPath'],
                ]);
            }

            if ($bloque !== null) {
                $sembradas++;
            }

            $refs[] = [
                'printingUuid'     => $fila['printingUuid'],
                'face'             => $fila['face'],
                // **Cuál mandó**, que puede no ser el pedido: si esa impresión
                // solo está sembrada en inglés, aquí pone `English` y el cliente
                // sabe contra qué está casando en vez de creerse que tiene la
                // referencia de su idioma.
                'language'         => $fila['language'],
                'scryfallId'       => $fila['scryfallId'],
                // **Y el idioma de ESA imagen**, que es otra cosa: el
                // `scryfallId` cae al inglés cuando MTGJSON no publica la
                // traducción, y eso pasa en el 52 % del catálogo. El cliente
                // sella la siembra con este campo y nunca con el que pidió; si
                // no, subiría descriptores ingleses etiquetados `Spanish` y el
                // `INSERT IGNORE` de `vision_orb_store` los dejaría ahí para
                // siempre. Es el envenenamiento del índice entrando por nuestro
                // propio cliente, que dice la verdad sobre lo que pidió y no
                // sobre lo que recibió.
                'scryfallLanguage' => $fila['scryfallLanguage'],
                // Los tres van juntos o no va ninguno: `keypoints` sin bloque es
                // una promesa que el cliente no puede cumplir, y partiría el
                // `cv.Mat` por el sitio equivocado si algún día llegara suelto.
                'keypoints'        => $bloque !== null ? $fila['keypoints'] : null,
                'nfeatures'        => $bloque !== null ? $fila['nfeatures'] : null,
                'orb'              => $bloque !== null ? base64_encode($bloque) : null,
            ];
        }

        $this->logger->info('ORB: referencias servidas', [
            'user_id'     => $request['user_id'] ?? null,
            'oracle_id'   => $oracleId,
            'language'    => $idioma->value,
            'impresiones' => count($refs),
            'sembradas'   => $sembradas,
        ]);

        return $this->successResponse('Referencias ORB.', ['refs' => $refs]);
    }

    /**
     * Siembra los descriptores de UNA cara de UNA impresión. **204 · 400 · 409.**
     *
     * El orden de los pasos no es libre y es la mitad del hito:
     *
     *  1. **Forma del payload** → 400. Incluye el `keypoints * 40`, que es lo que
     *     descarta el relleno.
     *  2. **¿Existe la impresión, está ya sembrada?** → 400 / 409, y **sin tocar
     *     el disco**. El 409 tiene que salir de aquí: `INSERT IGNORE` se traga
     *     también el fallo de clave ajena, así que «no inserté» no distingue «ya
     *     estaba» de «ese uuid no existe», y sin esta comprobación una carta con
     *     un uuid mal escrito devolvería 204 y no se sembraría **nunca**, en
     *     silencio.
     *  3. **El bloque, a un `.parcial`.** Patrón de `ScryfallImageDownloader`: si
     *     la petición se corta, lo que queda es un `.parcial` huérfano y no un
     *     bloque truncado que el móvil leería como descriptores válidos.
     *  4. **`INSERT IGNORE`.** Si pierde una carrera con otro cliente, el
     *     `.parcial` se tira y sale 409: **el fichero que ya estaba no se
     *     reescribe**, que es justo lo que el hito exige.
     *  5. **Renombrado.** Y si falla, se quita la fila: una fila que apunta a un
     *     fichero que no existe es una impresión que el móvil cree sembrada y que
     *     esta acción se negaría a volver a sembrar, para siempre.
     *
     * @param  array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function orbStore(array $request): array
    {
        $datos = is_array($request['data'] ?? null) ? $request['data'] : [];

        $printingUuid = $datos['printingUuid'] ?? null;

        if (!is_string($printingUuid) || !OrbDescriptorStore::esUuidValido(trim($printingUuid))) {
            return $this->errorResponse('`printingUuid` falta o no tiene forma de uuid.', 400);
        }

        $printingUuid = trim($printingUuid);

        $face = $datos['face'] ?? null;

        if (!is_string($face) || !in_array($face, OrbDescriptorStore::CARAS, true)) {
            return $this->errorResponse("`face` tiene que ser 'front' o 'back'.", 400);
        }

        // Opcional, y su ausencia es `English`: el cliente anterior al M6 siembra
        // exactamente lo que sembraba, y sus 2.638 filas son inglesas de verdad.
        // El idioma entra en la clave, así que sembrar una cara en español NO
        // pisa la inglesa ni la sustituye: son dos filas y dos ficheros.
        $idioma = $this->idioma($datos['language'] ?? null);

        if ($idioma === null) {
            return $this->errorResponse('`language` no es un idioma del catálogo.', 400);
        }

        $nfeatures = $this->entero($datos['nfeatures'] ?? null);
        $keypoints = $this->entero($datos['keypoints'] ?? null);

        if ($nfeatures === null || $nfeatures < 1 || $nfeatures > self::NFEATURES_MAXIMO) {
            return $this->errorResponse(
                '`nfeatures` tiene que ser un entero entre 1 y ' . self::NFEATURES_MAXIMO . '.',
                400
            );
        }

        // `0 < keypoints <= nfeatures`. El tope de arriba es el que acota el
        // tamaño del fichero: sin él, `keypoints` es quien decide cuántos
        // megabytes se escriben en disco, y lo decide el cliente.
        if ($keypoints === null || $keypoints < 1 || $keypoints > $nfeatures) {
            return $this->errorResponse(
                '`keypoints` tiene que ser un entero entre 1 y `nfeatures` (' . $nfeatures . ').',
                400
            );
        }

        $orb = $datos['orb'] ?? null;

        if (!is_string($orb) || $orb === '') {
            return $this->errorResponse('Falta `orb`: el bloque de descriptores en base64.', 400);
        }

        // `strict`: un base64 con basura dentro devuelve false en vez de
        // ignorarla en silencio, y aquí lo que entra acaba en una tabla
        // compartida del catálogo.
        $bloque = base64_decode($orb, true);

        if ($bloque === false) {
            return $this->errorResponse('`orb` no es base64 válido.', 400);
        }

        $esperados = $keypoints * OrbDescriptorStore::BYTES_POR_KEYPOINT;

        if (strlen($bloque) !== $esperados) {
            // El mensaje dice los 40 en voz alta a propósito: quien mande
            // `keypoints*32` —el contrato viejo, el que se enmendó— ve aquí
            // exactamente por qué le rebota, en vez de un 400 mudo.
            return $this->errorResponse(
                'El bloque mide ' . strlen($bloque) . ' bytes y tenía que medir ' . $esperados
                . ' (' . $keypoints . ' keypoints x ' . OrbDescriptorStore::BYTES_POR_KEYPOINT
                . ' B: 32 de descriptor + 8 de coordenadas).',
                400
            );
        }

        $estado = $this->orb->estadoDe($printingUuid, $face, $idioma->value);

        if (!$estado['existe']) {
            return $this->errorResponse('Esa impresión no existe en el catálogo.', 400);
        }

        if ($estado['sembrada']) {
            return $this->yaSembrada($request, $printingUuid, $face, $idioma->value);
        }

        $escrito = $this->almacen->escribirParcial($printingUuid, $face, $idioma->value, $bloque);

        if (!$this->orb->sembrar(
            $printingUuid,
            $face,
            $idioma->value,
            $nfeatures,
            $keypoints,
            $escrito['relativa']
        )) {
            // Carrera perdida contra otro cliente entre `estadoDe()` y aquí. El
            // `.parcial` se tira y el fichero bueno se queda como estaba.
            $this->almacen->descartarParcial($escrito['parcial']);

            return $this->yaSembrada($request, $printingUuid, $face, $idioma->value);
        }

        try {
            $this->almacen->publicar($escrito['parcial']);
        } catch (Throwable $e) {
            $this->orb->olvidar($printingUuid, $face, $idioma->value);

            throw $e;
        }

        $this->logger->info('ORB: impresión sembrada', [
            'user_id'       => $request['user_id'] ?? null,
            'printing_uuid' => $printingUuid,
            'face'          => $face,
            'language'      => $idioma->value,
            'nfeatures'     => $nfeatures,
            'keypoints'     => $keypoints,
            'bytes'         => $esperados,
            'ruta'          => $escrito['relativa'],
        ]);

        return $this->successResponse('Descriptores sembrados.', null, 204);
    }

    /**
     * El 409, con su log.
     *
     * No es un error del cliente ni hay nada que arreglar: es el caso normal de
     * dos móviles —o dos escaneos— que llegan a la misma impresión. Se registra
     * en `info` porque es lo que dirá, cuando el índice esté maduro, que la
     * siembra dejó de costar peticiones.
     *
     * @param  array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function yaSembrada(
        array $request,
        string $printingUuid,
        string $face,
        string $language
    ): array {
        $this->logger->info('ORB: siembra descartada, esa cara ya estaba', [
            'user_id'       => $request['user_id'] ?? null,
            'printing_uuid' => $printingUuid,
            'face'          => $face,
            'language'      => $language,
        ]);

        return $this->errorResponse('Esa cara ya está sembrada en ese idioma: no se sobrescribe.', 409);
    }

    /**
     * El `language` del payload → `CardLanguage`, o `null` si no es uno.
     *
     * **La ausencia no es un error: es `English`**, que es el comportamiento
     * anterior al M6 y lo que mantiene vivo al cliente que ya está instalado. Lo
     * que sí es error es traer un idioma que el catálogo no conoce, y por eso
     * `null` significa «vino algo, y no vale» y nunca «no vino nada».
     *
     * Va por `CardLanguage` y no por una comparación suelta por dos motivos: es
     * la **lista cerrada** de 18 valores que impide que un `../` del cliente
     * llegue a componer el nombre del fichero, y es la que normaliza `'es'` y
     * `'spanish'` al `'Spanish'` que guarda la columna — tres formas del mismo
     * idioma serían tres filas de la misma cara y tres ficheros.
     */
    private function idioma(mixed $valor): ?CardLanguage
    {
        if ($valor === null || $valor === '') {
            return CardLanguage::porDefecto();
        }

        return CardLanguage::intentar($valor);
    }

    /**
     * Entero del cliente → `int`, o null si no lo era.
     *
     * Acepta la cadena numérica porque un JSON de JavaScript manda a veces
     * `"700"`, pero **no** acepta `"700 "` ni `7.5`: `is_numeric()` con
     * comprobación de que el valor sobrevive el viaje de ida y vuelta es lo que
     * impide que un `700.9` se convierta en 700 sin que nadie lo decida.
     */
    private function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (is_string($valor) && $valor !== '' && ctype_digit($valor)) {
            return (int) $valor;
        }

        return null;
    }
}
