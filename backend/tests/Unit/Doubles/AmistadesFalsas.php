<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\Amistad;
use App\Domain\Social\EstadoAmistad;
use PDOException;
use RuntimeException;

/**
 * Amistades de mentira, en memoria, con los cuatro tipos de espectador que la
 * tabla de verdad de `Visibilidad` tiene que saber distinguir.
 *
 * Reproduce las cosas que `MySqlFriendshipRepository` promete y que son
 * justamente las formas que el Plan - Amigos y Seguimiento identifica de abrir
 * `friends` de más:
 *
 *  1. **`pending` NO es amistad.** `pide()` deja una fila, y `sonAmigos()` sigue
 *     diciendo que no. Un doble que respondiera «hay fila entre estos dos»
 *     haría pasar en verde el fallo más fácil de escribir del plan y el más
 *     difícil de ver: funciona perfectamente en toda prueba manual, porque
 *     quien prueba acepta la solicitud.
 *  2. **La pareja es simétrica.** La clave es `(min, max)`, que es exactamente
 *     el `UNIQUE (user_low, user_high)` por columnas generadas del esquema: da
 *     igual quién pidiera y da igual en qué orden se pregunte. Un doble que
 *     guardara `(a, b)` tal cual dejaría pasar una `Visibilidad` que solo viera
 *     la amistad desde el lado de quien la pidió, y media app vería la mitad.
 *  3. **Ese `UNIQUE` REVIENTA, no devuelve false.** `crearSolicitud()` lanza un
 *     `PDOException` con SQLSTATE `23000` y código de driver `1062`, que es
 *     literalmente lo que MySQL 8.0.44 devolvió al medirlo en el M1
 *     (`Duplicate entry '999001-999002' for key 'friendships.uq_pareja'`). Si
 *     este doble devolviera un booleano, el 409 de `friend_request` no estaría
 *     probado contra nada parecido a la realidad — y el 409 es el único sitio
 *     donde el plan confía la corrección a la base de datos en vez de a un `if`.
 *
 * **`sigue()` no toca las filas de amistad, y ahí está la mitad de lo que este
 * doble prueba.** El seguimiento vive en `user_follow`, que es otra tabla y otro
 * puerto que no llega hasta el M3 — pero inventarse aquí ese puerto para poder
 * «probar» al seguidor sería inventarse el agujero, porque desde el punto de
 * vista de `Visibilidad` un seguidor **es** un desconocido: no hay nada que
 * `Visibilidad` deba poder preguntar sobre él. Así que el seguimiento se anota
 * en `$seguimientos`, un array que `sonAmigos()` no lee y no leerá nunca, y el
 * test afirma que está puesto **y** que no abre nada. Si algún día `sigue()`
 * tuviera que escribir en las filas para que un test pasara, sería porque
 * alguien ha unido las dos tablas con un `OR` y `friends` se ha convertido en
 * `everyone`.
 *
 * `$preguntas` cuenta las consultas por el mismo motivo que `$lecturas` en
 * `PrivacidadFalsa`: la regla 1 de `Visibilidad` —eres tú— se resuelve antes de
 * mirar nada, y una amistad consultada de más es una consulta por sección en
 * una ruta pública con rate limit.
 */
class AmistadesFalsas implements FriendshipRepositoryInterface
{
    /**
     * Las filas de `friendships`, por id, como en la tabla.
     *
     * @var array<int, Amistad>
     */
    public array $filas = [];

    /**
     * El `UNIQUE (user_low, user_high)` traído a memoria: pareja simétrica → id.
     *
     * @var array<string, int>
     */
    private array $porPareja = [];

    /**
     * Lo que el `JOIN users` del listado saca de cada persona. **Sin `email`**,
     * igual que la consulta real: lo que no se selecciona no se puede publicar
     * por descuido.
     *
     * @var array<int, array{username: string, displayName: ?string, avatarUrl: ?string}>
     */
    private array $personas = [];

    /**
     * Quién sigue a quién. **`sonAmigos()` no lo consulta**, y esa es toda su
     * razón de existir: se guarda para que un test pueda afirmar que el
     * seguimiento está realmente puesto y que aun así no abre absolutamente
     * nada. Los pares son asimétricos a propósito, como la `PRIMARY KEY
     * (follower_id, followed_id)` de `user_follow`: A→B y B→A son dos hechos
     * legítimos a la vez.
     *
     * @var list<array{int, int}> pares (seguidor, seguido)
     */
    public array $seguimientos = [];

    /** Cuántas veces se ha preguntado por una amistad. */
    public int $preguntas = 0;

    private int $siguienteId = 1;

    /** Amistad hecha: se pidió y se aceptó. Es el ÚNICO estado que abre `friends`. */
    public function acepta(int $a, int $b): self
    {
        $this->escribir($a, $b, EstadoAmistad::Aceptada);

        return $this;
    }

    /**
     * Solicitud enviada y **sin aceptar**. Esto no es una amistad, por mucho que
     * haya fila: quien la recibió no ha dicho que sí. Se conserva la dirección
     * porque en la tabla real `requester_id` se guarda aunque el `UNIQUE` sea
     * simétrico —es lo que decide quién puede aceptar—, pero para `sonAmigos()`
     * da exactamente igual.
     */
    public function pide(int $solicitante, int $destinatario): self
    {
        $this->escribir($solicitante, $destinatario, EstadoAmistad::Pendiente);

        return $this;
    }

