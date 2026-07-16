<?php
declare(strict_types=1);

namespace Navicat\Mongo;

/**
 * Pure-PHP MongoDB / Amazon DocumentDB client.
 *
 * Speaks the wire protocol (OP_MSG, opcode 2013) over a TLS or plain TCP
 * socket using stream_socket_client. No ext-mongodb, no Composer.
 *
 * Connection options (constructor array):
 *   host, port, username, password, authDb (default 'admin'),
 *   tls (bool, default true for DocumentDB), caFile (path|null),
 *   tlsAllowInvalid (bool, default false), authMechanism (auto|SCRAM-SHA-1|SCRAM-SHA-256),
 *   appName (string), timeout (seconds).
 */
final class MongoClient
{
    /** @var resource|null */
    private $sock = null;
    private int $requestId = 0;
    /** @var array<string,mixed> */
    private array $opt;
    /** @var array<string,mixed> */
    private array $hello = [];

    /** @param array<string,mixed> $options */
    public function __construct(array $options)
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \RuntimeException('MongoClient requires a 64-bit PHP build (BSON int64).');
        }
        $this->opt = $options + [
            'port' => 27017,
            'authDb' => 'admin',
            'tls' => true,
            'caFile' => null,
            'tlsAllowInvalid' => false,
            'authMechanism' => 'auto',
            'appName' => 'navicat-php',
            'timeout' => 10,
        ];
    }

    public function connect(): void
    {
        if ($this->sock !== null) {
            return;
        }
        $host = (string)$this->opt['host'];
        $port = (int)$this->opt['port'];
        $timeout = (int)$this->opt['timeout'];

        if (!empty($this->opt['tls'])) {
            $ssl = [
                'verify_peer' => empty($this->opt['tlsAllowInvalid']),
                'verify_peer_name' => empty($this->opt['tlsAllowInvalid']),
                'SNI_enabled' => true,
                'peer_name' => $host,
            ];
            if (!empty($this->opt['caFile']) && is_file((string)$this->opt['caFile'])) {
                $ssl['cafile'] = (string)$this->opt['caFile'];
            }
            if (!empty($this->opt['tlsAllowInvalid'])) {
                $ssl['allow_self_signed'] = true;
            }
            $ctx = stream_context_create(['ssl' => $ssl]);
            $uri = "tls://{$host}:{$port}";
        } else {
            $ctx = stream_context_create();
            $uri = "tcp://{$host}:{$port}";
        }

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($uri, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new \RuntimeException("Mongo connect failed ({$host}:{$port}): [{$errno}] {$errstr}");
        }
        stream_set_timeout($sock, $timeout);
        $this->sock = $sock;

        $this->handshake();
        if (($this->opt['username'] ?? '') !== '') {
            $this->authenticate();
        }
    }

    private function handshake(): void
    {
        $this->hello = $this->command($this->opt['authDb'], [
            'hello' => 1,
            'client' => [
                'application' => ['name' => (string)$this->opt['appName']],
                'driver' => ['name' => 'navicat-php-pure', 'version' => '1.0'],
                'os' => ['type' => PHP_OS_FAMILY],
            ],
        ]);
    }

    private function authenticate(): void
    {
        $mech = (string)$this->opt['authMechanism'];
        if ($mech === 'auto') {
            $supported = $this->hello['saslSupportedMechs'] ?? [];
            $supported = is_array($supported) ? array_map('strval', $supported) : [];
            $mech = in_array('SCRAM-SHA-256', $supported, true) ? 'SCRAM-SHA-256' : 'SCRAM-SHA-1';
        }
        $authDb = (string)$this->opt['authDb'];
        Scram::authenticate(
            fn(array $cmd) => $this->command($authDb, $cmd),
            $mech,
            (string)$this->opt['username'],
            (string)$this->opt['password'],
            $authDb
        );
    }

    /**
     * Send one OP_MSG command against a database and return the decoded reply.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function command(string $db, array $command): array
    {
        if ($this->sock === null) {
            $this->connect();
        }
        // $db must be the last field of the command document per OP_MSG spec.
        $command['$db'] = $db;

        $doc = Bson::encode($command);
        $section = "\x00" . $doc;             // section kind 0 (body)
        $flagBits = pack('V', 0);
        $body = $flagBits . $section;
        $reqId = ++$this->requestId;
        $header = pack('V', 16 + strlen($body))
            . pack('V', $reqId)
            . pack('V', 0)
            . pack('V', 2013);                // OP_MSG

        $this->writeAll($header . $body);
        return $this->readReply();
    }

    /** @return array<string,mixed> */
    private function readReply(): array
    {
        $header = $this->readExact(16);
        $msgLen = unpack('V', substr($header, 0, 4))[1];
        $payload = $this->readExact($msgLen - 16);

        $offset = 4; // skip flagBits
        $kind = ord($payload[$offset]);
        $offset++;
        if ($kind !== 0) {
            throw new \RuntimeException("Unexpected OP_MSG section kind {$kind}");
        }
        return Bson::decode($payload, $offset);
    }

    /**
     * Run a command and assert ok:1, raising a useful error otherwise.
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function runCommand(string $db, array $command): array
    {
        $reply = $this->command($db, $command);
        if (($reply['ok'] ?? 0) != 1) {
            $msg = $reply['errmsg'] ?? ('command failed: ' . json_encode($command));
            $code = isset($reply['code']) ? (int)self::scalar($reply['code']) : 0;
            throw new \RuntimeException("Mongo error" . ($code ? " [{$code}]" : '') . ": {$msg}");
        }
        return $reply;
    }

    /**
     * Execute a cursor-returning command (find/aggregate/listCollections/...) and
     * drain all batches via getMore. Returns the full list of documents.
     *
     * @param array<string,mixed> $command
     * @return list<array<string,mixed>>
     */
    public function cursorAll(string $db, array $command, int $maxDocs = 100000): array
    {
        $reply = $this->runCommand($db, $command);
        $cursor = $reply['cursor'] ?? null;
        if (!is_array($cursor)) {
            return [];
        }
        $collFull = (string)($cursor['ns'] ?? '');
        $collName = $collFull !== '' ? substr($collFull, strpos($collFull, '.') + 1) : '';
        $docs = array_values($cursor['firstBatch'] ?? []);
        $cursorId = self::scalar($cursor['id'] ?? 0);

        while ($cursorId != 0 && count($docs) < $maxDocs) {
            $more = $this->runCommand($db, [
                'getMore' => new Int64((int)$cursorId),
                'collection' => $collName,
                'batchSize' => 1000,
            ]);
            $c = $more['cursor'] ?? [];
            $batch = array_values($c['nextBatch'] ?? []);
            $docs = array_merge($docs, $batch);
            $cursorId = self::scalar($c['id'] ?? 0);
            if ($batch === []) {
                break;
            }
        }

        return array_slice($docs, 0, $maxDocs);
    }

    /** @return array<string,mixed> */
    public function helloInfo(): array
    {
        return $this->hello;
    }

    public function close(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function writeAll(string $data): void
    {
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = fwrite($this->sock, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Mongo socket write failed');
            }
            $written += $n;
        }
    }

    private function readExact(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('Mongo socket read timed out');
                }
                throw new \RuntimeException('Mongo socket closed mid-read');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** Unwrap Int64/scalar for comparisons. */
    private static function scalar(mixed $v): int|float|string
    {
        if ($v instanceof Int64) {
            return $v->value;
        }
        if (is_int($v) || is_float($v) || is_string($v)) {
            return $v;
        }
        return 0;
    }
}
