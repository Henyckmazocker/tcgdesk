<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Import\AssumedPrintingChooser;
use App\Domain\Import\CardResolution;
use App\Domain\Import\CardResolver;
use App\Domain\Import\ParsedRow;

/**
 * Pasa las filas de un fichero importado por el resolvedor y las reparte en dos
 * montones: **resueltas** y **conflictos**.
 *
 * Existe como use case y no como método de un controller por lo mismo que
 * `SearchCards`: lo van a llamar dos entradas distintas —la acción
 * `import_preview` de la vista y, si algún día hace falta, la CLI— y duplicar el
 * reparto en dos sitios es lo que garantiza que se desincronicen.
 *
 * **No escribe nada.** Ni en la colección ni en el catálogo: es la mitad de
 * arriba del alto obligatorio del pipeline, y lo que sale de aquí solo se aplica
 * después de que el usuario lo confirme en la previsualización.
 *
 * La forma de la respuesta es la del contrato `import_preview` del plan. Tres
 * matices que conviene leer antes de consumirla:
 *
 *  - **Toda fila resuelta trae `printingUuid`.** El resolvedor puede dejarlo a
 *    null —los pasos por nombre identifican la carta, no la impresión—, y ese
 *    hueco lo cierra aquí `AssumedPrintingChooser` con la impresión más barata
 *    (M5, «La edición asumida»). Lo que no consigue impresión **no sale como
 *    resuelto**: baja a conflicto, porque sin `printingUuid` no hay nada que
 *    escribir en la colección.
 *  - **`assumedPrinting` marca justo esas filas**, con `printingCount` para decir
 *    entre cuántas ediciones se eligió, y `summary.assumedCount` las cuenta. Es
 *    lo que la previsualización pinta como «edición asumida» y lo que permite
 *    cambiarlas antes de confirmar; sin esa marca sería un fallo silencioso.
 *  - **`totalQuantity` cuenta solo lo resuelto**, que es lo único que podría
 *    acabar en la colección sin intervención; los conflictos ya traen su
 *    `quantity` fila a fila.
 */
class ResolveCards
{
    public function __construct(
        private readonly CardResolver $resolvedor,
        private readonly AssumedPrintingChooser $edicionAsumida
    ) {
    }

    /**
     * @param  list<ParsedRow> $filas
     * @return array{
     *     total: int,
     *     resolved: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     summary: array{resolvedCount: int, conflictCount: int, assumedCount: int, totalQuantity: int}
     * }
     */
    public function __invoke(array $filas): array
    {
        $veredictos = $this->resolvedor->resolver($filas);

        // Una sola consulta para TODAS las filas que resolvieron carta pero no
        // edición: la clave del lote es (oracleId, acabado), así que la misma
        // carta repetida 300 veces se pregunta una.
        $asumidas = $this->edicionAsumida->elegir($veredictos);

        $resueltas  = [];
        $conflictos = [];
        $cantidad   = 0;
        $conEdicionAsumida   = 0;

        foreach ($veredictos as $veredicto) {
            if (!$veredicto->estaResuelta()) {
                $conflictos[] = $this->aConflicto($veredicto, null, $asumidas);
                continue;
            }

            if ($veredicto->tieneImpresion()) {
                $resueltas[] = $this->aResuelta($veredicto);
                $cantidad   += $veredicto->fila->quantity;
                continue;
            }

            $asumida = $this->asumidaDe($asumidas, $veredicto->oracleId, $veredicto->fila->finish);

            if ($asumida === null) {
                // La carta existe pero el catálogo no ofrece ninguna impresión
                // suya. No se cuela sin `printingUuid`: iría a la colección como
                // una fila que no se puede escribir.
                $conflictos[] = $this->aConflicto($veredicto, CardResolution::NO_ENCONTRADA);
                continue;
            }

            $resueltas[] = $this->aResuelta($veredicto, $asumida);
            $cantidad   += $veredicto->fila->quantity;
            $conEdicionAsumida++;
        }

        return [
            'total'     => count($filas),
            'resolved'  => $resueltas,
            'conflicts' => $conflictos,
            'summary'   => [
                'resolvedCount' => count($resueltas),
                'conflictCount' => count($conflictos),
                'assumedCount'  => $conEdicionAsumida,
                'totalQuantity' => $cantidad,
            ],
        ];
    }

