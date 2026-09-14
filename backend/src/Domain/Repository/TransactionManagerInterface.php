<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Una transacción que abarca **más de un repositorio**.
 *
 * Existe por un caso concreto y solo por ese: `ApplyImport` escribe en
 * `mtg_collection_item` por el puerto de colección y crea el mazo por el de
 * mazos, y el plan de M7 exige que las dos cosas ocurran **en la misma
 * transacción** —o una decklist a medias dejaría las cartas en la colección y
 * ningún mazo, o al revés—. Dentro de un solo agregado la transacción es del
 * repositorio (`deleteConCartas()`, `changeCardIdentity()`); cuando cruza dos
 * puertos, no hay repositorio a quien pedírsela.
 *
 * No es una fuga de la base de datos al dominio: el use case no ve PDO, no abre
 * ni cierra nada y no sabe si por debajo hay una transacción, un `SAVEPOINT` o
 * una cola. Solo dice **qué tiene que pasar entero o no pasar**.
 *
 * La implementación **respeta la transacción que ya esté abierta** —anidar
 * `beginTransaction()` en PDO no crea una transacción, la ignora—, igual que
 * hace `MySqlCollectionRepository::upsertLote()`.
 */
interface TransactionManagerInterface
{
    /**
     * Ejecuta la obra dentro de una transacción y devuelve lo que ella devuelva.
     *
     * Cualquier excepción hace `rollBack()` y se relanza: es lo que convierte
     * un `printing_uuid` que no está en el catálogo en un error claro y no en
     * una importación a medias.
     *
     * @template T
     * @param  callable():T $obra
     * @return T
     */
    public function enTransaccion(callable $obra): mixed;
}
