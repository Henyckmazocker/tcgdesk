<?php

declare(strict_types=1);

namespace App\Domain\Deck;

use InvalidArgumentException;

/**
 * Un mazo, ya validado: las cuatro columnas que el usuario escribe
 * (`name`, `status`, `format`, `notes`) más el dueño.
 *
 * El `userId` **nunca** sale del cuerpo de la petición: lo pone `AuthMiddleware`
 * en el request y el use case lo pasa aparte, exactamente igual que en
 * `CollectionItem`. `mtg_deck.id` es un `BIGINT AUTO_INCREMENT` global, así que
 * si el dueño viniera del payload bastaría con probar números para escribir en
 * los mazos de otro.
 *
 * **`format` es texto libre acotado, no un enum.** Tiene que casar con
 * `mtg_legality.format`, que son los nombres de MTGJSON en minúsculas
 * (`commander`, `standard`, `modern`) y los publica el origen: inventar aquí una
 * lista propia obligaría a tocar el dominio cada vez que MTGJSON añada un
 * formato, y —peor— dejaría fuera formatos legales que la tabla sí conoce. Lo
 * único que se hace es normalizar a minúsculas, que es la forma en la que están
 * escritos en `mtg_legality`: con otra caja el `JOIN` de M6 no casaría ni una
 * fila y nadie vería un error, solo faltarían los avisos de legalidad.
 */
final class Deck
{
    public const NOMBRE_MAXIMO = 255;

    public const FORMATO_MAXIMO = 24;

    public const NOTAS_MAXIMO = 1024;

    /**
     * El tamaño mínimo de un mazo, que es **un aviso y no un bloqueo**: un mazo
     * de 40 cartas se guarda igual, solo que la ficha dice que le faltan.
     *
     * Son los dos números del plan y no una tabla por formato: 60 en general y
     * **100 en `commander`**. Deliberadamente no se extienden a los otros
     * formatos de cien cartas que `mtg_legality` conoce (`duel`, `brawl`,
     * `oathbreaker`, `paupercommander`, `predh`…): inventarles el mínimo aquí
     * sería decidir por MTGJSON, que no publica ese dato en ninguna tabla.
     */
    public const TAMANO_MINIMO = 60;

    public const TAMANO_MINIMO_COMMANDER = 100;

