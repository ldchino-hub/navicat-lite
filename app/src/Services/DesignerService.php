<?php
declare(strict_types=1);

namespace Navicat\Services;

final class DesignerService
{
    /** @param array<string,mixed> $design @return string */
    public static function generateMySqlDDL(array $design, ?string $existingName = null): string
    {
        $tableName = $existingName ?? (string)$design['name'];
        $lines = [];

        foreach ($design['columns'] ?? [] as $col) {
            $def = '  ' . self::quoteMySql((string)$col['name']) . ' ' . (string)$col['type'];
            if (!empty($col['length']) && self::shouldUseLength((string)$col['type'])) {
                $def .= '(' . (int)$col['length'] . ')';
            }
            if (empty($col['nullable'])) {
                $def .= ' NOT NULL';
            }
            if (!empty($col['autoIncrement'])) {
                $def .= ' AUTO_INCREMENT';
            }
            $defaultValue = self::normalizeDefault($col['defaultValue'] ?? null);
            if ($defaultValue !== null) {
                $def .= ' DEFAULT ' . $defaultValue;
            }
            if (!empty($col['comment'])) {
                $def .= " COMMENT '" . str_replace("'", "''", (string)$col['comment']) . "'";
            }
            $lines[] = $def;
        }

        $pkCols = [];
        foreach ($design['columns'] ?? [] as $col) {
            if (!empty($col['primaryKey'])) {
                $pkCols[] = self::quoteMySql((string)$col['name']);
            }
        }
        if ($pkCols) {
            $lines[] = '  PRIMARY KEY (' . implode(', ', $pkCols) . ')';
        }

        foreach ($design['indexes'] ?? [] as $idx) {
            if (empty($idx['columns'])) {
                continue;
            }
            $type = !empty($idx['unique']) ? 'UNIQUE KEY' : 'KEY';
            $cols = array_map(static fn(string $c): string => self::quoteMySql($c), $idx['columns']);
            $lines[] = '  ' . $type . ' ' . self::quoteMySql((string)$idx['name']) . ' (' . implode(', ', $cols) . ')';
        }

        foreach ($design['foreignKeys'] ?? [] as $fk) {
            if (empty($fk['columns']) || empty($fk['referencedTable']) || empty($fk['referencedColumns'])) {
                continue;
            }
            $def = '  CONSTRAINT ' . self::quoteMySql((string)$fk['name']) . ' FOREIGN KEY ('
                . implode(', ', array_map(static fn(string $c): string => self::quoteMySql($c), $fk['columns']))
                . ') REFERENCES ' . self::quoteMySql((string)$fk['referencedTable']) . ' ('
                . implode(', ', array_map(static fn(string $c): string => self::quoteMySql($c), $fk['referencedColumns']))
                . ')';
            if (!empty($fk['onDelete'])) {
                $def .= ' ON DELETE ' . $fk['onDelete'];
            }
            if (!empty($fk['onUpdate'])) {
                $def .= ' ON UPDATE ' . $fk['onUpdate'];
            }
            $lines[] = $def;
        }

        $opts = [];
        if (!empty($design['engine'])) {
            $opts[] = 'ENGINE=' . $design['engine'];
        }
        if (!empty($design['charset'])) {
            $opts[] = 'DEFAULT CHARSET=' . $design['charset'];
        }
        if (!empty($design['comment'])) {
            $opts[] = "COMMENT='" . str_replace("'", "''", (string)$design['comment']) . "'";
        }

        $triggerDdl = [];
        foreach ($design['triggers'] ?? [] as $t) {
            if (empty($t['name']) || empty($t['statement'])) {
                continue;
            }
            $stmt = rtrim((string)$t['statement'], ";\n\r\t ");
            $triggerDdl[] = 'DROP TRIGGER IF EXISTS ' . self::quoteMySql((string)$t['name']) . ";\n"
                . 'CREATE TRIGGER ' . self::quoteMySql((string)$t['name']) . ' '
                . $t['timing'] . ' ' . $t['event'] . ' ON ' . self::quoteMySql((string)$design['name'])
                . "\nFOR EACH ROW\n{$stmt};";
        }

        if ($existingName && $existingName !== $design['name']) {
            return implode("\n", [
                'DROP TABLE IF EXISTS ' . self::quoteMySql((string)$design['name']) . ';',
                'CREATE TABLE ' . self::quoteMySql((string)$design['name']) . " (\n" . implode(",\n", $lines) . "\n) " . implode(' ', $opts) . ';',
                ...$triggerDdl,
            ]);
        }

        $tableDdl = $existingName
            ? 'ALTER TABLE ' . self::quoteMySql($tableName) . "\n"
                . implode(",\n", array_map(static fn(string $l): string => '  ADD ' . ltrim($l), $lines)) . ';'
            : 'CREATE TABLE ' . self::quoteMySql($tableName) . " (\n" . implode(",\n", $lines) . "\n) " . implode(' ', $opts) . ';';

        return implode("\n", [$tableDdl, ...$triggerDdl]);
    }

