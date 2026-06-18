<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\App;
use Navicat\Database;

final class UpdaterService
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function versionFile(): string
    {
        return self::root() . '/VERSION';
    }

    public static function currentVersion(): string
    {
        $f = self::versionFile();
        return is_file($f) ? trim((string)file_get_contents($f)) : '1.0.0';
    }

    private static function gitBin(): string
    {
        foreach (['/usr/local/bin/git', '/usr/bin/git', '/bin/git'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        return 'git';
    }

    private static function git(string $args): string
    {
        $root = self::root();
        $git = self::gitBin();
        $path = getenv('PATH') ?: '';
        if (!str_contains($path, '/usr/local/bin')) {
            putenv('PATH=/usr/local/bin:/usr/bin:/bin:' . $path);
        }
        return trim((string)shell_exec(
            escapeshellarg($git) . ' -C ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null'
        ));
    }

    private static function isGitRepo(): bool
    {
        return is_dir(self::root() . '/.git');
    }

    /** Prefer VERSION on a ref; fall back to nearest tag. */
    private static function versionAtRef(string $ref): string
    {
        $fromFile = self::git('show ' . $ref . ':VERSION');
        if ($fromFile !== '') {
            return $fromFile;
        }
        $tag = self::git('describe --tags --always ' . $ref);
        return $tag !== '' ? $tag : '';
    }

    public static function checkForUpdates(): array
    {
        $current = self::currentVersion();

        if (!self::isGitRepo()) {
            return [
                'current'      => $current,
                'latest'       => $current,
                'behindBy'     => 0,
                'upToDate'     => true,
                'commit'       => '(not a git clone — use deploy-ftp.sh)',
                'remoteCommit' => '',
                'gitAvailable' => false,
            ];
        }

        self::git('fetch origin');

        $behind = (int)self::git('rev-list HEAD..origin/main --count');
        $remoteVersion = self::versionAtRef('origin/main');
        $latest = $remoteVersion !== '' ? $remoteVersion : self::versionAtRef('HEAD');
        if ($latest === '') {
            $latest = $current;
        }

        $commit = self::git('log -1 --oneline');
        $remoteCommit = self::git('log -1 --oneline origin/main');

        $versionDiffers = $remoteVersion !== '' && $remoteVersion !== $current;
        $upToDate = $behind === 0 && !$versionDiffers;

        return [
            'current'      => $current,
            'latest'       => $latest,
            'behindBy'     => $behind,
            'upToDate'     => $upToDate,
            'commit'       => $commit !== '' ? $commit : '(unknown)',
            'remoteCommit' => $remoteCommit !== '' ? $remoteCommit : '(unknown)',
            'gitAvailable' => true,
        ];
    }

    public static function runUpdate(callable $emit): void
    {
        $root    = self::root();
        $current = self::currentVersion();

        $emit(['type' => 'log', 'message' => "Current version: {$current}"]);

        // 1. Fetch
        $emit(['type' => 'log', 'message' => 'Fetching remote…']);
        $fetch = shell_exec('git -C ' . escapeshellarg($root) . ' fetch origin 2>&1');
        $emit(['type' => 'log', 'message' => trim((string)$fetch) ?: 'Fetch complete.']);

        // 2. Check for local modifications
        $dirty = trim((string)shell_exec(
            'git -C ' . escapeshellarg($root) . ' status --porcelain 2>/dev/null'
        ));
        if ($dirty !== '') {
            $emit(['type' => 'error', 'message' => "Local modifications detected — cannot pull.\n{$dirty}"]);
            return;
        }

        // 3. Pull
        $emit(['type' => 'log', 'message' => 'Pulling latest code…']);
        $pull = shell_exec('git -C ' . escapeshellarg($root) . ' pull origin main 2>&1');
        $pullOut = trim((string)$pull);
        $emit(['type' => 'log', 'message' => $pullOut]);

        if (str_contains($pullOut, 'error:') || str_contains($pullOut, 'CONFLICT')) {
            $emit(['type' => 'error', 'message' => 'Git pull failed. See above for details.']);
            return;
        }

        // 4. Run pending migrations
        $emit(['type' => 'log', 'message' => 'Running database migrations…']);
        $migrationsDir = $root . '/migrations';
        $db = App::db();
        if (is_dir($migrationsDir)) {
            try {
                Database::migrateAll($db, $migrationsDir);
                $emit(['type' => 'log', 'message' => '  ✓ migrations applied']);
            } catch (\Throwable $e) {
                $emit(['type' => 'error', 'message' => '  ✗ migrations: ' . $e->getMessage()]);
            }
        }

        // 5. Sync VERSION from repo (file on main, then tag)
        $newVersion = self::versionAtRef('HEAD');
        if ($newVersion === '' && is_file(self::versionFile())) {
            $newVersion = trim((string)file_get_contents(self::versionFile()));
        }
        if ($newVersion !== '') {
            file_put_contents(self::versionFile(), $newVersion . "\n");
            $emit(['type' => 'log', 'message' => "Version updated to {$newVersion}"]);
        }

        // 6. Audit
        try {
            AuditService::log(null, 'system.update', $newVersion ?: 'unknown', [
                'from' => $current,
                'to'   => $newVersion,
            ]);
        } catch (\Throwable) {}

        $emit(['type' => 'done', 'message' => 'Update complete. Reload the page.']);
    }
}
