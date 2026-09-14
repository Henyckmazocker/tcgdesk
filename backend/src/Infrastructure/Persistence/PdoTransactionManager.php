<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\TransactionManagerInterface;
use PDO;
use Throwable;

/**
 * La transacción de varios repositorios, sobre la conexión PDO compartida.
 *
 * Los `MySql*Repository` reciben **la misma instancia de PDO** del contenedor,
 * así que abrir aquí la transacción hace que todo lo que escriban dentro de la
 * obra vaya en ella. `upsertLote()` ya está escrito contando con eso: mira
 * `inTransaction()` y no abre la suya cuando manda alguien de fuera.
 *
 * El mismo cuidado, aquí: si al entrar ya hay una transacción abierta **no se
 * abre otra** ni se hace commit al salir, porque anidar `beginTransaction()` en
 * PDO no crea una transacción sino que lanza, y un commit anticipado
 * confirmaría a medias el trabajo de quien la abrió.
 */
class PdoTransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function enTransaccion(callable $obra): mixed
    {
        $propia = !$this->db->inTransaction();

        if ($propia) {
            $this->db->beginTransaction();
        }

        try {
            $resultado = $obra();

            if ($propia) {
                $this->db->commit();
            }

            return $resultado;
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }
}
