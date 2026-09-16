<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Collection\CardLanguage;
use App\Domain\Repository\CardResolutionRepositoryInterface;

/**
 * Los pasos del resolvedor de dos velocidades, en orden y con la regla de
 * oro: **ante la duda, conflicto**.
 *
 * ```
 * 1. ¿Hay scryfallId?     → mtg_printing.scryfall_id                    [EXACTO]
 * 2. ¿Hay set + número?   → (set_code, collector_number)                [EXACTO]
 *    → si la línea trae TAMBIÉN nombre y NO concuerda → CONFLICTO con las dos
 * 2b. ¿Nombre + número SIN set? → (nombre normalizado, collector_number)[EXACTO]
 *    → si el par tiene VARIAS impresiones detrás, CEDE EL TURNO al paso 3
 * 3. Nombre normalizado   → mtg_card.name_normalized                    [EXACTO]
 *    3b. si no casa, reintento por la CARA FRONTAL (parte por ' // ')   [EXACTO]
 *    → si la clave tiene MÁS DE UNA carta detrás → CONFLICTO con candidatos
 * 3c. Nombre LOCALIZADO   → mtg_printing_localized.name_normalized      [EXACTO]
 *    → la misma regla del 3, en los otros nueve idiomas
 * 4. FULLTEXT BOOLEAN     → si devuelve exactamente 1 resultado, resuelve
 *                           si devuelve varios → CONFLICTO y AQUÍ SE ACABA
 * 5. Nombre APROXIMADO    → distancia de edición <= 2, solo sobre los
 *                           `not_found` del 4. Un único candidato a la
 *                           distancia mínima, o CONFLICTO
 * ```
 *
 * ## Por qué hay un paso por idioma y otro por parecido
 *
 * Los dos últimos nacieron el 2026-09-15 del escáner por cámara, y los dos
 * arreglan un `not_found` que no era culpa de nadie:
 *
 *  - **El 3c**, porque el resolvedor era monolingüe. Las 410.604 filas de
 *    nombres traducidos no las consultaba nadie, así que una Llanura española no
 *    se encontraba ni fotografiada ni importada en un CSV.
 *  - **El 5**, porque una errata de UN CARÁCTER tiraba la lectura entera. Es lo
 *    que le pasa al OCR con «Tlanura» y «Llasura», los dos únicos fallos de
 *    nombre de las fixturas reales.
 *
 * Los dos entran **para todos**, `/import` incluido: un nombre mal tecleado en un
 * fichero es el mismo problema que uno mal leído en una foto.
 *
 *
 * ## Por qué los pasos encadenan en vez de excluirse
 *
 * Un paso que no resuelve **no falla: cede el turno**. Un Scryfall ID que no está
 * en el catálogo (una carta más nueva que el mirror) no invalida la fila, que
 * todavía puede resolverse por set + número o por nombre. Lo único que corta la
 * cadena es una resolución con un único candidato… o un empate, que es
 * información suficiente para parar: si el paso 3 encuentra dos cartas, el paso 4
 * no va a encontrar menos, y bajar a un paso más difuso solo puede empeorar la
 * respuesta.
 *
 * ## Por qué recibe una lista y no una fila
 *
 * Porque el `import_preview` del plan resuelve ficheros de 20.000 líneas dentro de
 * una petición HTTP. Los tres pasos exactos se resuelven con **una consulta por
 * paso** para el lote entero; el paso 4, que sí consulta línea a línea, solo lo
 * pisan las pocas que ningún paso exacto resolvió, y sus respuestas se memorizan
 * por nombre para que un fichero con 300 veces la misma carta no lo pague 300
 * veces.
 *
 * ## Por qué el paso 2 mira el nombre aunque resuelva por el número
 *
 * Porque `(set, número)` es exacto pero **lo teclea un humano**, y un dígito de
 * más apunta a otra carta que existe: `Lim-Dûl's Vault (ICE) 96` resolvía en
 * silencio a *Shyft*, y `Path to Exile (MM2) 20` a *Iona, Shield of Emeria*. Dos
 * datos exactos que se contradicen no son una ambigüedad —no falta información,
 * sobra— y no los desempata el resolvedor: la fila va a conflicto `mismatch` con
 * **las dos cartas**, la del nombre y la del número. Si la línea no trae nombre
 * no hay contradicción posible y el paso 2 sigue resolviendo solo.
 *
 * Una fila inválida (`ParsedRow::esValida() === false`) no entra en los cuatro
 * pasos: va directa a conflicto con motivo `invalid`, con el valor crudo intacto
 * para que la previsualización enseñe lo que el fichero decía de verdad.
 */
