<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use App\Domain\Social\Visibilidad;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\AmistadesFalsas;
use Tests\Unit\Doubles\PrivacidadFalsa;

/**
 * Las cuatro reglas de `Visibilidad`, que son la seguridad entera del
 * Plan - Perfil Público y Mazos Compartibles.
 *
 * Lo que protege, por orden de daño si se rompe:
 *
 *  1. **Que `friends` sigue siendo false.** La tabla `friendships` no existe. Un
 *     `true` provisional aquí publica la colección de todo el mundo, y lo hace
 *     en silencio: la respuesta con datos de más parece correcta.
 *  2. **Que `nobody` no tiene excepciones** salvo el propio dueño, ni con sesión
 *     ni sin ella.
 *  3. **Que `everyone` se ve SIN sesión.** Es el punto entero del plan: si esto
 *     se rompiera al revés, el perfil público no sería público.
 *  4. **Que tú siempre te ves a ti mismo**, incluso con la sección en `nobody`.
 *     Sin esta regla, ocultar una sección te la esconde también a ti y parece
 *     que se han borrado los datos.
 *  5. **Que un usuario sin fila se comporta como los defectos**, que es el caso
 *     de absolutamente todo el mundo hoy.
 *
 * La matriz va por las cinco secciones a propósito: la única diferencia entre
 * ellas es el defecto, y una regla que se olvidara de una sección concreta solo
 * se vería probándolas todas.
 *
 * ------------------------------------------------------------------------
 *
 * **Desde el M0 del Plan - Amigos y Seguimiento, este fichero se adelanta al
 * código.** La tabla de verdad completa de `puedeVer()` —las cinco secciones
 * por los tres niveles por los cinco tipos de espectador— está escrita **antes**
 * de que `Visibilidad` sepa de amistades, y por eso hay cinco casos que **fallan
 * a propósito**: los de `friends` vistos por un amigo aceptado. Son el hito.
 * Pasarán en el M2, cuando la regla 4 deje de responder que no por `esInerte()`
 * y pase a preguntarle a `FriendshipRepositoryInterface`. **Si alguien los pone
 * en verde antes de eso, lo que ha hecho es abrir `friends` sin tabla.**
 *
 * Los otros cuatro tipos de espectador pasan hoy y tienen que seguir pasando
 * **idénticos** después del M2, que es el criterio literal del plan: quien tiene
 * una solicitud `pending` y quien sigue el perfil ven exactamente lo mismo que
 * un desconocido en las quince combinaciones. Si alguno difiere, hay un agujero,
 * y son los dos agujeros que el plan nombra: que `pending` cuente como amistad,
 * y que el seguimiento se cuele como amistad.
 *
 * El espectador **anónimo** no está en la tabla porque no es uno de los cuatro
 * tipos que el plan contrasta —no puede ser amigo, ni pedir, ni seguir—: lo
 * cubren los tests de reglas de arriba, que lo prueban en los tres niveles.
 */
final class VisibilidadTest extends TestCase
{
    private const DUENYO     = 7;
    private const EXTRANYO   = 42;
    private const SIN_SESION = null;

    /** Amistad `accepted`: el único estado que abre el nivel `friends`. */
    private const AMIGO = 11;

    /** Pidió amistad al dueño y sigue esperando. **No es un amigo.** */
    private const PENDIENTE = 13;

    /**
     * El caso espejo: es el DUEÑO quien le pidió amistad, y él no ha aceptado.
     * Existe aparte porque el `UNIQUE` es simétrico y la fila es la misma; lo
     * que cambia es quién pidió, y eso no puede cambiar la respuesta.
     */
    private const PENDIENTE_INVERSO = 19;

    /** Sigue el perfil del dueño. No le ha pedido nada y no tiene ningún acceso. */
    private const SEGUIDOR = 17;

    private PrivacidadFalsa $privacidad;

    private AmistadesFalsas $amistades;

    private Visibilidad $visibilidad;

    protected function setUp(): void
    {
        $this->privacidad = new PrivacidadFalsa();

        // Las cuatro relaciones montadas de una vez y para todos los tests: son
        // hechos sobre las personas, no sobre la privacidad, y no cambian
        // cuando cambia el nivel de una sección.
        $this->amistades = (new AmistadesFalsas())
            ->acepta(self::DUENYO, self::AMIGO)
            ->pide(self::PENDIENTE, self::DUENYO)
            ->pide(self::DUENYO, self::PENDIENTE_INVERSO)
            ->sigue(self::SEGUIDOR, self::DUENYO);

        $this->visibilidad = new Visibilidad($this->privacidad, $this->amistades);
    }

