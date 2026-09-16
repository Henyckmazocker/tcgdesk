<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Repository\OrbDescriptorRepositoryInterface;

/**
 * `mtg_printing_orb` de mentira, en memoria.
 *
 * Reproduce lo único que hay que reproducir para probar el controller, y es
 * exactamente lo que `INSERT IGNORE` hace y lo que un upsert NO haría:
 * **sembrar una cara que ya estaba devuelve `false` y no cambia nada**. Es la
 * contención contra el envenenamiento del índice, y un doble que sobrescribiera
 * dejaría pasar el fallo entero.
 *
 * **Desde el M6 la clave lleva el idioma** (`<uuid>|<face>|<idioma>`), igual que
 * la PK de la tabla: una cara sembrada en inglés tiene que seguir sembrable en
 * español, y un doble que siguiera casando por `(uuid, face)` devolvería 409 y
 * pondría el test en verde sobre el fallo que el hito existe para arreglar.
 *
 * Y `refsDe()` reproduce la **caída al inglés**: si esa impresión no está
 * sembrada en el idioma pedido pero sí en inglés, manda la inglesa y lo dice en
 * `language`.
 *
 * **Y reproduce la OTRA caída, la del `scryfallId`**, que es la que la enmienda
 * del 2026-09-16 sacó a la luz: cuando MTGJSON no publica la imagen localizada,
 * el id que va es el inglés y `scryfallLanguage` dice `'English'`. Son dos
 * caídas independientes —la de la referencia sembrada y la de la imagen— y el
 * doble las tiene separadas porque en la consulta real lo están.
 */
class DescriptoresOrbFalsos implements OrbDescriptorRepositoryInterface
{
    /** El idioma al que se cae cuando la impresión no está sembrada en el pedido. */
    private const INGLES = 'English';

    /** @var list<string> Los `printing_uuid` que existen en el catálogo de mentira. */
    public array $enCatalogo = [];

    /**
     * Las impresiones de cada `oracle_id`, en el orden en que las devuelve el SQL.
     *
     * `scryfallId` es el inglés, el de `mtg_printing`. `scryfallIdPorIdioma` son
     * los de `mtg_printing_localized`, que solo existen para los idiomas que
     * MTGJSON publica: lo que falte cae al inglés, que es lo que hace el
     * `COALESCE` de la consulta real.
     *
     * @var array<string, list<array{
     *     printingUuid: string,
     *     scryfallId: string|null,
     *     scryfallIdPorIdioma?: array<string, string>
     * }>>
     */
    public array $impresionesPorOracle = [];

    /**
     * Las filas sembradas, por `<uuid>|<face>|<idioma>`.
     *
     * @var array<string, array{nfeatures: int, keypoints: int, localPath: string}>
     */
    public array $filas = [];

    public function refsDe(string $oracleId, string $language): array
    {
        $salida = [];

        foreach ($this->impresionesPorOracle[$oracleId] ?? [] as $impresion) {
            $localizado = $impresion['scryfallIdPorIdioma'][$language] ?? null;
            $scryfallId = $localizado ?? $impresion['scryfallId'];
            // El `CASE WHEN l.scryfall_id IS NOT NULL` de la consulta real: el
            // idioma de la IMAGEN, que solo es el pedido si MTGJSON la publica.
            $idiomaImagen = $localizado !== null ? $language : self::INGLES;
            $sembradas    = [];

            foreach (['front', 'back'] as $face) {
                // El idioma pedido manda; el inglés es la red de abajo. Es el
                // `ROW_NUMBER() ... ORDER BY (language = ?) DESC` de la consulta
                // real, que se queda con UNA fila por (impresión, cara).
                foreach ([$language, self::INGLES] as $candidato) {
                    $fila = $this->filas[$this->clave($impresion['printingUuid'], $face, $candidato)] ?? null;

                    if ($fila === null) {
                        continue;
                    }

                    $sembradas[] = [
                        'printingUuid'     => $impresion['printingUuid'],
                        'scryfallId'       => $scryfallId,
                        'scryfallLanguage' => $idiomaImagen,
                        'face'             => $face,
                        'language'         => $candidato,
                        'nfeatures'        => $fila['nfeatures'],
                        'keypoints'        => $fila['keypoints'],
                        'localPath'        => $fila['localPath'],
                    ];

                    break;
                }
            }

            if ($sembradas === []) {
                // El LEFT JOIN sin pareja: una fila por impresión, con todo a
                // null. Es el caso que el `*Hecho cuando:*` del M1 mide. El
                // idioma que sale es el PEDIDO: es en el que hay que sembrarla.
                $salida[] = [
                    'printingUuid'     => $impresion['printingUuid'],
                    'scryfallId'       => $scryfallId,
                    'scryfallLanguage' => $idiomaImagen,
                    'face'             => 'front',
                    'language'         => $language,
                    'nfeatures'        => null,
                    'keypoints'        => null,
                    'localPath'        => null,
                ];

                continue;
            }

            foreach ($sembradas as $fila) {
                $salida[] = $fila;
            }
        }

        return $salida;
    }

    public function estadoDe(string $printingUuid, string $face, string $language): array
    {
        return [
            'existe'   => in_array($printingUuid, $this->enCatalogo, true),
            'sembrada' => isset($this->filas[$this->clave($printingUuid, $face, $language)]),
        ];
    }

    public function sembrar(
        string $printingUuid,
        string $face,
        string $language,
        int $nfeatures,
        int $keypoints,
        string $rutaRelativa
    ): bool {
        $clave = $this->clave($printingUuid, $face, $language);

        // INSERT IGNORE: el primero gana. Sobrescribir aquí sería el upsert de
        // `mtg_image_cache`, que es justo lo que esta tabla NO puede hacer.
        if (isset($this->filas[$clave])) {
            return false;
        }

        $this->filas[$clave] = [
            'nfeatures' => $nfeatures,
            'keypoints' => $keypoints,
            'localPath' => $rutaRelativa,
        ];

        return true;
    }

    public function olvidar(string $printingUuid, string $face, string $language): void
    {
        unset($this->filas[$this->clave($printingUuid, $face, $language)]);
    }

    private function clave(string $printingUuid, string $face, string $language): string
    {
        return $printingUuid . '|' . $face . '|' . $language;
    }
}