    /** @param array<string,mixed> $design @param array<string,mixed> $current */
    public static function generateMySqlAlterDDL(array $design, array $current): string
    {
        $table = self::quoteMySql((string)$current['name']);
        $statements = [];
        $currentCols = [];
        foreach ($current['columns'] ?? [] as $c) {
            $currentCols[(string)$c['name']] = $c;
        }
        $currentIdx = [];
        foreach ($current['indexes'] ?? [] as $i) {
            $currentIdx[(string)$i['name']] = true;
        }
        $currentFk = [];
        foreach ($current['foreignKeys'] ?? [] as $f) {
            $currentFk[(string)$f['name']] = true;
        }

        if (($design['name'] ?? '') !== ($current['name'] ?? '')) {
            $statements[] = 'RENAME TABLE ' . self::quoteMySql((string)$current['name'])
                . ' TO ' . self::quoteMySql((string)$design['name']) . ';';
        }
        $target = self::quoteMySql((string)$design['name']);

        foreach ($design['columns'] ?? [] as $col) {
            $existing = $currentCols[(string)$col['name']] ?? null;
            $def = self::quoteMySql((string)$col['name']) . ' ' . (string)$col['type'];
            if (!empty($col['length']) && self::shouldUseLength((string)$col['type'])) {
                $def .= '(' . (int)$col['length'] . ')';
            }
            if (empty($col['nullable'])) {
                $def .= ' NOT NULL';
            }
            if (!empty($col['autoIncrement'])) {
                $def .= ' AUTO_INCREMENT';
            }
            $defaultValue = self::normalizeDefault($col['defaultValue'] ?? null);
            if ($defaultValue !== null) {
                $def .= ' DEFAULT ' . $defaultValue;
            }
            if (!empty($col['comment'])) {
                $def .= " COMMENT '" . str_replace("'", "''", (string)$col['comment']) . "'";
            }

            if (!$existing) {
                $statements[] = "ALTER TABLE {$target} ADD COLUMN {$def};";
            } else {
                $expectedType = !empty($col['length']) && self::shouldUseLength((string)$col['type'])
                    ? (string)$col['type'] . '(' . (int)$col['length'] . ')'
                    : (string)$col['type'];
                $changed = ($existing['type'] ?? '') !== $expectedType
                    || !empty($existing['nullable']) !== !empty($col['nullable'])
                    || ($existing['defaultValue'] ?? null) !== ($col['defaultValue'] ?? null)
                    || !empty($existing['autoIncrement']) !== !empty($col['autoIncrement']);
                if ($changed) {
                    $statements[] = "ALTER TABLE {$target} MODIFY COLUMN {$def};";
                }
            }
        }

        $currentPk = implode(',', array_map(
            static fn(array $c): string => (string)$c['name'],
            array_filter($current['columns'] ?? [], static fn(array $c): bool => !empty($c['primaryKey']))
        ));
        $newPk = implode(',', array_map(
            static fn(array $c): string => (string)$c['name'],
            array_filter($design['columns'] ?? [], static fn(array $c): bool => !empty($c['primaryKey']))
        ));
        if ($newPk && $newPk !== $currentPk) {
            if ($currentPk) {
                $statements[] = "ALTER TABLE {$target} DROP PRIMARY KEY;";
            }
            $pkQuoted = implode(', ', array_map(static fn(string $c): string => self::quoteMySql($c), explode(',', $newPk)));
            $statements[] = "ALTER TABLE {$target} ADD PRIMARY KEY ({$pkQuoted});";
        }

        foreach ($design['indexes'] ?? [] as $idx) {
            if (empty($idx['columns']) || isset($currentIdx[(string)$idx['name']])) {
                continue;
            }
            $unique = !empty($idx['unique']) ? 'UNIQUE ' : '';
            $cols = implode(', ', array_map(static fn(string $c): string => self::quoteMySql($c), $idx['columns']));
            $statements[] = "ALTER TABLE {$target} ADD {$unique}INDEX "
                . self::quoteMySql((string)$idx['name']) . " ({$cols});";
        }

        foreach ($design['foreignKeys'] ?? [] as $fk) {
            if (isset($currentFk[(string)$fk['name']]) || empty($fk['columns']) || empty($fk['referencedTable']) || empty($fk['referencedColumns'])) {
                continue;
            }
            $sql = "ALTER TABLE {$target} ADD CONSTRAINT " . self::quoteMySql((string)$fk['name']) . ' FOREIGN KEY ('
                . implode(', ', array_map(static fn(string $c): string => self::quoteMySql($c), $fk['columns']))
                . ') REFERENCES ' . self::quoteMySql((string)$fk['referencedTable']) . ' ('
                . implode(', ', array_map(static fn(string $c): string => self::quoteMySql($c), $fk['referencedColumns']))
                . ')';
            if (!empty($fk['onDelete'])) {
                $sql .= ' ON DELETE ' . $fk['onDelete'];
            }
            if (!empty($fk['onUpdate'])) {
                $sql .= ' ON UPDATE ' . $fk['onUpdate'];
            }
            $statements[] = $sql . ';';
        }

        foreach ($design['triggers'] ?? [] as $t) {
            if (empty($t['name']) || empty($t['statement'])) {
                continue;
            }
            $stmt = rtrim((string)$t['statement'], ";\n\r\t ");
            $statements[] = 'DROP TRIGGER IF EXISTS ' . self::quoteMySql((string)$t['name']) . ';';
            $statements[] = 'CREATE TRIGGER ' . self::quoteMySql((string)$t['name']) . ' '
                . $t['timing'] . ' ' . $t['event'] . " ON {$target}\nFOR EACH ROW\n{$stmt};";
        }

        return $statements ? implode("\n", $statements) : '-- No structural changes detected.';
    }

