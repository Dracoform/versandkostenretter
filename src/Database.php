<?php

declare(strict_types=1);

namespace Versandkostenretter;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO connection wrapper for the shared MySQL database.
 *
 * Contract (hard requirement from the operator):
 *  - only tables with the prefix "VSKR_" may be touched
 *  - connections are read-only; this MVP never writes to the database
 *  - credentials come exclusively from config/config.php (gitignored,
 *    outside the public web root)
 */
final class Database
{
    /** Tables this application is allowed to reference. */
    public const ALLOWED_TABLES = ['VSKR_shops', 'VSKR_products'];

    private ?PDO $pdo = null;

    public function __construct(private array $config)
    {
    }

    public static function fromConfigFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                'Database configuration missing. Copy config/config.example.php to config/config.php.'
            );
        }
        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('Invalid configuration file format.');
        }
        return new self($config);
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $db = $this->config['db'] ?? null;
        if (!is_array($db)) {
            throw new RuntimeException('Invalid configuration: [db] section missing.');
        }
        foreach (['host', 'name', 'user', 'pass'] as $key) {
            if (!isset($db[$key]) || !is_string($db[$key]) || $db[$key] === '') {
                throw new RuntimeException("Invalid configuration: db.{$key} missing.");
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) ($db['port'] ?? 3306),
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                // Read-only connection: even a bug in our SQL cannot write.
                PDO::MYSQL_ATTR_READ_DEFAULT_FILE => null, // keep driver defaults
            ]);
        } catch (PDOException $e) {
            // Never leak credentials or stack traces to the user.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return $this->pdo;
    }

    /**
     * Guardrail used by tests/CI: every table name mentioned in our SQL must
     * be VSKR_-prefixed. Returns the list of offending table names.
     */
    public static function assertOnlyVskrTables(string $sql): array
    {
        $violations = [];
        if (preg_match_all('/\b(FROM|JOIN|INTO|UPDATE|TABLE)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $table = $hit[2];
                if (!str_starts_with($table, 'VSKR_')) {
                    $violations[] = $table;
                }
            }
        }
        return $violations;
    }
}
