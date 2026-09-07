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

        // Full-DSN override (used by tests/local SQLite runs). Production
        // config never sets db.dsn and keeps the MySQL construction below.
        $dsn = isset($db['dsn']) && is_string($db['dsn']) && $db['dsn'] !== ''
            ? $db['dsn']
            : sprintf(
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
    public const METADATA_SCHEMA = 'information_schema';

    /**
     * Statement-aware VSKR namespace guard.
     *
     * Validation model:
     *  1. The SQL is split into statements; comments and string literals are
     *     stripped FIRST so their prose/content can never be mistaken for
     *     table names (the old regex read "the" out of a comment).
     *  2. Each statement is classified:
     *       - SELECT ... FROM information_schema.*   => allowed (read-only
     *         metadata queries used by our idempotent migrations)
     *       - any write/DDL target (CREATE/ALTER/DROP/INSERT/UPDATE/DELETE/
     *         REPLACE/TRUNCATE/RENAME)                => table MUST be VSKR_*
     *       - any other reference to a non-VSKR table => rejected
     *  3. information_schema is metadata-READ-only: any statement that would
     *     write to it (or any other non-VSKR schema) is rejected.
     *
     * @return list<string> offending table/schema identifiers (empty = valid)
     */
    public static function assertOnlyVskrTables(string $sql): array
    {
        $violations = [];
        foreach (self::splitStatements($sql) as $statement) {
            foreach (self::validateStatement($statement) as $v) {
                $violations[] = $v;
            }
        }
        return array_values(array_unique($violations));
    }

    /**
     * Strip comments ('-- ...', '# ...', '/* ... *''.'/') and string literals
     * ('...' and "...") with backslash-escape awareness, then split into
     * statements on semicolons that terminate a statement.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $two = substr($sql, $i, 2);

            if ($two === '--' || $c === '#') {                 // line comment
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                $out .= ' ';
                continue;
            }
            if ($two === '/*') {                               // block comment
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $out .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {      // string/identifier
                $quote = $c;
                $out .= ' ';                                   // content never reaches the parser
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === '\\' && $quote !== '`') { $i += 2; continue; } // escaped char
                    if ($sql[$i] === $quote) {
                        if ($quote === "'" && ($sql[$i + 1] ?? '') === "'") { $i += 2; continue; } // '' escape
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            $out .= $c;
            $i++;
        }

        $statements = [];
        foreach (preg_split('/;\s*\n|;/u', $out) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $statements[] = $part;
            }
        }
        return $statements;
    }

    /**
     * Validate one comment-free, literal-free statement.
     *
     * @return list<string>
     */
    private static function validateStatement(string $statement): array
    {
        $violations = [];
        // Write/DDL verbs: their TARGET must be VSKR_*.
        // Note: 'ON DUPLICATE KEY UPDATE <col> = ...' is a clause of the INSERT,
        // not a statement whose target is a table — excluded explicitly.
        $writeTargets = [];

        // CREATE INDEX <name> ON <table> / DROP INDEX <name> ON <table>:
        // the target is the table after ON.
        if (preg_match_all('/\b(?:CREATE|DROP)\s+INDEX\s+`?[A-Za-z0-9_]+`?\s+ON\s+`?([A-Za-z0-9_]+)`?(?:\s*\.\s*`?([A-Za-z0-9_]+)`?)?/i', $statement, $mi, PREG_SET_ORDER)) {
            foreach ($mi as $hit) {
                $writeTargets[] = ($hit[2] ?? '') !== '' ? $hit[2] : $hit[1];
            }
        }

        if (preg_match_all('/\b(?:(?<!KEY\s)UPDATE|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|INSERT\s+INTO|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE(?:\s+TABLE)?|RENAME\s+TO)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_]+)`?(?:\s*\.\s*`?([A-Za-z0-9_]+)`?)?/i', $statement, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                // 'UPDATE' handled via negative lookbehind; optional
                // IF [NOT] EXISTS is skipped so the real table is captured;
                // schema.table form: hit[2] holds the table, hit[1] the schema.
                if (($hit[2] ?? '') !== '') {
                    $writeTargets[] = $hit[1];
                    $writeTargets[] = $hit[2];
                } else {
                    $writeTargets[] = $hit[1];
                }
            }
        }
        foreach ($writeTargets as $target) {
            $uTarget = strtoupper($target);
            if ($uTarget === strtoupper(self::METADATA_SCHEMA) || str_starts_with($uTarget, strtoupper(self::METADATA_SCHEMA) . '_')) {
                $violations[] = $target; // metadata schema is READ-only
            } elseif (!str_starts_with($target, 'VSKR_')) {
                $violations[] = $target;
            }
        }

        // Read paths: FROM / JOIN. information_schema allowed, anything else
        // non-VSKR rejected.
        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?([A-Za-z0-9_]+)`?(?:\s*\.\s*`?([A-Za-z0-9_]+)`?)?/i', $statement, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $first = $hit[1];
                $second = $hit[2] ?? '';
                if ($second !== '') {
                    // Qualified name schema.table: only the metadata schema may
                    // be read outside VSKR_.
                    if (strtoupper($first) !== strtoupper(self::METADATA_SCHEMA)) {
                        $violations[] = $first;
                    }
                    continue;
                }
                if (!str_starts_with($first, 'VSKR_') && strtoupper($first) !== strtoupper(self::METADATA_SCHEMA)) {
                    $violations[] = $first;
                }
            }
        }

        return $violations;
    }
}
