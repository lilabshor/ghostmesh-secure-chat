<?php
declare(strict_types=1);

namespace GhostMesh\Config;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST')     ?: 'localhost';
            $port = getenv('DB_PORT')     ?: '3306';
            $db   = getenv('DB_DATABASE') ?: 'ghostmesh_db';
            $user = getenv('DB_USERNAME') ?: 'root';
            $pass = getenv('DB_PASSWORD') ?: '';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

            $options = [
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT         => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    'koneksi database ghostmesh gagal: ' . $e->getMessage(),
                    (int) $e->getCode()
                );
            }
        }

        return self::$instance;
    }
}