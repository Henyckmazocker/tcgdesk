<?php

declare(strict_types=1);

namespace App\Domain\Social;

/**
 * Una fila de `friendships`, con lo único que hace falta para decidir quién
 * puede hacer qué con ella.
 *
 * **Por qué existe teniendo el `UNIQUE` simétrico.** El esquema guarda la pareja
 * ordenada en `user_low`/`user_high` para que la fila cruzada sea imposible,
 * pero conserva `requester_id` y `addressee_id` **a propósito**: quién pidió
 * sigue importando, y de hecho es lo único que decide quién puede aceptar. Si
 * esta clase tuviera solo «los dos ids», el caso que el M2 del
 * Plan - Amigos y Seguimiento existe para cerrar —que el propio solicitante
 * acepte su solicitud— no se podría ni formular.
 *
 * Los tres predicados de abajo son la seguridad entera de la amistad, y están
 * aquí y no repartidos por los use cases por el mismo motivo por el que
 * `Visibilidad` es un solo sitio: cuatro comprobaciones parecidas en cuatro use
 * cases es cómo una de ellas acaba invertida. `AceptarAmistad` y
 * `RechazarAmistad` preguntan `laRecibio()`; `DeshacerAmistad` pregunta
 * `participa()`, porque deshacer lo puede hacer cualquiera de los dos.
 *
 * **`elOtro()` nunca devuelve el propio id**, y de eso depende que el listado de
 * `/friends` enseñe personas y no un espejo. Se le pasa siempre el usuario
 * autenticado, que sale de `AuthMiddleware` y jamás del cuerpo.
 */
final class Amistad
{
    public function __construct(
        public readonly int $id,
        public readonly int $requesterId,
        public readonly int $addresseeId,
        public readonly EstadoAmistad $estado,
        public readonly ?string $creadaEl = null,
    ) {
    }

    /**
     * @param array<string, mixed> $fila tal como la devuelve `friendships`
     */
    public static function fromRow(array $fila): self
    {
        return new self(
            id:          (int) $fila['id'],
            requesterId: (int) $fila['requester_id'],
            addresseeId: (int) $fila['addressee_id'],
            estado:      EstadoAmistad::desde($fila['status'] ?? null),
            creadaEl:    isset($fila['created_at']) ? (string) $fila['created_at'] : null,
        );
    }

    /** Pidió él esta amistad. **No puede aceptarla**: ver `laRecibio()`. */
    public function laPidio(int $userId): bool
    {
        return $this->requesterId === $userId;
    }

    /**
     * La recibió, o sea: **es el único que puede aceptarla o rechazarla**.
     *
     * Sin esta comprobación en los use cases, cualquiera acepta sus propias
     * solicitudes y la amistad recíproca deja de ser recíproca — que es
     * exactamente como el nivel `friends` pasaría a significar «cualquiera que
     * pulse pedir».
     */
    public function laRecibio(int $userId): bool
    {
        return $this->addresseeId === $userId;
    }

    /** Es uno de los dos. Lo que hace falta para **deshacer**, que es cosa de ambos. */
    public function participa(int $userId): bool
    {
        return $this->laPidio($userId) || $this->laRecibio($userId);
    }

    /**
     * El id de la OTRA persona. Solo tiene sentido si `participa($userId)`; si
     * no, devolvería al solicitante por descarte y el listado enseñaría a
     * alguien que no toca, así que quien llama comprueba antes.
     */
    public function elOtro(int $userId): int
    {
        return $this->laPidio($userId) ? $this->addresseeId : $this->requesterId;
    }

    public function estaAceptada(): bool
    {
        return $this->estado === EstadoAmistad::Aceptada;
    }
}