    /** @return iterable<string, array{Seccion}> */
    public static function secciones(): iterable
    {
        foreach (Seccion::cases() as $seccion) {
            yield $seccion->value => [$seccion];
        }
    }

    /** @return iterable<string, array{Seccion, Nivel}> */
    public static function seccionesPorNivel(): iterable
    {
        foreach (Seccion::cases() as $seccion) {
            foreach (Nivel::cases() as $nivel) {
                yield $seccion->value . ' en ' . $nivel->value => [$seccion, $nivel];
            }
        }
    }

    // ------------------------------------------------------------------
    // Regla 1 — tú siempre te ves a ti mismo
    // ------------------------------------------------------------------

    #[DataProvider('seccionesPorNivel')]
    public function testElDuenyoSeVeAsiMismoEnCualquierNivel(Seccion $seccion, Nivel $nivel): void
    {
        $this->privacidad->todasEn(self::DUENYO, $nivel);

        $this->assertTrue(
            $this->visibilidad->puedeVer(self::DUENYO, self::DUENYO, $seccion),
            "Con {$seccion->value} en {$nivel->value}, el dueño ha dejado de verse a sí mismo"
        );
    }

    /**
     * La regla 1 va la PRIMERA, y esto es lo que lo comprueba: con la sección en
     * `nobody` —el nivel que no admite excepciones— el dueño la sigue viendo.
     */
    public function testNiSiquieraNobodyEscondeElPerfilDeSuDuenyo(): void
    {
        $this->privacidad->todasEn(self::DUENYO, Nivel::Nadie);

        $this->assertTrue($this->visibilidad->puedeVer(self::DUENYO, self::DUENYO, Seccion::Valor));
    }

    // ------------------------------------------------------------------
    // Regla 2 — nobody es nadie
    // ------------------------------------------------------------------

    #[DataProvider('secciones')]
    public function testNobodyNoLoVeNiUnExtranyoNiUnAnonimo(Seccion $seccion): void
    {
        $this->privacidad->todasEn(self::DUENYO, Nivel::Nadie);

        $this->assertFalse($this->visibilidad->puedeVer(self::DUENYO, self::EXTRANYO, $seccion));
        $this->assertFalse($this->visibilidad->puedeVer(self::DUENYO, self::SIN_SESION, $seccion));
    }

    // ------------------------------------------------------------------
    // Regla 3 — everyone es todos, también sin sesión
    // ------------------------------------------------------------------

    #[DataProvider('secciones')]
    public function testEveryoneLoVeCualquieraIncluidoQuienNoTieneCuenta(Seccion $seccion): void
    {
        $this->privacidad->todasEn(self::DUENYO, Nivel::Todos);

        $this->assertTrue($this->visibilidad->puedeVer(self::DUENYO, self::EXTRANYO, $seccion));
        $this->assertTrue(
            $this->visibilidad->puedeVer(self::DUENYO, self::SIN_SESION, $seccion),
            "{$seccion->value} en everyone no se ve sin sesión, que es el punto entero del plan"
        );
    }

    // ------------------------------------------------------------------
    // Regla 4 — friends es false mientras no exista `friendships`
    // ------------------------------------------------------------------

    /**
     * El test que no se puede tocar hasta que exista la tabla `friendships`.
     */
    #[DataProvider('secciones')]
    public function testFriendsEsFalseParaTodoElMundoSinLaTablaDeAmistades(Seccion $seccion): void
    {
        $this->privacidad->todasEn(self::DUENYO, Nivel::Amigos);

        $this->assertFalse(
            $this->visibilidad->puedeVer(self::DUENYO, self::EXTRANYO, $seccion),
            "{$seccion->value} en friends se está enseñando a un usuario con sesión, y `friendships` no existe"
        );
        $this->assertFalse(
            $this->visibilidad->puedeVer(self::DUENYO, self::SIN_SESION, $seccion),
            "{$seccion->value} en friends se está enseñando a un anónimo"
        );
    }

    /**
     * La prueba 2 de la sección ✅ del plan, en unitario: las cinco secciones en
     * `friends` son cinco «privada» para cualquiera que no seas tú.
     */
    public function testConLasCincoEnFriendsNoSeVeNadaSalvoParaElDuenyo(): void
    {
        $this->privacidad->todasEn(self::DUENYO, Nivel::Amigos);

        foreach (Seccion::cases() as $seccion) {
            $this->assertFalse($this->visibilidad->puedeVer(self::DUENYO, self::EXTRANYO, $seccion));
            $this->assertTrue($this->visibilidad->puedeVer(self::DUENYO, self::DUENYO, $seccion));
        }
    }

