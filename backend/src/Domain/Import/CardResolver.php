<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Repository\CardResolutionRepositoryInterface;

/**
 * Los cuatro pasos del resolvedor de dos velocidades, en orden y con la regla de
 * oro: **ante la duda, conflicto**.
 *
 * ```
 * 1. ¿Hay scryfallId?     → mtg_printing.scryfall_id                    [EXACTO]
 * 2. ¿Hay set + número?   → (set_code, collector_number)                [EXACTO]
 *    → si la línea trae TAMBIÉN nombre y NO concuerda → CONFLICTO con las dos
 * 3. Nombre normalizado   → mtg_card.name_normalized                    [EXACTO]
 *    3b. si no casa, reintento por la CARA FRONTAL (parte por ' // ')   [EXACTO]
 *    → si la clave tiene MÁS DE UNA carta detrás → CONFLICTO con candidatos
 * 4. FULLTEXT BOOLEAN     → si devuelve exactamente 1 resultado, resuelve
 *                           si devuelve varios o ninguno → CONFLICTO
 * ```
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
        $pendientes = $this->pasoNombreNormalizado($pendientes, $veredictos, $desacuerdos);
        $this->pasoFulltext($pendientes, $veredictos);

        /** @var list<CardResolution> */
        return array_values($veredictos);
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
                $veredictos[$i] = CardResolution::resuelta($fila, $impresiones[$clave], '1');
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
                $veredictos[$i] = CardResolution::resuelta($fila, $impresiones[$clave], '2');
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
                $veredictos[$i] = CardResolution::resuelta($fila, $candidatos[0], $porFila[$i]['paso']);
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
     * Paso 4 — FULLTEXT. El único inexacto, y por eso el único con la regla del
     * «exactamente 1».
     *
     * @param array<int, ParsedRow>           $pendientes
     * @param array<int, CardResolution|null> $veredictos
     */
    private function pasoFulltext(array $pendientes, array &$veredictos): void
    {
        /** @var array<string, list<array<string, mixed>>> $memoria */
        $memoria = [];

        foreach ($pendientes as $i => $fila) {
            $nombre = $fila->name !== null ? trim($fila->name) : '';

            if ($nombre === '') {
                // Sin nombre y sin identificador exacto no hay nada que buscar.
                $veredictos[$i] = CardResolution::conflicto($fila, CardResolution::NO_ENCONTRADA);
                continue;
            }

            $memo = mb_strtolower($nombre);

            if (!isset($memoria[$memo])) {
                $memoria[$memo] = $this->catalogo->cartasPorTexto($nombre, self::CANDIDATOS_FULLTEXT);
            }

            $candidatos = $memoria[$memo];

            if (count($candidatos) === 1) {
                $veredictos[$i] = CardResolution::resuelta($fila, $candidatos[0], '4');
                continue;
            }

            $veredictos[$i] = CardResolution::conflicto(
                $fila,
                $candidatos === [] ? CardResolution::NO_ENCONTRADA : CardResolution::AMBIGUA,
                $candidatos
            );
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
}
