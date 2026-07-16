<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\Drivers\DriverFactory;

final class DiffService
{
    /**
     * @param list<string>|null $tables If provided, only diff these tables.
     * @return array<string,mixed>
     */
    public static function schemaDiff(
        array $sourceConn,
        string $sourceDb,
        array $targetConn,
        string $targetDb,
        ?array $tables = null,
    ): array {
        $a = DriverFactory::getDriver($sourceConn);
        $b = DriverFactory::getDriver($targetConn);

        $ta = self::normalizeNames(array_map(static fn(array $x): string => (string)$x['name'], $a->listTablesLight($sourceDb)));
        $tb = self::normalizeNames(array_map(static fn(array $x): string => (string)$x['name'], $b->listTablesLight($targetDb)));

        $va = self::normalizeNames(array_map(static fn(array $x): string => (string)$x['name'], $a->listViews($sourceDb)));
        $vb = self::normalizeNames(array_map(static fn(array $x): string => (string)$x['name'], $b->listViews($targetDb)));

        $ra = self::normalizeRoutineNames($a->listRoutines($sourceDb));
        $rb = self::normalizeRoutineNames($b->listRoutines($targetDb));

        $tga = self::normalizeNames(array_map(static fn(array $x): string => (string)$x['name'], $a->listTriggers($sourceDb)));
        $tgb = self::normalizeNames(array_map(static fn(array $x): string => (string)$x['name'], $b->listTriggers($targetDb)));

        if ($tables !== null) {
            $want = array_flip(self::normalizeNames($tables));
            $filter = static fn(array $xs): array => array_values(array_filter($xs, static fn(string $n): bool => isset($want[$n])));
            $ta = $filter($ta);
            $tb = $filter($tb);
            $va = $filter($va);
            $vb = $filter($vb);
        }

        $tableDiff = self::namedSetDiff($ta, $tb);
        $viewDiff = self::namedSetDiff($va, $vb);
        $routineDiff = self::namedSetDiff($ra, $rb);
        $triggerDiff = self::namedSetDiff($tga, $tgb);

        $commonTables = $tableDiff['matched'];
        $tableColumnDiffs = [];
        foreach ($commonTables as $t) {
            try {
                $ia = $a->getTableInfo($sourceDb, $t);
                $ib = $b->getTableInfo($targetDb, $t);
                $colsA = self::summarizeColumns($ia['columns'] ?? []);
                $colsB = self::summarizeColumns($ib['columns'] ?? []);
                $cd = self::namedSetDiff(array_keys($colsA), array_keys($colsB));
                if ($cd['onlyInLeft'] !== [] || $cd['onlyInRight'] !== [] || self::columnsDifferForCommon($colsA, $colsB, $cd['matched'])) {
                    $tableColumnDiffs[] = [
                        'table' => $t,
                        'columnsMissingInRight' => $cd['onlyInLeft'],
                        'columnsMissingInLeft' => $cd['onlyInRight'],
                        'typeMismatches' => self::findTypeMismatches($colsA, $colsB, $cd['matched']),
                    ];
                }
            } catch (\Throwable $e) {
                $tableColumnDiffs[] = [
                    'table' => $t,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'tables' => [
                'onlyInLeft' => $tableDiff['onlyInLeft'],
                'onlyInRight' => $tableDiff['onlyInRight'],
                'matched' => $tableDiff['matched'],
                'counts' => $tableDiff['counts'],
                'columnDiffs' => $tableColumnDiffs,
            ],
            'views' => $viewDiff,
            'routines' => $routineDiff,
            'triggers' => $triggerDiff,
        ];
    }

    public static function dataDiff(
        array $sourceConn,
        string $sourceDb,
        string $sourceTable,
        array $targetConn,
        string $targetDb,
        string $targetTable,
        int $pageSize = 500,
        int $maxSampleDiffRows = 200,
    ): array {
        $a = DriverFactory::getDriver($sourceConn);
        $b = DriverFactory::getDriver($targetConn);

        $t1 = (int)($a->queryPaginated($sourceDb, $sourceTable, ['offset' => 0, 'limit' => 1])['total'] ?? 0);
        $t2 = (int)($b->queryPaginated($targetDb, $targetTable, ['offset' => 0, 'limit' => 1])['total'] ?? 0);

        $colsA = $a->queryPaginated($sourceDb, $sourceTable, ['offset' => 0, 'limit' => 1])['columns'];
        $colsB = $b->queryPaginated($targetDb, $targetTable, ['offset' => 0, 'limit' => 1])['columns'];
        /** @var list<string> $colsA */
        /** @var list<string> $colsB */
        $commonCols = array_values(array_intersect(self::normalizeNameListIdentical($colsA), self::normalizeNameListIdentical($colsB)));
        sort($commonCols);

        $pka = $a->getPrimaryKeys($sourceDb, $sourceTable);
        $orderBy = $pka[0] ?? ($commonCols[0] ?? null);

        /** @var list<array<string,mixed>> $sampleDiffs */
        $sampleDiffs = [];
        if ($commonCols !== [] && $t1 <= 100_000 && $t2 <= 100_000) {
            $offset = 0;
            while ($offset < $t1 || $offset < $t2) {
                $opts = [
                    'offset' => $offset,
                    'limit' => $pageSize,
                    'structuredFilters' => [],
                ];
                if ($orderBy !== null) {
                    $opts['orderBy'] = $orderBy;
                    $opts['orderDir'] = 'ASC';
                }

                $qa = $a->queryPaginated($sourceDb, $sourceTable, $opts);
                $qb = $b->queryPaginated($targetDb, $targetTable, $opts);
                /** @var list<array<string,mixed>> $ra */
                $ra = $qa['rows'] ?? [];
                /** @var list<array<string,mixed>> $rb */
                $rb = $qb['rows'] ?? [];

                $len = max(count($ra), count($rb));
                for ($i = 0; $i < $len; $i++) {
                    $xa = $ra[$i] ?? null;
                    $xb = $rb[$i] ?? null;
                    if ($xa === null || $xb === null || self::projectRowDiffers($xa, $xb, $commonCols)) {
                        $sampleDiffs[] = [
                            'pageOffset' => $offset,
                            'indexInPage' => $i,
                            'left' => $xa === null ? null : self::projectRow($xa, $commonCols),
                            'right' => $xb === null ? null : self::projectRow($xb, $commonCols),
                        ];
                        if (count($sampleDiffs) >= $maxSampleDiffRows) {
                            break 2;
                        }
                    }
                }

                $offset += $pageSize;
                if ($qa['rows'] === [] && $qb['rows'] === []) {
                    break;
                }
            }
        }

        return [
            'leftTotal' => $t1,
            'rightTotal' => $t2,
            'countsMatch' => $t1 === $t2,
            'columnsLeft' => $colsA ?? [],
            'columnsRight' => $colsB ?? [],
            'commonColumnsCompared' => $commonCols,
            'orderedBy' => $orderBy,
            'sampleDiffs' => $sampleDiffs,
            'note' => $commonCols === []
                ? 'No overlapping column names to compare.'
                : 'Sample comparison walks pages in ascending order — use primary key ordering when possible.',
        ];
    }

    /** @return list<string> */
    private static function normalizeNames(array $names): array
    {
        $names = array_map(static fn(string $s): string => trim($s), $names);
        sort($names);
        return array_values(array_unique($names));
    }

    /** @param list<mixed> $cols */
    private static function normalizeNameListIdentical(array $cols): array
    {
        $out = [];
        foreach ($cols as $c) {
            if (is_string($c)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** @param list<string> $a @param list<string> $b */
    private static function namedSetDiff(array $a, array $b): array
    {
        $onlyLeft = array_values(array_diff($a, $b));
        $onlyRight = array_values(array_diff($b, $a));
        $matched = array_values(array_intersect($a, $b));
        return [
            'onlyInLeft' => $onlyLeft,
            'onlyInRight' => $onlyRight,
            'matched' => $matched,
            'counts' => ['left' => count($a), 'right' => count($b)],
        ];
    }

    /** @param list<array<string,mixed>> $routines */
    private static function normalizeRoutineNames(array $routines): array
    {
        $names = [];
        foreach ($routines as $r) {
            $names[] = (string)($r['name'] ?? '');
        }
        return self::normalizeNames($names);
    }

    private static function summarizeColumns(array $cols): array
    {
        $out = [];
        foreach ($cols as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = trim((string)($c['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $nullable = isset($c['nullable']) ? (bool)$c['nullable'] : null;
            $out[$name] = ['type' => isset($c['type']) ? (string)$c['type'] : null, 'nullable' => $nullable];
        }
        ksort($out);
        return $out;
    }

    private static function columnsDifferForCommon(array $colsA, array $colsB, array $names): bool
    {
        foreach ($names as $n) {
            $ta = $colsA[$n]['type'] ?? null;
            $tb = $colsB[$n]['type'] ?? null;
            if ($ta !== $tb) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,array{type:string|null,nullable?:bool|string|null}> $colsA */
    /** @param list<string> $matched */
    private static function findTypeMismatches(array $colsA, array $colsB, array $matched): array
    {
        $out = [];
        foreach ($matched as $n) {
            $ta = $colsA[$n]['type'] ?? null;
            $tb = $colsB[$n]['type'] ?? null;
            if ($ta !== $tb) {
                $out[] = ['column' => $n, 'leftType' => $ta, 'rightType' => $tb];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function projectRow(array $row, array $cols): array
    {
        $out = [];
        foreach ($cols as $c) {
            $out[$c] = $row[$c] ?? null;
        }
        return $out;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private static function projectRowDiffers(array $a, array $b, array $cols): bool
    {
        foreach ($cols as $c) {
            $va = $a[$c] ?? null;
            $vb = $b[$c] ?? null;
            if ($va != $vb) {
                return true;
            }
        }
        return false;
    }
}