    // ------------------------------------------------------------------
    // Sin fila: los defectos, que es el caso de todo el mundo hoy
    // ------------------------------------------------------------------

    public function testSinFilaLaColeccionLosMazosYLasEdicionesSonPublicos(): void
    {
        foreach ([Seccion::Coleccion, Seccion::Mazos, Seccion::Sets] as $seccion) {
            $this->assertTrue(
                $this->visibilidad->puedeVer(self::DUENYO, self::SIN_SESION, $seccion),
                "{$seccion->value} debería nacer en everyone"
            );
        }
    }

    /**
     * Y las dos sensibles nacen en `friends`, o sea, hoy CERRADAS. Si alguien
     * «uniformiza» los defectos de la migración, este test se cae.
     */
    public function testSinFilaElValorYLaListaDeDeseosNacenCerrados(): void
    {
        foreach ([Seccion::Valor, Seccion::Deseos] as $seccion) {
            $this->assertFalse(
                $this->visibilidad->puedeVer(self::DUENYO, self::SIN_SESION, $seccion),
                "{$seccion->value} debería nacer en friends, que hoy es que no"
            );
            $this->assertFalse($this->visibilidad->puedeVer(self::DUENYO, self::EXTRANYO, $seccion));
        }
    }

    // ------------------------------------------------------------------
    // Lo que no es una regla pero rompe lo mismo
    // ------------------------------------------------------------------

    /** Cada sección responde por sí sola: abrir una no abre las demás. */
    public function testCadaSeccionSeResuelveConSuPropioNivel(): void
    {
        $this->privacidad
            ->todasEn(self::DUENYO, Nivel::Nadie)
            ->pon(self::DUENYO, Seccion::Mazos, Nivel::Todos);

        $this->assertTrue($this->visibilidad->puedeVer(self::DUENYO, self::SIN_SESION, Seccion::Mazos));
        $this->assertFalse($this->visibilidad->puedeVer(self::DUENYO, self::SIN_SESION, Seccion::Coleccion));
    }

    /**
     * Dos dueños distintos no comparten permisos.
     *
     * Es el fallo que introduciría una caché mal indexada, y sería de los graves:
     * el perfil de uno respondiendo con la privacidad de otro.
     */
    public function testLaPrivacidadDeUnUsuarioNoSeAplicaALaDeOtro(): void
    {
        $this->privacidad
            ->todasEn(self::DUENYO, Nivel::Nadie)
            ->todasEn(self::EXTRANYO, Nivel::Todos);

        $this->assertFalse($this->visibilidad->puedeVer(self::DUENYO, null, Seccion::Coleccion));
        $this->assertTrue($this->visibilidad->puedeVer(self::EXTRANYO, null, Seccion::Coleccion));
    }

    /**
     * Preguntar por las cinco secciones es UNA consulta, no cinco.
     *
     * La ruta del perfil pregunta por las cinco de una tacada para decir cuáles
     * enseña, y está abierta a internet con rate limit: cinco `SELECT` idénticos
     * por visita multiplican por cinco el coste de la ráfaga que M0 limita.
     */
    public function testLasCincoSeccionesDelMismoDuenyoSeLeenDeUnaSolaConsulta(): void
    {
        foreach (Seccion::cases() as $seccion) {
            $this->visibilidad->puedeVer(self::DUENYO, self::SIN_SESION, $seccion);
        }

        $this->assertSame(1, $this->privacidad->lecturas);
    }

    /** Y el atajo del dueño ni siquiera consulta la tabla. */
    public function testVerteATiMismoNoConsultaLaPrivacidad(): void
    {
        $this->visibilidad->puedeVer(self::DUENYO, self::DUENYO, Seccion::Valor);

        $this->assertSame(0, $this->privacidad->lecturas);
    }

    // ==================================================================
    // M0 del Plan - Amigos y Seguimiento — la tabla de verdad completa
    //
    // Escrita ANTES de que `Visibilidad` sepa de amistades. Los cinco casos
    // de `friends` x «amigo aceptado» fallan a propósito y son el hito; los
    // demás pasan hoy y tienen que pasar IDÉNTICOS después del M2.
    // ==================================================================