final class CardResolver
{
    /**
     * Techo de candidatos que se traen del paso 4.
     *
     * Solo hace falta saber si hay uno o más de uno; el resto de la lista es para
     * que la previsualización pueda enseñar candidatos, y 25 ya son más de los que
     * nadie va a leer.
     */
    private const CANDIDATOS_FULLTEXT = 25;

    /**
     * Distancia de edición máxima del paso 5. **Es 2, y subirla es caro en la
     * dirección peor.**
     *
     * Dos caracteres cubren lo que el OCR falla de verdad —una letra cambiada,
     * una doble leída como simple— y dejan fuera lo que no es una errata sino
     * otra carta. El catálogo está lleno de nombres que se diferencian en tres o
     * cuatro caracteres (`Shock` / `Shocker`, las cinco tierras básicas en sus
     * diez idiomas), y a distancia 3 empiezan a empatarse entre sí: como la regla
     * es «uno o ninguno», el efecto de subirla no es resolver más, es convertir
     * aciertos en `ambiguous`.
     */
    private const DISTANCIA_MAXIMA = 2;

    public function __construct(
        private readonly CardResolutionRepositoryInterface $catalogo,
        private readonly NameNormalizer $normalizador,
    ) {
    }

    /**
     * @param  list<ParsedRow> $filas
     * @return list<CardResolution> Uno por fila, EN EL MISMO ORDEN
     */
    public function resolver(array $filas): array
    {
        /** @var array<int, CardResolution|null> $veredictos */
        $veredictos = [];
        /** @var array<int, ParsedRow> $pendientes */
        $pendientes = [];

        foreach ($filas as $i => $fila) {
            $veredictos[$i] = null;

            if (!$fila->esValida()) {
                $veredictos[$i] = CardResolution::conflicto($fila, CardResolution::INVALIDA);
                continue;
            }

            $pendientes[$i] = $fila;
        }

        // Índice → impresión que dice el (set, número) de una fila cuyo NOMBRE
        // dice otra carta. Se rellena en el paso 2 y lo cierra el paso 3, que es
        // quien sabe a qué carta apunta el nombre.
        /** @var array<int, array<string, mixed>> $desacuerdos */
        $desacuerdos = [];

        $pendientes = $this->pasoScryfallId($pendientes, $veredictos);
        $pendientes = $this->pasoSetYNumero($pendientes, $veredictos, $desacuerdos);
        $pendientes = $this->pasoNombreYNumero($pendientes, $veredictos);
        $pendientes = $this->pasoNombreNormalizado($pendientes, $veredictos, $desacuerdos);
        $pendientes = $this->pasoNombreLocalizado($pendientes, $veredictos);
        $pendientes = $this->pasoFulltext($pendientes, $veredictos);
        $this->pasoNombreAproximado($pendientes, $veredictos);

        /** @var list<CardResolution> */
        return array_values($veredictos);
    }

