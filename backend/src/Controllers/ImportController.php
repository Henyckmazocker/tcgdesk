<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\ApplyImport;
use App\Application\UseCase\ResolveCards;
use App\Domain\Import\ParserRegistry;
use InvalidArgumentException;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Las dos acciones de la importación, que son **las dos mitades del alto
 * obligatorio** del pipeline:
 *
 * ```
 * Fichero → [Detector] → [Parser] → [Resolvedor] → PREVISUALIZACIÓN → [Aplicador] → colección
 *                     import_preview                              import_apply
 * ```
 *
 * `import_preview` **no escribe nada** —ni en la colección ni en el catálogo— y
 * eso no es una promesa del comentario: este controller no conoce el repositorio
 * de la colección, solo el use case `ApplyImport`, que es el que llama la otra
 * acción. Una importación de 3.000 cartas que se aplica sola y sale mal es un
 * desastre difícil de deshacer, así que entre leer el fichero y escribir en la
 * colección hay una pantalla y un botón.
 *
 * El cliente devuelve en `import_apply` **solo las filas que el usuario ha
 * confirmado**, incluidas las que resolvió a mano eligiendo candidato. El
 * servidor no guarda la previsualización entre las dos llamadas: no hay estado
 * intermedio que caducar, y el usuario puede tardar veinte minutos en arreglar
 * sus siete conflictos sin que nada se pierda.
 *
 * Desde M7 el payload de `import_apply` admite además `deck: {name, status,
 * format}` —la casilla «importar como mazo»—, y entonces el use case crea el
 * mazo **en la misma transacción** que escribe la colección. La acción es la
 * misma: `routes.php` no cambia, solo gana un campo opcional.
 */
class ImportController extends BaseController
{
    /**
     * Techo del contenido que se acepta previsualizar.
     *
     * La escala que declara el plan es «ficheros de 5 MB y 20.000 líneas»; 10 MB
     * deja margen de sobra y sigue cabiendo en el `memory_limit` de 128M real del
     * contenedor. **Ojo:** por debajo de esto manda `post_max_size` de PHP, que
     * por defecto son 8M y se sube en `public/.htaccess`; si el cuerpo lo supera,
     * PHP vacía la petición antes de que este código exista.
     */
    public const MAXIMO_BYTES = 10 * 1024 * 1024;

    /** Mismo techo de filas que la aplicación: lo que se previsualiza se aplica. */
    public const MAXIMO_FILAS = ApplyImport::MAXIMO_FILAS;

    /**
     * Segundos para la previsualización.
     *
     * `max_execution_time` por defecto son 30 en Apache y el plan exige que un
     * fichero grande se previsualice **sin timeout**. Se sube aquí y no en la
     * configuración de PHP para que solo lo disfruten estas dos acciones: una
     * acción normal que tarde 30 segundos es un bug, no una importación.
     */
    private const SEGUNDOS = 120;