    /**
     * Los cinco espectadores de la tabla, y qué debe ver cada uno en cada nivel.
     *
     * La relación de cada uno con el dueño la monta `setUp()` y no cambia; lo
     * único que se mueve entre casos es el nivel de la sección. Las tres
     * columnas se leen así:
     *
     * | espectador          | nobody | friends | everyone |
     * |---------------------|--------|---------|----------|
     * | yo                  | sí     | sí      | sí       | ← regla 1, va antes que todo
     * | amigo aceptado      | no     | **sí**  | sí       | ← la única columna que hoy falla
     * | solicitud pendiente | no     | no      | sí       |
     * | seguidor            | no     | no      | sí       |
     * | desconocido         | no     | no      | sí       |
     *
     * Las tres últimas filas son la misma fila, y eso es exactamente lo que el
     * plan exige comprobar: pedir amistad y seguir un perfil no mueven ni una
     * casilla respecto a no haber hecho nada.
     *
     * @return array<string, array{int, array<string, bool>}>
     */
    private static function espectadores(): array
    {
        return [
            'yo' => [self::DUENYO, [
                Nivel::Nadie->value  => true,
                Nivel::Amigos->value => true,
                Nivel::Todos->value  => true,
            ]],
            'amigo aceptado' => [self::AMIGO, [
                Nivel::Nadie->value  => false,
                Nivel::Amigos->value => true,
                Nivel::Todos->value  => true,
            ]],
            'solicitud pendiente' => [self::PENDIENTE, [
                Nivel::Nadie->value  => false,
                Nivel::Amigos->value => false,
                Nivel::Todos->value  => true,
            ]],
            'seguidor' => [self::SEGUIDOR, [
                Nivel::Nadie->value  => false,
                Nivel::Amigos->value => false,
                Nivel::Todos->value  => true,
            ]],
            'desconocido' => [self::EXTRANYO, [
                Nivel::Nadie->value  => false,
                Nivel::Amigos->value => false,
                Nivel::Todos->value  => true,
            ]],
        ];
    }

    /**
     * Las cinco secciones x los tres niveles x los cinco espectadores.
     *
     * Va por las cinco secciones y no por una porque lo único que las distingue
     * es el defecto, y una regla que se olvidara de una sola solo se ve
     * probándolas todas — el mismo motivo que los providers de arriba.
     *
     * @return iterable<string, array{Seccion, Nivel, int, bool, string}>
     */
    public static function tablaDeVerdad(): iterable
    {
        foreach (Seccion::cases() as $seccion) {
            foreach (Nivel::cases() as $nivel) {
                foreach (self::espectadores() as $quien => [$espectadorId, $esperado]) {
                    yield "{$seccion->value} en {$nivel->value} visto por {$quien}" => [
                        $seccion,
                        $nivel,
                        $espectadorId,
                        $esperado[$nivel->value],
                        $quien,
                    ];
                }
            }
        }
    }

    #[DataProvider('tablaDeVerdad')]
    public function testLaTablaDeVerdadDePuedeVer(
        Seccion $seccion,
        Nivel $nivel,
        int $espectadorId,
        bool $esperado,
        string $quien
    ): void {
        $this->privacidad->todasEn(self::DUENYO, $nivel);

        $this->assertSame(
            $esperado,
            $this->visibilidad->puedeVer(self::DUENYO, $espectadorId, $seccion),
            $esperado
                ? "{$seccion->value} en {$nivel->value} NO se le está enseñando a «{$quien}», y debería"
                : "{$seccion->value} en {$nivel->value} se le está enseñando a «{$quien}», que no tiene por qué verlo"
        );
    }

