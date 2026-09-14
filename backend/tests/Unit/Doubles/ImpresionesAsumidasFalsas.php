<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Repository\AssumedPrintingRepositoryInterface;

/**
 * La elección de edición, en memoria.
 *
 * No reproduce el criterio de «la más barata» —eso es SQL y se prueba contra la
 * base de datos—: reproduce el **contrato**, que es lo que consumen el chooser y
 * el use case. Lo que sí vigila es que la pregunta vaya **en lote y sin
 * repetirse**: guarda cada llamada y las claves pedidas, porque una carta
 * repetida en 300 líneas tiene que preguntarse una vez y un fichero de 20.000
 * líneas no puede convertirse en 20.000 consultas.
 */
class ImpresionesAsumidasFalsas implements AssumedPrintingRepositoryInterface
{
    /** @var array<string, array<string, mixed>> 'oracleId|finish' → impresión elegida */
    public array $elegidas = [];

    /** @var list<list<array{oracleId: string, finish: string}>> Una entrada por llamada */
    public array $llamadas = [];

    public function conImpresion(
        string $oracleId,
        string $finish,
        string $printingUuid,
        string $setCode = 'TST',
        int $printingCount = 2,
        ?float $priceEur = null
    ): self {
        $this->elegidas[$oracleId . '|' . $finish] = [
            'printingUuid'    => $printingUuid,
            'setCode'         => $setCode,
            'collectorNumber' => '1',
            'priceEur'        => $priceEur,
            'printingCount'   => $printingCount,
        ];

        return $this;
    }

    public function masBaratasPorCarta(array $peticiones): array
    {
        $this->llamadas[] = $peticiones;

        $salida = [];

        foreach ($peticiones as $peticion) {
            $clave = $peticion['oracleId'] . '|' . $peticion['finish'];

            if (isset($this->elegidas[$clave])) {
                $salida[$clave] = $this->elegidas[$clave];
            }
        }

        return $salida;
    }
}