    /**
     * @param  array{printingUuid: string, setCode: string, collectorNumber: string,
     *               priceEur: float|null, printingCount: int}|null $asumida
     * @return array<string, mixed>
     */
    private function aResuelta(CardResolution $veredicto, ?array $asumida = null): array
    {
        $fila = $veredicto->fila;

        return [
            'line'         => $fila->sourceLine,
            'printingUuid' => $asumida['printingUuid'] ?? $veredicto->printingUuid,
            'oracleId'     => $veredicto->oracleId,
            'name'         => $veredicto->name,
            'setCode'      => $asumida['setCode'] ?? $veredicto->setCode ?? $fila->setCode,
            'finish'       => $fila->finish,
            'language'     => $fila->language,
            // ## `detectedLanguage` NO ES `language`, Y LA DIFERENCIA ES EL M8
            //
            // `language` es el de la FILA: lo que el fichero decía o lo que el
            // cliente mandó, con su respaldo ya aplicado. Nunca es null y no
            // dice nada de la carta.
            //
            // `detectedLanguage` es lo que **declaró el nombre que resolvió**,
            // o null si ninguno lo declaró. Van los dos y por separado a
            // propósito: fundirlos haría indistinguible «lo detecté» de «me
            // rendí y usé el ajuste», que es justo lo que el cliente necesita
            // distinguir para no pisar el código impreso en la esquina.
            'detectedLanguage' => $veredicto->language,
            'condition'    => $fila->condition,
            'quantity'     => $fila->quantity,
            // La zona que dijo la decklist pegada (`Deck` / `Sideboard` / …).
            // A la colección no le afecta —no hay `board` en
            // `mtg_collection_item`—: viaja para que `import_apply` pueda
            // repartir las zonas si el usuario además crea el mazo.
            'board'        => $fila->board,
            'step'         => $veredicto->paso,
            // La marca del contrato. `printingCount` es entre cuántas ediciones
            // se eligió: 1 cuando no había nada que elegir porque el fichero ya
            // decía la impresión (Scryfall ID, o set + número).
            'assumedPrinting' => $asumida !== null,
            'printingCount'   => $asumida['printingCount'] ?? 1,
            'assumedPriceEur' => $asumida['priceEur'] ?? null,
        ];
    }

    /**
     * @param  string|null $motivo    Fuerza el motivo cuando la fila no llegó a
     *                                conflicto por el resolvedor sino por no
     *                                haber impresión que asumir.
     * @param  array<string, array<string, mixed>> $asumidas
     * @return array<string, mixed>
     */
    private function aConflicto(CardResolution $veredicto, ?string $motivo = null, array $asumidas = []): array
    {
        $fila = $veredicto->fila;

        return [
            'line'       => $fila->sourceLine,
            // El crudo es lo que se le enseña al usuario: lo que su fichero decía,
            // sin normalizar. Si el parser no lo conservó, al menos el nombre.
            'raw'        => $fila->crudo !== [] ? $fila->crudo : ['name' => $fila->name],
            'reason'     => $motivo ?? $veredicto->motivo,
            'detail'     => $fila->motivoDeError(),
            'finish'     => $fila->finish,
            'language'   => $fila->language,
            'condition'  => $fila->condition,
            'quantity'   => $fila->quantity,
            // También en el conflicto: arreglarlo a mano no puede perder la
            // zona en la que la línea estaba escrita.
            'board'      => $fila->board,
            'candidates' => array_map(
                fn (array $candidato): array => $this->aCandidato($candidato, $fila->finish, $asumidas),
                $veredicto->candidatos
            ),
        ];
    }

    /**
     * Un candidato listo para que el usuario lo elija **y se pueda aplicar**.
     *
     * Un candidato de los pasos por nombre llega sin `printingUuid` en cuanto su
     * carta tiene varias impresiones, y elegirlo daría una fila que
     * `import_apply` no puede escribir. Se le pone la misma edición asumida que a
     * las filas resueltas —la más barata— y se marca igual, así que arreglar un
     * conflicto a mano no mete una edición a ciegas.
     *
     * @param  array<string, mixed> $candidato
     * @param  array<string, array<string, mixed>> $asumidas
     * @return array<string, mixed>
     */
    private function aCandidato(array $candidato, string $finish, array $asumidas): array
    {
        if (($candidato['printingUuid'] ?? null) !== null) {
            return $candidato + ['assumedPrinting' => false, 'printingCount' => 1];
        }

        $asumida = $this->asumidaDe($asumidas, $candidato['oracleId'] ?? null, $finish);

        if ($asumida === null) {
            return $candidato + ['assumedPrinting' => false, 'printingCount' => 0];
        }

        return array_merge($candidato, [
            'printingUuid'    => $asumida['printingUuid'],
            'setCode'         => $asumida['setCode'],
            'assumedPrinting' => true,
            'printingCount'   => $asumida['printingCount'],
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>> $asumidas
     * @return array{printingUuid: string, setCode: string, collectorNumber: string,
     *               priceEur: float|null, printingCount: int}|null
     */
    private function asumidaDe(array $asumidas, ?string $oracleId, string $finish): ?array
    {
        if ($oracleId === null || $oracleId === '') {
            return null;
        }

        /** @var array{printingUuid: string, setCode: string, collectorNumber: string, priceEur: float|null, printingCount: int}|null */
        return $asumidas[AssumedPrintingChooser::clave($oracleId, $finish)] ?? null;
    }
}
