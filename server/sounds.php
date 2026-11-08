<?php
declare(strict_types=1);

// Sound library helpers for api.php: pure functions, no I/O, no output.
// Stored format, the only one the board plays: WAV, PCM 16-bit, mono, 16 kHz, max 15 s, plain 44-byte header.

const SOUND_BUILTIN = ['none', 'chime', 'bell', 'dinner', 'alert']; // tones generated on the board itself
const SOUND_RATE = 16000;
const SOUND_MAX_DATA = 480000;   // 15 s of 16-bit mono at 16 kHz
const SOUND_UPLOAD_MAX = 600000; // whole uploaded file, extra chunks included
const SOUND_LIBRARY_MAX = 50;
const SOUND_NAME_MAX = 24;
const SOUND_ID_RE = '/^s[0-9]{1,10}$/D';
const MYINSTANTS_MP3_RE = '#^/media/sounds/[A-Za-z0-9_.%()~-]+\.mp3$#iD';

/**
 * Checks an uploaded WAV and rewrites it with the plain 44-byte header (extra chunks such as LIST are dropped).
 * Returns ['wav' => bytes, 'ms' => duration] or ['code' => 400|413, 'error' => text].
 */
function wavCanonical(string $raw): array
{
    $len = strlen($raw);
    if ($len < 12 || substr($raw, 0, 4) !== 'RIFF' || substr($raw, 8, 4) !== 'WAVE') {
        return ['code' => 400, 'error' => 'not a WAV file'];
    }
    $fmt = null;
    $data = null;
    for ($off = 12; $off + 8 <= $len && $data === null;) {
        $id = substr($raw, $off, 4);
        $size = unpack('V', $raw, $off + 4)[1];
        $body = $off + 8;
        if ($id === 'fmt ' && $fmt === null) {
            if ($size < 16 || $body + 16 > $len) {
                return ['code' => 400, 'error' => 'WAV fmt chunk is broken'];
            }
            $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', $raw, $body);
            if ($fmt['format'] === 0xFFFE && $size >= 40 && $body + 26 <= $len) {
                $fmt['format'] = unpack('v', $raw, $body + 24)[1]; // WAVE_FORMAT_EXTENSIBLE: sub-format GUID starts with the real tag
            }
        } elseif ($id === 'data') {
            if ($fmt === null) {
                return ['code' => 400, 'error' => 'WAV data chunk comes before fmt'];
            }
            if ($body + $size > $len) {
                return ['code' => 400, 'error' => 'WAV data chunk is truncated'];
            }
            $data = substr($raw, $body, $size);
        }
        $off = $body + $size + ($size & 1); // chunks are padded to an even size
    }
    if ($fmt === null || $data === null) {
        return ['code' => 400, 'error' => 'WAV needs a fmt and a data chunk'];
    }
    if ($fmt['format'] !== 1 || $fmt['channels'] !== 1 || $fmt['rate'] !== SOUND_RATE || $fmt['bits'] !== 16) {
        return ['code' => 400, 'error' => sprintf('WAV must be PCM 16-bit mono 16000 Hz, got format %d, %d channel(s), %d Hz, %d-bit',
            $fmt['format'], $fmt['channels'], $fmt['rate'], $fmt['bits'])];
    }
    $n = strlen($data);
    if ($n === 0 || $n % 2 !== 0) {
        return ['code' => 400, 'error' => 'WAV has no whole 16-bit samples'];
    }
    if ($n > SOUND_MAX_DATA) {
        return ['code' => 413, 'error' => 'sound is longer than 15 s'];
    }
    return ['wav' => wavHeader($n) . $data, 'ms' => intdiv($n * 1000, SOUND_RATE * 2)];
}

function wavHeader(int $dataBytes): string
{
    return 'RIFF' . pack('V', 36 + $dataBytes) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, SOUND_RATE, SOUND_RATE * 2, 2, 16)
        . 'data' . pack('V', $dataBytes);
}

/** Library display name: UTF-8, whitespace collapsed, control chars removed, 1-24 chars. Null when invalid. */
function soundName(mixed $name): ?string
{
    if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
        return null;
    }
    $name = trim(preg_replace('/\p{Cc}/u', '', preg_replace('/\s+/u', ' ', $name)));
    $len = mb_strlen($name, 'UTF-8');
    return $len >= 1 && $len <= SOUND_NAME_MAX ? $name : null;
}

/** A message may carry a built-in tone or the id of a sound in the library. */
function soundAllowed(mixed $sound, array $library): bool
{
    return is_string($sound)
        && (in_array($sound, SOUND_BUILTIN, true) || in_array($sound, array_column($library, 'id'), true));
}

/**
 * A pasted myinstants link -> ['kind' => 'page'|'mp3', 'url' => https URL to fetch], or null.
 * Accepts instant pages (/en/instant/<slug>/) and direct /media/sounds/<file>.mp3 links; query and fragment are dropped.
 */
function myinstantsSource(string $url): ?array
{
    if (!preg_match('#^https?://(?:www\.)?myinstants\.com(/[^?\#]*)(?:[?\#].*)?$#iD', trim($url), $m)) {
        return null;
    }
    $path = $m[1];
    if (preg_match('#^/(?:[a-z]{2}(?:-[a-z]{2})?/)?instant/[A-Za-z0-9_-]+/?$#D', $path)) {
        return ['kind' => 'page', 'url' => 'https://www.myinstants.com' . rtrim($path, '/') . '/'];
    }
    if (preg_match(MYINSTANTS_MP3_RE, $path)) {
        return ['kind' => 'mp3', 'url' => 'https://www.myinstants.com' . $path];
    }
    return null;
}

/** Every URL the server fetches, redirects included: https, a myinstants host, a plain path and nothing else. */
function myinstantsFetchable(string $url): bool
{
    return (bool)preg_match('#^https://(?:www\.)?myinstants\.com/[A-Za-z0-9/_.%()~-]*$#D', $url);
}

/** First /media/sounds/<file>.mp3 path on an instant page, or null. */
function myinstantsMp3Path(string $html): ?string
{
    preg_match_all('#/media/sounds/[^"\'\s<>/]+\.mp3#i', $html, $m);
    foreach ($m[0] as $path) {
        if (preg_match(MYINSTANTS_MP3_RE, $path)) {
            return $path;
        }
    }
    return null;
}

/** ID3 tag or an MPEG audio frame sync at the start. */
function isMp3(string $bytes): bool
{
    return str_starts_with($bytes, 'ID3')
        || (strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0);
}
