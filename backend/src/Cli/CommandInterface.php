<?php

declare(strict_types=1);

namespace App\Cli;

/**
 * Contrato de todo comando de `bin/tcgdesk`.
 *
 * Los comandos de ingesta del Plan - Mirror del Catálogo MTG
 * (`catalog:import`, `prices:sync`, `images:cache`) implementan esto.
 *
 * `run()` devuelve el código de salida del proceso: 0 = éxito, ≠0 = error. Es lo
 * que mira el cron, así que no es decorativo — un comando que falla en silencio
 * con código 0 deja el catálogo desactualizado sin que nadie se entere.
 */
interface CommandInterface
{
    /** Nombre con el que se invoca, p.ej. 'catalog:import'. */
    public function getName(): string;

    /** Una línea; sale en el listado de `bin/tcgdesk`. */
    public function getDescription(): string;

    /**
     * @param  string[] $args Argumentos posteriores al nombre del comando.
     * @return int Código de salida del proceso.
     */
    public function run(array $args): int;
}
