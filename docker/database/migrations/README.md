# Migraciones de base de datos

Esta carpeta contiene migraciones SQL incrementales que se aplican **encima** del esquema base
(`init.sql`).

> [!warning] La regla que aquí no se rompe
> `init.sql` solo se ejecuta sobre una base de datos virgen. En cuanto el volumen `mysql_data`
> existe, MySQL no vuelve a mirarlo nunca. Por eso **todo cambio posterior al esquema es una
> migración**, sin excepción — incluidas las tablas `mtg_*` del catálogo, que las crea el
> *Plan - Mirror del Catálogo MTG*.
>
> LibraryVue rompió esta disciplina y su `init.sql` ya no describe su esquema real. Es la razón de
> que este README exista desde el primer commit.

## Convención de nombres

```
YYYYMMDD_HHMMSS_descripcion.sql
```

Ejemplos:
```
20260812_090000_mtg_catalog_tables.sql
20260815_143000_mtg_price_daily.sql
```

- El prefijo **fecha+hora** garantiza un orden alfabético determinista.
- La **descripción** va en minúsculas con guiones bajos, breve y orientada a la acción.
- Cada fichero se aplica **exactamente una vez** y queda registrado en la tabla `migration_history`.

## Reglas

1. **Nunca modifiques una migración ya aplicada.** Añade una nueva.
2. **Usa siempre `IF NOT EXISTS` / `IF EXISTS`.**
3. **Cada fichero debe ser idempotente** en lo posible.
4. **No hay ficheros de rollback** — diseña con cuidado.
5. `utf8mb4` / `utf8mb4_unicode_ci` explícito en cada `CREATE TABLE`. La colación tiene que coincidir
   con la de la conexión o los `JOIN` por nombre de carta fallarán.

## Aplicar migraciones

```bash
# Desarrollo — aplica las pendientes sin resetear la BD
./dev-setup.sh --migrate

# Directamente (desde la raíz del proyecto)
./docker/database/run_migrations.sh
```

## Plantilla

```sql
-- Migration: YYYYMMDD_HHMMSS_descripcion.sql
-- Descripción: <qué hace esta migración>

CREATE TABLE IF NOT EXISTS nueva_tabla (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    -- ... columnas
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Tabla de control

El runner crea `migration_history` si no existe (también está declarada en `init.sql`):

```sql
CREATE TABLE migration_history (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    filename   VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    checksum   VARCHAR(64)  NOT NULL
);
```

Para ver qué se ha aplicado:
```bash
docker compose exec mysql mysql -u tcgdesk_user -p tcgdesk_db \
  -e "SELECT filename, applied_at FROM migration_history ORDER BY applied_at;"
```