    /**
     * El idioma que **declara el nombre que resolvió**, o `null` si ninguno lo
     * declara. Es la cascada del M8, y aquí están sus tres escalones de abajo.
     *
     * El de arriba —el bloque impreso en la esquina— no pasa por aquí y **sigue
     * mandando**: lo lee el móvil, llega en `ParsedRow::$language` y el cliente
     * solo mira esto cuando aquel vino vacío. Es lo único impreso en la carta
     * física, así que nada de lo que se decida aquí lo pisa.
     *
     * ```
     * 2. El nombre casó en `mtg_printing_localized` y su clave apunta a UN
     *    idioma                                              → ese idioma
     * 3. El nombre casó en el índice INGLÉS (`mtg_card`)      → 'English'
     * 4. No lo casó ningún nombre —pasos 1, 2 y 2b, que
     *    resuelven por identificador o por (edición, número)— → null
     * ```
     *
     * ## El escalón 4 es el que garantiza que esto no empeore nada
     *
     * `null` no es «no sé»: es «que decida el cliente», y el cliente hace
     * exactamente lo de siempre —caer en `ajustes.language`—. Donde no hay
     * señal, el comportamiento es el de antes del hito y no una adivinanza.
     *
     * ## Y por qué el 3 dice `English` en vez de callarse
     *
     * Porque la **ausencia** de coincidencia localizada es en sí misma la señal:
     * `mtg_card.name_normalized` guarda el inglés, así que casar ahí es haber
     * leído un nombre inglés. Sin este escalón, una carta inglesa heredaría el
     * «Spanish» que el usuario dejó puesto en el selector, que es justo la
     * mitad del problema que el hito arregla.
     *
     * El paso **5 no es un escalón nuevo**: busca el parecido en las dos tablas
     * a la vez, así que se fía solo de la señal que traiga el candidato —la
     * localizada la trae; la inglesa, no—. Un parecido a dos caracteres del
     * índice inglés es demasiado poco para declarar un idioma.
     *
     * @param array<string, mixed> $carta El candidato con el que se resolvió
     */
    private function idiomaDetectado(string $paso, array $carta): ?string
    {
        $idioma = match ($paso) {
            '3', '3b', '4' => CardLanguage::English->value,
            '3c', '5'      => isset($carta['language']) && is_string($carta['language'])
                ? $carta['language']
                : null,
            // 1, 2 y 2b: aquí no resolvió un nombre, resolvió un identificador.
            default        => null,
        };

        // El vocabulario es el de `CardLanguage` porque lo que sale de aquí
        // acaba en `mtg_collection_item.language`: un idioma que MTGJSON
        // publicase mañana y el enum no conociera haría que `collection_add`
        // contestara 422 a una lectura que el escáner dio por buena. Sin
        // vocabulario, `null` y al ajuste del usuario.
        return $idioma === null ? null : CardLanguage::intentar($idioma)?->value;
    }

    /**
     * Paso 1 — Scryfall ID. Gana sobre todo lo demás cuando viene.
     *
     * @param  array<int, ParsedRow>              $pendientes
     * @param  array<int, CardResolution|null>    $veredictos
     * @return array<int, ParsedRow>              Las que siguen sin resolver
     */
    private function pasoScryfallId(array $pendientes, array &$veredictos): array
    {
        $ids = [];
        foreach ($pendientes as $fila) {
            if ($fila->scryfallId !== null && $fila->scryfallId !== '') {
                $ids[mb_strtolower($fila->scryfallId)] = true;
            }
        }

        if ($ids === []) {
            return $pendientes;
        }

        $impresiones = $this->catalogo->impresionesPorScryfallId(array_keys($ids));
        $siguen      = [];

        foreach ($pendientes as $i => $fila) {
            $clave = $fila->scryfallId !== null ? mb_strtolower($fila->scryfallId) : '';

            if (isset($impresiones[$clave])) {
                $veredictos[$i] = CardResolution::resuelta(
                    $fila,
                    $impresiones[$clave],
                    '1',
                    $this->idiomaDetectado('1', $impresiones[$clave])
                );
                continue;
            }

            // Un Scryfall ID que no está en el catálogo NO descarta la fila: puede
            // ser una carta más nueva que el mirror y seguir siendo resoluble por
            // nombre. Cede el turno al paso siguiente.
            $siguen[$i] = $fila;
        }

        return $siguen;
    }

