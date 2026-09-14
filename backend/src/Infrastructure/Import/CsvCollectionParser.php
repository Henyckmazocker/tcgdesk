<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Import\CollectionParserInterface;
use App\Domain\Import\ParsedRow;

/**
 * Lo que ManaBox, Moxfield y Archidekt tienen EN COMÚN: los tres exportan un
 * CSV con cabecera, con Scryfall ID, y con las mismas cuatro trampas.
 *
 * El plan pide que añadir un formato sea «un fichero pequeño». Sin esta clase
 * no lo es: los tres necesitan exactamente el mismo lector (BOM, CRLF, número
 * de línea física, columnas por nombre) y el mismo mapeo a los objetos de valor
 * de la colección. Lo único que de verdad cambia entre ellos es **cómo se
 * llaman las columnas** y **qué columna delata el formato**, y eso es lo que
 * cada subclase declara: dos métodos y un mapa.
 *
 * Las cuatro trampas resueltas aquí de una vez, que cuestan una tarde cada una:
 *
 *  1. **BOM.** Los ficheros exportados desde Windows empiezan por
 *     `\xEF\xBB\xBF` y hacen que la primera columna de la cabecera no case
 *     NUNCA. Se quita antes de nada, en `supports()` y en `parse()`.
 *  2. **El acabado es texto, no un booleano**: vale `normal`, `foil` o
 *     `etched`, y `etched` tiene precio propio en `mtg_price_current`. Se mapea
 *     a los tres valores del ENUM con `Finish`, no a un `bool`.
 *  3. **La condición viene con nombres distintos en cada app** (`near_mint`,
 *     `Near Mint`, `NM`, `D`). Es la causa conocida de los *"Could not parse
 *     card condition"*. Lo resuelve `Condition`, que ya conoce los alias.
 *  4. **El idioma también** (`en`, `English`, `EN`), y hay que dejarlo en la
 *     forma larga de MTGJSON, que es con la que casa `mtg_printing_localized`.
 *     Lo resuelve `CardLanguage`.
 *
 * Y la regla que manda sobre todas: **lo que no case va a conflicto, no al
 * valor por defecto**. Una columna ausente —o vacía— sí cae al valor por
 * defecto, porque el fichero no afirma nada; una columna presente con un valor
 * que no se reconoce marca la fila como inválida y **conserva el valor crudo**.
 * Caer a `NM` cuando el fichero decía otra cosa falsearía la valoración de la
 * colección al alza sin que el usuario se entere.
 *
 * Las columnas se buscan **por nombre de cabecera, nunca por posición**: las
 * tres apps añaden y quitan columnas con cada versión y un parser posicional se
 * rompe en cada actualización. Sobrar columnas no molesta y faltar una opcional
 * tampoco.
 */
abstract class CsvCollectionParser implements CollectionParserInterface
{
    protected const BOM = "\xEF\xBB\xBF";

    /**
     * Campo de `ParsedRow` => nombres de cabecera aceptados, en orden de
     * preferencia. Es el vocabulario más común (el de ManaBox y Archidekt); las
     * subclases solo declaran **lo que difiere**.
     *
     * @var array<string, string[]>
     */
    private const MAPA_POR_DEFECTO = [
        'scryfallId'      => ['scryfall id'],
        'name'            => ['name'],
        'setCode'         => ['set code'],
        'collectorNumber' => ['collector number'],
        'finish'          => ['foil', 'finish'],
        'language'        => ['language'],
        'condition'       => ['condition'],
        'quantity'        => ['quantity'],
    ];

    /**
     * Columnas que **solo exporta este formato**. Con que aparezca una, el
     * fichero es suyo sin más preguntas. Es lo que mantiene inequívoca la
     * detección ahora que hay tres CSV con cabeceras que se parecen.
     *
     * @return string[] Nombres ya normalizados (minúsculas, sin `_`)
     */
    abstract protected function columnasHuella(): array;

    /**
     * Columnas sin las cuales el fichero no puede ser de este formato. Se
     * exigen TODAS.
     *
     * @return string[]
     */
    abstract protected function columnasObligatorias(): array;

