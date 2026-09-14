<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Deck\Board;
use App\Domain\Import\CollectionParserInterface;
use App\Domain\Import\ParsedRow;

/**
 * Lee una lista pegada a mano: la de MTG Arena, la de cualquier web de mazos, la
 * de un foro. **Es el único formato del plan que no trae Scryfall ID**, así que
 * todo el riesgo del pipeline vive aquí y la resolución cae entera del lado del
 * nombre (pasos 3 y 4 del resolvedor).
 *
 * No hereda de `CsvCollectionParser` a propósito: no hay cabecera, no hay
 * columnas y no hay comillas — el lector es otro. Lo que sí comparte es la regla
 * que manda en todo el plan: **lo que no case va a conflicto con su número de
 * línea, nunca se descarta en silencio**.
 *
 * ## Las tres formas del plan, más las dos que trae una lista real
 *
 * ```
 * 4 Lightning Bolt (M10) 146     cantidad + nombre + edición + número
 * 4x Lightning Bolt              la `x` pegada a la cantidad
 * 4 Lightning Bolt               solo cantidad y nombre
 * 4 Lightning Bolt (M10)         edición sin número (medio Arena la escribe así)
 * Lightning Bolt                 sin cantidad: vale 1 (ver abajo)
 * ```
 *
 * **Una línea que es solo un nombre vale 1 copia.** Es lo que significa una
 * lista de nombres sueltos, y el coste de equivocarse es mínimo: la
 * previsualización de M5 enseña la cantidad antes de escribir nada. Lo que NO
 * hace es contagiar a `supports()` — ver más abajo.
 *
 * ## Qué se ignora, y por qué no descuadra el número de línea
 *
 * Líneas vacías, comentarios (`//`, `#`) y las cabeceras `Deck`, `Sideboard`,
 * `Commander` y `Companion` no producen fila, y se saltan **sin consumir número
 * de línea**: se recorre el texto por líneas físicas y `sourceLine` es el índice
 * de esa línea en el texto original, no el ordinal de la fila producida. Es lo
 * que exige el *hecho cuando* del hito: el usuario tiene que poder encontrar en
 * su pantalla la línea que la previsualización le señala.
 *
 * ## Las cabeceras ya no se tiran: cambian la zona (M7)
 *
 * Hasta M7 este comentario decía que «el sideboard se importa como una carta
 * más: aquí se importa una colección, no un mazo». **Ya no es verdad.** Las
 * cuatro cabeceras **cambian el board activo** y cada fila viaja marcada con él
 * (`ParsedRow::$board`), así que pegar una decklist con `Deck` / `Sideboard` /
 * `Commander` reparte las tres zonas cuando `import_apply` crea además el mazo.
 *
 * Lo que **no** ha cambiado es la colección: `mtg_collection_item` no tiene
 * `board` y una carta es la misma esté donde esté, así que el sideboard sigue
 * sumando a la colección como una carta más. La zona solo la mira el mazo.
 *
 * ## La trampa del `//`
 *
 * `//` es a la vez marca de comentario **y** el separador de las caras de una
 * carta de doble cara (`Delver of Secrets // Insectile Aberration`), y las
 * **501 de 501** cartas `transform`/`modal_dfc` del catálogo guardan el nombre
 * completo con ` // `. Se distinguen por la posición y solo por la posición:
 *
 *  - `//` **al principio de la línea** (tras recortar espacios) → comentario;
 *  - ` // ` **en medio** → parte del nombre, y se conserva entero.
 *
 * Por eso **no se recortan comentarios a final de línea**: hacerlo partiría por
 * la mitad el nombre de todas las cartas de doble cara.
 *
 * ## Lo que este parser NO hace
 *
 * No elige impresión. Una línea sin `(SET)` deja `setCode` y `collectorNumber`
 * a null y ahí acaba su trabajo: elegir la impresión más barata de una carta con
 * varias —la «edición asumida» del contrato— es del resolvedor y de la
 * previsualización, no de quien lee el texto.
 *
 * Tampoco valida que la carta exista, ni que la edición sea real: los sets de
 * Arena a veces no están en MTGJSON, y **un set desconocido no es un error de
 * parseo**. Se pasa tal cual y decide el resolvedor.
 */
