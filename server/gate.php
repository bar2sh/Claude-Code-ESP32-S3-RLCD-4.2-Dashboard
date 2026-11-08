<?php
declare(strict_types=1);

// Front door of the app (docs/SPEC.md, "Blokada urządzeń"). .htaccess sends every request except api.php here.
// It serves only the files of APPLOCK_FILES, so config.php, data/ and the tests stay out of reach.
// With app_lock on and every place taken, a browser without a known device cookie gets 404 for everything,
// and an app it installed earlier receives a service worker that removes it. Any doubt (no config, a broken
// app_lock, a corrupt devices.json) also ends in 404 and a line in the PHP error log.
// The gate never hands out a device cookie: only api.php does, after a valid token and under the lock.

require __DIR__ . '/applock.php';

// Takes the place of the app's service worker: deletes the app's caches, unregisters itself and reloads
// the open windows, which then get 404.
const GATE_KILL_SWITCH = <<<'JS'
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k.startsWith('tablica-')).map((k) => caches.delete(k)));
    await self.registration.unregister();
    const wins = await self.clients.matchAll({ type: 'window' });
    await Promise.all(wins.map((w) => w.navigate(w.url).catch(() => {})));
  })());
});

JS;

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'GET' && $method !== 'HEAD') {
    notFound();
}

$config = gateConfig();
if ($config === null) {
    notFound();
}

// The cookie path and the file names come from the script path, so a rewrite that hides it must not guess.
$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
if (!str_ends_with($script, '/gate.php')) {
    gateLog('SCRIPT_NAME is ' . json_encode($script, JSON_UNESCAPED_SLASHES) . ', not the path of gate.php');
    notFound();
}

$base = applockBase();
$path = rawurldecode(explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''), 2)[0]);
if ($path === rtrim($base, '/')) {
    $rel = null; // the app folder without its slash
} elseif (str_starts_with($path, $base)) {
    $rel = substr($path, strlen($base));
    $rel = $rel === '' ? 'index.html' : $rel;
    if (!isset(APPLOCK_FILES[$rel])) {
        notFound();
    }
} else {
    notFound();
}

$lockError = applockConfigError($config);
if ($lockError !== null) {
    gateLog($lockError);
    notFound();
}
$limit = applockLimit($config);
if ($limit > 0) {
    $devices = applockRead(rtrim((string)($config['data_dir'] ?? (__DIR__ . '/data')), '/'));
    if ($devices === null) {
        gateLog(APPLOCK_CORRUPT);
        notFound();
    }
    $cookie = applockCookie();
    if ($cookie !== null && applockKnown($devices, $limit, $cookie)) {
        applockSetCookie($cookie);
    } elseif (count($devices) >= $limit) {
        if ($rel === 'sw.js' && $method === 'GET' && ($_SERVER['HTTP_SERVICE_WORKER'] ?? '') === 'script') {
            killSwitch();
        }
        notFound();
    }
    // A free place: anyone may open the app and log in. api.php takes the place after a valid token.
}

if ($rel === null) {
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    http_response_code(301);
    header('Cache-Control: no-store');
    header('Location: ' . $base . ($query === '' ? '' : "?$query"));
    exit;
}

$file = __DIR__ . '/' . $rel;
$mtime = is_file($file) ? filemtime($file) : false;
if ($mtime === false) {
    gateLog("app file $rel is missing: upload it");
    notFound();
}
header('Content-Type: ' . APPLOCK_FILES[$rel]);
header('Cache-Control: private, no-cache');
header('Vary: Cookie');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
$since = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
if ($since !== false && $since >= $mtime) {
    http_response_code(304);
    exit;
}
if ($method === 'GET') {
    readfile($file);
}
exit;

/** config.php as an array, or null (logged) when it is missing or cannot be loaded. */
function gateConfig(): ?array
{
    $file = getenv('RLCD_CONFIG') ?: __DIR__ . '/config.php';
    if (!is_file($file)) {
        gateLog('config.php missing');
        return null;
    }
    try {
        $config = require $file;
    } catch (Throwable $e) {
        // the message may quote the file, so only the kind of error and the line go to the log
        gateLog('config.php cannot be loaded: ' . get_class($e) . ' on line ' . $e->getLine());
        return null;
    }
    if (!is_array($config)) {
        gateLog('config.php does not return an array');
        return null;
    }
    return $config;
}

function killSwitch(): never
{
    header('Content-Type: ' . APPLOCK_JS);
    header('Cache-Control: no-store');
    echo GATE_KILL_SWITCH;
    exit;
}

/** The same answer for a missing file, a locked app and every error: nothing here. */
function notFound(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        echo "<!doctype html>\n<title>404 Not Found</title>\n<h1>Not Found</h1>\n"
            . "<p>The requested URL was not found on this server.</p>\n";
    }
    exit;
}

function gateLog(string $message): void
{
    error_log('tablica gate: ' . $message);
}
