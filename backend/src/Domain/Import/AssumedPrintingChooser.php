<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Repository\AssumedPrintingRepositoryInterface;

/**
 * **La edición asumida**: qué impresión se escribe cuando el fichero dice qué
 * carta es pero no de qué edición.
 *
 * Es la pieza que faltaba del contrato. `CardResolver` deja `printingUuid` a null
 * en las filas que resolvió por nombre (pasos 3, 3b y 4) cuando la carta tiene
 * más de una impresión, porque elegir edición **no es del resolvedor**: el
 * resolvedor identifica cartas y su regla de oro es no inventarse nada.
 *
 * Aquí sí se elige, y con una regla escrita en el plan: **la más barata** por
 * precio de Cardmarket, y **la más antigua** si ninguna de sus impresiones
 * cotiza. El porqué de «barata» y no «reciente» es la promesa de la valoración —
 * si se acierta, se acierta; si no, la colección queda valorada de menos, nunca
 * inflada.
 *
 * Y no es un fallo silencioso, que es lo que separa esto de la regla de oro: la
 * fila viaja marcada con `assumedPrinting: true` y su `printingCount`, la
 * previsualización la pinta como «edición asumida» y el resumen la cuenta, así
 * que el usuario puede cambiarla **antes** de confirmar. Lo que se asume es la
 * edición de una carta ya identificada sin lugar a dudas; un *nombre* ambiguo
 * sigue yendo a conflicto.
 *
 * ## Una consulta para todas las filas
 *
 * Recibe los veredictos del lote entero y no una fila, por lo mismo que
 * `CardResolver`: 20.000 líneas de texto plano sin edición serían 20.000
 * consultas. Además la misma carta repetida en 300 líneas pregunta **una vez**,
 * porque la clave del lote es `(oracleId, acabado)` y no la línea.
 */
final class AssumedPrintingChooser
{
    public function __construct(
        private readonly AssumedPrintingRepositoryInterface $catalogo
    ) {
    }

    /**
     * La impresión asumida de todo lo que la necesita en este lote: **las filas
     * resueltas sin edición y los candidatos de los conflictos**.
     *
     * Los candidatos entran por lo mismo que las filas y no por completitud: un
     * candidato de conflicto es lo que el usuario va a **elegir a mano** en la
     * previsualización, y los pasos por nombre lo devuelven sin `printingUuid`
     * en cuanto esa carta tiene varias impresiones. Sin edición asumida, elegir
     * *Lightning Bolt* en un conflicto daría una fila que `import_apply` no
     * puede escribir, y el arreglo manual —que es el plan B entero de M0— no
     * serviría de nada.
     *
     * Van todos en la MISMA consulta, por eso se devuelve el mapa crudo en vez
     * de un resultado por fila: la clave es `oracleId|acabado` y quien llama
     * busca en él tanto la fila como cada uno de sus candidatos.
     *
     * @param  list<CardResolution> $veredictos
     * @return array<string, array{printingUuid: string, setCode: string,
     *                             collectorNumber: string, priceEur: float|null,
     *                             printingCount: int}>
     *         Clave `oracleId|acabado`. Solo trae las cartas que el catálogo sabe
     *         imprimir; lo que no aparece es que no se ha podido elegir.
     */
    public function elegir(array $veredictos): array
    {
        /** @var array<string, array{oracleId: string, finish: string}> $peticiones */
        $peticiones = [];

        foreach ($veredictos as $veredicto) {
            $acabado = $veredicto->fila->finish;

            if ($veredicto->estaResuelta()) {
                if (!$veredicto->tieneImpresion()) {
                    $this->anotar($peticiones, $veredicto->oracleId, $acabado);
                }

                continue;
            }

            foreach ($veredicto->candidatos as $candidato) {
                if (($candidato['printingUuid'] ?? null) === null) {
                    $this->anotar($peticiones, $candidato['oracleId'] ?? null, $acabado);
                }
            }
        }

        if ($peticiones === []) {
            return [];
        }

        return $this->catalogo->masBaratasPorCarta(array_values($peticiones));
    }

    /**
     * La clave con la que se pregunta y se busca. El acabado forma parte de ella
     * porque el precio se une por `(printing, finish)`: la impresión más barata
     * de una carta en foil no tiene por qué ser la misma que en normal.
     */
    public static function clave(string $oracleId, string $finish): string
    {
        return $oracleId . '|' . $finish;
    }

    /**
     * @param array<string, array{oracleId: string, finish: string}> $peticiones
     */
    private function anotar(array &$peticiones, ?string $oracleId, string $finish): void
    {
        if ($oracleId === null || $oracleId === '') {
            return;
        }

        // Indexado por la clave: la misma carta repetida en 300 líneas se
        // pregunta UNA vez.
        $peticiones[self::clave($oracleId, $finish)] = [
            'oracleId' => $oracleId,
            'finish'   => $finish,
        ];
    }
}
