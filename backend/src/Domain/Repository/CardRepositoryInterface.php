<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Catalog\SearchCriteria;

/**
 * Lectura del catálogo.
 *
 * Separada de CatalogRepositoryInterface a propósito: aquella la usa la ingesta
 * por CLI y escribe cientos de miles de filas; esta la usan las peticiones de
 * usuario y solo lee. Que un controller no pueda ni nombrar un método de
 * escritura del catálogo es la garantía de que el mirror es de solo lectura desde
 * el punto de vista de la app.
 */
interface CardRepositoryInterface
{
    /**
     * Busca printings por nombre en cualquier idioma, con filtros.
     *
     * `nextCursor` es null cuando no hay más páginas, y es lo único que el
     * cliente necesita guardar para seguir el scroll: nunca se pagina por
     * offset visible.
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function search(SearchCriteria $criterios): array;

    /**
     * Un printing por su uuid, con legalidades y nombres localizados.
     *
     * @return array<string, mixed>|null null si no existe
     */
    public function findByUuid(string $uuid): ?array;

    /**
     * Las impresiones hermanas de un printing: las de su misma carta.
     *
     * Se entra por el uuid de UNA impresión, no por el `oracle_id`, porque es lo
     * único que el cliente tiene en la mano —la ficha, la fila de importación, el
     * candidato— y resolver la carta a la que pertenece es trabajo del
     * repositorio, que ya la tiene delante.
     *
     * **null es "el uuid no existe", y solo eso.** Una carta nunca reimpresa
     * devuelve una página con un item, no null: la ausencia de alternativas no es
     * un recurso que falte, y el router traduce solo el null en 404.
     *
     * @param  int $limite Filas por página; el acotado que venga del cliente lo
     *                     hace quien lo recibe, no esta capa
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null}|null
     */
    public function impresionesDe(string $uuid, ?string $cursor, int $limite): ?array;

    /**
     * Las fichas de catálogo de N impresiones, **en una sola consulta**.
     *
     * Nace con el escáner (`scan_resolve`): el resolvedor identifica la
     * impresión y devuelve su `printingUuid`, pero no `collectorNumber`, ni
     * `finishes`, ni `priceEur` — que son justo los tres que el menú del escáner
     * necesita para pintar el precio y para decidir el acabado. Este método
     * cierra ese hueco **sin tocar el resolvedor**: es lectura de catálogo y
     * nada más.
     *
     * **Por lote y no por uuid**, y no es una optimización prematura: una página
     * de binder son nueve cartas de golpe, y `findByUuid()` cuesta CUATRO
     * consultas por carta —ficha, legalidades, nombres localizados e histórico
     * de precio—, o sea 36 para pintar una página, con un montón de dato que
     * quien pregunta esto no usa.
     *
     * **Se devuelve indexado por uuid, no como lista**, porque quien pregunta
     * tiene los uuid en la mano y necesita volver a casar cada ficha con su
     * fila: una lista le obligaría a recorrerla N veces. Un uuid que no exista
     * **no sale en el mapa** —ausencia, nunca una entrada a null—, así que el
     * tamaño de la respuesta no tiene por qué ser el de la petición.
     *
     * @param  list<string> $uuids
     * @return array<string, array<string, mixed>> uuid → contrato CatalogCard
     */
    public function porUuids(array $uuids): array;


    /** @return list<array<string, mixed>> Todas las ediciones, para el filtro */
    public function allSets(): array;
}
