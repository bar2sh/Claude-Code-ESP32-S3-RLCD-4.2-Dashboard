<?php
declare(strict_types=1);

// Device lock of the PWA, shared by gate.php and api.php (docs/SPEC.md, "Blokada urządzeń").
// config.php: 'app_lock' => ['devices' => N]. The first N browsers that log in take the places; once they are
// taken, every other browser gets 404 for the app files, and from the API too when it brings a valid token.
// data/devices.json keeps only a hash of each device cookie, the person's name and the date.
// No output here: the callers decide what to answer.

const APPLOCK_COOKIE = '__Secure-tablica_device';
const APPLOCK_FILE = 'devices.json';
const APPLOCK_CORRUPT = 'devices.json is corrupt: fix it or delete it to free every place';
const APPLOCK_COOKIE_DAYS = 400; // the longest lifetime browsers accept; every recognised use renews it
const APPLOCK_GRACE = 10;        // seconds: a cookieless request of the same person right after a claim
const APPLOCK_HEX_RE = '/^[0-9a-f]{64}$/D';
const APPLOCK_JS = 'text/javascript; charset=utf-8';
// The only files gate.php serves: the app files of sw.js plus sw.js itself. A new app file goes here too.
const APPLOCK_FILES = [
    'index.html' => 'text/html; charset=utf-8',
    'app.css' => 'text/css; charset=utf-8',
    'app.js' => APPLOCK_JS,
    'sw.js' => APPLOCK_JS,
    'lib/api.js' => APPLOCK_JS,
    'lib/wav.js' => APPLOCK_JS,
    'lib/dither.js' => APPLOCK_JS,
    'lib/text.js' => APPLOCK_JS,
    'lib/anim.js' => APPLOCK_JS,
    'lib/dash.js' => APPLOCK_JS,
    'lib/i18n.js' => APPLOCK_JS,
    'manifest.webmanifest' => 'application/manifest+json',
    'manifest-pl.webmanifest' => 'application/manifest+json',
    'icons/icon.svg' => 'image/svg+xml',
    'icons/icon-192.png' => 'image/png',
    'icons/icon-512.png' => 'image/png',
    'icons/apple-touch-icon.png' => 'image/png',
];

/** Null when app_lock is absent or well formed, else the error to report. A typo never switches the lock off. */
function applockConfigError(array $config): ?string
{
    $lock = $config['app_lock'] ?? null;
    if ($lock === null) {
        return null;
    }
    if (is_array($lock) && array_keys($lock) === ['devices'] && is_int($lock['devices']) && $lock['devices'] >= 0) {
        return null;
    }
    return "app_lock must be ['devices' => N] with N a whole number (0 = off)";
}

/** How many browsers may use the app, 0 = no lock. Valid only after applockConfigError() returned null. */
function applockLimit(array $config): int
{
    return (int)($config['app_lock']['devices'] ?? 0);
}

/** The app folder as the browser sees it, with the trailing slash: the path of the device cookie. */
function applockBase(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return str_starts_with($dir, '/') ? "$dir/" : '/';
}

/** True for a web browser. The board, the hook, img2rlcd.py and curl send none of these headers. */
function applockFromBrowser(): bool
{
    return isset($_SERVER['HTTP_SEC_FETCH_SITE']) || isset($_SERVER['HTTP_SEC_FETCH_MODE'])
        || str_starts_with((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'Mozilla/');
}

/** The device cookie of this request, or null when it is missing or malformed. */
function applockCookie(): ?string
{
    $value = $_COOKIE[APPLOCK_COOKIE] ?? null;
    return is_string($value) && preg_match(APPLOCK_HEX_RE, $value) === 1 ? $value : null;
}

function applockHash(string $value): string
{
    return hash('sha256', $value);
}

/**
 * The devices of data/devices.json, oldest first: [] when the file is missing or blank,
 * null when it cannot be read or has another shape (the callers then deny access).
 */
function applockRead(string $dataDir): ?array
{
    $path = "$dataDir/" . APPLOCK_FILE;
    if (!file_exists($path)) {
        return [];
    }
    $raw = is_file($path) ? @file_get_contents($path) : false;
    if ($raw === false) {
        return null;
    }
    if (trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    $devices = is_array($data) ? ($data['devices'] ?? null) : null;
    if (!is_array($devices) || !array_is_list($devices)) {
        return null;
    }
    foreach ($devices as $d) {
        if (!is_array($d) || !is_string($d['hash'] ?? null) || preg_match(APPLOCK_HEX_RE, $d['hash']) !== 1) {
            return null;
        }
    }
    return $devices;
}

/** True when the cookie belongs to one of the first $limit devices: a lower limit keeps the oldest. */
function applockKnown(array $devices, int $limit, ?string $cookie): bool
{
    if ($cookie === null) {
        return false;
    }
    $hash = applockHash($cookie);
    foreach (array_slice($devices, 0, $limit) as $d) {
        if (hash_equals($d['hash'], $hash)) {
            return true;
        }
    }
    return false;
}

/** Sends the device cookie again, so it lives another APPLOCK_COOKIE_DAYS from now. */
function applockSetCookie(string $value): void
{
    setcookie(APPLOCK_COOKIE, $value, [
        'expires' => time() + APPLOCK_COOKIE_DAYS * 86400,
        'path' => applockBase(),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