    /**
     * Paso 2 — set + número de coleccionista, **contrastado con el nombre**.
     *
     * El par identifica la impresión sin ambigüedad, pero eso no basta para
     * resolver: si la línea trae además un nombre y no es el de esa impresión, la
     * fila es un dato contradictorio y se apunta en `$desacuerdos` para que el
     * paso 3 la cierre con las dos cartas. Sin nombre —o con un nombre que no
     * deja clave normalizable— no hay nada que contrastar y resuelve como antes.
     *
     * @param  array<int, ParsedRow>              $pendientes
     * @param  array<int, CardResolution|null>    $veredictos
     * @param  array<int, array<string, mixed>>   $desacuerdos Salida: índice → impresión del número
     * @return array<int, ParsedRow>
     */
    private function pasoSetYNumero(array $pendientes, array &$veredictos, array &$desacuerdos): array
    {
        $pares = [];
        foreach ($pendientes as $fila) {
            $clave = $this->claveDeSet($fila);
            if ($clave !== null) {
                $pares[$clave] = [
                    'setCode'         => strtoupper((string) $fila->setCode),
                    'collectorNumber' => (string) $fila->collectorNumber,
                ];
            }
        }

        if ($pares === []) {
            return $pendientes;
        }

        $impresiones = $this->catalogo->impresionesPorSetYNumero(array_values($pares));
        $siguen      = [];

        foreach ($pendientes as $i => $fila) {
            $clave = $this->claveDeSet($fila);

            if ($clave === null || !isset($impresiones[$clave])) {
                $siguen[$i] = $fila;
                continue;
            }

            if ($this->nombreConcuerda($fila, $impresiones[$clave])) {
                $veredictos[$i] = CardResolution::resuelta(
                    $fila,
                    $impresiones[$clave],
                    '2',
                    $this->idiomaDetectado('2', $impresiones[$clave])
                );
                continue;
            }

            // El número dice una carta y el nombre dice otra. Ni se elige una ni
            // se calla: sigue al paso 3 SOLO para averiguar a qué carta apunta el
            // nombre, y allí sale conflicto con las dos.
            $desacuerdos[$i] = $impresiones[$clave];
            $siguen[$i]      = $fila;
        }

        return $siguen;
    }

    /**
     * Paso 2b — nombre + número de coleccionista **cuando la línea no trae
     * edición**, el dato que hasta el 2026-09-15 se tiraba a la basura.
     *
     * `claveDeSet()` devuelve `null` en cuanto falta `setCode`, así que
     * `Thoughtseize / 1117` caía al paso 3 —que solo mira el nombre— y entraba con
     * edición asumida teniendo con qué acertar la impresión exacta. Lo pagaban
     * `/import` y sobre todo las Secret Lair, que no imprimen el código de edición
     * en la esquina. Medido en la BD viva: el par identifica de forma única el
     * **90,42 %** del catálogo y el **98,54 %** de las 2.599 Secret Lair.
     *
     * ## Cede el turno; no conflictúa, y por eso no puede romper nada
     *
     * Un par ambiguo —`Plains 250` vive en 14 ediciones— no vuelve del catálogo, y
     * la ausencia hace todo el trabajo: la fila sigue al paso 3 y se resuelve por
     * nombre con edición asumida **exactamente igual que antes de que este paso
     * existiera**. No hay rama de conflicto que escribir ni motivo nuevo que
     * añadir al contrato.
     *
     * ## Aquí NO se contrasta el nombre, y el paso 2 sí
     *
     * En el paso 2 el nombre es un dato aparte que puede contradecir al par y por
     * eso pasa por `nombreConcuerda()`. Aquí el nombre **es la clave de búsqueda**:
     * la impresión que vuelve concuerda por construcción —el catálogo casa contra
     * `name_normalized` y contra su cara frontal, la misma vara del paso 3—, así
     * que un contraste posterior no podría fallar nunca.
     *
     * @param  array<int, ParsedRow>           $pendientes
     * @param  array<int, CardResolution|null> $veredictos
     * @return array<int, ParsedRow>
     */
    private function pasoNombreYNumero(array $pendientes, array &$veredictos): array
    {
        $pares = [];
        foreach ($pendientes as $fila) {
            $clave = $this->claveDeNombreYNumero($fila);
            if ($clave !== null) {
                $pares[$clave] = [
                    'name'            => $this->normalizador->normalizar((string) $fila->name),
                    'collectorNumber' => (string) $fila->collectorNumber,
                ];
            }
        }

        if ($pares === []) {
            return $pendientes;
        }

        $impresiones = $this->catalogo->impresionesPorNombreYNumero(array_values($pares));
        $siguen      = [];

        foreach ($pendientes as $i => $fila) {
            $clave = $this->claveDeNombreYNumero($fila);

            if ($clave === null || !isset($impresiones[$clave])) {
                $siguen[$i] = $fila;
                continue;
            }

            $veredictos[$i] = CardResolution::resuelta(
                $fila,
                $impresiones[$clave],
                '2b',
                $this->idiomaDetectado('2b', $impresiones[$clave])
            );
        }

        return $siguen;
    }

