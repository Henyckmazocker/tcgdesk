<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Construye la conexión PDO a MySQL desde las variables de entorno.
 *
 * `utf8mb4` en el DSN no es opcional: los nombres de carta japoneses y los
 * símbolos de maná lo exigen, y la colación de la conexión tiene que coincidir
 * con la de las tablas o los JOIN por nombre fallarán.
 */
class DatabaseConnector
{
    public function getConnection(): PDO
    {
        $host    = $_ENV['DB_HOST'] ?? 'mysql';
        $port    = $_ENV['DB_PORT'] ?? '3306';
        $dbName  = $_ENV['DB_DATABASE'] ?? '';
        $user    = $_ENV['DB_USERNAME'] ?? '';
        $pass    = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            // El mensaje de PDO lleva credenciales; no se propaga al cliente.
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }
}
