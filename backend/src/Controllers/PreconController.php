<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\ImportPreconToCollection;
use InvalidArgumentException;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * La única acción de los precons que **escribe**.
 *
 * Leer el catálogo de precons va por las dos rutas `GET` de `CatalogHttpRouter`
 * —lectura pública, paginable y cacheable, la tercera divergencia aprobada—, y
 * **meter la caja en tu colección no**: es dato de usuario, así que vuelve al
 * endpoint único por `POST` con `Auth → Csrf` como el resto de las escrituras.
 * La divergencia `GET` es solo de lectura y no se extiende.
 *
 * Controller fino como los otros tres: saca el `user_id` de donde lo dejó
 * `AuthMiddleware` —nunca del cuerpo—, delega y traduce a código HTTP:
 *
 *  - **`null` del use case = 404.** Ese `file_name` no está en `mtg_precon`.
 *  - **`InvalidArgumentException` = 422.** Un `deck_status` que no existe, o una
 *    caja sin ni una carta importable.
 *  - **`PDOException` de clave foránea = 422.** El use case ya deja fuera las
 *    huérfanas, así que aquí solo puede llegar si el catálogo cambió entre la
 *    lectura y la escritura. La transacción lo ha tirado todo: la colección
 *    sigue como estaba y no hay mazo a medias.
 */
class PreconController extends BaseController
{
    public function __construct(
        private readonly ImportPreconToCollection $importar,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Un clic: la caja entera en la colección y el mazo montado. O, con
     * `is_wishlist`, en la lista de deseos y el mazo pidiéndola entera.
     */
    public function addToCollection(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->importar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $this->logger->info('Precon rechazado: printing inexistente', [
                    'user_id'   => $userId,
                    'file_name' => $request['data']['file_name'] ?? null,
                ]);

                return $this->errorResponse(
                    'Alguna carta de esa caja ya no está en el catálogo. No se ha importado nada.',
                    422
                );
            }

            // Desbordamiento del SMALLINT UNSIGNED al sumar cantidades.
            if ($e->getCode() === '22003') {
                return $this->errorResponse('Alguna cantidad se sale del máximo por línea.', 422);
            }

            throw $e;
        }

        if ($resultado === null) {
            return $this->errorResponse('Ese precon no existe en el catálogo.', 404);
        }

        $this->logger->info('Precon importado', [
            'user_id'   => $userId,
            'file_name' => $resultado['precon']['fileName'] ?? null,
            'wishlist'  => $resultado['isWishlist'],
            'deck_id'   => $resultado['deck']['id'] ?? null,
            'inserted'  => $resultado['inserted'],
            'updated'   => $resultado['updated'],
            'quantity'  => $resultado['totalQuantity'],
            'unknown'   => $resultado['unknownPrintings'],
        ]);

        return $this->successResponse($this->mensaje($resultado), $resultado);
    }

    /**
     * El parte del clic, con los números que hacen falta para confiar en él.
     *
     * Dice **cuántos ejemplares** entraron y cuántas líneas ya tenías sumadas
     * —comprar dos veces la misma caja suma y no duplica, y hay que verlo—, y
     * **dice en voz alta lo que se asumió**: que las cartas son inglesas y están
     * en NM, porque MTGJSON no publica ni idioma ni condición.
     *
     * Y dice **dónde** han caído: colección o lista de deseos. Es la misma
     * operación con las cartas en el otro conjunto, así que un mensaje que
     * dijera «en tu colección» a quien pulsó «lo quiero» estaría mintiendo sobre
     * la única diferencia que hay.
     *
     * @param array<string, mixed> $resultado
     */
    private function mensaje(array $resultado): string
    {
        $aDeseos = (bool) $resultado['isWishlist'];

        $mensaje = ($aDeseos ? 'Caja deseada: ' : 'Caja importada: ')
            . $resultado['totalQuantity'] . ' ejemplares en tu '
            . ($aDeseos ? 'lista de deseos' : 'colección') . ' ('
            . $resultado['inserted'] . ' líneas nuevas y ' . $resultado['updated'] . ' sumadas a las que ya tenías)'
            . ', y el mazo «' . ($resultado['deck']['name'] ?? '?') . '» '
            . ($aDeseos ? 'creado, pidiendo sus ' : 'montado con ')
            . $resultado['deckCards'] . ' cartas.';

        if ($resultado['unknownPrintings'] > 0) {
            $mensaje .= ' ' . $resultado['unknownPrintings'] . ' línea(s) se han quedado fuera porque esas '
                . 'cartas todavía no están en tu catálogo.';
        }

        if ($resultado['tokenLines'] > 0) {
            $mensaje .= ' Las ' . $resultado['tokenLines'] . ' línea(s) de fichas van en el mazo pero no '
                . 'en la ' . ($aDeseos ? 'lista de deseos' : 'colección') . '.';
        }

        return $mensaje . ' Se han dado por ' . $resultado['assumed']['language'] . ' y '
            . $resultado['assumed']['condition'] . ': MTGJSON no publica ni idioma ni estado.';
    }
}
