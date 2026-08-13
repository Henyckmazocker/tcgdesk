<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Search;

/**
 * Traduce lo que el usuario teclea a una expresión de `MATCH ... AGAINST (... IN
 * BOOLEAN MODE)`.
 *
 * Parece trivial y no lo es: **un token que no está en el índice, exigido con
 * '+', hace que la consulta no devuelva NADA**. Es el fallo que M0 midió sobre el
 * catálogo real —`Jace, the Mind Sculptor` daba cero resultados— y hay dos clases
 * de token así:
 *
 *   1. Los más cortos que `innodb_ft_min_token_size` (3 por defecto). Por eso
 *      'wall of air' se busca como '+wall* +air*'.
 *   2. Las stopwords de InnoDB. 'the' mide 3 caracteres, así que el filtro por
 *      longitud no la pilla, pero tampoco está indexada.
 *
 * La lista de stopwords se **lee del servidor**, no se copia aquí: cambiarla es
 * una opción de configuración de MySQL y una copia a mano se desincroniza sola.
 */
class BooleanExpressionBuilder
{
    /**
     * @param array<string, true> $stopwords indexadas en minúsculas
     */
    public function __construct(
        private readonly array $stopwords = [],
        private readonly int $minTokenSize = 3
    ) {
    }

    /**
     * @return string Expresión booleana, o '' si no queda nada que buscar
     */
    public function construir(string $texto): string
    {
        $tokens = $this->tokenizar($texto);

        if ($tokens === []) {
            return '';
        }

        $utiles = array_values(array_filter($tokens, fn (string $t) => $this->esIndexable($t)));

        // Si TODO lo tecleado son palabras cortas o stopwords ('Ire', 'The Ring'),
        // se buscan igualmente: más vale un resultado dudoso que ninguno, y el
        // desempate por exactitud del repositorio lo recoloca.
        if ($utiles === []) {
            $utiles = $tokens;
        }

        return implode(' ', array_map(
            fn (string $t) => '+' . $t . '*',
            $utiles
        ));
    }

    /**
     * Parte el texto en palabras, tirando la puntuación.
     *
     * La coma de 'Jace, the Mind Sculptor' y las dos barras de las cartas de
     * doble cara son operadores en BOOLEAN MODE; dejarlas pasar da errores de
     * sintaxis o resultados absurdos.
     *
     * @return list<string>
     */
    private function tokenizar(string $texto): array
    {
        $limpio = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $texto) ?? '';

        return preg_split('/\s+/u', trim($limpio), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function esIndexable(string $token): bool
    {
        return mb_strlen($token) >= $this->minTokenSize
            && !isset($this->stopwords[mb_strtolower($token)]);
    }

    /**
     * ¿El texto lleva caracteres japoneses, chinos o coreanos?
     *
     * Decide contra qué índice va la búsqueda: esos idiomas no se separan por
     * espacios y el parser por defecto de MySQL no los tokeniza, así que van a
     * `name_cjk`, que usa el parser ngram.
     */
    public function esCjk(string $texto): bool
    {
        return (bool) preg_match(
            '/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}]/u',
            $texto
        );
    }
}
