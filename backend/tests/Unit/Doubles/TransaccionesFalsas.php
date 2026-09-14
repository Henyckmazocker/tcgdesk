<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Repository\TransactionManagerInterface;
use Throwable;

/**
 * La transacción de mentira: ejecuta la obra y cuenta cuántas veces se abrió.
 *
 * No puede deshacer nada —los dobles escriben en arrays de PHP— y por eso lo que
 * prueba es lo único que un doble puede probar aquí: que las dos escrituras
 * **van dentro de una** y no cada una por su lado. Que el `rollBack()` de
 * verdad funcione es de MySQL, y se comprueba contra la base real.
 */
class TransaccionesFalsas implements TransactionManagerInterface
{
    public int $aperturas = 0;

    public int $fallos = 0;

    public function enTransaccion(callable $obra): mixed
    {
        $this->aperturas++;

        try {
            return $obra();
        } catch (Throwable $e) {
            $this->fallos++;

            throw $e;
        }
    }
}