    /** @param array<string,mixed> $design */
    public static function generatePostgresDDL(array $design, ?string $existingName = null): string
    {
        $tableName = $existingName ?? (string)$design['name'];
        $lines = [];

        foreach ($design['columns'] ?? [] as $col) {
            $def = '  ' . self::quotePg((string)$col['name']) . ' ' . (string)$col['type'];
            if (empty($col['nullable'])) {
                $def .= ' NOT NULL';
            }
            $defaultValue = self::normalizeDefault($col['defaultValue'] ?? null);
            if ($defaultValue !== null) {
                $def .= ' DEFAULT ' . $defaultValue;
            }
            $lines[] = $def;
        }

        $pkCols = [];
        foreach ($design['columns'] ?? [] as $col) {
            if (!empty($col['primaryKey'])) {
                $pkCols[] = self::quotePg((string)$col['name']);
            }
        }
        if ($pkCols) {
            $lines[] = '  PRIMARY KEY (' . implode(', ', $pkCols) . ')';
        }

        foreach ($design['foreignKeys'] ?? [] as $fk) {
            if (empty($fk['columns']) || empty($fk['referencedTable']) || empty($fk['referencedColumns'])) {
                continue;
            }
            $def = '  CONSTRAINT ' . self::quotePg((string)$fk['name']) . ' FOREIGN KEY ('
                . implode(', ', array_map(static fn(string $c): string => self::quotePg($c), $fk['columns']))
                . ') REFERENCES ' . self::quotePg((string)$fk['referencedTable']) . ' ('
                . implode(', ', array_map(static fn(string $c): string => self::quotePg($c), $fk['referencedColumns']))
                . ')';
            if (!empty($fk['onDelete'])) {
                $def .= ' ON DELETE ' . $fk['onDelete'];
            }
            if (!empty($fk['onUpdate'])) {
                $def .= ' ON UPDATE ' . $fk['onUpdate'];
            }
            $lines[] = $def;
        }

        $tableDdl = $existingName
            ? 'DROP TABLE IF EXISTS ' . self::quotePg($tableName) . " CASCADE;\n"
                . 'CREATE TABLE ' . self::quotePg((string)$design['name']) . " (\n" . implode(",\n", $lines) . "\n);"
            : 'CREATE TABLE ' . self::quotePg($tableName) . " (\n" . implode(",\n", $lines) . "\n);";

        $indexDdl = [];
        foreach ($design['indexes'] ?? [] as $idx) {
            if (empty($idx['columns'])) {
                continue;
            }
            $unique = !empty($idx['unique']) ? 'UNIQUE ' : '';
            $cols = implode(', ', array_map(static fn(string $c): string => self::quotePg($c), $idx['columns']));
            $indexDdl[] = "CREATE {$unique}INDEX " . self::quotePg((string)$idx['name'])
                . ' ON ' . self::quotePg((string)$design['name']) . " ({$cols});";
        }

        return implode("\n", [$tableDdl, ...$indexDdl]);
    }