    /**
     * Solo las diferencias con `MAPA_POR_DEFECTO`.
     *
     * @return array<string, string[]>
     */
    protected function mapaDeColumnas(): array
    {
        return [];
    }

    public function supports(string $filename, string $sample): bool
    {
        $cabecera = $this->cabeceraDe($sample);

        if ($cabecera === []) {
            return false;
        }

        foreach ($this->columnasHuella() as $huella) {
            if (in_array($huella, $cabecera, true)) {
                return true;
            }
        }

        foreach ($this->columnasObligatorias() as $columna) {
            if (!in_array($columna, $cabecera, true)) {
                // Último recurso: una exportación recortada a mano, pero con el
                // nombre de fichero que pone la app por defecto
                // (`ManaBox_Collection.csv`, `moxfield_export.csv`,
                // `archidekt-collection.csv`). Aun así se exige que traiga algo
                // resoluble, para no reclamar un CSV cualquiera por el nombre.
                return str_contains(strtolower($filename), $this->getName())
                    && in_array('name', $cabecera, true)
                    && in_array('scryfall id', $cabecera, true);
            }
        }

        return true;
    }

    /** @return ParsedRow[] */
    public function parse(string $content): array
    {
        $content = $this->sinBom($content);

        $flujo = fopen('php://memory', 'r+');
        if ($flujo === false) {
            return [];
        }

        fwrite($flujo, $content);
        rewind($flujo);

        /** @var string[]|null $cabecera */
        $cabecera = null;
        $filas    = [];
        $linea    = 0;
        $posicion = 0;

        while (($registro = fgetcsv($flujo)) !== false) {
            // Número de línea FÍSICA del fichero: es lo que la previsualización
            // le enseña al usuario para que encuentre el error en su CSV. Se
            // cuenta por los saltos que ha consumido el registro, así que un
            // campo entrecomillado con salto de línea dentro no lo descuadra.
            $siguiente     = ftell($flujo);
            $lineaRegistro = $linea + 1;
            $linea        += max(1, substr_count(
                substr($content, $posicion, (int) $siguiente - $posicion),
                "\n"
            ));
            $posicion = (int) $siguiente;

            if ($this->esRegistroVacio($registro)) {
                continue;
            }

            if ($cabecera === null) {
                $cabecera = array_map(
                    fn (?string $celda): string => $this->normalizarNombreDeColumna((string) $celda),
                    $registro
                );
                continue;
            }

            $filas[] = $this->filaDesde($this->asociar($cabecera, $registro), $lineaRegistro);
        }

        fclose($flujo);

        return $filas;
    }

    // ------------------------------------------------------------------ campos

