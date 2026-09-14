<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\DeshacerAmistad;
use App\Domain\Social\ResultadoAmistad;
use App\Domain\Social\Seccion;
use App\Domain\Social\Visibilidad;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\AmistadesFalsas;
use Tests\Unit\Doubles\PrivacidadFalsa;

/**
 * Deshacer una amistad, que es la única de las tres acciones sobre una fila
 * existente que **pueden hacer los dos lados**.
 *
 * Una amistad se pide de un lado y se concede del otro, pero se rompe desde
 * cualquiera de los dos: exigir el permiso del que no quiere romperla sería
 * exigir permiso para dejar de enseñarle tus cosas.
 *
 * El test que cierra el fichero es el que vale por todos: **quitar la amistad
 * quita el acceso en la siguiente lectura**, sin nada que invalidar y sin nada
 * que «deshacer» del pasado. Se comprueba contra `Visibilidad` de verdad y no
 * contra el repositorio, porque es ahí donde se nota.
 */
final class DeshacerAmistadTest extends TestCase
{
    private const UNO  = 7;
    private const OTRO = 11;
    private const AJENO = 42;

    private AmistadesFalsas $amistades;

    private DeshacerAmistad $deshacer;

    private int $id;

    protected function setUp(): void
    {
        $this->amistades = new AmistadesFalsas();
        $this->deshacer  = new DeshacerAmistad($this->amistades);

        $this->id = $this->amistades
            ->acepta(self::UNO, self::OTRO)
            ->idDe(self::UNO, self::OTRO);
    }

    public function testLaDeshaceElQueLaPidio(): void
    {
        self::assertSame(
            ResultadoAmistad::Hecho,
            ($this->deshacer)(self::UNO, ['friendship_id' => $this->id])
        );

        self::assertNull($this->amistades->buscar($this->id));
    }

    public function testYTambienElQueLaAcepto(): void
    {
        self::assertSame(
            ResultadoAmistad::Hecho,
            ($this->deshacer)(self::OTRO, ['friendship_id' => $this->id])
        );

        self::assertNull($this->amistades->buscar($this->id));
    }

    public function testUnTerceroNoPuedeYRecibeUn404(): void
    {
        $resultado = ($this->deshacer)(self::AJENO, ['friendship_id' => $this->id]);

        self::assertSame(ResultadoAmistad::NoExiste, $resultado);
        self::assertSame(404, $resultado->codigoHttp());
        self::assertNotNull($this->amistades->buscar($this->id));
    }

    /**
     * **El solicitante puede retirar su propia solicitud** (enmienda del
     * 2026-09-14, decidida por David; ver el Log del plan).
     *
     * Antes esto devolvía 409 con el argumento de que una pendiente «no es una
     * amistad» y de que borrarla le quitaba la notificación al otro. Las dos
     * cosas son ciertas y ninguna basta: sin este caso, **quien pide amistad no
     * tiene ninguna forma de echarse atrás**, porque `RechazarAmistad` es solo
     * del destinatario. Quedarse atado a una petición propia hasta que el otro
     * conteste no es una garantía de nadie. Y que la notificación desaparezca es
     * exactamente lo que significa retirarla.
     */
    public function testElSolicitanteRetiraSuPropiaSolicitudPendiente(): void
    {
        $amistades = new AmistadesFalsas();
        $id        = $amistades->pide(self::UNO, self::OTRO)->idDe(self::UNO, self::OTRO);

        $resultado = (new DeshacerAmistad($amistades))(self::UNO, ['friendship_id' => $id]);

        self::assertSame(ResultadoAmistad::Hecho, $resultado);
        self::assertSame(200, $resultado->codigoHttp());
        self::assertNull($amistades->buscar($id), 'La solicitud retirada sigue en la tabla');
    }

    /**
     * Y el destinatario también, porque el criterio ya no es el estado de la
     * fila sino de qué lado estás. Que además tenga `friend_reject` no le quita
     * esta vía: las dos borran la misma fila y el resultado es el mismo.
     */
    public function testElDestinatarioTambienPuedeDeshacerUnaPendiente(): void
    {
        $amistades = new AmistadesFalsas();
        $id        = $amistades->pide(self::UNO, self::OTRO)->idDe(self::UNO, self::OTRO);

        $resultado = (new DeshacerAmistad($amistades))(self::OTRO, ['friendship_id' => $id]);

        self::assertSame(ResultadoAmistad::Hecho, $resultado);
        self::assertNull($amistades->buscar($id));
    }

    /**
     * Lo que NO cambia con la enmienda: un tercero sigue sin poder tocar una
     * pendiente ajena. El estado dejó de importar; `participa()` no.
     */
    public function testUnTerceroTampocoPuedeDeshacerUnaPendienteAjena(): void
    {
        $amistades = new AmistadesFalsas();
        $id        = $amistades->pide(self::UNO, self::OTRO)->idDe(self::UNO, self::OTRO);

        $resultado = (new DeshacerAmistad($amistades))(self::AJENO, ['friendship_id' => $id]);

        self::assertSame(ResultadoAmistad::NoExiste, $resultado);
        self::assertSame(404, $resultado->codigoHttp());
        self::assertNotNull($amistades->buscar($id));
    }

    /**
     * Lo que se corta es la PRÓXIMA lectura, y se corta entera y de golpe.
     * Contra `Visibilidad` de verdad, que es el único sitio donde se decide si
     * algo se ve.
     */
    public function testQuitarLaAmistadQuitaElAccesoEnLaSiguienteLectura(): void
    {
        $privacidad  = new PrivacidadFalsa();
        $visibilidad = new Visibilidad($privacidad, $this->amistades);

        // `Valor` nace en `friends` por defecto: es justo lo que este plan abre.
        self::assertTrue($visibilidad->puedeVer(self::UNO, self::OTRO, Seccion::Valor));

        ($this->deshacer)(self::OTRO, ['friendship_id' => $this->id]);

        // Una `Visibilidad` nueva porque la de arriba cachea los NIVELES —no la
        // amistad— dentro de la misma petición; en producción cada petición
        // construye la suya.
        self::assertFalse(
            (new Visibilidad($privacidad, $this->amistades))->puedeVer(self::UNO, self::OTRO, Seccion::Valor)
        );
    }
}
