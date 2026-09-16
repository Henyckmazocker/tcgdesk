<?php

declare(strict_types=1);

namespace App\Cli\Commands;

use App\Cli\CommandInterface;
use App\Domain\Repository\PriceRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Dice si el histórico de precios se ha quedado atrás.
 *
 * **Por qué existe.** `mtg_price_daily` estuvo 33 días sin una sola fila nueva y
 * no lo delató nada: el cron no estaba instalado, así que no había ni un código
 * de salida que mirar. Lo perdido no se recupera —MTGJSON retiene 90 días— y el
 * único síntoma de que el sync dejó de correr es que `MAX(price_date)` se queda
 * quieto. Este comando es ese síntoma convertido en código de salida.
 *
 * **Por qué es un comando y no un `SELECT` dentro de `prices-sync.sh`.** Por el
 * mismo criterio que el resto de la capa CLI: se dispara desde cron, devuelve
 * código de salida y no necesita HTTP — y siendo un comando se prueba con
 * PHPUnit, que es lo que un `mysql -e` incrustado en un script no permite.
 *
 * **Lo que NO hace, a propósito:** ni notifica, ni mira precios carta a carta,
 * ni escribe una tabla de estado de jobs. Eso es otra feature; aquí basta con un
 * código de salida distinto de 0 y una línea en el log del cron.
 */
class PricesHealthCommand implements CommandInterface
{
    /**
     * Días de desfase que se toleran sin dar la alarma.
     *
     * **Dos y no uno.** MTGJSON publica su build a las 9:00 EST y el cron corre a
     * las 15:30 CEST: un día que el job falle se recupera solo al siguiente, así
     * que avisar al primer día de desfase sería avisar de algo que ya está
     * arreglándose. A partir del tercero ya no hay explicación inocente.
     */
    private const MARGEN_DIAS = 2;

    /** @var callable(): DateTimeImmutable */
    private $hoy;

    /**
     * @param (callable(): DateTimeImmutable)|null $hoy El reloj se inyecta —como
     *        en `RateLimiter`— porque si no los tres casos que hay que probar
     *        (al día, un día de margen, parado) dependerían de qué día se lance
     *        la suite, y un test que solo pasa hoy no prueba nada.
     */
    public function __construct(
        private readonly PriceRepositoryInterface $precios,
        private readonly LoggerInterface $logger,
        ?callable $hoy = null
    ) {
        $this->hoy = $hoy ?? static fn (): DateTimeImmutable => new DateTimeImmutable('today');
    }

    public function getName(): string
    {
        return 'prices:health';
    }

    public function getDescription(): string
    {
        return 'Avisa si el histórico de precios lleva días sin actualizarse';
    }

    public function run(array $args): int
    {
        $ultima = $this->precios->ultimaFechaHistorico();

        if ($ultima === null) {
            // Ni una fila. No es «un poco desfasado», es que nunca se sembró: el
            // catálogo se ordena por precios que no existen.
            $this->logger->error('prices:health — el histórico está vacío');
            fwrite(STDERR, "El histórico de precios está VACÍO: no hay ni una fila en mtg_price_daily.\n");

            return 1;
        }

        // Los dos a medianoche y el diff del día viejo HACIA hoy: así el signo ya
        // significa «días parado» y no hay que darle la vuelta. Con horas de por
        // medio, `%a` truncaría 47 horas a 1 día y el margen valdría tres.
        $hoy     = ($this->hoy)()->setTime(0, 0);
        $ultimo  = new DateTimeImmutable($ultima . ' 00:00:00');
        $desfase = (int) $ultimo->diff($hoy)->format('%r%a');

        if ($desfase > self::MARGEN_DIAS) {
            $this->logger->error('prices:health — histórico desfasado', [
                'ultima_fecha' => $ultima,
                'dias'         => $desfase,
            ]);

            fwrite(
                STDERR,
                sprintf(
                    "El histórico de precios lleva %d días parado: el último día es %s.\n"
                    . "Lo que no se capture se pierde — MTGJSON solo retiene 90 días.\n",
                    $desfase,
                    $ultima
                )
            );

            return 1;
        }

        printf(
            "Histórico de precios al día: último día %s (%d %s de desfase).\n",
            $ultima,
            $desfase,
            $desfase === 1 ? 'día' : 'días'
        );

        return 0;
    }
}