    public function __construct(
        private readonly ParserRegistry $parsers,
        private readonly ResolveCards $resolver,
        private readonly ApplyImport $aplicar,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Detecta el formato, parsea y resuelve. **Sin escribir nada.**
     */
    public function preview(array $request): array
    {
        set_time_limit(self::SEGUNDOS);

        $datos     = $request['data'] ?? [];
        $contenido = is_string($datos['content'] ?? null) ? $datos['content'] : '';

        if (trim($contenido) === '') {
            return $this->errorResponse('No hay nada que importar: el fichero o el texto llegó vacío.', 422);
        }

        if (strlen($contenido) > self::MAXIMO_BYTES) {
            return $this->errorResponse(
                'El fichero pesa ' . $this->enMegas(strlen($contenido)) . ' y el máximo son '
                . $this->enMegas(self::MAXIMO_BYTES) . '.',
                422
            );
        }

        $formato  = trim((string) ($datos['format'] ?? ''));
        $fichero  = trim((string) ($datos['filename'] ?? ''));

        try {
            $parser = $formato !== ''
                ? $this->parsers->porNombre($formato)
                : $this->parsers->detectar($fichero, $contenido);
        } catch (InvalidArgumentException $e) {
            return $this->conFormatos($e->getMessage(), 422);
        }

        // Ningún parser lo reclama: un fichero corrupto o de otro juego produce un
        // error CLARO, nunca una importación a medias. Van los formatos conocidos
        // para que la vista pueda ofrecer elegirlo a mano —una lista de cartas sin
        // ninguna cantidad, por ejemplo, no se autodetecta a propósito—.
        if ($parser === null) {
            return $this->conFormatos(
                'No se reconoce el formato de ese fichero. Elige uno a mano si sabes cuál es.',
                422
            );
        }

        $filas = $parser->parse($contenido);

        if (count($filas) > self::MAXIMO_FILAS) {
            return $this->errorResponse(
                'El fichero trae ' . count($filas) . ' cartas y el máximo por importación es '
                . self::MAXIMO_FILAS . '. Pártelo en dos.',
                422
            );
        }

        if ($filas === []) {
            return $this->conFormatos(
                'Se ha reconocido el formato (' . $parser->getName() . ') pero no hay ni una carta dentro.',
                422
            );
        }

        $salida = ($this->resolver)($filas);

        $this->logger->info('Previsualización de importación', [
            'user_id'   => $request['user_id'] ?? null,
            'format'    => $parser->getName(),
            'total'     => $salida['total'],
            'conflicts' => $salida['summary']['conflictCount'],
            'assumed'   => $salida['summary']['assumedCount'],
        ]);

        return $this->successResponse('Previsualización lista.', [
            'format'  => $parser->getName(),
            'formats' => $this->parsers->nombres(),
        ] + $salida);
    }

    /**
     * Escribe en la colección lo que el usuario confirmó. **Suma, no sincroniza.**
     */
    public function apply(array $request): array
    {
        set_time_limit(self::SEGUNDOS);

        $userId = $this->usuario($request);

        try {
            $resultado = ($this->aplicar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            // Clave foránea: alguno de los `printingUuid` no está en el catálogo.
            // La transacción de `upsertLote` ya lo ha tirado todo, así que la
            // colección sigue exactamente como estaba.
            if ($e->getCode() === '23000') {
                $this->logger->info('Importación rechazada: printing inexistente', ['user_id' => $userId]);

                return $this->errorResponse(
                    'Alguna de esas cartas no existe en el catálogo. No se ha importado nada.',
                    422
                );
            }

            // Desbordamiento del SMALLINT UNSIGNED al sumar cantidades.
            if ($e->getCode() === '22003') {
                return $this->errorResponse('Alguna cantidad se sale del máximo por línea.', 422);
            }

            throw $e;
        }

        $this->logger->info('Importación aplicada', [
            'user_id'  => $userId,
            'inserted' => $resultado['inserted'],
            'updated'  => $resultado['updated'],
            'quantity' => $resultado['totalQuantity'],
            // Solo cuando la importación creó además el mazo (M7). Cada
            // importación con la casilla marcada crea el SUYO.
            'deck_id'  => $resultado['deck']['id'] ?? null,
        ]);

        $mensaje = 'Importación aplicada: ' . $resultado['inserted'] . ' líneas nuevas y '
            . $resultado['updated'] . ' sumadas a las que ya tenías.';

        if (isset($resultado['deck'])) {
            $mensaje .= ' Y el mazo «' . $resultado['deck']['name'] . '» con '
                . $resultado['deck']['cards'] . ' cartas.';
        }

        return $this->successResponse($mensaje, $resultado);
    }

    /** Un error que además dice qué formatos existen, para el selector manual. */
    private function conFormatos(string $mensaje, int $httpCode): array
    {
        return [
            'status'    => 'error',
            'message'   => $mensaje,
            'data'      => ['formats' => $this->parsers->nombres()],
            'http_code' => $httpCode,
        ];
    }

    private function enMegas(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
}
