<?php

declare(strict_types=1);

namespace App\Infrastructure\Scryfall;

use InvalidArgumentException;

/**
 * Espaciador de peticiones: como mucho N por segundo.
 *
 * Scryfall pide **10 req/s** ([[TCGDesk/Fuentes de Datos]], «Reglas de uso que
 * hay que respetar»). No es una recomendación amable: es la condición de una
 * fuente que nos deja las imágenes gratis, y saltársela con 30.000 cartas en la
 * colección es la forma de que nos corten.
 *
 * Se espacian los **inicios** de petición, no los finales: si una descarga tarda
 * 400 ms, la siguiente sale ya, porque en ese segundo solo hemos pedido una vez.
 * Espaciar los finales daría 1/(0,1 + latencia) req/s, bastante menos de lo
 * permitido, y multiplicaría por tres el tiempo del comando sin ganar nada.
 *
 * El reloj y la espera se inyectan para que el test compruebe el intervalo sin
 * dormir de verdad: un test que tarda un segundo por cada diez peticiones
 * simuladas no se escribe, y lo que no se escribe no protege nada.
 */
class RateLimiter
{
    /** Segundos que deben pasar entre dos inicios de petición. */
    private readonly float $intervalo;

    private ?float $ultimo = null;

    /** @var callable(): float */
    private $reloj;

    /** @var callable(int): mixed */
    private $dormir;

    /**
     * @param float                  $porSegundo Peticiones por segundo permitidas
     * @param (callable(): float)|null    $reloj   Por defecto `microtime(true)`
     * @param (callable(int): mixed)|null $dormir  Por defecto `usleep`, en microsegundos
     */
    public function __construct(float $porSegundo = 10.0, ?callable $reloj = null, ?callable $dormir = null)
    {
        if ($porSegundo <= 0) {
            throw new InvalidArgumentException('El ritmo debe ser mayor que cero.');
        }

        $this->intervalo = 1.0 / $porSegundo;
        $this->reloj     = $reloj  ?? static fn (): float => microtime(true);
        $this->dormir    = $dormir ?? static fn (int $us) => usleep($us);
    }

    /** Bloquea lo necesario para que no se supere el ritmo. */
    public function esperar(): void
    {
        $ahora = ($this->reloj)();

        if ($this->ultimo !== null) {
            $faltan = $this->intervalo - ($ahora - $this->ultimo);

            if ($faltan > 0) {
                ($this->dormir)((int) ceil($faltan * 1_000_000));
                $ahora = ($this->reloj)();
            }
        }

        $this->ultimo = $ahora;
    }

    /** Peticiones por segundo configuradas; lo usa el comando para informar. */
    public function porSegundo(): float
    {
        return 1.0 / $this->intervalo;
    }
}
