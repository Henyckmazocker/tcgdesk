<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\ResolveCards;
use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Import\CardResolution;
use App\Domain\Import\ParsedRow;
use App\Domain\Repository\CardRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * La única acción del escáner de cámara: **`scan_resolve`**.
 *
 * ```
 * MÓVIL                                   BACKEND (aquí)
 * ML Kit → bloques de texto
 * parser esquina → {name, set, nº, idioma} ──▶ ParsedRow ──▶ ResolveCards
 *                                                              └─▶ veredictos
 * menú inferior  ◀────────────── results[] ◀── + ficha de catálogo por lote
 * ```
 *
 * **No reimplementa ni una regla de identificación.** El resolvedor de cuatro
 * pasos y la elección de edición asumida ya existen, probados, detrás del use
 * case `ResolveCards` — el mismo que invoca `ImportController::preview()`. Que
 * el escáner no necesite tocarlos es justo la prueba de que el diseño es el
 * correcto: un OCR que exigiera cambiar el resolvedor sería un resolvedor
 * escrito para ficheros y no para cartas.
 *
 * ## AQUÍ HUBO UNA VÍA VISUAL, Y NO VUELVE
 *
 * Hasta el 2026-09-15 cada lectura podía traer un hash perceptual de 64 bits del
 * recorte de la carta, que se barría por distancia de Hamming contra
 * `mtg_printing_hash` **antes** de construir un solo `ParsedRow`. Se borró entera
 * con el M1 del Plan - Escáner de Cartas por Cámara, tabla incluida, porque está
 * medida y muerta: una foto real queda a **11-12 bits** de su propia referencia
 * contra un margen mediano de **6** en un índice de 111.819, y sobre 8 cartas la
 * correcta salió en los puestos **#5 a #3218** — cero aciertos. Ni más bits ni
 * restringir el barrido por nombre lo rescatan, y las siete tandas de medidas
 * están íntegras en el `## 📅 Log` de ese plan.
 *
 * Lo que sobrevivió a esa caída —y a la del par `(edición, número)` antes que
 * ella— es **el nombre**: el OCR lo lee bien el 80 % de las veces. Por eso hoy
 * este controller solo cose una vía, la de texto, y la identificación de la
 * **impresión** se certifica por otros medios en vez de adivinarse.
 *
 * **No escribe nada**, ni en la colección ni en el catálogo: este controller no
 * conoce el repositorio de la colección. Meter la carta es `collection_add` y
 * `deck_card_add`, que ya existían y que llama el cliente cuando el usuario
 * confirma. Por eso la ruta va sin `CsrfMiddleware`, como toda lectura del repo.
 *
 * ## El contraste cruzado del paso 2 es un regalo, no un estorbo
 *
 * `CardResolver` manda a conflicto `mismatch` la lectura cuyo nombre y cuyo
 * `(set, número)` no dicen la misma carta. Con OCR es exactamente lo que se
 * quiere: un dígito mal leído no apunta a la nada, apunta a **otra carta que
 * existe** —`Lim-Dûl's Vault (ICE) 96` es *Shyft*—, y sin el contraste se
 * escribiría en la colección en silencio. Por eso el parser del móvil manda
 * siempre el nombre aunque haya leído el número.
 *
 * ## Por qué hace falta una segunda lectura de catálogo
 *
 * Porque una fila resuelta de `ResolveCards` **no trae `collectorNumber`, ni
 * `finishes`, ni `priceEur`** —no los necesita quien importa un fichero— y son
 * los tres que el menú del escáner necesita: el precio para enseñarlo, y los
 * acabados para decidir el del ejemplar (si la impresión solo admite uno, ese
 * gana sobre el ajuste de sesión, porque el catálogo lo sabe mejor). Se piden
 * **en una sola consulta para todas las lecturas resueltas**, nunca una por
 * carta: una página de binder son nueve.
 */