    /**
     * ¿El nombre que trae la línea es el de esta impresión?
     *
     * Se compara por la **misma clave normalizada del paso 3** —nunca por
     * igualdad literal— y con su mismo reintento por la CARA FRONTAL: quien
     * teclea `Delver of Secrets (ISD) 51` escribe solo la mitad izquierda y el
     * catálogo guarda `Delver of Secrets // Insectile Aberration`. Comparando
     * literalmente, las 501 cartas de doble cara serían todas un conflicto.
     *
     * Devuelve `true` cuando no hay nada que contrastar: sin nombre, o con un
     * nombre que no deja clave, no existe contradicción que detectar.
     *
     * @param array<string, mixed> $impresion
     */
    private function nombreConcuerda(ParsedRow $fila, array $impresion): bool
    {
        $tecleado = $fila->name !== null ? trim($fila->name) : '';

        if ($tecleado === '') {
            return true;
        }

        $clave = $this->normalizador->normalizar($tecleado);

        if ($clave === '') {
            return true;
        }

        $delCatalogo = $this->claveDelCatalogo($impresion);

        if ($delCatalogo === '' || $clave === $delCatalogo) {
            return true;
        }

        return $this->normalizador->caraFrontal($tecleado)
            === $this->normalizador->caraFrontal($delCatalogo);
    }

    /**
     * La clave normalizada de la carta de una impresión.
     *
     * Se usa `mtg_card.name_normalized` tal cual viene, para comparar contra
     * EXACTAMENTE la misma columna por la que busca el paso 3; si el repositorio
     * no la trajera, se recalcula del nombre con el mismo normalizador, que es
     * quien pobló la columna.
     *
     * @param array<string, mixed> $impresion
     */
    private function claveDelCatalogo(array $impresion): string
    {
        $clave = isset($impresion['nameNormalized']) ? (string) $impresion['nameNormalized'] : '';

        if ($clave !== '') {
            return $clave;
        }

        return isset($impresion['name'])
            ? $this->normalizador->normalizar((string) $impresion['name'])
            : '';
    }

