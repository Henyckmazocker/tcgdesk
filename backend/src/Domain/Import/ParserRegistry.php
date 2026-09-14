<?php

declare(strict_types=1);

namespace App\Domain\Import;

use InvalidArgumentException;

/**
 * Elige el parser que sabe leer un fichero. **El usuario no selecciona formato
 * en ningún momento**: sube su exportación y la app la reconoce.
 *
 * Vive en el dominio y no en infraestructura porque **no conoce ningún formato
 * concreto**: recibe los parsers por constructor y pregunta a cada uno. Los que
 * sí conocen un formato externo (`ManaBoxCsvParser` y los que vengan) están en
 * `Infrastructure/Import`, igual que `Infrastructure/Mtgjson`.
 *
 * El orden del array manda: gana el primero que diga que sí. Por eso los
 * parsers específicos (CSV con cabecera reconocible) se registran ANTES que los
 * genéricos, que en el plan es el de texto plano — un parser que acepta
 * cualquier cosa tiene que ser siempre el último de la lista.
 */
final class ParserRegistry
{
    /**
     * Bytes de contenido que se le pasan a `supports()`. La cabecera de un CSV
     * cabe de sobra, y así decidir el formato de un fichero de 5 MB no obliga a
     * recorrerlo entero.
     */
    private const TAMANO_MUESTRA = 4096;

    /** @var CollectionParserInterface[] */
    private readonly array $parsers;

    /** @param CollectionParserInterface[] $parsers Del más específico al más genérico */
    public function __construct(array $parsers)
    {
        $this->parsers = array_values($parsers);
    }

    /**
     * @return CollectionParserInterface|null null cuando ningún parser lo
     *         reconoce — un fichero corrupto o de otro juego produce un error
     *         claro, no una importación a medias.
     */
    public function detectar(string $filename, string $content): ?CollectionParserInterface
    {
        $muestra = substr($content, 0, self::TAMANO_MUESTRA);

        foreach ($this->parsers as $parser) {
            if ($parser->supports($filename, $muestra)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Para cuando el cliente fuerza el formato en la petición (`format`), o
     * para las pruebas.
     *
     * @throws InvalidArgumentException si no hay parser con ese nombre
     */
    public function porNombre(string $name): CollectionParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->getName() === $name) {
                return $parser;
            }
        }

        throw new InvalidArgumentException('Formato de importación no soportado: ' . $name);
    }

    /** @return string[] Los formatos registrados, en orden de detección */
    public function nombres(): array
    {
        return array_map(
            static fn (CollectionParserInterface $parser): string => $parser->getName(),
            $this->parsers
        );
    }
}
