<?php
declare(strict_types=1);

// Web Push for the app (docs/SPEC.md, "Powiadomienia push"): the phone shows the Claude sessions as one
// notification that the server rewrites whenever a session changes state. No library: VAPID (RFC 8292) and the
// aes128gcm message encryption (RFC 8291) are done here with PHP's openssl, hash and curl extensions.
// Subscriptions live in data/push.json, never in state.json, so no answer of the API ever carries them.

const PUSH_FILE = 'push.json';
const PUSH_MAX = 10;                 // browsers that may subscribe; the oldest gives way to a new one
const PUSH_EVENTS = ['working', 'waiting', 'done']; // what a device may ask to be alerted about
const PUSH_PREFS = ['working' => false, 'waiting' => true, 'done' => true, 'sound' => true];
const PUSH_TIMEOUT_S = 2;            // all push services together; the hook gives up after 3 s
const PUSH_TTL_S = 3600;             // a status older than an hour is not worth waking a phone for
const PUSH_NAME_MAX = 32;            // characters of a session name in the notification
const PUSH_ENDPOINT_MAX = 1024;
const PUSH_TAG = 'claude';           // one notification, replaced in place
// The server posts to the endpoint a browser hands in, so only the known push services are accepted.
const PUSH_HOSTS = '/(?:^|\.)(?:fcm\.googleapis\.com|push\.services\.mozilla\.com|notify\.windows\.com|push\.apple\.com)$/D';
const PUSH_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200'; // P-256 public key, DER header

const PUSH_TEXT = [
    'en' => [
        'working' => 'Working: {name}', 'waiting' => 'Waiting for you: {name}', 'done' => 'Done: {name}',
        'update' => 'Claude sessions', 'test' => 'Notifications work',
        'l.working' => 'Working', 'l.waiting' => 'Waiting', 'l.done' => 'Done', 'none' => 'No Claude sessions right now.',
        'question' => 'question', 'plan' => 'plan', 'permission' => 'permission',
    ],
    'pl' => [
        'working' => 'Pracuje: {name}', 'waiting' => 'Czeka na Ciebie: {name}', 'done' => 'Gotowe: {name}',
        'update' => 'Sesje Claude', 'test' => 'Powiadomienia działają',
        'l.working' => 'W toku', 'l.waiting' => 'Czeka', 'l.done' => 'Gotowe', 'none' => 'Teraz nie ma sesji Claude.',
        'question' => 'pytanie', 'plan' => 'plan', 'permission' => 'zgoda',
    ],
];

