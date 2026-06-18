<?php
declare(strict_types=1);

namespace Navicat\Mongo;

/**
 * Minimal but complete BSON codec in pure PHP (no ext-mongodb).
 *
 * Supported types cover everything MongoDB / Amazon DocumentDB return for
 * commands, queries and CRUD: double, string, document, array, binary,
 * ObjectId, bool, datetime, null, regex, int32, timestamp, int64, decimal128
 * (decoded as string), minkey/maxkey.
 *
 * IMPORTANT byte-order note: on this PHP build pack('E'/'e') for doubles does
 * NOT round-trip correctly, while pack('d') (machine order, little-endian on
 * x86_64) does. We rely on the x86_64 little-endian assumption — guarded in
 * MongoClient. All integers use explicit little-endian codes ('V','v','P').
 */
final class Bson
{
    /** @param array<string,mixed> $doc */
    public static function encode(array $doc): string
    {
        $body = '';
        foreach ($doc as $key => $val) {
            $body .= self::encodeElement((string)$key, $val);
        }
        $body .= "\x00";
        return pack('V', strlen($body) + 4) . $body;
    }

    /** Encode a PHP list as a BSON array document (keys "0","1",...). */
    private static function encodeArray(array $list): string
    {
        $body = '';
        $i = 0;
        foreach ($list as $val) {
            $body .= self::encodeElement((string)$i, $val);
            $i++;
        }
        $body .= "\x00";
        return pack('V', strlen($body) + 4) . $body;
    }

    private static function encodeElement(string $key, mixed $val): string
    {
        $ckey = $key . "\x00";

        if ($val instanceof ObjectId) {
            return "\x07" . $ckey . $val->bytes();
        }
        if ($val instanceof UTCDateTime) {
            return "\x09" . $ckey . pack('P', $val->milliseconds);
        }
        if ($val instanceof Int64) {
            return "\x12" . $ckey . pack('P', $val->value);
        }
        if ($val instanceof Binary) {
            return "\x05" . $ckey . pack('V', strlen($val->data)) . chr($val->subtype) . $val->data;
        }
        if ($val instanceof Regex) {
            return "\x0B" . $ckey . $val->pattern . "\x00" . $val->flags . "\x00";
        }
        if (is_int($val)) {
            // Use int32 when it fits, otherwise int64.
            if ($val >= -2147483648 && $val <= 2147483647) {
                return "\x10" . $ckey . pack('l', $val);
            }
            return "\x12" . $ckey . pack('P', $val);
        }
        if (is_float($val)) {
            return "\x01" . $ckey . pack('d', $val);
        }
        if (is_bool($val)) {
            return "\x08" . $ckey . ($val ? "\x01" : "\x00");
        }
        if (is_null($val)) {
            return "\x0A" . $ckey;
        }
        if (is_string($val)) {
            $s = $val . "\x00";
            return "\x02" . $ckey . pack('V', strlen($s)) . $s;
        }
        if ($val instanceof \stdClass) {
            // Empty/explicit document marker (e.g. an empty {} filter).
            return "\x03" . $ckey . self::encode((array)$val);
        }
        if (is_array($val)) {
            return self::isList($val)
                ? "\x04" . $ckey . self::encodeArray($val)
                : "\x03" . $ckey . self::encode($val);
        }
        if ($val instanceof \JsonSerializable) {
            $j = $val->jsonSerialize();
            if (is_array($j)) {
                return self::encodeElement($key, $j);
            }
        }

        throw new \RuntimeException("Cannot BSON-encode value for key '{$key}' of type " . gettype($val));
    }

    /** @return array<string,mixed> */
    public static function decode(string $data, int &$offset = 0): array
    {
        $len = unpack('V', substr($data, $offset, 4))[1];
        $end = $offset + $len;
        $offset += 4;
        $out = [];
        while ($offset < $end - 1) {
            $type = ord($data[$offset]);
            $offset++;
            $nul = strpos($data, "\x00", $offset);
            $key = substr($data, $offset, $nul - $offset);
            $offset = $nul + 1;
            $out[$key] = self::decodeValue($data, $type, $offset);
        }
        $offset = $end;
        return $out;
    }

    private static function decodeValue(string $data, int $type, int &$offset): mixed
    {
        switch ($type) {
            case 0x01: // double (machine order = LE on x86_64)
                $v = unpack('d', substr($data, $offset, 8))[1];
                $offset += 8;
                return $v;
            case 0x02: // UTF-8 string
                $sl = unpack('V', substr($data, $offset, 4))[1];
                $offset += 4;
                $s = substr($data, $offset, $sl - 1);
                $offset += $sl;
                return $s;
            case 0x03: // embedded document
                return self::decode($data, $offset);
            case 0x04: // array
                $doc = self::decode($data, $offset);
                return array_values($doc);
            case 0x05: // binary
                $bl = unpack('V', substr($data, $offset, 4))[1];
                $offset += 4;
                $sub = ord($data[$offset]);
                $offset++;
                $bytes = substr($data, $offset, $bl);
                $offset += $bl;
                return new Binary($bytes, $sub);
            case 0x06: // undefined (deprecated)
                return null;
            case 0x07: // ObjectId
                $oid = new ObjectId(bin2hex(substr($data, $offset, 12)));
                $offset += 12;
                return $oid;
            case 0x08: // bool
                $v = ord($data[$offset]) === 1;
                $offset++;
                return $v;
            case 0x09: // UTC datetime
                $ms = unpack('P', substr($data, $offset, 8))[1];
                $offset += 8;
                return new UTCDateTime($ms);
            case 0x0A: // null
                return null;
            case 0x0B: // regex
                $p = strpos($data, "\x00", $offset);
                $pattern = substr($data, $offset, $p - $offset);
                $offset = $p + 1;
                $f = strpos($data, "\x00", $offset);
                $flags = substr($data, $offset, $f - $offset);
                $offset = $f + 1;
                return new Regex($pattern, $flags);
            case 0x0D: // javascript code
            case 0x0E: // symbol (deprecated)
                $sl = unpack('V', substr($data, $offset, 4))[1];
                $offset += 4;
                $s = substr($data, $offset, $sl - 1);
                $offset += $sl;
                return $s;
            case 0x10: // int32
                $v = unpack('l', substr($data, $offset, 4))[1];
                $offset += 4;
                return $v;
            case 0x11: // timestamp (uint64) -> keep as Int64
                $v = unpack('P', substr($data, $offset, 8))[1];
                $offset += 8;
                return new Int64($v);
            case 0x12: // int64
                $v = unpack('P', substr($data, $offset, 8))[1];
                $offset += 8;
                return new Int64($v);
            case 0x13: // decimal128 -> hex string (no native PHP type)
                $raw = substr($data, $offset, 16);
                $offset += 16;
                return ['$numberDecimal' => '0x' . strtoupper(bin2hex(strrev($raw)))];
            case 0xFF: // min key
                return ['$minKey' => 1];
            case 0x7F: // max key
                return ['$maxKey' => 1];
            default:
                throw new \RuntimeException(sprintf('Unsupported BSON type 0x%02X at offset %d', $type, $offset));
        }
    }

    /** True when an array is a sequential 0..n list (BSON array vs document). */
    private static function isList(array $a): bool
    {
        if ($a === []) {
            return true;
        }
        return array_keys($a) === range(0, count($a) - 1);
    }
}