    /** @param list<array<string,mixed>> $columns @param list<array<string,mixed>> $indexes @return array<string,mixed> */
    public static function tableInfoToDesign(string $tableName, array $columns, array $indexes): array
    {
        $foreignKeys = [];
        foreach ($columns as $c) {
            if (!empty($c['isForeignKey']) && !empty($c['referencedTable'])) {
                $foreignKeys[] = [
                    'name' => 'fk_' . $tableName . '_' . $c['name'],
                    'columns' => [(string)$c['name']],
                    'referencedTable' => (string)$c['referencedTable'],
                    'referencedColumns' => [(string)($c['referencedColumn'] ?? 'id')],
                ];
            }
        }

        $idxOut = [];
        foreach ($indexes as $i) {
            if (!empty($i['primary'])) {
                continue;
            }
            $idxOut[] = [
                'name' => (string)$i['name'],
                'columns' => $i['columns'] ?? [],
                'unique' => !empty($i['unique']),
            ];
        }

        $colOut = [];
        foreach ($columns as $c) {
            $colOut[] = [
                'name' => (string)$c['name'],
                'type' => (string)$c['type'],
                'nullable' => !empty($c['nullable']),
                'defaultValue' => $c['defaultValue'] ?? null,
                'primaryKey' => !empty($c['isPrimaryKey']),
                'comment' => $c['comment'] ?? null,
            ];
        }

        return [
            'name' => $tableName,
            'columns' => $colOut,
            'indexes' => $idxOut,
            'foreignKeys' => $foreignKeys,
            'triggers' => [],
        ];
    }

    private static function quoteMySql(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    private static function quotePg(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private static function shouldUseLength(string $type): bool
    {
        $t = strtoupper(trim($type));
        if (str_contains($t, '(')) {
            return false;
        }
        return (bool)preg_match('/^(VARCHAR|CHAR|VARBINARY|BINARY)$/', $t);
    }

    private static function normalizeDefault(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = trim((string)$value);
        if (preg_match('/^(NULL|CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|TRUE|FALSE)$/i', $v)) {
            return $v;
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return $v;
        }
        if ((str_starts_with($v, "'") && str_ends_with($v, "'"))
            || (str_starts_with($v, '"') && str_ends_with($v, '"'))) {
            return $v;
        }
        return "'" . str_replace("'", "''", $v) . "'";
    }
}
