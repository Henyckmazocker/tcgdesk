<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * El veredicto del resolvedor sobre UNA fila importada.
 *
 * Solo hay dos estados y el segundo no es un error: o la fila apunta a una carta
 * concreta sin margen de duda, o va a **conflicto** con sus candidatos para que
 * el usuario elija en la previsualización. La regla de oro del plan es que no
 * existe un tercer estado «lo más probable es que sea esta»: meter *Chain
 * Lightning* en la colección creyendo que es *Lightning Bolt* corrompe el
 * inventario sin que nadie se entere, y eso es peor que pedir una confirmación.
 *
 * ## `printingUuid` puede ser null en una fila RESUELTA
 *
 * No es un descuido. Los pasos 1 y 2 identifican **la impresión** (Scryfall ID, o
 * set + número, único en las 110.384 filas de `mtg_printing`), así que traen
 * `printingUuid`. Los pasos 3 y 4 identifican **la carta** —el usuario tecleó un
 * nombre y nada más—, y ahí solo hay una impresión evidente cuando la carta se
 * imprimió una sola vez. En el resto, la carta está resuelta y la edición sigue
 * sin decidir: elegir una por su cuenta sería inventarse un dato que el fichero
 * no traía, y de qué edición se escribe en la colección decide la
 * previsualización (M5), no el resolvedor.
 */
final class CardResolution
{
    /** Motivos del contrato `import_preview`, literales del plan. */
    public const AMBIGUA       = 'ambiguous';
    public const NO_ENCONTRADA = 'not_found';
    public const INVALIDA      = 'invalid';

    /**
     * Motivo AÑADIDO al contrato el 2026-09-10: la línea trae **nombre y
     * set + número a la vez y no dicen la misma carta**.
     *
     * No es `ambiguous`: una ambigüedad es no tener información suficiente para
     * elegir, y aquí sobra información —hay dos datos exactos que se contradicen—.
     * `Lim-Dûl's Vault (ICE) 96` es la línea que lo describe: `ICE 96` es *Shyft*.
     * Los candidatos vienen en el orden en que el usuario los tecleó: primero la
     * carta que dice el NOMBRE, y la última, la impresión que dice el NÚMERO.
     */
    public const DESACUERDO = 'mismatch';

    /**
     * @param string|null                     $setCode    Edición, solo si se conoce la impresión
     * @param string|null                     $paso       '1', '2', '3', '3b' o '4'; null si no resolvió
     * @param string|null                     $motivo     AMBIGUA | NO_ENCONTRADA | INVALIDA | DESACUERDO; null si resolvió
     * @param list<array<string, mixed>>      $candidatos Lo que el usuario tendrá que desempatar
     */
    private function __construct(
        public readonly ParsedRow $fila,
        public readonly ?string $printingUuid,
        public readonly ?string $oracleId,
        public readonly ?string $name,
        public readonly ?string $setCode,
        public readonly ?string $paso,
        public readonly ?string $motivo,
        public readonly array $candidatos,
    ) {
    }

    /**
     * @param array<string, mixed> $carta Candidato único: oracleId, name y, si se
     *                                    conoce, printingUuid
     */
    public static function resuelta(ParsedRow $fila, array $carta, string $paso): self
    {
        return new self(
            $fila,
            isset($carta['printingUuid']) ? (string) $carta['printingUuid'] : null,
            isset($carta['oracleId']) ? (string) $carta['oracleId'] : null,
            isset($carta['name']) ? (string) $carta['name'] : null,
            isset($carta['setCode']) ? (string) $carta['setCode'] : null,
            $paso,
            null,
            [],
        );
    }

    /** @param list<array<string, mixed>> $candidatos */
    public static function conflicto(ParsedRow $fila, string $motivo, array $candidatos = []): self
    {
        return new self($fila, null, null, null, null, null, $motivo, $candidatos);
    }

    public function estaResuelta(): bool
    {
        return $this->motivo === null;
    }

    /** ¿Se sabe también QUÉ impresión, y no solo qué carta? */
    public function tieneImpresion(): bool
    {
        return $this->printingUuid !== null;
    }
}
