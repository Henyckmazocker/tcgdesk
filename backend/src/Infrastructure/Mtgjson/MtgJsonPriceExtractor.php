<?php

declare(strict_types=1);

namespace App\Infrastructure\Mtgjson;

/**
 * Saca de un objeto de precios de MTGJSON lo único que guardamos:
 * `paper.cardmarket.retail`, en euros.
 *
 * MTGJSON publica cinco proveedores y cuatro de ellos cotizan en USD
 * (tcgplayer, cardkingdom, manapool, cardhoarder). Guardarlos multiplicaría por
 * cuatro la tabla de precios y metería conversión de divisa en toda la UI a
 * cambio de nada: el mercado que importa desde España es Cardmarket, que además
 * es el único que ya viene en euros.
 *
 * También se ignora `buylist` —lo que el vendedor paga por comprarte la carta—:
 * la valoración de una colección va a precio de venta.
 */
class MtgJsonPriceExtractor
{
    /** Los tres acabados del ENUM de mtg_price_daily. */
    private const ACABADOS = ['normal', 'foil', 'etched'];

    /**
     * @param  array<string, mixed> $precios El objeto de un uuid de AllPrices*
     * @return list<array{finish: string, price_date: string, price_eur: float}>
     */
    public function extraer(array $precios): array
    {
        $cardmarket = $precios['paper']['cardmarket'] ?? null;

        if (!is_array($cardmarket)) {
            return [];
        }

        // Cinturón y tirantes: si MTGJSON cambiara la divisa de Cardmarket, es
        // preferible no guardar nada a guardar dólares en una columna que toda
        // la app lee como euros.
        if (($cardmarket['currency'] ?? 'EUR') !== 'EUR') {
            return [];
        }

        $retail = $cardmarket['retail'] ?? [];

        if (!is_array($retail)) {
            return [];
        }

        $filas = [];

        foreach (self::ACABADOS as $acabado) {
            $porFecha = $retail[$acabado] ?? null;

            if (!is_array($porFecha)) {
                continue;
            }

            foreach ($porFecha as $fecha => $precio) {
                if (!is_numeric($precio)) {
                    continue;
                }

                $filas[] = [
                    'finish'     => $acabado,
                    'price_date' => (string) $fecha,
                    'price_eur'  => (float) $precio,
                ];
            }
        }

        return $filas;
    }

    /**
     * De todas las filas, la más reciente por acabado.
     *
     * Es lo que va a `mtg_price_current`, la tabla desnormalizada que convierte
     * "ordenar el catálogo por precio" en un índice en vez de en una subconsulta
     * correlacionada con MAX(price_date).
     *
     * @param  list<array{finish: string, price_date: string, price_eur: float}> $filas
     * @return list<array{finish: string, price_date: string, price_eur: float}>
     */
    public function masRecientesPorAcabado(array $filas): array
    {
        $mejor = [];

        foreach ($filas as $fila) {
            $actual = $mejor[$fila['finish']] ?? null;

            if ($actual === null || $fila['price_date'] > $actual['price_date']) {
                $mejor[$fila['finish']] = $fila;
            }
        }

        return array_values($mejor);
    }
}
