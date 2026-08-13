<?php

declare(strict_types=1);

namespace App\Infrastructure\Mtgjson;

/**
 * Traduce un set de MTGJSON a las filas de nuestras tablas mtg_*.
 *
 * Es la única clase que conoce la forma del JSON de MTGJSON, y no toca la base de
 * datos: recibe arrays y devuelve arrays. Por eso es la pieza que se testea de
 * verdad —el resto de la ingesta es PDO— y por eso el importador puede cambiar de
 * fuente sin tocar el repositorio.
 */
class MtgJsonMapper
{
    /**
     * Idiomas que MTGJSON publica sin separación por espacios.
     *
     * Sus nombres se copian a `name_cjk`, que es la columna con parser `ngram`:
     * el parser FULLTEXT por defecto tokeniza por espacios y con estos idiomas
     * devuelve cero resultados. El resto de idiomas deja la columna a NULL para
     * no engordar un índice que no van a usar.
     */
    private const IDIOMAS_CJK = [
        'Japanese',
        'Korean',
        'Chinese Simplified',
        'Chinese Traditional',
    ];

    /** MTGJSON escribe las legalidades en CamelCase; nuestro ENUM, en snake. */
    private const LEGALIDADES = [
        'legal'      => 'legal',
        'banned'     => 'banned',
        'restricted' => 'restricted',
        'not legal'  => 'not_legal',
    ];

    /** Rarezas que admite el ENUM de mtg_printing. */
    private const RAREZAS = ['common', 'uncommon', 'rare', 'mythic', 'special', 'bonus'];

    /**
     * Fila de mtg_set a partir del objeto de set.
     *
     * @param  array<string, mixed> $set
     * @return array<string, mixed>
     */
    public function set(array $set): array
    {
        return [
            'code'           => $set['code'],
            'name'           => $set['name'],
            'release_date'   => $set['releaseDate'] ?? null,
            'set_type'       => $set['type'] ?? null,
            'total_set_size' => $set['totalSetSize'] ?? null,
            'block'          => $set['block'] ?? null,
            'is_online_only' => !empty($set['isOnlineOnly']) ? 1 : 0,
        ];
    }

    /**
     * Decide si una carta de MTGJSON se ingiere o se descarta.
     *
     * Se queda **solo con la cara frontal** de las cartas multi-cara. No es una
     * preferencia: las dos caras de una `transform` comparten `scryfallId` (medido
     * sobre ISD: 20 pares, mismo id) y `mtg_printing.uq_scryfall` es único, así
     * que insertar ambas rompe la ingesta. No se pierde nada buscable: `name` y
     * cada entrada de `foreignData` ya traen los dos nombres unidos por ' // ',
     * en los diez idiomas.
     *
     * @param array<string, mixed> $card
     */
    public function esCaraIngerible(array $card): bool
    {
        $side = $card['side'] ?? null;

        return $side === null || $side === 'a';
    }

    /**
     * Fila de mtg_card (la carta conceptual, deduplicada por oracle_id).
     *
     * Devuelve null si la carta no trae `scryfallOracleId`: sin él no hay clave
     * primaria posible y la FK de mtg_printing no tendría a qué apuntar. El
     * importador las cuenta y las reporta en vez de reventar la ingesta entera.
     *
     * @param  array<string, mixed>      $card
     * @param  array<string, mixed>|null $carasTraseras Caras b/c del mismo cartón, para el texto
     * @return array<string, mixed>|null
     */
    public function card(array $card, array $carasTraseras = []): ?array
    {
        $oracleId = $card['identifiers']['scryfallOracleId'] ?? null;

        if ($oracleId === null || $oracleId === '') {
            return null;
        }

        // El texto de las caras traseras se concatena para que la ficha no pierda
        // la mitad de la carta. Los demás campos (coste, tipo, colores) son los de
        // la cara frontal, que es la que define el cartón.
        $texto = $card['text'] ?? null;
        foreach ($carasTraseras as $cara) {
            if (!empty($cara['text'])) {
                $texto = ($texto ?? '') . "\n//\n" . $cara['text'];
            }
        }

        return [
            'oracle_id'      => $oracleId,
            'name'           => $card['name'],
            'mana_cost'      => $card['manaCost'] ?? null,
            'mana_value'     => $card['manaValue'] ?? null,
            'type_line'      => $card['type'] ?? null,
            'oracle_text'    => $texto,
            'colors'         => implode('', $card['colors'] ?? []),
            'color_identity' => implode('', $card['colorIdentity'] ?? []),
            'layout'         => $card['layout'] ?? null,
            'edhrec_rank'    => $card['edhrecRank'] ?? null,
        ];
    }

