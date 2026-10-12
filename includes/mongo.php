<?php
/**
 * Lightweight MongoDB client (OP_MSG wire protocol + BSON), no PHP extension needed.
 * MongoDB is used as the app's event / audit store:  database carbon_calc, collection activity_events
 */
const MONGO_HOST = '127.0.0.1';
const MONGO_PORT = 27017;
const MONGO_DB   = 'carbon_calc';
const MONGO_COLL = 'activity_events';

function cc_is_list(array $a): bool {   // same as array_is_list(), works on PHP 8.0 (XAMPP)
    $i = 0;
    foreach ($a as $k => $_) { if ($k !== $i++) return false; }
    return true;
}

final class MongoDate { public function __construct(public int $ms) {} }

function bson_encode(array $doc): string {
    $body = '';
    $isList = cc_is_list($doc) && $doc !== [];
    foreach ($doc as $k => $v) {
        $name = (string)$k . "\0";
        if ($v instanceof MongoDate)        $body .= "\x09" . $name . pack('q', $v->ms);
        elseif (is_array($v))               $body .= ((cc_is_list($v) && $v !== []) ? "\x04" : "\x03") . $name . bson_encode($v);
        elseif (is_bool($v))                $body .= "\x08" . $name . ($v ? "\x01" : "\x00");
        elseif (is_int($v))                 $body .= ($v >= -2147483648 && $v <= 2147483647) ? "\x10" . $name . pack('l', $v) : "\x12" . $name . pack('q', $v);
        elseif (is_float($v))               $body .= "\x01" . $name . pack('e', $v);
        elseif ($v === null)                $body .= "\x0A" . $name;
        else { $s = (string)$v;             $body .= "\x02" . $name . pack('V', strlen($s) + 1) . $s . "\0"; }
    }
    return pack('V', strlen($body) + 5) . $body . "\0";
}

function bson_decode(string $b, int &$pos = 0): array {
    $start = $pos; $len = unpack('V', $b, $pos)[1]; $end = $start + $len - 1; $pos += 4; $out = [];
    while ($pos < $end) {
        $t = ord($b[$pos++]); $z = strpos($b, "\0", $pos); $k = substr($b, $pos, $z - $pos); $pos = $z + 1;
        switch ($t) {
            case 0x01: $v = unpack('e', $b, $pos)[1]; $pos += 8; break;
            case 0x02: $n = unpack('V', $b, $pos)[1]; $v = substr($b, $pos + 4, $n - 1); $pos += 4 + $n; break;
            case 0x03: case 0x04: $v = bson_decode($b, $pos); break;
            case 0x05: $n = unpack('V', $b, $pos)[1]; $v = bin2hex(substr($b, $pos + 5, $n)); $pos += 5 + $n; break;
            case 0x07: $v = bin2hex(substr($b, $pos, 12)); $pos += 12; break;           // ObjectId → hex
            case 0x08: $v = $b[$pos++] === "\x01"; break;
            case 0x09: $v = new MongoDate(unpack('q', $b, $pos)[1]); $pos += 8; break;
            case 0x0A: $v = null; break;
            case 0x10: $v = unpack('l', $b, $pos)[1]; $pos += 4; break;
            case 0x11: case 0x12: $v = unpack('q', $b, $pos)[1]; $pos += 8; break;
            case 0x13: $v = bin2hex(substr($b, $pos, 16)); $pos += 16; break;
            default: throw new RuntimeException("Unsupported BSON type $t");
        }
        $out[$k] = $v;
    }
    $pos = $end + 1;
    return $out;
}

/** Run one database command. Returns the reply document, or null if MongoDB is not reachable. */
function mongo_cmd(array $cmd): ?array {
    static $sock = null, $down = false, $rid = 0;
    if ($down) return null;
    if ($sock === null) {
        $sock = @stream_socket_client('tcp://' . MONGO_HOST . ':' . MONGO_PORT, $en, $es, 0.5);
        if (!$sock) { $down = true; $sock = null; return null; }
        stream_set_timeout($sock, 3);
    }
    $cmd['$db'] = MONGO_DB;
    $payload = pack('V', 0) . "\x00" . bson_encode($cmd);
    $msg = pack('VVVV', 16 + strlen($payload), ++$rid, 0, 2013) . $payload;
    if (@fwrite($sock, $msg) === false) { $down = true; return null; }
    $hdr = mongo_read($sock, 16);
    if ($hdr === null) { $down = true; return null; }
    $len = unpack('V', $hdr)[1];
    $rest = mongo_read($sock, $len - 16);
    if ($rest === null) { $down = true; return null; }
    $pos = 5;                                   // skip flagBits(4) + section kind(1)
    return bson_decode($rest, $pos);
}
function mongo_read($sock, int $n): ?string {
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($sock, $n - strlen($buf));
        if ($chunk === false || $chunk === '') return null;
        $buf .= $chunk;
    }
    return $buf;
}

function mongo_now(): MongoDate { return new MongoDate((int)floor(microtime(true) * 1000)); }
function mongo_online(): bool { $r = mongo_cmd(['ping' => 1]); return $r !== null && ($r['ok'] ?? 0) == 1; }

/** Log an app event to MongoDB. Never breaks the app if MongoDB is off. */
function mongo_log(string $type, array $details = []): void {
    try {
        mongo_cmd(['insert' => MONGO_COLL, 'documents' => [[
            'type'      => $type,
            'user_id'   => (int)($_SESSION['user_id'] ?? 0),
            'user_name' => (string)($_SESSION['name'] ?? 'guest'),
            'details'   => $details,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'at'        => mongo_now(),
        ]]]);
    } catch (Throwable $e) { /* MongoDB is optional */ }
}

function mongo_find(array $filter, int $limit = 50): array {
    $r = mongo_cmd(['find' => MONGO_COLL, 'filter' => $filter,   // [] encodes as {}
                    'sort' => ['at' => -1], 'limit' => $limit]);
    return $r['cursor']['firstBatch'] ?? [];
}

function mongo_aggregate(array $pipeline): array {
    $r = mongo_cmd(['aggregate' => MONGO_COLL, 'pipeline' => $pipeline, 'cursor' => []]);
    return $r['cursor']['firstBatch'] ?? [];
}
