<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Scryfall\RateLimiter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * El ritmo de 10 req/s no es una recomendación amable: es la condición de uso de
 * la fuente que nos deja las imágenes gratis ([[TCGDesk/Fuentes de Datos]]).
 *
 * El reloj y la espera se inyectan, así que este test comprueba el intervalo real
 * **sin dormir ni un milisegundo**. Un test que tardara un segundo por cada diez
 * peticiones simuladas no se escribiría, y lo que no se escribe no protege nada.
 */
final class RateLimiterTest extends TestCase
{
    /** @var list<int> microsegundos dormidos, en orden */
    private array $dormido = [];

    private float $reloj = 0.0;

    private function limitador(float $porSegundo): RateLimiter
    {
        return new RateLimiter(
            $porSegundo,
            fn (): float => $this->reloj,
            function (int $us): void {
                $this->dormido[] = $us;
                // El reloj avanza lo que se ha dormido: es lo que hace del doble
                // una simulación y no un contador de llamadas.
                $this->reloj += $us / 1_000_000;
            }
        );
    }

    public function testLaPrimeraPeticionNoEspera(): void
    {
        $this->limitador(10.0)->esperar();

        self::assertSame([], $this->dormido);
    }

    /** A 10 req/s, dos peticiones seguidas se separan 100 ms. */
    public function testDiezPorSegundoSeparaCienMilisegundos(): void
    {
        $limitador = $this->limitador(10.0);

        $limitador->esperar();
        $limitador->esperar();

        self::assertSame([100_000], $this->dormido);
    }

    /**
     * Lo que se espacia son los **inicios**, no los finales. Si la descarga
     * anterior tardó más que el intervalo, la siguiente sale ya: en ese segundo
     * solo hemos pedido una vez y frenar más regalaría ancho de banda a cambio
     * de nada.
     */
    public function testSiLaDescargaTardoMasQueElIntervaloNoSeEspera(): void
    {
        $limitador = $this->limitador(10.0);

        $limitador->esperar();
        $this->reloj += 0.4; // la descarga tardó 400 ms
        $limitador->esperar();

        self::assertSame([], $this->dormido);
    }

    /** Diez peticiones a 10 req/s ocupan 0,9 s de espera: nueve intervalos. */
    public function testDiezPeticionesNuncaSuperanElRitmo(): void
    {
        $limitador = $this->limitador(10.0);

        for ($i = 0; $i < 10; $i++) {
            $limitador->esperar();
        }

        self::assertCount(9, $this->dormido);
        self::assertSame(900_000, array_sum($this->dormido));
        self::assertEqualsWithDelta(0.9, $this->reloj, 0.000001);

        // La comprobación que de verdad importa: 10 peticiones en 0,9 s son
        // 11,1 req/s de pico aparente, pero el intervalo mínimo entre dos
        // cualesquiera es de 100 ms, que es lo que Scryfall mide.
        foreach ($this->dormido as $us) {
            self::assertGreaterThanOrEqual(100_000, $us);
        }
    }

    public function testUnRitmoMasLentoEsperaMas(): void
    {
        $limitador = $this->limitador(2.0);

        $limitador->esperar();
        $limitador->esperar();

        self::assertSame([500_000], $this->dormido);
        self::assertSame(2.0, $limitador->porSegundo());
    }

    public function testUnRitmoCeroONegativoNoSeAcepta(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimiter(0.0);
    }
}
