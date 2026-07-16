<?php
declare(strict_types=1);

namespace Navicat\Services;

use PDO;

/** Executes SQL dump files statement-by-statement (no shell tools). */
final class SqlScriptRunner
{
    /** @param callable(string):void|null $onStatement @param array{override?:bool,sourceDatabase?:string} $options */
    public static function runFile(
        PDO $pdo,
        string $filePath,
        ?callable $onStatement = null,
        array $options = [],
        string $targetDatabase = '',
        string $engine = 'mysql',
    ): void {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Backup file not found');
        }

        $sql = self::readFile($filePath);
        if (!empty($options['override']) && $targetDatabase !== '') {
            $sourceDb = trim((string)($options['sourceDatabase'] ?? '')) ?: self::parseBackupSourceDatabase($sql);
            if ($sourceDb !== '' && $sourceDb !== $targetDatabase && $engine === 'mysql') {
                if ($onStatement) {
                    $onStatement("Override: remapping {$sourceDb} → {$targetDatabase}");
                }
                $sql = self::rewriteMysqlDatabase($sql, $sourceDb, $targetDatabase);
            }
        }
        self::runSql($pdo, $sql, $onStatement, $options, $targetDatabase, $engine);
    }

    public static function readFile(string $filePath): string
    {
        if (str_ends_with(strtolower($filePath), '.gz')) {
            $data = gzfile($filePath);
            if ($data === false) {
                throw new \RuntimeException('Failed to read gzip backup');
            }
            return implode('', $data);
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new \RuntimeException('Failed to read backup file');
        }
        return $sql;
    }

    /** @param callable(string):void|null $onStatement @param array{override?:bool,sourceDatabase?:string} $options */
    public static function runSql(
        PDO $pdo,
        string $sql,
        ?callable $onStatement = null,
        array $options = [],
        string $targetDatabase = '',
        string $engine = 'mysql',
    ): void {
        $override = !empty($options['override']);
        foreach (self::splitStatements($sql) as $statement) {
            $trim = trim($statement);
            if ($trim === '' || self::isSkippable($trim)) {
                continue;
            }
            if ($onStatement) {
                $onStatement($trim);
            }
            try {
                $pdo->exec($trim);
            } catch (\PDOException $e) {
                if (!$override || !self::isAlreadyExistsError($e, $engine)) {
                    throw $e;
                }
                $drop = self::dropBeforeCreate($trim, $targetDatabase, $engine);
                if ($drop === null) {
                    throw $e;
                }
                $pdo->exec($drop);
                $pdo->exec($trim);
            }
        }
    }

    public static function parseBackupSourceDatabase(string $sql): ?string
    {
        if (preg_match('/^-- Database:\s*(.+)$/m', $sql, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public static function rewriteMysqlDatabase(string $sql, string $sourceDb, string $targetDb): string
    {
        if ($sourceDb === $targetDb) {
            return $sql;
        }
        $src = preg_quote(self::mysqlIdent($sourceDb), '/');
        return preg_replace('/' . $src . '\./i', self::mysqlIdent($targetDb) . '.', $sql) ?? $sql;
    }

    private static function mysqlIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function isAlreadyExistsError(\PDOException $e, string $engine): bool
    {
        if ($engine !== 'mysql') {
            $code = (string)$e->getCode();
            return $code === '42P07' || $code === '42710';
        }
        $msg = $e->getMessage();
        return str_contains($msg, '1050') || str_contains($msg, '1304') || str_contains($msg, 'already exists');
    }

    private static function dropBeforeCreate(string $stmt, string $targetDatabase, string $engine): ?string
    {
        if ($engine !== 'mysql') {
            return null;
        }
        $upper = strtoupper(ltrim($stmt));
        if (str_starts_with($upper, 'CREATE TABLE')) {
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:(`[^`]+`)\.(`[^`]+`)|(`[^`]+`))/i', $stmt, $m)) {
                $db = $m[1] ?? self::mysqlIdent($targetDatabase);
                $table = $m[2] ?? $m[3];
                return "DROP TABLE IF EXISTS {$db}.{$table}";
            }
        }
        if (str_starts_with($upper, 'CREATE VIEW')) {
            if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM=\S+\s+)?(?:DEFINER=\S+\s+)?(?:SQL\s+SECURITY\s+\S+\s+)?VIEW\s+(?:(`[^`]+`)\.(`[^`]+`)|(`[^`]+`))/i', $stmt, $m)) {
                $db = $m[1] ?? self::mysqlIdent($targetDatabase);
                $view = $m[2] ?? $m[3];
                return "DROP VIEW IF EXISTS {$db}.{$view}";
            }
        }
        if (str_starts_with($upper, 'CREATE TRIGGER')) {
            if (preg_match('/^CREATE\s+(?:DEFINER=\S+\s+)?TRIGGER\s+(?:(`[^`]+`)\.)?(`[^`]+`)/i', $stmt, $m)) {
                return 'DROP TRIGGER IF EXISTS ' . $m[2];
            }
        }
        if (str_starts_with($upper, 'CREATE PROCEDURE') || str_starts_with($upper, 'CREATE FUNCTION')) {
            if (preg_match('/^CREATE\s+(?:DEFINER=\S+\s+)?(?:PROCEDURE|FUNCTION)\s+(?:(`[^`]+`)\.(`[^`]+`)|(`[^`]+`))/i', $stmt, $m)) {
                $db = $m[1] ?? self::mysqlIdent($targetDatabase);
                $name = $m[2] ?? $m[3];
                $kind = str_starts_with($upper, 'CREATE PROCEDURE') ? 'PROCEDURE' : 'FUNCTION';
                return "DROP {$kind} IF EXISTS {$db}.{$name}";
            }
        }
        return null;
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $len = strlen($sql);
        $buf = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        $dollarTag = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                $buf .= $ch;
                if ($ch === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            if ($inBlockComment) {
                $buf .= $ch;
                if ($ch === '*' && $next === '/') {
                    $buf .= $next;
                    $i++;
                    $inBlockComment = false;
                }
                continue;
            }

            if ($dollarTag !== null) {
                $buf .= $ch;
                if ($ch === '$') {
                    $maybe = substr($sql, $i, strlen($dollarTag));
                    if ($maybe === $dollarTag) {
                        $buf .= substr($dollarTag, 1);
                        $i += strlen($dollarTag) - 1;
                        $dollarTag = null;
                    }
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($ch === '-' && $next === '-') {
                    $inLineComment = true;
                    $buf .= $ch;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $inBlockComment = true;
                    $buf .= $ch . $next;
                    $i++;
                    continue;
                }
                if ($ch === '$') {
                    if (preg_match('/^\$[A-Za-z0-9_]*\$/A', substr($sql, $i), $m)) {
                        $dollarTag = $m[0];
                        $buf .= $dollarTag;
                        $i += strlen($dollarTag) - 1;
                        continue;
                    }
                }
            }

            if (!$inDouble && !$inBacktick && $ch === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inSingle = !$inSingle;
                $buf .= $ch;
                continue;
            }
            if (!$inSingle && !$inBacktick && $ch === '"' && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inDouble = !$inDouble;
                $buf .= $ch;
                continue;
            }
            if (!$inSingle && !$inDouble && $ch === '`') {
                $inBacktick = !$inBacktick;
                $buf .= $ch;
                continue;
            }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $statements[] = $buf;
                $buf = '';
                continue;
            }

            $buf .= $ch;
        }

        if (trim($buf) !== '') {
            $statements[] = $buf;
        }

        return $statements;
    }

    private static function isSkippable(string $sql): bool
    {
        $upper = strtoupper(ltrim($sql));
        return str_starts_with($upper, '--')
            || str_starts_with($upper, '/*')
            || $upper === 'BEGIN'
            || $upper === 'COMMIT'
            || $upper === 'START TRANSACTION';
    }
}
