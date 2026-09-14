<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Un parser de colección: convierte el contenido de un fichero exportado por
 * otra app en filas neutras (`ParsedRow[]`) que el resolvedor sabe masticar.
 *
 * **Es la pieza que hace barato añadir formatos.** ManaBox, Moxfield, Archidekt
 * y el texto plano se diferencian solo en cómo se lee el fichero; a partir de
 * `ParsedRow` el pipeline (resolvedor → previsualización → aplicador) es el
 * mismo para todos. Si añadir un formato obliga a tocar algo más que una clase
 * nueva y su registro, esta interfaz está mal.
 *
 * El parser **no resuelve cartas ni toca la base de datos**: no sabe qué es un
 * `printing_uuid`. Solo lee texto y normaliza los tres objetos de valor de la
 * colección (acabado, idioma y estado).
 */
interface CollectionParserInterface
{
    /**
     * ¿Este parser reconoce el fichero? Es la detección automática: el usuario
     * no elige formato en ningún momento.
     *
     * @param string $filename Nombre del fichero subido (puede venir vacío si
     *                         el contenido se pegó a mano)
     * @param string $sample   Primeros bytes del contenido — suficiente para la
     *                         cabecera, sin obligar a cargar 5 MB para decidir
     */
    public function supports(string $filename, string $sample): bool;

    /** Identificador estable del formato: 'manabox', 'moxfield', … */
    public function getName(): string;

    /**
     * @param  string $content Contenido completo del fichero
     * @return ParsedRow[] Una fila por registro, EN ORDEN y con su número de
     *                     línea. Las filas que no se pudieron normalizar
     *                     vienen marcadas (`esValida() === false`), nunca se
     *                     descartan en silencio.
     */
    public function parse(string $content): array;
}