    /**
     * El criterio literal con el que el plan da por bueno este hito: **quien
     * tiene una solicitud pendiente y quien sigue el perfil ven exactamente lo
     * mismo que un desconocido, en las quince combinaciones.**
     *
     * No compara contra una tabla de valores esperados sino contra la respuesta
     * real del desconocido, a propósito: así sigue significando lo mismo el día
     * que la del desconocido cambie por lo que sea. Lo que se afirma es que las
     * tres columnas son **la misma columna**, que es lo que se rompería si
     * alguien preguntara «¿hay fila entre estos dos?» en vez de «¿hay fila con
     * `status = 'accepted'`?», o si uniera `friendships` y `user_follow` con un
     * `OR`. Los dos agujeros que el plan nombra, en un solo test.
     */
    public function testElPendienteYElSeguidorVenExactamenteLoMismoQueUnDesconocido(): void
    {
        foreach (Nivel::cases() as $nivel) {
            $visibilidad = $this->visibilidadConTodoEn($nivel);

            foreach (Seccion::cases() as $seccion) {
                $delDesconocido = $visibilidad->puedeVer(self::DUENYO, self::EXTRANYO, $seccion);

                $this->assertSame(
                    $delDesconocido,
                    $visibilidad->puedeVer(self::DUENYO, self::PENDIENTE, $seccion),
                    "Con {$seccion->value} en {$nivel->value}, PEDIR amistad cambia lo que se ve: "
                    . 'una solicitud sin aceptar está contando como amistad'
                );

                $this->assertSame(
                    $delDesconocido,
                    $visibilidad->puedeVer(self::DUENYO, self::SEGUIDOR, $seccion),
                    "Con {$seccion->value} en {$nivel->value}, SEGUIR cambia lo que se ve: "
                    . 'friends acaba de convertirse en «cualquiera que pulse seguir»'
                );
            }
        }
    }

    /**
     * Y da igual quién pidiera.
     *
     * El `UNIQUE` es simétrico, así que la fila pendiente entre A y B es una
     * sola mirada desde los dos lados; lo que no puede pasar es que la
     * dirección la convierta en un sí. Se prueba con la sección en `friends`
     * porque es el único nivel donde la respuesta podría depender de la
     * amistad: en `nobody` y en `everyone` ya está decidida sin preguntar.
     */
    public function testUnaSolicitudPendienteNoAbreNadaEnNingunaDeLasDosDirecciones(): void
    {
        $this->privacidad->todasEn(self::DUENYO, Nivel::Amigos);

        foreach (Seccion::cases() as $seccion) {
            $this->assertFalse(
                $this->visibilidad->puedeVer(self::DUENYO, self::PENDIENTE, $seccion),
                "{$seccion->value}: quien PIDIÓ la amistad ya está viendo el nivel friends"
            );

            $this->assertFalse(
                $this->visibilidad->puedeVer(self::DUENYO, self::PENDIENTE_INVERSO, $seccion),
                "{$seccion->value}: aquel a quien el dueño pidió amistad ya ve el nivel friends sin haber aceptado"
            );
        }
    }

    /**
     * La premisa sobre la que se sostiene toda la tabla: el seguimiento está
     * realmente puesto, y aun así no produce una amistad **en ninguno de los dos
     * sentidos**.
     *
     * Sin esta comprobación, la fila «seguidor» de la tabla pasaría también con
     * un doble en el que `sigue()` no hiciera nada — y entonces no estaría
     * probando que seguir no da acceso, sino que no seguir no lo da.
     */
    public function testSeguirNoProduceJamasUnaAmistad(): void
    {
        $this->assertContains(
            [self::SEGUIDOR, self::DUENYO],
            $this->amistades->seguimientos,
            'El seguimiento ni siquiera está montado: la fila «seguidor» de la tabla no prueba nada'
        );

        $this->assertFalse($this->amistades->sonAmigos(self::SEGUIDOR, self::DUENYO));
        $this->assertFalse($this->amistades->sonAmigos(self::DUENYO, self::SEGUIDOR));
    }

    /**
     * La regla 1 va la primera y se resuelve entera sin salir del método: verte
     * a ti mismo no consulta la tabla de privacidad —eso ya lo afirma el test de
     * arriba— y tampoco puede consultar la de amistades. Nadie es amigo de sí
     * mismo, así que una consulta aquí sería una que siempre responde que no.
     */
    public function testVerteATiMismoNoPreguntaPorAmistades(): void
    {
        $this->visibilidad->puedeVer(self::DUENYO, self::DUENYO, Seccion::Valor);

        $this->assertSame(0, $this->amistades->preguntas);
    }

    /**
     * Una `Visibilidad` nueva con las cinco secciones del dueño en ese nivel.
     *
     * Hace falta que sea nueva: la caché por petición guarda los niveles del
     * dueño la primera vez que se le pregunta y vive lo que viva el objeto. Un
     * bucle que recorriera los tres niveles reutilizando la misma instancia los
     * resolvería los tres con el primero y pasaría en verde sin probar nada.
     */
    private function visibilidadConTodoEn(Nivel $nivel): Visibilidad
    {
        $this->privacidad->todasEn(self::DUENYO, $nivel);

        return new Visibilidad($this->privacidad, $this->amistades);
    }
}