    /**
     * Seguir a alguien. **No escribe ninguna fila de amistad, a propósito**:
     * seguir no es un estado de la amistad, es otra tabla, y este método existe
     * para que un test pueda montar un seguidor de verdad y comprobar que sigue
     * siendo un desconocido para `Visibilidad`.
     */
    public function sigue(int $seguidor, int $seguido): self
    {
        $this->seguimientos[] = [$seguidor, $seguido];

        return $this;
    }

    /**
     * Lo que el `JOIN users` sacaría de esta persona. Sin registrar, el listado
     * inventa un `username` a partir del id: basta para probar que cada fila
     * trae a la persona del OTRO lado, que es lo que puede salir mal.
     */
    public function persona(int $id, string $username, ?string $displayName = null, ?string $avatarUrl = null): self
    {
        $this->personas[$id] = [
            'username'    => $username,
            'displayName' => $displayName,
            'avatarUrl'   => $avatarUrl,
        ];

        return $this;
    }

    /** El id de la fila entre esos dos, para poder mandarlo en una petición. */
    public function idDe(int $a, int $b): int
    {
        return $this->porPareja[self::pareja($a, $b)]
            ?? throw new RuntimeException('No hay ninguna amistad montada entre ' . $a . ' y ' . $b . '.');
    }

    /**
     * @inheritDoc
     */
    public function sonAmigos(int $a, int $b): bool
    {
        $this->preguntas++;

        // Los dos noes que importan y que son distintos entre sí: no hay fila
        // (desconocido, o seguidor) y hay fila pero está en `pending`. Ninguno
        // de los dos es una amistad, y por eso el doble no los distingue al
        // responder: lo único que distingue es `accepted`.
        $amistad = $this->deLaPareja($a, $b);

        return $amistad !== null && $amistad->estaAceptada();
    }

    /**
     * @inheritDoc
     */
    public function crearSolicitud(int $solicitante, int $destinatario): int
    {
        if (isset($this->porPareja[self::pareja($solicitante, $destinatario)])) {
            throw self::duplicado($solicitante, $destinatario);
        }

        return $this->escribir($solicitante, $destinatario, EstadoAmistad::Pendiente);
    }

    /**
     * @inheritDoc
     */
    public function buscar(int $friendshipId): ?Amistad
    {
        return $this->filas[$friendshipId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function aceptar(int $friendshipId): bool
    {
        $amistad = $this->filas[$friendshipId] ?? null;

        if ($amistad === null || $amistad->estaAceptada()) {
            return false;
        }

        $this->filas[$friendshipId] = new Amistad(
            id:          $amistad->id,
            requesterId: $amistad->requesterId,
            addresseeId: $amistad->addresseeId,
            estado:      EstadoAmistad::Aceptada,
            creadaEl:    $amistad->creadaEl,
        );

        return true;
    }

    /**
     * @inheritDoc
     */
    public function eliminar(int $friendshipId): bool
    {
        $amistad = $this->filas[$friendshipId] ?? null;

        if ($amistad === null) {
            return false;
        }

        unset(
            $this->filas[$friendshipId],
            $this->porPareja[self::pareja($amistad->requesterId, $amistad->addresseeId)]
        );

        return true;
    }

    /**
     * @inheritDoc
     */
    public function listarDe(int $userId): array
    {
        $listado = [];

        foreach ($this->filas as $amistad) {
            if (!$amistad->participa($userId)) {
                continue;
            }

            $otro = $amistad->elOtro($userId);

            $listado[] = [
                'amistad' => $amistad,
                'persona' => ['id' => $otro] + ($this->personas[$otro] ?? [
                    'username'    => 'usuario' . $otro,
                    'displayName' => null,
                    'avatarUrl'   => null,
                ]),
            ];
        }

        return $listado;
    }

    /** Crea o pisa la fila entre esos dos y devuelve su id. */
    private function escribir(int $solicitante, int $destinatario, EstadoAmistad $estado): int
    {
        $clave = self::pareja($solicitante, $destinatario);
        $id    = $this->porPareja[$clave] ?? $this->siguienteId++;

        $this->porPareja[$clave] = $id;
        $this->filas[$id]        = new Amistad(
            id:          $id,
            requesterId: $solicitante,
            addresseeId: $destinatario,
            estado:      $estado,
            creadaEl:    '2026-09-14 12:00:00',
        );

        return $id;
    }

    private function deLaPareja(int $a, int $b): ?Amistad
    {
        $id = $this->porPareja[self::pareja($a, $b)] ?? null;

        return $id === null ? null : $this->filas[$id];
    }

    /**
     * La clave simétrica, que es el `UNIQUE (user_low, user_high)` del esquema
     * traído a memoria: `LEAST` y `GREATEST` de los dos ids. Calcularla en un
     * solo sitio es lo que garantiza que escribir y leer usen la misma.
     */
    private static function pareja(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    /**
     * El `1062` tal y como lo lanza el driver: SQLSTATE `23000` en `getCode()`
     * —que es una **cadena**, y por eso hay que escribir la propiedad en vez de
     * pasarla al constructor de `Exception`, que solo acepta enteros— y el
     * código real de MySQL en `errorInfo[1]`, que es lo único que distingue una
     * clave duplicada de una foránea rota.
     */
    public static function duplicado(int $a, int $b): PDOException
    {
        return new class (min($a, $b) . '-' . max($a, $b)) extends PDOException {
            public function __construct(string $pareja)
            {
                parent::__construct(
                    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry "
                    . "'{$pareja}' for key 'friendships.uq_pareja'"
                );

                $this->code      = '23000';
                $this->errorInfo = ['23000', 1062, "Duplicate entry '{$pareja}' for key 'friendships.uq_pareja'"];
            }
        };
    }
}