final class PlainTextParser implements CollectionParserInterface
{
    private const NOMBRE = 'plaintext';

    private const BOM = "\xEF\xBB\xBF";

    /**
     * Cabeceras de sección que **no son una carta**: son las que escriben Arena
     * y las webs de mazos. Cualquier otra cae por la forma «solo un nombre», se
     * intenta resolver y termina en conflicto — que es lo correcto, porque este
     * parser no conoce el catálogo y no le toca decidir qué es una carta.
     *
     * Desde M7 **cambian la zona activa** en vez de tirarse: la traducción a los
     * valores del ENUM `mtg_deck_card.board` (`deck` → `main`,
     * `sideboard` → `side`) la hace `Board::desde()` con sus alias, para no
     * repetir aquí una segunda lista que se desincronizaría con la del dominio.
     *
     * La lista **sigue siendo estas cuatro y no todos los alias de `Board`** a
     * propósito: `supports()` cuenta las líneas útiles descontando las cabeceras,
     * y ampliarla dejaría fuera del cómputo líneas que hoy sí cuentan —una carta
     * llamada `Token` existe—. Esto no relaja el umbral del 50 %.
     */
    private const CABECERAS = ['deck', 'sideboard', 'commander', 'companion'];

    /**
     * Una línea de carta, entera.
     *
     * La cantidad se limita a **tres dígitos a propósito**: con `\d{1,4}` la
     * línea `1996 World Champion` —carta real del catálogo, igual que
     * `70,000 Light-Years from Home` y `17-Year Cicadas`— se leería como 1996
     * copias de «World Champion». Nadie pega mil copias de una carta; el
     * catálogo sí tiene tres nombres que empiezan por número.
     *
     * El nombre es perezoso (`.*?`) para que ` (M10) 146` gane como edición
     * cuando está al final, en vez de comerse el paréntesis dentro del nombre.
     */
    private const FORMA = '/^
        (?: (?<cantidad> \d{1,3} ) \s* [xX]? \s+ )?
        (?<nombre> \S .*? )
        (?: \s+ \( (?<set> [A-Za-z0-9]{2,6} ) \)
            (?: \s+ (?<numero> [A-Za-z0-9\x{2605}\x{2020}\-]{1,15} ) )?
        )?
    \s*$/xu';

    /**
     * Qué puede contener un nombre de carta. **Medido sobre el catálogo real**
     * (34.992 nombres): además de letras, marcas y dígitos, los únicos signos
     * que aparecen son `! " & ' ( ) + , - . / : ; ? _` y el espacio (más `®`,
     * `—` y `꞉`, en un nombre cada uno). Se añaden las variantes tipográficas
     * que un pegado desde el navegador convierte solo (`’ ‘ ´ ` – … ™ ©`) y que
     * `NameNormalizer` ya sabe digerir.
     *
     * Lo que trae cualquier otro carácter —`$`, `*`, `=`, `{`, `[`, `|`, `@`…—
     * no es una carta: es el «Comprado en TCGPlayer por $32.10» que se cuela al
     * copiar de una web. Va a conflicto con su número de línea, no a la
     * colección.
     */
    private const NOMBRE_VALIDO = '/^[\p{L}\p{M}\p{N} !"&\'()+,\-.\/:;?_´`’‘–—…™©®꞉]+$/u';

    /**
     * Proporción mínima de líneas útiles que tienen que traer **cantidad
     * explícita** para dar el contenido por una lista de cartas.
     *
     * El porqué del umbral está en el apartado de `supports()`.
     */
    private const PROPORCION_MINIMA = 0.5;

    public function getName(): string
    {
        return self::NOMBRE;
    }