    /**
     * Fila de mtg_printing (el cartón concreto).
     *
     * @param  array<string, mixed> $card
     * @return array<string, mixed>|null
     */
    public function printing(array $card, string $setCode): ?array
    {
        $oracleId = $card['identifiers']['scryfallOracleId'] ?? null;

        if ($oracleId === null || $oracleId === '') {
            return null;
        }

        $finishes = $card['finishes'] ?? [];
        $rareza   = strtolower($card['rarity'] ?? '');

        return [
            'uuid'             => $card['uuid'],
            'oracle_id'        => $oracleId,
            'set_code'         => $setCode,
            'collector_number' => (string) ($card['number'] ?? ''),
            // MTGJSON publica alguna rareza fuera del ENUM en productos raros
            // (p. ej. 'oversized'); cae a 'special' en vez de abortar el set.
            'rarity'           => in_array($rareza, self::RAREZAS, true) ? $rareza : 'special',
            'artist'           => $card['artist'] ?? null,
            'border_color'     => $card['borderColor'] ?? null,
            'frame_version'    => $card['frameVersion'] ?? null,
            'is_reprint'       => !empty($card['isReprint']) ? 1 : 0,
            'has_foil'         => in_array('foil', $finishes, true) ? 1 : 0,
            'has_nonfoil'      => in_array('nonfoil', $finishes, true) ? 1 : 0,
            'has_etched'       => in_array('etched', $finishes, true) ? 1 : 0,
            'scryfall_id'      => $card['identifiers']['scryfallId'] ?? null,
            'mcm_id'           => isset($card['identifiers']['mcmId'])
                ? (int) $card['identifiers']['mcmId']
                : null,
        ];
    }

    /**
     * Filas de mtg_printing_localized a partir de `foreignData`.
     *
     * Una por idioma. `name_cjk` solo se rellena para los idiomas sin espacios;
     * en el resto va NULL y el índice ngram no los ve.
     *
     * @param  array<string, mixed> $card
     * @return list<array<string, mixed>>
     */
    public function localized(array $card): array
    {
        $filas = [];

        foreach ($card['foreignData'] ?? [] as $traduccion) {
            $idioma = $traduccion['language'] ?? null;
            $nombre = $traduccion['name'] ?? null;

            if ($idioma === null || $nombre === null || $nombre === '') {
                continue;
            }

            $filas[] = [
                'printing_uuid' => $card['uuid'],
                'language'      => $idioma,
                'name'          => $nombre,
                'text'          => $traduccion['text'] ?? null,
                'type_line'     => $traduccion['type'] ?? null,
                'name_cjk'      => in_array($idioma, self::IDIOMAS_CJK, true) ? $nombre : null,
            ];
        }

        return $filas;
    }

    /**
     * Filas de mtg_legality. Una por formato.
     *
     * @param  array<string, mixed> $card
     * @return list<array<string, mixed>>
     */
    public function legalities(array $card): array
    {
        $oracleId = $card['identifiers']['scryfallOracleId'] ?? null;

        if ($oracleId === null || $oracleId === '') {
            return [];
        }

        $filas = [];

        foreach ($card['legalities'] ?? [] as $formato => $estado) {
            $normalizado = self::LEGALIDADES[strtolower((string) $estado)] ?? null;

            if ($normalizado === null) {
                continue;
            }

            $filas[] = [
                'oracle_id' => $oracleId,
                'format'    => mb_substr((string) $formato, 0, 24),
                'status'    => $normalizado,
            ];
        }

        return $filas;
    }
}
