<?php

declare(strict_types=1);

namespace App\Domain\Social;

/**
 * Cómo acabó un intento de mover una amistad: aceptarla, rechazarla o
 * deshacerla.
 *
 * **Por qué un enum y no `true`/`false` ni `null`.** Los tres use cases pueden
 * fallar por tres motivos que se responden con tres códigos HTTP distintos —404,
 * 403 y 409— y que no se pueden confundir sin consecuencias:
 *
 *  - Un `false` genérico obligaría al controller a adivinar, y adivinar aquí
 *    significa elegir entre confirmar que una fila existe (403) o negarlo (404)
 *    **por accidente**. `friendships.id` es un autoincremental global: quien va
 *    probando números aprende de la diferencia.
 *  - Peor, un `false` haría que el caso que este hito existe para cerrar —el
 *    solicitante aceptando su propia solicitud— se respondiera igual que «ese
 *    id no existe», y el día que alguien «arreglara» ese 404 devolvería un 200.
 *
 * `NoTeCorresponde` es **la única línea de seguridad del M2** del
 * Plan - Amigos y Seguimiento, escrita como un valor con nombre para que borrarla
 * no sea posible sin darse cuenta.
 *
 * El mensaje NO vive aquí: lo pone `FriendController`, porque «esa solicitud ya
 * estaba aceptada» y «esa amistad todavía está pendiente» son el mismo
 * `EstadoQueNoToca` visto desde dos acciones distintas. Lo que sí vive aquí es
 * el código, porque ese no depende de la acción.
 */
enum ResultadoAmistad
{
    /** Salió bien: la fila cambió de estado, o desapareció. */
    case Hecho;

    /**
     * No hay ninguna fila con ese id — **o la hay y no es de quien pregunta**.
     *
     * Las dos cosas se responden igual, y es el mismo criterio que
     * `DeckController` aplica a un mazo ajeno: decir «existe pero no es tuya»
     * convierte el id autoincremental en un censo de quién tiene amistades con
     * quién. El 403 se reserva para quien **sí** está en la fila pero está en el
     * lado que no toca, que es información que esa persona ya tenía.
     */
    case NoExiste;

    /**
     * Estás en la fila, pero del lado que no puede hacer esto. Hoy solo pasa de
     * una forma: **el solicitante intentando aceptar su propia solicitud**.
     */
    case NoTeCorresponde;

    /**
     * La fila es tuya y del lado correcto, pero está en el otro estado:
     * aceptar algo ya aceptado, o deshacer algo que todavía está pendiente.
     */
    case EstadoQueNoToca;

    public function codigoHttp(): int
    {
        return match ($this) {
            self::Hecho           => 200,
            self::NoExiste        => 404,
            self::NoTeCorresponde => 403,
            self::EstadoQueNoToca => 409,
        };
    }
}
