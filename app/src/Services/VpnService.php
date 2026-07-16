<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\App;

/** OpenVPN on-demand for connections flagged use_vpn (same model as db-scripts.sh). */
final class VpnService
{
    private static bool $weStarted = false;
    private static bool $shutdownRegistered = false;

    /** @param array<string,mixed> $conn */
    public static function connectionRequiresVpn(array $conn): bool
    {
        if (!empty($conn['use_vpn'])) {
            return true;
        }
        $group = (string)($conn['config_group'] ?? '');
        return $group !== '' && str_ends_with($group, '-vpn');
    }

    /** @param array<string,mixed> $conn */
    public static function ensureForConnection(array $conn): void
    {
        if (!self::connectionRequiresVpn($conn)) {
            return;
        }
        if (!(bool)(App::config()['vpn_enabled'] ?? false)) {
            throw new \RuntimeException(
                'Esta conexión requiere VPN PCI pero vpn_enabled=false en config.php'
            );
        }
        if (self::tunnelActive()) {
            return;
        }
        self::start();
        if (self::$weStarted && !self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'shutdownCleanup']);
            self::$shutdownRegistered = true;
        }
    }

    /** @return array{active:bool,weStarted:bool,tunnel:bool,enabled:bool} */
    public static function status(): array
    {
        $enabled = (bool)(App::config()['vpn_enabled'] ?? false);
        $active = self::tunnelActive();
        return [
            'enabled' => $enabled,
            'active' => $active,
            'tunnel' => $active,
            'weStarted' => self::$weStarted,
        ];
    }

    /** @return array{active:bool,weStarted:bool,tunnel:bool,enabled:bool} */
    public static function up(): array
    {
        if (!(bool)(App::config()['vpn_enabled'] ?? false)) {
            throw new \RuntimeException('VPN deshabilitada en config.php');
        }
        if (!self::tunnelActive()) {
            self::start();
        }
        return self::status();
    }

    /** @return array{active:bool,weStarted:bool,tunnel:bool,enabled:bool} */
    public static function down(): array
    {
        self::stop();
        return self::status();
    }

    public static function shutdownCleanup(): void
    {
        if (self::$weStarted) {
            self::stop();
        }
    }

    public static function tunnelActive(): bool
    {
        $out = [];
        $code = 0;
        @exec('ip -4 -o addr show 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return false;
        }
        foreach ($out as $line) {
            if (preg_match('/^\d+:\s+tun\d+\s+inet\s/', $line)) {
                return true;
            }
        }
        return false;
    }

    private static function start(): void
    {
        $cfg = self::cfg('vpn_config', '/etc/openvpn/client/pci.ovpn');
        $auth = self::cfg('vpn_auth', '/etc/openvpn/client/pci.auth');
        $pidf = self::cfg('vpn_pidfile', '/var/run/openvpn-navicat.pid');
        $logf = self::cfg('vpn_log', '/var/log/openvpn-navicat.log');
        $bin = self::openvpnBin();

        if (!self::sudoReadable($cfg)) {
            throw new \RuntimeException("Perfil OpenVPN no legible: {$cfg}");
        }
        if (!self::sudoReadable($auth)) {
            throw new \RuntimeException("Auth OpenVPN no legible: {$auth}");
        }

        if (self::sudoFileExists($pidf)) {
            $oldPid = trim((string)self::sudoCat($pidf));
            if ($oldPid !== '' && self::pidAlive($oldPid)) {
                if (self::tunnelActive()) {
                    self::$weStarted = false;
                    return;
                }
                self::sudoKill($oldPid);
            }
            self::sudoRm($pidf);
        }

        $cmd = sprintf(
            'sudo %s --config %s --auth-user-pass %s --daemon openvpn-navicat --writepid %s --log-append %s',
            escapeshellarg($bin),
            escapeshellarg($cfg),
            escapeshellarg($auth),
            escapeshellarg($pidf),
            escapeshellarg($logf),
        );
        self::runChecked($cmd, 'No se pudo iniciar OpenVPN');

        for ($i = 0; $i < 60; $i++) {
            if (self::tunnelActive()) {
                self::$weStarted = true;
                return;
            }
            usleep(500_000);
        }

        self::stop();
        throw new \RuntimeException('Timeout esperando túnel VPN (60s). Ver ' . $logf);
    }

    private static function stop(): void
    {
        if (!self::$weStarted) {
            return;
        }
        $pidf = self::cfg('vpn_pidfile', '/var/run/openvpn-navicat.pid');
        if (self::sudoFileExists($pidf)) {
            $pid = trim((string)self::sudoCat($pidf));
            if ($pid !== '') {
                self::sudoKill($pid);
            }
            self::sudoRm($pidf);
        }
        self::$weStarted = false;
    }

    private static function cfg(string $key, string $default): string
    {
        $val = App::config()[$key] ?? $default;
        return (string)$val;
    }

    private static function openvpnBin(): string
    {
        foreach (['/usr/local/sbin/openvpn', '/usr/sbin/openvpn', '/sbin/openvpn'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        throw new \RuntimeException('Ejecutable openvpn no encontrado');
    }

    private static function runChecked(string $cmd, string $failMsg): void
    {
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $detail = trim(implode("\n", $out));
            throw new \RuntimeException($failMsg . ($detail !== '' ? ': ' . $detail : ''));
        }
    }

    private static function sudoReadable(string $path): bool
    {
        $out = [];
        $code = 0;
        exec('sudo test -r ' . escapeshellarg($path) . ' 2>/dev/null', $out, $code);
        return $code === 0;
    }

    private static function sudoFileExists(string $path): bool
    {
        $code = 0;
        exec('sudo test -f ' . escapeshellarg($path) . ' 2>/dev/null', $out, $code);
        return $code === 0;
    }

    private static function sudoCat(string $path): string
    {
        $out = [];
        exec('sudo cat ' . escapeshellarg($path) . ' 2>/dev/null', $out, $code);
        return $code === 0 ? implode("\n", $out) : '';
    }

    private static function sudoRm(string $path): void
    {
        exec('sudo rm -f ' . escapeshellarg($path) . ' 2>/dev/null');
    }

    private static function sudoKill(string $pid): void
    {
        exec('sudo kill ' . escapeshellarg($pid) . ' 2>/dev/null');
    }

    private static function pidAlive(string $pid): bool
    {
        $code = 0;
        exec('sudo kill -0 ' . escapeshellarg($pid) . ' 2>/dev/null', $out, $code);
        return $code === 0;
    }
}