class ScanController extends BaseController
{
    /**
     * Techo de lecturas por petición.
     *
     * **La ruta no lleva `ValidationMiddleware`** —es una lectura, y ese
     * middleware solo sabe mirar si un campo está—, así que el acotado lo hace
     * esta clase o no lo hace nadie: `lecturas` es un array de longitud
     * arbitraria que sale de un cliente, y una llamada con 10.000 entradas ata
     * el resolvedor durante toda la petición.
     *
     * 60 y no 9: el modo binder manda una página —nueve— y lo que se quiere
     * dejar abierto es mandar varias páginas juntas si alguna vez conviene. Nada
     * legítimo pasa de unas pocas decenas.
     */
    public const MAXIMO_LECTURAS = 60;

    public function __construct(
        private readonly ResolveCards $resolver,
        private readonly CardRepositoryInterface $cartas,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resuelve un lote de lecturas del escáner contra el catálogo. **Sin escribir.**
     *
     * Cada lectura trae `id` y, todo lo demás opcional, lo que el OCR creyese
     * leer: nombre, código de edición, número de coleccionista e idioma. **Todas
     * siguen el mismo camino y no hay ramas**: una `ParsedRow` por lectura y un
     * solo `ResolveCards` para el lote entero.
     *
     * Hubo tres ramas mientras existió el barrido por hash, y el que las lea en
     * el historial debe saber que no se echan de menos: la vía visual se borró
     * con el M1 del Plan - Escáner de Cartas por Cámara por no acertar nunca, y
     * lo que resolvía de verdad —el nombre— es justo lo que este camino ya hacía.
     *
     * @param  array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function resolve(array $request): array
    {
        $datos    = is_array($request['data'] ?? null) ? $request['data'] : [];
        $lecturas = $datos['lecturas'] ?? null;

        if (!is_array($lecturas) || $lecturas === []) {
            return $this->errorResponse('No hay nada que resolver: `lecturas` llegó vacío.', 422);
        }

        if (count($lecturas) > self::MAXIMO_LECTURAS) {
            return $this->errorResponse(
                'Llegaron ' . count($lecturas) . ' lecturas y el máximo por petición son '
                . self::MAXIMO_LECTURAS . '. Pártelo en varias.',
                422
            );
        }

        /** @var list<ParsedRow> $filas */
        $filas = [];
        /** @var array<int, string> $ids índice de la detección → id del cliente */
        $ids = [];
        /** @var array<int, array<string, mixed>> $porLinea */
        $porLinea = [];

        foreach (array_values($lecturas) as $i => $lectura) {
            if (!is_array($lectura)) {
                return $this->errorResponse('La lectura en la posición ' . $i . ' no es un objeto.', 422);
            }

            $ids[$i] = $this->idDe($lectura, $i);
            $filas[] = $this->aFila($lectura, $i);
        }

        $salida = ($this->resolver)($filas);

        // Una sola consulta para TODAS las resueltas. `porUuids()` deduplica por
        // su cuenta, así que la misma carta leída dos veces en la misma tanda no
        // se pregunta dos veces.
        $fichas = $this->cartas->porUuids($this->uuidsDe($salida['resolved']));

        foreach ($salida['resolved'] as $fila) {
            $porLinea[(int) $fila['line']] = $this->aVeredictoResuelto($fila, $fichas);
        }

        foreach ($salida['conflicts'] as $fila) {
            $porLinea[(int) $fila['line']] = $this->aVeredictoEnConflicto($fila);
        }

        // `ResolveCards` devuelve dos montones y el cliente espera una lista.
        // Se reordena por el índice de la detección para devolver las lecturas
        // en el mismo orden en que llegaron: el `id` las emparejaría igual, pero
        // un orden estable es una fuente menos de parpadeo en el menú.
        ksort($porLinea);

        $resultados = [];
        foreach ($porLinea as $linea => $veredicto) {
            // El `id` va el primero y es lo único que pone el cliente: vuelve
            // intacto porque es lo que empareja cada veredicto con su fila del
            // menú cuando el usuario ya ha tocado tres cosas más.
            $resultados[] = ['id' => $ids[$linea]] + $veredicto;
        }

        // Los totales son los de la PETICIÓN y no los de `ResolveCards`, aunque
        // desde que se fue la vía del hash vuelvan a coincidir: `count($lecturas)`
        // es el denominador honesto —lo que el usuario apuntó con la cámara— y el
        // día que otra vía vuelva a resolver sin pasar por el resolvedor, seguirá
        // siéndolo sin que nadie tenga que acordarse de cambiarlo.
        $resueltas = count(array_filter($porLinea, static fn (array $v): bool => $v['resolved'] === true));

        $this->logger->info('Lecturas del escáner resueltas', [
            'user_id'   => $request['user_id'] ?? null,
            'total'     => count($lecturas),
            'resolved'  => $resueltas,
            'conflicts' => count($porLinea) - $resueltas,
            'assumed'   => $salida['summary']['assumedCount'],
        ]);

        return $this->successResponse('Lecturas resueltas.', ['results' => $resultados]);
    }

    /**
     * Una lectura del OCR → la fila que entiende el resolvedor.
     *
     * Tres decisiones que no se ven en la firma:
     *
     *  - **`scryfallId` va a null siempre.** No está impreso en ninguna carta,
     *    así que el paso 1 del resolvedor nunca entra por aquí y la resolución
     *    empieza de verdad en el paso 2.
     *  - **`quantity` es 1.** Escanear es apuntar a UN ejemplar; las copias
     *    repetidas las suma el menú con su botón `+1`, no esta acción.
     *  - **Las tres dimensiones se aceptan y NO se exigen.** Vienen del ajuste
     *    de sesión y de lo que el OCR leyese del bloque de la esquina; lo que no
     *    llegue, o llegue ilegible, cae al valor por defecto en vez de tumbar la
     *    petición entera. Se usa `intentar()` y no `desde()` a propósito: un
     *    idioma mal leído en una carta de nueve no puede perder las otras ocho.
     *    El `finish` además importa para la resolución, porque la edición
     *    asumida se elige por (carta, acabado).
     *
     * `rarity` viaja en el contrato de la petición y **no se usa aquí**: el
     * resolvedor no la mira. Se lee en el móvil porque está impresa junto al
     * número y sirve para que el usuario reconozca la carta en el menú.
     *
     * @param array<string, mixed> $lectura
     */
    private function aFila(array $lectura, int $indice): ParsedRow
    {
        return new ParsedRow(
            scryfallId: null,
            name: $this->texto($lectura['name'] ?? null),
            setCode: $this->texto($lectura['setCode'] ?? null),
            collectorNumber: $this->texto($lectura['collectorNumber'] ?? null),
            finish: (Finish::intentar($lectura['finish'] ?? null) ?? Finish::porDefecto())->value,
            language: (CardLanguage::intentar($lectura['language'] ?? null) ?? CardLanguage::porDefecto())->value,
            condition: (Condition::intentar($lectura['condition'] ?? null) ?? Condition::porDefecto())->value,
            quantity: 1,
            // La "línea de origen" de un escaneo es el índice de la detección:
            // es lo que `ResolveCards` devuelve en cada fila y lo único con lo
            // que se puede volver al `id` que puso el cliente.
            sourceLine: $indice,
        );
    }

    /**
     * El `id` que puso el cliente, o el índice si no puso ninguno.
     *
     * No se inventa un identificador propio: el cliente ya tiene el suyo pegado
     * a la fila del menú, y devolverle otro le obligaría a mantener dos.
     *
     * @param array<string, mixed> $lectura
     */
    private function idDe(array $lectura, int $indice): string
    {
        $id = $lectura['id'] ?? null;

        if (is_string($id) && trim($id) !== '') {
            return $id;
        }

        if (is_int($id)) {
            return (string) $id;
        }

        return (string) $indice;
    }

    /**
     * Los `printingUuid` de las filas resueltas, para preguntarlos de una vez.
     *
     * @param  list<array<string, mixed>> $resueltas
     * @return list<string>
     */
    private function uuidsDe(array $resueltas): array
    {
        $uuids = [];

        foreach ($resueltas as $fila) {
            $uuid = $fila['printingUuid'] ?? null;

            if (is_string($uuid) && $uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    /**
     * Fila resuelta de `ResolveCards` + ficha de catálogo → veredicto del contrato.
     *
     * `step` viaja **tal cual lo da el resolvedor**, que es como ya lo sirve
     * `/import`: es `'1'`, `'2'`, `'3'`, `'3b'` o `'4'`, y `'3b'` —el reintento
     * por la cara frontal de una carta de doble cara— no es un número. Pasarlo a
     * entero perdería ese paso, que es justo el que distingue "casó el nombre
     * entero" de "casó solo la cara de delante".
     *
     * Si la ficha no está —un uuid que el catálogo ya no tiene— los tres campos
     * de catálogo van a null en vez de romper el lote: la carta sigue resuelta,
     * lo que falta es el adorno.
     *
     * @param  array<string, mixed> $fila
     * @param  array<string, array<string, mixed>> $fichas
     * @return array<string, mixed>
     */
    private function aVeredictoResuelto(array $fila, array $fichas): array
    {
        $uuid  = is_string($fila['printingUuid'] ?? null) ? $fila['printingUuid'] : null;
        $ficha = $uuid !== null ? ($fichas[$uuid] ?? null) : null;

        $fuente = $this->fuenteDeCerteza($fila);

        return [
            'resolved'        => true,
            'printingUuid'    => $fila['printingUuid'],
            'oracleId'        => $fila['oracleId'],
            'name'            => $fila['name'],
            'setCode'         => $fila['setCode'],
            'collectorNumber' => $ficha['collectorNumber'] ?? null,
            'finishes'        => $ficha['finishes'] ?? null,
            'priceEur'        => $ficha['priceEur'] ?? null,
            'assumedPrinting' => $fila['assumedPrinting'],
            'printingCount'   => $fila['printingCount'],
            'step'            => $fila['step'],
            'reason'          => null,
            'candidates'      => [],
            'printingCertain' => $fuente !== null,
            'certaintySource' => $fuente,
            // El idioma que DETECTÓ el nombre, o null. Ver `aVeredictoResuelto()`.
            'language'        => $this->idiomaDetectado($fila),
        ];
    }

    /**
     * ¿Está la IMPRESIÓN cerrada, y por qué? La verja del modo manos libres.
     *
     * Devuelve **la fuente** y no un booleano porque lo que se está montando aquí
     * es una lista abierta de fuentes de certeza, no una condición:
     *
     *  - **`single`** — la carta tiene una sola impresión (`printingCount === 1`).
     *    No hay nada que asumir: el catálogo la cierra solo.
     *  - **`corner`** — alguna vuelta leyó el bloque de la esquina y el resolvedor
     *    cerró por los pasos `1`, `2` o `2b`, que son los que identifican la
     *    impresión y no la carta.
     *  - **`art`** — ORB identificó la impresión mirando la ilustración.
     *    **Hoy no lo emite nadie**, y eso es a propósito: lo emitirá el
     *    Plan - Reconocimiento de la Impresión por su Arte, y que la rama ya
     *    exista es la diferencia entre que aquel plan encaje y que tenga que
     *    refactorizar este.
     *
     * Lo que NO es certeza: los pasos `3`, `3b`, `3c`, `4` y `5` resuelven **la
     * carta**, no la impresión. Cuando esa carta tiene siete ediciones, el
     * resolvedor asume una y `assumedPrinting` lo dice; escribirla sola sería
     * meter en la colección una edición inventada, que es exactamente lo que la
     * verja existe para impedir.
     *
     * @param  array<string, mixed> $fila
     * @return string|null `single`, `corner`, `art`, o null si no hay certeza
     */
    private function fuenteDeCerteza(array $fila): ?string
    {
        // ## EL ORDEN IMPORTA, Y NO ES EL INTUITIVO
        //
        // `printingCount` NO significa «cuántas impresiones tiene la carta»:
        // significa **entre cuántas se eligió**, y vale 1 también cuando no hubo
        // nada que elegir porque la lectura ya traía la impresión
        // (`ResolveCards.php:141`). Así que una carta con 130 ediciones leída por
        // su bloque de esquina llega aquí con `printingCount === 1`.
        //
        // Las dos son certeza y el booleano saldría igual, pero la FUENTE
        // mentiría: diría `single` —«esta carta solo se imprimió una vez»— de una
        // *Lightning Bolt*. Y la fuente no es decorativa: es lo que el menú
        // enseña y lo que el plan de ORB va a extender. Se mira primero **cómo se
        // resolvió**, y `single` queda para cuando de verdad no había otra.
        $fuentePorPaso = match ($fila['step'] ?? null) {
            '1', '2', '2b' => 'corner',
            default        => null,
        };

        if ($fuentePorPaso !== null) {
            return $fuentePorPaso;
        }

        return ($fila['printingCount'] ?? null) === 1 ? 'single' : null;
    }

    /**
     * Fila en conflicto → veredicto del contrato, con **la misma forma**.
     *
     * Las dos formas tienen exactamente las mismas claves y solo cambian los
     * valores: el menú pinta una lista, no dos, y un cliente que tuviera que
     * mirar qué claves existen antes de leerlas es un cliente que se rompe el
     * día que una carta deja de resolver.
     *
     * `reason` toma los mismos cuatro valores que `/import` —`ambiguous`,
     * `not_found`, `invalid`, `mismatch`— y `candidates` la misma forma que
     * consume `ImportCandidate.vue`. Cero conceptos nuevos: el escáner reutiliza
     * la taxonomía de conflictos que la importación ya tenía probada.
     *
     * El `name` sale del crudo porque es lo único que se puede enseñar de una
     * lectura que no resolvió: lo que el OCR creyó leer.
     *
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aVeredictoEnConflicto(array $fila): array
    {
        $crudo = is_array($fila['raw'] ?? null) ? $fila['raw'] : [];

        return [
            'resolved'        => false,
            'printingUuid'    => null,
            'oracleId'        => null,
            'name'            => $this->texto($crudo['name'] ?? null),
            'setCode'         => null,
            'collectorNumber' => null,
            'finishes'        => null,
            'priceEur'        => null,
            'assumedPrinting' => false,
            'printingCount'   => 0,
            'step'            => null,
            'reason'          => $fila['reason'],
            'candidates'      => $fila['candidates'],
            // Una fila que ni siquiera resolvió la carta no puede tener cerrada
            // la impresión. Van igualmente porque las dos formas del contrato
            // tienen SIEMPRE las mismas claves.
            'printingCertain' => false,
            'certaintySource' => null,
            // Una lectura que no se ha entendido no declara idioma. Va igual,
            // porque las dos formas del contrato tienen SIEMPRE las mismas claves.
            'language'        => null,
        ];
    }

    /**
     * El idioma que detectó el NOMBRE, nunca el que mandó el cliente.
     *
     * Es el escalón nuevo del M8 y lo decide entero `CardResolver`: una clave de
     * `mtg_printing_localized` que apunta a un solo idioma lo declara, el índice
     * inglés declara `English`, y todo lo demás —clave ambigua, o una lectura
     * resuelta por el bloque de la esquina sin que el nombre casara en ningún
     * índice— declara **null**.
     *
     * **`null` no es un fallo y no se rellena aquí.** Significa «no hay señal», y
     * el cliente cae en su `ajustes.language`, que es lo que hacía antes del
     * hito. Poner aquí un valor por defecto sería devolver el idioma que el
     * usuario ya tenía disfrazado de idioma detectado, y el cliente perdería la
     * única forma que tiene de saber si puede fiarse.
     *
     * `ResolveCards` lo sirve como `detectedLanguage` para no chocar con el
     * `language` de la fila —el que llegó en la petición—, y aquí sale como
     * `language` porque en el contrato del escáner el campo solo puede
     * significar una cosa.
     *
     * @param array<string, mixed> $fila
     */
    private function idiomaDetectado(array $fila): ?string
    {
        $idioma = $fila['detectedLanguage'] ?? null;

        return is_string($idioma) && $idioma !== '' ? $idioma : null;
    }

    /**
     * Texto del cliente → cadena limpia, o null si no había nada.
     *
     * El vacío tiene que llegar al resolvedor como **null y no como `''`**: sus
     * pasos preguntan por `!== null && !== ''` en unos sitios y solo por null en
     * otros, y un OCR que no leyó el número manda con frecuencia la cadena
     * vacía.
     */
    private function texto(mixed $valor): ?string
    {
        if (!is_string($valor) && !is_int($valor)) {
            return null;
        }

        $limpio = trim((string) $valor);

        return $limpio === '' ? null : $limpio;
    }
}