    /**
     * Pasos 3 y 3b — la clave normalizada, y su reintento por la cara frontal.
     *
     * Los dos comparten la misma regla y por eso viven juntos: **más de un
     * candidato es un conflicto**, nunca la primera de la lista.
     *
     * Aquí se cierran también las filas que el paso 2 marcó en desacuerdo, y no
     * es un añadido caprichoso: son las únicas que ya han pasado por la búsqueda
     * por nombre del lote, así que cerrarlas aquí es lo que evita una segunda
     * ronda de consultas solo para ellas. Una fila en desacuerdo **no puede
     * resolver** por mucho que su nombre case con una sola carta: es justo esa
     * carta la que contradice al número.
     *
     * @param  array<int, ParsedRow>            $pendientes
     * @param  array<int, CardResolution|null>  $veredictos
     * @param  array<int, array<string, mixed>> $desacuerdos índice → impresión del paso 2
     * @return array<int, ParsedRow>
     */
    private function pasoNombreNormalizado(array $pendientes, array &$veredictos, array $desacuerdos = []): array
    {
        $porFila = $this->candidatosPorNombre($pendientes);
        $siguen  = [];

        foreach ($pendientes as $i => $fila) {
            $candidatos = $porFila[$i]['candidatos'] ?? [];

            if (isset($desacuerdos[$i])) {
                // Las dos cartas, en el orden en que el usuario las tecleó: la que
                // dice el NOMBRE y, la última, la que dice el NÚMERO.
                $veredictos[$i] = CardResolution::conflicto(
                    $fila,
                    CardResolution::DESACUERDO,
                    array_merge($candidatos, [$desacuerdos[$i]])
                );
                continue;
            }

            if (count($candidatos) === 1) {
                $veredictos[$i] = CardResolution::resuelta(
                    $fila,
                    $candidatos[0],
                    $porFila[$i]['paso'],
                    $this->idiomaDetectado($porFila[$i]['paso'], $candidatos[0])
                );
                continue;
            }

            if (count($candidatos) > 1) {
                // Clave con varias cartas detrás. No se elige ninguna y no se baja
                // al paso 4: el empate ya es la respuesta.
                $veredictos[$i] = CardResolution::conflicto($fila, CardResolution::AMBIGUA, $candidatos);
                continue;
            }

            $siguen[$i] = $fila;
        }

        return $siguen;
    }

    /**
     * A qué carta apunta el NOMBRE de cada fila, para el lote entero y en **dos
     * consultas como mucho**: la clave normalizada y, solo para lo que no case,
     * la cara frontal. Nunca una consulta por fila.
     *
     * @param  array<int, ParsedRow> $filas
     * @return array<int, array{candidatos: list<array<string, mixed>>, paso: string}>
     */
    private function candidatosPorNombre(array $filas): array
    {
        $claves = [];
        foreach ($filas as $i => $fila) {
            if ($fila->name === null || trim($fila->name) === '') {
                continue;
            }

            $clave = $this->normalizador->normalizar($fila->name);
            if ($clave !== '') {
                $claves[$i] = $clave;
            }
        }

        if ($claves === []) {
            return [];
        }

        $porClave = $this->catalogo->cartasPorNombreNormalizado(array_values(array_unique($claves)));

        $caras = [];
        foreach ($claves as $i => $clave) {
            if (($porClave[$clave] ?? []) === []) {
                $cara = $this->normalizador->caraFrontal((string) $filas[$i]->name);
                if ($cara !== '') {
                    $caras[$i] = $cara;
                }
            }
        }

        $porCara = $caras === []
            ? []
            : $this->catalogo->cartasPorCaraFrontal(array_values(array_unique($caras)));

        $salida = [];

        foreach ($claves as $i => $clave) {
            $candidatos = $porClave[$clave] ?? [];
            $paso       = '3';

            if ($candidatos === [] && isset($caras[$i])) {
                $candidatos = $porCara[$caras[$i]] ?? [];
                $paso       = '3b';
            }

            $salida[$i] = ['candidatos' => $candidatos, 'paso' => $paso];
        }

        return $salida;
    }

