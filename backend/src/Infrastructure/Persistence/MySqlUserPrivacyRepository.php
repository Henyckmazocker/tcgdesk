<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\UserPrivacyRepositoryInterface;
use App\Domain\Social\Descubrimiento;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use PDO;

/**
 * Los niveles de privacidad en MySQL.
 *
 * Una lectura y una escritura sobre una fila por `PRIMARY KEY`, con una sola
 * regla que no es obvia: **la ausencia de fila no es un error, es el juego de
 * valores por defecto**. La fila la crea `guardarNiveles()` la primera vez que
 * el usuario cambia algo, así que quien no ha tocado el panel no la tiene y el
 * perfil público tiene que funcionar igual.
 *
 * Los defectos se piden a `Seccion::nivelesPorDefecto()` y no se copian aquí:
 * son los mismos `DEFAULT` que declara la migración, y el único sitio donde el
 * duplicado está documentado es esa clase.
 */
class MySqlUserPrivacyRepository implements UserPrivacyRepositoryInterface
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * @inheritDoc
     */
    public function nivelesDe(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT show_collection, show_value, show_decks, show_sets, show_wishlist
               FROM user_privacy_settings
              WHERE user_id = :user_id
              LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);

        $fila = $stmt->fetch();

        // Se parte SIEMPRE de los defectos y se pisan las columnas que traiga la
        // fila, en vez de construir el array desde la fila. Así, si algún día se
        // añade una sección al ENUM y la migración tarda en aplicarse, la sección
        // nueva sale con su defecto en lugar de faltar del array —y `Valor` y
        // `Deseos` tienen por defecto `friends`, que hoy es cerrado—.
        $niveles = Seccion::nivelesPorDefecto();

        if ($fila === false) {
            return $niveles;
        }

        foreach (Seccion::cases() as $seccion) {
            // `intentar()` y no `desde()`: un valor que no case con el ENUM
            // —una columna añadida a mano, una BD a medio migrar— deja el
            // defecto en su sitio en vez de tumbar la petición entera. Fallar
            // aquí convertiría un dato raro en un 500 de una ruta pública.
            $nivel = Nivel::intentar($fila[$seccion->columna()] ?? null);

            if ($nivel !== null) {
                $niveles[$seccion->value] = $nivel;
            }
        }

        return $niveles;
    }

    /**
     * @inheritDoc
     */
    public function guardarNiveles(int $userId, array $cambios): array
    {
        // Se parte de lo que hay —que sin fila son los cinco defectos— para
        // poder rellenar el INSERT entero. Es la mitad del truco: la otra mitad
        // es que el ON DUPLICATE KEY UPDATE toca SOLO las columnas que cambian,
        // así que estos valores leídos únicamente se usan cuando la fila no
        // existía. Si alguien escribe entre este SELECT y el INSERT, lo suyo no
        // se pisa: su columna no está en la lista de asignaciones.
        $actuales = $this->nivelesDe($userId);

        $valores      = [];
        $asignaciones = [];
        $parametros   = ['user_id' => $userId];

        // Se recorren las secciones del ENUM y NO las claves de $cambios: el
        // nombre de columna sale siempre de `Seccion::columna()`, nunca de algo
        // que haya podido llegar del cliente. Un nombre de columna no se puede
        // parametrizar, así que este bucle es lo único que separa esta consulta
        // de una inyección.
        foreach (Seccion::cases() as $seccion) {
            $columna = $seccion->columna();
            $nuevo   = $cambios[$seccion->value] ?? null;

            $valores[] = ':ins_' . $columna;
            $parametros['ins_' . $columna] = ($nuevo ?? $actuales[$seccion->value])->value;

            if ($nuevo === null) {
                continue;
            }

            // El MISMO valor, con OTRO nombre de marcador. Con
            // `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un
            // marcador nombrado en dos puntos de la misma sentencia, y aquí cada
            // sección que cambia aparece exactamente en dos: la lista de VALUES
            // y la de asignaciones del ODKU.
            $asignaciones[] = $columna . ' = :upd_' . $columna;
            $parametros['upd_' . $columna] = $nuevo->value;
        }

        // Sin nada que cambiar no hay sentencia que escribir: un ODKU sin
        // asignaciones no es SQL válido, y crear la fila con los defectos
        // «porque sí» convertiría una petición vacía en un cambio de estado.
        if ($asignaciones === []) {
            return $actuales;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO user_privacy_settings
                    (user_id, show_collection, show_value, show_decks, show_sets, show_wishlist)
             VALUES (:user_id, ' . implode(', ', $valores) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $asignaciones)
        );
        $stmt->execute($parametros);

        // Se releen en vez de devolver la mezcla calculada arriba: lo que vale
        // es lo que quedó en la tabla, y si el ENUM rechazara un valor o otra
        // petición hubiera escrito a la vez, la mezcla mentiría.
        return $this->nivelesDe($userId);
    }

    /**
     * @inheritDoc
     */
    public function descubrimientoDe(int $userId): Descubrimiento
    {
        $stmt = $this->db->prepare(
            'SELECT ' . Descubrimiento::COLUMNA . '
               FROM user_privacy_settings
              WHERE user_id = :user_id
              LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);

        $fila = $stmt->fetch();

        // `intentar()` y no `desde()`, por lo mismo que en `nivelesDe()`: un
        // valor que no case con el ENUM —una BD a medio migrar, una columna
        // tocada a mano— deja el defecto en su sitio en vez de tumbar la
        // petición. Y sin fila, el defecto: hoy eso es todo el mundo.
        if ($fila === false) {
            return Descubrimiento::porDefecto();
        }

        return Descubrimiento::intentar($fila[Descubrimiento::COLUMNA] ?? null)
            ?? Descubrimiento::porDefecto();
    }

    /**
     * @inheritDoc
     */
    public function guardarDescubrimiento(int $userId, Descubrimiento $valor): Descubrimiento
    {
        // El nombre de columna sale de la constante del enum y NUNCA de nada que
        // haya podido llegar del cliente, por lo mismo que el bucle sobre
        // `Seccion::cases()` de `guardarNiveles()`: un nombre de columna no se
        // puede parametrizar, así que lo único que separa esta consulta de una
        // inyección es de dónde sale ese trozo de texto.
        //
        // El MISMO valor con DOS nombres de marcador (`:ins_` y `:upd_`): con
        // `ATTR_EMULATE_PREPARES = false`, MySQL no admite reutilizar un
        // marcador nombrado en dos puntos de la misma sentencia, y aquí aparece
        // en los VALUES y en la asignación del ODKU.
        //
        // Las otras CINCO columnas no se mencionan, y eso es lo que hace que
        // esta escritura sea parcial de verdad. Cuando la fila no existe, MySQL
        // las rellena con el `DEFAULT` del esquema, que es exactamente el mismo
        // valor que `Seccion::nivelPorDefecto()` devuelve para cada una — así
        // que tocar SOLO este selector no mueve ni una sección de contenido.
        $stmt = $this->db->prepare(
            'INSERT INTO user_privacy_settings (user_id, ' . Descubrimiento::COLUMNA . ')
             VALUES (:user_id, :ins_valor)
             ON DUPLICATE KEY UPDATE ' . Descubrimiento::COLUMNA . ' = :upd_valor'
        );

        $stmt->execute([
            'user_id'   => $userId,
            'ins_valor' => $valor->value,
            'upd_valor' => $valor->value,
        ]);

        // Se relee en vez de devolver `$valor`, por lo mismo que
        // `guardarNiveles()`: lo que vale es lo que quedó en la tabla.
        return $this->descubrimientoDe($userId);
    }
}
