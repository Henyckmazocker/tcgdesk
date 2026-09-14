<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Repository\UserPrivacyRepositoryInterface;
use App\Domain\Social\Descubrimiento;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;

/**
 * Privacidad de mentira, en memoria.
 *
 * Reproduce las dos cosas que `MySqlUserPrivacyRepository` promete y que
 * `Visibilidad` da por hechas: que **un usuario sin fila devuelve los cinco
 * defectos** —no null, no array vacío— y que el array trae **siempre las cinco
 * secciones**. Un doble que devolviera solo lo que le han puesto haría pasar en
 * verde a una `Visibilidad` que se rompiera con el caso más común de todos, que
 * hoy es exactamente ese: nadie tiene fila.
 *
 * `$lecturas` cuenta consultas porque la caché por petición de `Visibilidad` es
 * lo que evita cinco `SELECT` idénticos en una ruta pública con rate limit.
 */
class PrivacidadFalsa implements UserPrivacyRepositoryInterface
{
    /** @var array<int, array<string, Nivel>> user_id → sección → nivel */
    public array $niveles = [];

    /** Cuántas veces se ha consultado la tabla. */
    public int $lecturas = 0;

    /** Cuántas veces se ha escrito en ella. Un `privacy_set` sin cambios no escribe. */
    public int $escrituras = 0;

    /**
     * `user_id` → si sale en el buscador. **Un array aparte de `$niveles`**, y
     * no una sexta clave dentro de él, exactamente como en el repositorio real:
     * `show_in_search` no es una `Seccion` y su valor no es un `Nivel`. Si este
     * doble las mezclara, haría pasar en verde el fallo que
     * `App\Domain\Social\Descubrimiento` explica: `Seguir::tieneCaraPublica()`
     * recorre `nivelesDe()` buscando un `everyone` y una sexta entrada abierta
     * por defecto le diría que sí a un perfil con las cinco secciones cerradas.
     *
     * @var array<int, Descubrimiento>
     */
    public array $busqueda = [];

    /** Lecturas de la sexta columna, contadas APARTE de las de las cinco. */
    public int $lecturasDeBusqueda = 0;

    /** Escrituras de la sexta columna, contadas aparte por lo mismo. */
    public int $escriturasDeBusqueda = 0;

    /** Pone el MISMO nivel en las cinco secciones de un usuario. */
    public function todasEn(int $userId, Nivel $nivel): self
    {
        foreach (Seccion::cases() as $seccion) {
            $this->niveles[$userId][$seccion->value] = $nivel;
        }

        return $this;
    }

    /** Pone el sexto selector de alguien, dejando sus cinco secciones como estén. */
    public function ponBusqueda(int $userId, Descubrimiento $valor): self
    {
        $this->busqueda[$userId] = $valor;

        return $this;
    }

    /** Pone una sección concreta, dejando el resto como esté. */
    public function pon(int $userId, Seccion $seccion, Nivel $nivel): self
    {
        $this->niveles[$userId] ??= Seccion::nivelesPorDefecto();
        $this->niveles[$userId][$seccion->value] = $nivel;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function nivelesDe(int $userId): array
    {
        $this->lecturas++;

        // Sin fila, los defectos. Es el contrato del puerto, no una comodidad
        // del doble: hoy no hay ni una fila en `user_privacy_settings`.
        return $this->niveles[$userId] ?? Seccion::nivelesPorDefecto();
    }

    /**
     * @inheritDoc
     */
    public function guardarNiveles(int $userId, array $cambios): array
    {
        $this->escrituras++;

        // La mezcla es LO QUE SE PRUEBA, así que el doble la hace igual que el
        // ODKU de MySQL: se parte de lo que hay —los defectos si no hay fila— y
        // se pisan SOLO las secciones que vienen. Un doble que guardara el array
        // recibido tal cual haría pasar en verde justo el fallo que este hito
        // tiene que impedir: cambiar una sección y devolver las otras cuatro al
        // defecto.
        $niveles = $this->niveles[$userId] ?? Seccion::nivelesPorDefecto();

        foreach (Seccion::cases() as $seccion) {
            if (isset($cambios[$seccion->value])) {
                $niveles[$seccion->value] = $cambios[$seccion->value];
            }
        }

        $this->niveles[$userId] = $niveles;

        return $niveles;
    }

    /**
     * @inheritDoc
     */
    public function descubrimientoDe(int $userId): Descubrimiento
    {
        $this->lecturasDeBusqueda++;

        // Sin fila, el defecto — que aquí es `everyone`, la única de las seis
        // columnas que nace abierta. Es el contrato del puerto y **el caso de
        // todo el mundo hoy**: un doble que devolviera `nobody` para quien no
        // tiene fila haría pasar en verde justo el fallo que el M6 avisa que
        // rompe el hito en silencio.
        return $this->busqueda[$userId] ?? Descubrimiento::porDefecto();
    }

    /**
     * @inheritDoc
     */
    public function guardarDescubrimiento(int $userId, Descubrimiento $valor): Descubrimiento
    {
        $this->escriturasDeBusqueda++;

        $this->busqueda[$userId] = $valor;

        return $valor;
    }
}