function pushB64(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Bytes of a base64url string, or null when it is not one. */
function pushUnB64(mixed $text): ?string
{
    if (!is_string($text) || !preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
        return null;
    }
    $bin = base64_decode(strtr($text, '-_', '+/'), true);
    return $bin === false ? null : $bin;
}

/** PEM of a P-256 public key given as the 65-byte uncompressed point. */
function pushPublicPem(string $point): string
{
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(PUSH_SPKI_PREFIX) . $point), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/** PEM of a P-256 private key given as the 32-byte scalar and its public point (SEC1 ECPrivateKey). */
function pushPrivatePem(string $d, string $point): string
{
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/** A fresh P-256 key pair as [32-byte scalar, 65-byte point]. */
function pushNewKey(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = $key === false ? null : (openssl_pkey_get_details($key)['ec'] ?? null);
    if (!is_array($ec)) {
        throw new RuntimeException('openssl cannot make a P-256 key');
    }
    $pad = fn(string $x): string => str_pad($x, 32, "\0", STR_PAD_LEFT); // openssl drops leading zero bytes
    return [$pad($ec['d']), "\x04" . $pad($ec['x']) . $pad($ec['y'])];
}

/**
 * The "push" block of config.php checked: ['ok' => true, 'public', 'private' (PEM), 'subject'] or
 * ['ok' => false, 'reason']. Nothing is guessed: without keys, a contact address or the PHP extensions
 * the feature is off and the reason says what to fill in.
 */
function pushConfig(array $config): array
{
    $off = fn(string $reason): array => ['ok' => false, 'reason' => $reason];
    foreach (['openssl_pkey_derive', 'openssl_sign', 'hash_hkdf', 'curl_multi_init'] as $fn) {
        if (!function_exists($fn)) {
            return $off('push needs the PHP extensions openssl and curl on this hosting');
        }
    }
    $p = $config['push'] ?? null;
    if (!is_array($p) || ($p['public'] ?? '') === '' && ($p['private'] ?? '') === '') {
        return $off('push is not set up: run tools/push-keys.php and copy its output into config.php');
    }
    $point = pushUnB64($p['public'] ?? null);
    $d = pushUnB64($p['private'] ?? null);
    if ($point === null || strlen($point) !== 65 || $point[0] !== "\x04" || $d === null || strlen($d) !== 32) {
        return $off('push keys in config.php are broken: run tools/push-keys.php again');
    }
    $subject = $p['subject'] ?? null;
    if (!is_string($subject) || !preg_match('#^(mailto:[^\s@]+@[^\s@]+|https://\S+)$#D', $subject)) {
        return $off('push.subject in config.php must be mailto:<your address> or an https:// address');
    }
    $pem = pushPrivatePem($d, $point);
    // a signature made with the private key must verify with the public one; reading the pair back proves nothing
    if (!@openssl_sign('pair', $sig, $pem, OPENSSL_ALGO_SHA256)
        || @openssl_verify('pair', $sig, pushPublicPem($point), OPENSSL_ALGO_SHA256) !== 1) {
        return $off('push keys in config.php do not belong together: run tools/push-keys.php again');
    }
    return ['ok' => true, 'public' => pushB64($point), 'private' => $pem, 'subject' => $subject];
}

/** The VAPID token for one push service: an ES256 JWT, signature as raw r|s, not openssl's DER. */
function pushJwt(string $audience, string $subject, string $privatePem, int $now): string
{
    $head = pushB64('{"typ":"JWT","alg":"ES256"}');
    $claims = pushB64((string)json_encode(['aud' => $audience, 'exp' => $now + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign("$head.$claims", $der, $privatePem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('openssl cannot sign');
    }
    return "$head.$claims." . pushB64(pushRawSignature($der));
}

/** DER SEQUENCE { INTEGER r, INTEGER s } as 64 bytes. */
function pushRawSignature(string $der): string
{
    $at = 2 + (ord($der[1]) > 0x80 ? ord($der[1]) - 0x80 : 0);
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$at + 1]);
        $out .= str_pad(ltrim(substr($der, $at + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $at += 2 + $len;
    }
    return $out;
}

/**
 * One aes128gcm record for a browser (RFC 8291): header (salt, record size, sender key) and the ciphertext.
 * $salt and $sender ([scalar, point]) are given only by the test that checks the RFC's example.
 */
function pushEncrypt(string $plain, string $receiverPoint, string $auth, ?string $salt = null, ?array $sender = null): string
{
    $salt ??= random_bytes(16);
    [$d, $point] = $sender ?? pushNewKey();
    $secret = openssl_pkey_derive(pushPublicPem($receiverPoint), pushPrivatePem($d, $point));
    if ($secret === false || strlen($secret) !== 32) {
        throw new RuntimeException('the browser key is not a P-256 point');
    }
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $receiverPoint . $point, $auth);
    $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag); // 0x02: last record
    if ($cipher === false) {
        throw new RuntimeException('openssl cannot encrypt');
    }
    return $salt . pack('N', 4096) . chr(strlen($point)) . $point . $cipher . $tag;
}

/** An https address of a known push service. RLCD_PUSH_ALLOW names one more prefix, for the tests' stand-in. */
function pushEndpointOk(mixed $url): bool
{
    if (!is_string($url) || strlen($url) > PUSH_ENDPOINT_MAX || preg_match('/[\x00-\x20\x7f-\xff]/', $url)) {
        return false;
    }
    $allow = (string)getenv('RLCD_PUSH_ALLOW');
    if ($allow !== '' && str_starts_with($url, $allow)) {
        return true;
    }
    $u = parse_url($url);
    return is_array($u) && ($u['scheme'] ?? '') === 'https' && !isset($u['user']) && !isset($u['port'])
        && preg_match(PUSH_HOSTS, strtolower((string)($u['host'] ?? ''))) === 1;
}

/** A device's choices with every field a bool, or null when a field is missing or of another type. */
function pushPrefs(mixed $prefs): ?array
{
    if (!is_array($prefs)) {
        return null;
    }
    $out = [];
    foreach (array_keys(PUSH_PREFS) as $k) {
        if (!is_bool($prefs[$k] ?? null)) {
            return null;
        }
        $out[$k] = $prefs[$k];
    }
    return $out;
}

/** The stored subscriptions: [] without the file, null when the file is damaged. */
function pushRead(string $dataDir): ?array
{
    $file = "$dataDir/" . PUSH_FILE;
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);
    $subs = is_array($data) ? ($data['subs'] ?? null) : null;
    if (!is_array($subs) || !array_is_list($subs)) {
        return null;
    }
    foreach ($subs as $x) {
        if (!is_array($x) || !pushEndpointOk($x['endpoint'] ?? null) || !is_string($x['p256dh'] ?? null) || !is_string($x['auth'] ?? null)) {
            return null;
        }
    }
    return $subs;
}

/**
 * What a session report means for the phone: 'working' (a turn started), 'waiting', 'done', 'update'
 * (the list changed without news, e.g. an answered question) or null (the state is the same as before).
 */
function pushEvent(?string $was, string $st): ?string
{
    if ($was === $st || $was === null && $st === 'end') {
        return null;
    }
    $active = $was === 'working' || $was === 'waiting';
    return match ($st) {
        'working' => $active ? 'update' : 'working',
        'waiting' => 'waiting',
        'done' => $active ? 'done' : 'update',
        default => 'update',
    };
}

function pushName(array $row): string
{
    $name = ($row['title'] ?? '') !== '' ? $row['title'] : (string)($row['alias'] ?? '');
    return mb_strlen($name, 'UTF-8') > PUSH_NAME_MAX ? mb_substr($name, 0, PUSH_NAME_MAX - 1, 'UTF-8') . '…' : $name;
}

/**
 * Title and body of the status notification: the title says what just happened, the body lists the sessions
 * by state, with "2/5" where a session has a task list. $rows is sessionList(); $id is the row the event is about.
 */
function pushText(string $lang, string $event, ?string $id, array $rows): array
{
    $tx = PUSH_TEXT[$lang] ?? PUSH_TEXT['en'];
    $name = '';
    $groups = ['working' => [], 'waiting' => [], 'done' => []];
    foreach ($rows as $row) {
        $state = $row['state'] ?? '';
        if (!isset($groups[$state])) {
            continue;
        }
        $item = pushName($row);
        if (($row['id'] ?? null) === $id) {
            $name = $item;
        }
        if (isset($row['tasks']['d'], $row['tasks']['t'])) {
            $item .= " {$row['tasks']['d']}/{$row['tasks']['t']}";
        }
        if ($state === 'waiting' && isset($tx[$row['kind'] ?? ''])) {
            $item .= ' (' . $tx[$row['kind']] . ')';
        }
        $groups[$state][] = $item;
    }
    $lines = [];
    foreach ($groups as $state => $items) {
        if ($items !== []) {
            $lines[] = $tx["l.$state"] . ' (' . count($items) . '): ' . implode(', ', $items);
        }
    }
    $title = isset($groups[$event]) && $name !== '' ? str_replace('{name}', $name, $tx[$event]) : $tx[$event === 'test' ? 'test' : 'update'];
    return [$title, $lines === [] ? $tx['none'] : implode("\n", $lines)];
}

/**
 * What the service worker gets for one device. alert: the notification announces itself again; silent: without
 * the phone's sound. An event the device did not ask for still updates the status, quietly.
 */
function pushPayload(array $sub, string $event, ?string $id, array $rows, int $rev): string
{
    $prefs = pushPrefs($sub['prefs'] ?? null) ?? PUSH_PREFS;
    $alert = $event === 'test' || ($prefs[$event] ?? false);
    [$title, $body] = pushText((string)($sub['lang'] ?? 'en'), $event, $id, $rows);
    return (string)json_encode([
        'v' => 1, 'tag' => PUSH_TAG, 'title' => $title, 'body' => $body,
        'alert' => $alert, 'silent' => !($alert && $prefs['sound']), 'rev' => $rev, 'ts' => time() * 1000,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Sends one encrypted message per item of $jobs ([['sub' => subscription, 'payload' => JSON, 'urgent' => bool]])
 * in parallel and returns the HTTP status for each key; 0 means the push service did not answer in time.
 */
function pushSend(array $cfg, array $jobs, int $timeout = PUSH_TIMEOUT_S): array
{
    $multi = curl_multi_init();
    $handles = [];
    $status = [];
    foreach ($jobs as $k => $job) {
        $status[$k] = 0;
        $sub = $job['sub'];
        $point = pushUnB64($sub['p256dh']);
        $auth = pushUnB64($sub['auth']);
        $u = parse_url($sub['endpoint']);
        if ($point === null || strlen($point) !== 65 || $auth === null || !is_array($u)) {
            $status[$k] = 400;
            continue;
        }
        try {
            $body = pushEncrypt($job['payload'], $point, $auth);
            $origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
            $jwt = pushJwt($origin, $cfg['subject'], $cfg['private'], time());
        } catch (Throwable) {
            $status[$k] = 400;
            continue;
        }
        $h = curl_init($sub['endpoint']);
        curl_setopt_array($h, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: ' . PUSH_TTL_S,
                'Urgency: ' . (!empty($job['urgent']) ? 'high' : 'normal'),
                'Topic: ' . PUSH_TAG, // a phone that was offline gets only the newest status
                "Authorization: vapid t=$jwt, k={$cfg['public']}",
            ],
        ]);
        curl_multi_add_handle($multi, $h);
        $handles[$k] = $h;
    }
    $until = microtime(true) + $timeout;
    do {
        curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running > 0 && microtime(true) < $until);
    foreach ($handles as $k => $h) {
        $status[$k] = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $h);
    }
    curl_multi_close($multi);
    return $status;
}