    /** El único formato con un mínimo propio. */
    public const FORMATO_COMMANDER = 'commander';

    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly DeckStatus $status,
        public readonly ?string $format = null,
        public readonly ?string $notes = null
    ) {
        if ($this->name === '') {
            throw new InvalidArgumentException('El mazo necesita un nombre.');
        }

        if (mb_strlen($this->name) > self::NOMBRE_MAXIMO) {
            throw new InvalidArgumentException(
                'El nombre del mazo no puede pasar de ' . self::NOMBRE_MAXIMO . ' caracteres.'
            );
        }

        if ($this->format !== null && mb_strlen($this->format) > self::FORMATO_MAXIMO) {
            throw new InvalidArgumentException(
                'El formato no puede pasar de ' . self::FORMATO_MAXIMO . ' caracteres.'
            );
        }

        if ($this->notes !== null && mb_strlen($this->notes) > self::NOTAS_MAXIMO) {
            throw new InvalidArgumentException(
                'Las notas no pueden pasar de ' . self::NOTAS_MAXIMO . ' caracteres.'
            );
        }
    }

    /**
     * El alta de un mazo: solo el nombre es obligatorio.
     *
     * Lo demás cae en el mismo defecto que la columna (`building`, sin formato,
     * sin notas), que es lo que permite crear un mazo escribiendo una sola cosa.
     *
     * @param  array<string, mixed> $peticion Payload del cliente, sin el user_id
     * @throws InvalidArgumentException si algo no es del dominio
     */
    public static function desdePeticion(int $userId, array $peticion): self
    {
        return new self(
            userId: $userId,
            name:   self::nombre($peticion['name'] ?? ''),
            // Ausente = el defecto; presente pero inválido = error. Un estado
            // que no existe es un fallo del cliente, no algo a adivinar: caer en
            // `building` dejaría un mazo montado sin consumir colección y el
            // aviso de sobreasignación no saltaría jamás.
            status: isset($peticion['status']) ? DeckStatus::desde($peticion['status']) : DeckStatus::porDefecto(),
            format: self::formato($peticion['format'] ?? null),
            notes:  self::notas($peticion['notes'] ?? null),
        );
    }

    /**
     * Los campos de una edición **parcial**, ya validados y con el nombre de su
     * columna.
     *
     * Devuelve solo las claves que venían en la petición: `deck_update` acepta
     * `name?`, `status?`, `format?` y `notes?`, y mandar solo el estado —lo que
     * hace el botón «desmontar» de M5— no puede borrar el nombre del mazo. Por
     * eso no se construye un `Deck` completo aquí: no hay con qué rellenar lo
     * que el cliente no mandó, y rellenarlo con defectos sería reescribir
     * silenciosamente lo que el usuario ya había puesto.
     *
     * La lista blanca es lo único que llega al `SET` del `UPDATE`, así que
     * ninguna clave inventada por el cliente puede acabar en el SQL.
     *
     * @param  array<string, mixed> $peticion
     * @return array<string, string|null> columna → valor
     * @throws InvalidArgumentException si algo no es del dominio
     */
    public static function camposDesdePeticion(array $peticion): array
    {
        $campos = [];

        if (array_key_exists('name', $peticion)) {
            $campos['name'] = self::nombre($peticion['name']);
        }

        if (array_key_exists('status', $peticion)) {
            $campos['status'] = DeckStatus::desde($peticion['status'])->value;
        }

        // `format` y `notes` son nullables de verdad: mandar null es "quítame el
        // formato", y hay que poder distinguirlo de "no lo menciono".
        if (array_key_exists('format', $peticion)) {
            $campos['format'] = self::formato($peticion['format']);
        }

        if (array_key_exists('notes', $peticion)) {
            $campos['notes'] = self::notas($peticion['notes']);
        }

        return $campos;
    }

    /**
     * Cuántas cartas pide el formato **como mínimo**, o null si no hay nada que
     * pedir.
     *
     * `null` cuando el mazo no tiene formato —`mtg_deck.format` es `NULL`able y
     * «sin formato» es un mazo perfectamente válido—: sin formato no hay reglas
     * que aplicar, así que no se avisa de nada. Es estático porque la pregunta
     * se hace sobre la fila que devuelve el repositorio, no sobre un `Deck`
     * reconstruido: el mazo que se lee de la base ya viene como array.
     *
     * **Es un aviso, no una validación.** Nadie llama a esto para decidir si se
     * guarda algo.
     */
    public static function tamanoMinimo(?string $formato): ?int
    {
        if ($formato === null || $formato === '') {
            return null;
        }

        return $formato === self::FORMATO_COMMANDER
            ? self::TAMANO_MINIMO_COMMANDER
            : self::TAMANO_MINIMO;
    }

    /**
     * Las columnas tal como van a `mtg_deck`.
     *
     * @return array<string, mixed>
     */
    public function aFila(): array
    {
        return [
            'user_id' => $this->userId,
            'name'    => $this->name,
            'status'  => $this->status->value,
            'format'  => $this->format,
            'notes'   => $this->notes,
        ];
    }

    private static function nombre(mixed $valor): string
    {
        $nombre = trim((string) (is_scalar($valor) ? $valor : ''));

        if ($nombre === '') {
            throw new InvalidArgumentException('El mazo necesita un nombre.');
        }

        return $nombre;
    }

    private static function formato(mixed $valor): ?string
    {
        $formato = strtolower(trim((string) (is_scalar($valor) ? $valor : '')));

        return $formato === '' ? null : $formato;
    }

    private static function notas(mixed $valor): ?string
    {
        $notas = trim((string) (is_scalar($valor) ? $valor : ''));

        return $notas === '' ? null : $notas;
    }
}