    /**
     * Paso 3c — la clave normalizada **en los otros nueve idiomas**.
     *
     * Misma regla que el 3 y el 3b, y por eso es el mismo código con otra
     * consulta detrás: uno resuelve, varios son `ambiguous` y cero cede el turno.
     * Lo que cambia es dónde mira — `mtg_printing_localized` en vez de
     * `mtg_card`—, y eso basta para que una Llanura deje de ser `not_found`.
     *
     * Va **después** del 3b y no antes: el inglés es el idioma del catálogo y el
     * de la inmensa mayoría de las colecciones, así que probarlo primero resuelve
     * casi todo sin tocar una tabla de 410.604 filas. Y va **antes** del 4,
     * porque esto es igualdad exacta y aquello es `FULLTEXT`: un paso exacto
     * nunca se pone detrás de uno inexacto.
     *
     * **La clave la calcula la misma función que normalizó la columna.** Si
     * alguien cambia `NameNormalizer` y no relanza `catalog:normalize --all`,
     * este paso deja de encontrar nada sin un solo error — igual que el paso 3.
     *
     * @param  array<int, ParsedRow>           $pendientes
     * @param  array<int, CardResolution|null> $veredictos
     * @return array<int, ParsedRow>
     */
    private function pasoNombreLocalizado(array $pendientes, array &$veredictos): array
    {
        $claves = [];
        foreach ($pendientes as $i => $fila) {
            if ($fila->name === null || trim($fila->name) === '') {
                continue;
            }

            $clave = $this->normalizador->normalizar($fila->name);
            if ($clave !== '') {
                $claves[$i] = $clave;
            }
        }

        if ($claves === []) {
            return $pendientes;
        }

        $porClave = $this->catalogo->cartasPorNombreLocalizado(array_values(array_unique($claves)));
        $siguen   = [];

        foreach ($pendientes as $i => $fila) {
            $candidatos = isset($claves[$i]) ? ($porClave[$claves[$i]] ?? []) : [];

            if (count($candidatos) === 1) {
                $veredictos[$i] = CardResolution::resuelta(
                    $fila,
                    $candidatos[0],
                    '3c',
                    $this->idiomaDetectado('3c', $candidatos[0])
                );
                continue;
            }

            if (count($candidatos) > 1) {
                // El mismo nombre traducido apuntando a dos cartas distintas. No
                // se elige ninguna, exactamente como en el paso 3.
                $veredictos[$i] = CardResolution::conflicto($fila, CardResolution::AMBIGUA, $candidatos);
                continue;
            }

            $siguen[$i] = $fila;
        }

        return $siguen;
    }

    /**
     * Paso 4 — FULLTEXT. El único inexacto hasta que llegó el 5, y por eso el
     * primero con la regla del «exactamente 1».
     *
     * **Devuelve las filas que se quedaron en `not_found`** para que el paso 5
     * las intente por parecido. Su veredicto ya está puesto: si el 5 tampoco
     * encuentra nada, el `not_found` sigue ahí y nadie tiene que acordarse de
     * volver a escribirlo.
     *
     * **Las `ambiguous` NO bajan al 5**, y es deliberado: un empate significa que
     * lo leído sí casa con cartas del catálogo, y buscar además las que se le
     * parecen solo puede convertir un empate honesto en una resolución falsa. El
     * paso 5 es para cuando no se encontró nada, que es la forma que tiene una
     * errata de presentarse.
     *
     * @param  array<int, ParsedRow>           $pendientes
     * @param  array<int, CardResolution|null> $veredictos
     * @return array<int, ParsedRow>           Las que quedaron sin encontrar
     */
    private function pasoFulltext(array $pendientes, array &$veredictos): array
    {
        /** @var array<string, list<array<string, mixed>>> $memoria */
        $memoria = [];
        $siguen  = [];

        foreach ($pendientes as $i => $fila) {
            $nombre = $fila->name !== null ? trim($fila->name) : '';

            if ($nombre === '') {
                // Sin nombre y sin identificador exacto no hay nada que buscar,
                // ni parecido que medir: esta no baja al paso 5.
                $veredictos[$i] = CardResolution::conflicto($fila, CardResolution::NO_ENCONTRADA);
                continue;
            }

            $memo = mb_strtolower($nombre);

            if (!isset($memoria[$memo])) {
                $memoria[$memo] = $this->catalogo->cartasPorTexto($nombre, self::CANDIDATOS_FULLTEXT);
            }

            $candidatos = $memoria[$memo];

            if (count($candidatos) === 1) {
                $veredictos[$i] = CardResolution::resuelta(
                    $fila,
                    $candidatos[0],
                    '4',
                    $this->idiomaDetectado('4', $candidatos[0])
                );
                continue;
            }

            $veredictos[$i] = CardResolution::conflicto(
                $fila,
                $candidatos === [] ? CardResolution::NO_ENCONTRADA : CardResolution::AMBIGUA,
                $candidatos
            );

            if ($candidatos === []) {
                $siguen[$i] = $fila;
            }
        }

        return $siguen;
    }