    /**
     * @param array<string, string> $campos cabecera normalizada => valor crudo
     */
    private function filaDesde(array $campos, int $linea): ParsedRow
    {
        /** @var array<string, string> $errores */
        $errores = [];

        $scryfallId = $this->valor($campos, 'scryfallId');
        $nombre     = $this->valor($campos, 'name');

        if ($scryfallId === null && $nombre === null) {
            // Sin Scryfall ID y sin nombre no hay nada que resolver: ni el paso
            // exacto ni el difuso tienen por dónde empezar.
            $errores['fila'] = 'la línea no trae ni Scryfall ID ni nombre de carta';
        }

        // ---- Acabado: texto de tres valores, no un booleano.
        $crudoFinish = $this->valor($campos, 'finish');
        $finish      = Finish::porDefecto()->value;

        if ($crudoFinish !== null) {
            $acabado = Finish::intentar($crudoFinish);

            if ($acabado === null) {
                $errores['finish'] = 'acabado no reconocido: ' . $crudoFinish;
                $finish            = $crudoFinish;
            } else {
                $finish = $acabado->value;
            }
        }

        // ---- Idioma: código corto o nombre propio → forma larga de MTGJSON.
        $crudoLanguage = $this->valor($campos, 'language');
        $language      = CardLanguage::porDefecto()->value;

        if ($crudoLanguage !== null) {
            $idioma = CardLanguage::intentar($crudoLanguage);

            if ($idioma === null) {
                $errores['language'] = 'idioma no reconocido: ' . $crudoLanguage;
                $language            = $crudoLanguage;
            } else {
                $language = $idioma->value;
            }
        }

        // ---- Estado: aquí es donde NO se cae al valor por defecto.
        $crudoCondition = $this->valor($campos, 'condition');
        $condition      = Condition::porDefecto()->value;

        if ($crudoCondition !== null) {
            $estado = Condition::intentar($crudoCondition);

            if ($estado === null) {
                $errores['condition'] = 'estado no reconocido: ' . $crudoCondition;
                $condition            = $crudoCondition;
            } else {
                $condition = $estado->value;
            }
        }

        // ---- Cantidad.
        $crudoQuantity = $this->valor($campos, 'quantity');
        $quantity      = 1;

        if ($crudoQuantity !== null) {
            if (preg_match('/^\d+$/', $crudoQuantity) !== 1 || (int) $crudoQuantity < 1) {
                $errores['quantity'] = 'cantidad no válida: ' . $crudoQuantity;
                $quantity            = 0;
            } else {
                $quantity = (int) $crudoQuantity;
            }
        }

        $setCode = $this->valor($campos, 'setCode');

        return new ParsedRow(
            scryfallId: $scryfallId === null ? null : strtolower($scryfallId),
            name: $nombre,
            setCode: $setCode === null ? null : strtoupper($setCode),
            collectorNumber: $this->valor($campos, 'collectorNumber'),
            finish: $finish,
            language: $language,
            condition: $condition,
            quantity: $quantity,
            sourceLine: $linea,
            errores: $errores,
            crudo: $campos,
        );
    }

    // ------------------------------------------------------------------ lectura

    /**
     * El valor del campo, buscando la columna por los nombres que acepta este
     * formato. Una celda vacía es una columna que el fichero NO afirma: se
     * trata igual que la columna ausente y se sigue buscando en la siguiente
     * alternativa.
     *
     * @param array<string, string> $campos
     */
    private function valor(array $campos, string $campo): ?string
    {
        $columnas = array_merge(self::MAPA_POR_DEFECTO, $this->mapaDeColumnas())[$campo] ?? [];

        foreach ($columnas as $columna) {
            $valor = trim($campos[$columna] ?? '');

            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    /**
     * @param  string[]       $cabecera
     * @param  array<?string> $registro
     * @return array<string, string>
     */
    private function asociar(array $cabecera, array $registro): array
    {
        $campos = [];

        foreach ($cabecera as $indice => $columna) {
            if ($columna === '') {
                continue;
            }

            $campos[$columna] = trim((string) ($registro[$indice] ?? ''));
        }

        return $campos;
    }

    /** @return string[] Cabecera normalizada, o [] si la muestra no es un CSV */
    private function cabeceraDe(string $sample): array
    {
        $sample = $this->sinBom($sample);
        $sample = str_replace("\r\n", "\n", $sample);

        $primera = strtok($sample, "\n");

        if ($primera === false || trim($primera) === '') {
            return [];
        }

        return array_map(
            fn (?string $celda): string => $this->normalizarNombreDeColumna((string) $celda),
            str_getcsv($primera, ',', '"', '\\')
        );
    }

    private function normalizarNombreDeColumna(string $columna): string
    {
        // 'Set code', 'Set Code' y 'SET_CODE' son la misma columna.
        $columna = str_replace('_', ' ', $columna);
        $columna = preg_replace('/\s+/', ' ', $columna) ?? $columna;

        return strtolower(trim($this->sinBom($columna)));
    }

    /** @param array<?string> $registro */
    private function esRegistroVacio(array $registro): bool
    {
        foreach ($registro as $celda) {
            if (trim((string) $celda) !== '') {
                return false;
            }
        }

        return true;
    }

    private function sinBom(string $texto): string
    {
        return str_starts_with($texto, self::BOM)
            ? substr($texto, strlen(self::BOM))
            : $texto;
    }
}