    /**
     * **Este es el parser que va SIEMPRE el último del registro**, porque es el
     * único que podría reclamar cualquier cosa. Y aun así no puede devolver
     * `true`: `ParserRegistry` tiene que seguir devolviendo `null` para un CSV
     * de Pokémon, una hoja de gastos, un JSON, un PNG o un fichero vacío, que es
     * lo que convierte «fichero de otro juego» en un error claro en vez de en
     * una importación a medias.
     *
     * El criterio, y su porqué:
     *
     *  1. **Nada de binario.** Un byte de control (fuera de `\t\r\n`) descarta
     *     el contenido de inmediato: mata el PNG sin mirar nada más.
     *  2. **La firma de una lista de cartas es la cantidad al principio de la
     *     línea**, no el nombre. Un nombre suelto es indistinguible de una línea
     *     de prosa, del `Nombre,Numero,Set,Rareza,Cantidad` de un CSV de otro
     *     juego o de una fila de una hoja de gastos: si contaran los nombres
     *     sueltos, este parser reclamaría los seis negativos y la detección
     *     dejaría de ser detección. Por eso se exige que **la mitad o más** de
     *     las líneas útiles —ni vacías, ni comentarios, ni cabeceras— casen con
     *     `4 Nombre` o `4x Nombre`, y que haya al menos una.
     *
     * La mitad, y no más, porque una lista pegada de verdad trae comentarios,
     * cabeceras que no están en la lista y alguna línea rota del copiar-pegar, y
     * el hito exige precisamente que esas se importen como conflicto en vez de
     * tumbar el fichero entero.
     *
     * **El precio, asumido:** una lista de nombres sueltos sin ninguna cantidad
     * no se autodetecta. `parse()` sí la lee sin problema, así que M5 la importa
     * en cuanto el cliente fuerce `format: "plaintext"` (`ParserRegistry::porNombre`).
     * Reclamarla automáticamente exigiría consultar el catálogo desde un
     * `supports()`, que ni ve la base de datos ni debe verla.
     */
    public function supports(string $filename, string $sample): bool
    {
        $sample = $this->sinBom($sample);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample) === 1) {
            return false;
        }

        $utiles         = 0;
        $conCantidad    = 0;

        foreach ($this->lineasDe($sample) as $linea) {
            $texto = trim($linea);

            if ($texto === '' || $this->esComentario($texto) || $this->esCabecera($texto)) {
                continue;
            }

            $utiles++;

            $campos = $this->casar($texto);

            if ($campos !== null && ($campos['cantidad'] ?? '') !== '') {
                $conCantidad++;
            }
        }

        if ($utiles === 0 || $conCantidad === 0) {
            return false;
        }

        return $conCantidad / $utiles >= self::PROPORCION_MINIMA;
    }

    /** @return ParsedRow[] */
    public function parse(string $content): array
    {
        $content = $this->sinBom($content);

        $filas = [];

        // La zona activa. Una decklist que no dice nada es todo main, que es lo
        // que significa una lista de cartas sin cabeceras.
        $zona = Board::porDefecto();

        foreach ($this->lineasDe($content) as $indice => $linea) {
            // `sourceLine` es la línea FÍSICA del texto que el usuario tiene
            // delante: las vacías, los comentarios y las cabeceras se saltan
            // pero SÍ cuentan, o el número que enseña la previsualización no
            // señalaría nada. La cabecera cambia la zona **sin** consumir
            // número: quien cuenta es `$indice`, no las filas producidas.
            $numero = $indice + 1;
            $texto  = trim($linea);

            if ($texto === '' || $this->esComentario($texto)) {
                continue;
            }

            $cabecera = $this->cabecera($texto);

            if ($cabecera !== null) {
                $zona = $cabecera;
                continue;
            }

            $filas[] = $this->filaDesde($texto, $numero, $zona);
        }

        return $filas;
    }

    // ------------------------------------------------------------------ campos

    private function filaDesde(string $texto, int $numero, Board $zona): ParsedRow
    {
        /** @var array<string, string> $errores */
        $errores = [];
        $campos  = $this->casar($texto);
        $nombre  = $campos['nombre'] ?? null;

        if ($nombre === null) {
            // Ni una de las formas. No se descarta: viaja marcada, con su línea
            // y con el texto crudo, para que la previsualización la enseñe tal
            // cual venía.
            return new ParsedRow(
                scryfallId: null,
                name: null,
                setCode: null,
                collectorNumber: null,
                finish: Finish::porDefecto()->value,
                language: CardLanguage::porDefecto()->value,
                condition: Condition::porDefecto()->value,
                quantity: 0,
                sourceLine: $numero,
                errores: ['linea' => 'la línea no casa con ninguna de las formas conocidas: ' . $texto],
                crudo: ['linea' => $texto],
                board: $zona->value,
            );
        }

        // La cantidad ausente vale 1: una lista de nombres sueltos es una lista
        // de cartas, una de cada.
        $crudoCantidad = $campos['cantidad'] ?? '';
        $quantity      = 1;

        if ($crudoCantidad !== '') {
            $quantity = (int) $crudoCantidad;

            if ($quantity < 1) {
                $errores['quantity'] = 'cantidad no válida: ' . $crudoCantidad;
                $quantity            = 0;
            }
        }

        $set    = $campos['set'] ?? '';
        $coleccionista = $campos['numero'] ?? '';

        return new ParsedRow(
            // El texto plano NUNCA trae Scryfall ID: es la razón de ser del
            // resolvedor por nombre y de todo el riesgo de este plan.
            scryfallId: null,
            name: $nombre,
            setCode: $set === '' ? null : strtoupper($set),
            collectorNumber: $coleccionista === '' ? null : $coleccionista,
            // Acabado, idioma y estado no existen en este formato. Aquí el valor
            // por defecto SÍ es legítimo —es una columna ausente, no una columna
            // presente con un valor que no casa—, que es la distinción que fija
            // `CsvCollectionParser`.
            finish: Finish::porDefecto()->value,
            language: CardLanguage::porDefecto()->value,
            condition: Condition::porDefecto()->value,
            quantity: $quantity,
            sourceLine: $numero,
            errores: $errores,
            crudo: ['linea' => $texto],
            // La zona la dice la última cabecera vista, no la línea: una
            // decklist escribe `Sideboard` una vez y valen las quince de abajo.
            board: $zona->value,
        );
    }

    /**
     * @return array{cantidad: string, nombre: string, set: string, numero: string}|null
     *         null si la línea no es una carta
     */
    private function casar(string $texto): ?array
    {
        if (preg_match(self::FORMA, $texto, $coincidencias) !== 1) {
            return null;
        }

        $nombre = trim($coincidencias['nombre'] ?? '');

        // Un nombre tiene que tener al menos una letra y solo caracteres que un
        // nombre de carta usa de verdad. Sin esto, cualquier línea de prosa
        // pegada por error se importaría como una carta llamada así.
        if ($nombre === ''
            || preg_match('/\p{L}/u', $nombre) !== 1
            || preg_match(self::NOMBRE_VALIDO, $nombre) !== 1
        ) {
            return null;
        }

        return [
            'cantidad' => $coincidencias['cantidad'] ?? '',
            'nombre'   => $nombre,
            'set'      => $coincidencias['set'] ?? '',
            'numero'   => $coincidencias['numero'] ?? '',
        ];
    }

    // ------------------------------------------------------------------ lectura

    /**
     * Las líneas FÍSICAS del texto, con los tres finales de línea que llegan de
     * verdad: `\r\n` de un pegado desde Windows, `\n` de todo lo demás y `\r`
     * suelto de algún exportador antiguo.
     *
     * @return list<string>
     */
    private function lineasDe(string $texto): array
    {
        return preg_split('/\r\n|\n|\r/', $texto) ?: [];
    }

    /**
     * `//` o `#` **al principio** de la línea. En medio NO: ` // ` separa las
     * dos caras de las 501 cartas de doble cara del catálogo.
     */
    private function esComentario(string $texto): bool
    {
        return str_starts_with($texto, '//') || str_starts_with($texto, '#');
    }

    /**
     * La zona que abre esta línea, o null si la línea no es una cabecera.
     *
     * `Deck`, `Sideboard`, `Commander` y `Companion`, con o sin dos puntos. La
     * traducción a los valores del ENUM la hace `Board::desde()`, que ya conoce
     * los alias `deck → main` y `sideboard → side`; aquí no se repite la lista.
     */
    private function cabecera(string $texto): ?Board
    {
        $texto = mb_strtolower(rtrim($texto, ':'), 'UTF-8');

        return in_array($texto, self::CABECERAS, true) ? Board::desde($texto) : null;
    }

    /** Lo mismo, en pregunta: lo que `supports()` descuenta de las útiles. */
    private function esCabecera(string $texto): bool
    {
        return $this->cabecera($texto) !== null;
    }

    private function sinBom(string $texto): string
    {
        return str_starts_with($texto, self::BOM)
            ? substr($texto, strlen(self::BOM))
            : $texto;
    }
}
