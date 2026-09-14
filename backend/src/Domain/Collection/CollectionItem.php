<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use InvalidArgumentException;

/**
 * Una línea de la colección, ya validada: las seis columnas del `UNIQUE KEY`
 * más la cantidad y las notas.
 *
 * Se construye con `desdePeticion()`, que **exige** el `printing_uuid` y el
 * `user_id` pero deja los otros cuatro en sus valores por defecto
 * (`normal`, `English`, `NM`, cantidad 1). Eso es lo que sostiene la mitigación
 * del riesgo del plan: la ficha de catálogo añade una carta con **un solo
 * clic**, y las cinco dimensiones existen en el modelo porque la importación
 * las necesita, no porque la UI las pida.
 *
 * El `userId` **nunca** sale del cuerpo de la petición: lo pone `AuthMiddleware`
 * en el request y el use case lo pasa aparte. Si viniera del payload,
 * cualquiera podría escribir en la colección de otro.
 */
final class CollectionItem
{
    /**
     * La columna es SMALLINT UNSIGNED (máximo 65.535) y el upsert **suma**, así
     * que un tope generoso por operación deja margen a las sumas sucesivas sin
     * que MySQL aborte por desbordamiento.
     */
    public const CANTIDAD_MAXIMA = 9999;

    public const NOTAS_MAXIMO = 512;

    public function __construct(
        public readonly int $userId,
        public readonly string $printingUuid,
        public readonly Finish $finish,
        public readonly CardLanguage $language,
        public readonly Condition $condition,
        public readonly int $quantity = 1,
        public readonly bool $isWishlist = false,
        public readonly ?string $notes = null
    ) {
        if ($this->printingUuid === '') {
            throw new InvalidArgumentException('Falta el printing_uuid de la carta.');
        }

        if ($this->quantity < 1 || $this->quantity > self::CANTIDAD_MAXIMA) {
            throw new InvalidArgumentException(
                "La cantidad debe estar entre 1 y " . self::CANTIDAD_MAXIMA . ", y llegó {$this->quantity}."
            );
        }

        if ($this->notes !== null && mb_strlen($this->notes) > self::NOTAS_MAXIMO) {
            throw new InvalidArgumentException('Las notas no pueden pasar de ' . self::NOTAS_MAXIMO . ' caracteres.');
        }
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente, sin el user_id
     * @throws InvalidArgumentException si algo no es del dominio
     */
    public static function desdePeticion(int $userId, array $peticion): self
    {
        $uuid = trim((string) ($peticion['printing_uuid'] ?? ''));

        $notas = isset($peticion['notes']) && trim((string) $peticion['notes']) !== ''
            ? trim((string) $peticion['notes'])
            : null;

        return new self(
            userId:       $userId,
            printingUuid: $uuid,
            // Ausente = el valor por defecto; presente pero inválido = error. Un
            // acabado que no existe es un fallo del cliente, no algo a adivinar:
            // guardarlo como 'normal' falsearía la valoración en silencio.
            finish:    isset($peticion['finish'])    ? Finish::desde($peticion['finish'])         : Finish::porDefecto(),
            language:  isset($peticion['language'])  ? CardLanguage::desde($peticion['language']) : CardLanguage::porDefecto(),
            condition: isset($peticion['condition']) ? Condition::desde($peticion['condition'])   : Condition::porDefecto(),
            quantity:  isset($peticion['quantity']) && is_numeric($peticion['quantity'])
                ? (int) $peticion['quantity']
                : 1,
            isWishlist: filter_var($peticion['is_wishlist'] ?? false, FILTER_VALIDATE_BOOL),
            notes:      $notas,
        );
    }

    /**
     * Las columnas tal como van a `mtg_collection_item`.
     *
     * @return array<string, mixed>
     */
    public function aFila(): array
    {
        return [
            'user_id'         => $this->userId,
            'printing_uuid'   => $this->printingUuid,
            'finish'          => $this->finish->value,
            'language'        => $this->language->value,
            'condition_grade' => $this->condition->value,
            'quantity'        => $this->quantity,
            'is_wishlist'     => $this->isWishlist ? 1 : 0,
            'notes'           => $this->notes,
        ];
    }
}