    /**
     * Paso 5 — el nombre APROXIMADO, a distancia de edición `<= 2`.
     *
     * El último recurso, y existe por una medida concreta: los dos únicos fallos
     * de nombre de las fixturas reales del OCR son erratas de **un solo
     * carácter** —«Tlanura» por *Llanura*, «Llasura» por *Llanura*—, y ni la
     * igualdad del paso 3 ni el `FULLTEXT` del 4 rescatan ninguna. Una letra mal
     * leída tiraba la lectura entera.
     *
     * **La regla dura: un solo candidato a distancia mínima, o `ambiguous`.** El
     * repositorio ya devuelve solo el escalón más cercano, así que dos aquí son
     * dos cartas igual de parecidas a lo leído — y entre dos igual de parecidas
     * no se elige nunca. Es lo que impide que un CSV con un nombre
     * deliberadamente distinto entre por la puerta de atrás: con dos cartas a
     * distancia 2, este paso no resuelve.
     *
     * **Entra para todos, `/import` incluido**, por decisión explícita: un nombre
     * mal tecleado en un CSV es el mismo problema que uno mal leído en una foto.
     *
     * @param array<int, ParsedRow>           $pendientes Solo las `not_found` del 4
     * @param array<int, CardResolution|null> $veredictos
     */
    private function pasoNombreAproximado(array $pendientes, array &$veredictos): void
    {
        /** @var array<string, list<array<string, mixed>>> $memoria */
        $memoria = [];

        foreach ($pendientes as $i => $fila) {
            $clave = $fila->name !== null ? $this->normalizador->normalizar($fila->name) : '';

            if ($clave === '') {
                continue;
            }

            if (!isset($memoria[$clave])) {
                $memoria[$clave] = $this->catalogo->cartasPorNombreAproximado($clave, self::DISTANCIA_MAXIMA);
            }

            $candidatos = $memoria[$clave];

            if (count($candidatos) === 1) {
                $veredictos[$i] = CardResolution::resuelta(
                    $fila,
                    $candidatos[0],
                    '5',
                    $this->idiomaDetectado('5', $candidatos[0])
                );
                continue;
            }

            if (count($candidatos) > 1) {
                $veredictos[$i] = CardResolution::conflicto($fila, CardResolution::AMBIGUA, $candidatos);
            }

            // Con cero candidatos NO se toca el veredicto: el `not_found` que
            // puso el paso 4 sigue siendo la respuesta correcta.
        }
    }

    /** 'SET|numero', o null si la fila no trae los dos. */
    private function claveDeSet(ParsedRow $fila): ?string
    {
        if ($fila->setCode === null || $fila->setCode === '') {
            return null;
        }

        if ($fila->collectorNumber === null || $fila->collectorNumber === '') {
            return null;
        }

        return strtoupper($fila->setCode) . '|' . $fila->collectorNumber;
    }

    /**
     * 'clave normalizada|numero' del paso 2b, o null si la fila no da para él.
     *
     * **La condición que no es evidente es la primera: si la fila TRAE edición,
     * este paso no se ejecuta.** Si el par `(set, número)` existió y no casó —o
     * casó y se contradijo con el nombre—, insistir por nombre y número resolvería
     * a **otra** impresión de la misma carta y taparía un dato contradictorio que
     * hoy sale a la luz como conflicto `mismatch`. El paso 2b es para las líneas
     * que no dicen edición, no para arreglar las que la dicen mal.
     */
    private function claveDeNombreYNumero(ParsedRow $fila): ?string
    {
        if ($fila->setCode !== null && $fila->setCode !== '') {
            return null;
        }

        if ($fila->collectorNumber === null || $fila->collectorNumber === '') {
            return null;
        }

        if ($fila->name === null || trim($fila->name) === '') {
            return null;
        }

        // El MISMO normalizador del paso 3: comparar el nombre tecleado con el del
        // catálogo letra a letra convertiría en fallo cada tilde y cada apóstrofo.
        $clave = $this->normalizador->normalizar($fila->name);

        return $clave === '' ? null : $clave . '|' . $fila->collectorNumber;
    }
}
