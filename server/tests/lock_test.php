<?php
declare(strict_types=1);
// Run: php server/tests/lock_test.php   (app_lock: gate.php and the device places of api.php, docs/SPEC.md)
// Starts php -S with tests/lock_router.php, which mounts the app at /app/sub/, and a temp config.

$root = dirname(__DIR__);
require "$root/applock.php"; // constants only: names, the file list, the messages
$tmp = sys_get_temp_dir() . '/rlcd-lock-test-' . bin2hex(random_bytes(4));
mkdir("$tmp/data", 0700, true);
$T = ['device' => str_repeat('d', 32), 'hook' => str_repeat('h', 32), 'u1' => str_repeat('a', 32), 'u2' => str_repeat('b', 32)];
$REG = "$tmp/data/devices.json";

const LOCK_ABSENT = '(absent)';
/** config.php with the given app_lock entry, or without the key for LOCK_ABSENT. */
function writeConfig(mixed $lock): void
{
    global $tmp, $T;
    $c = [
        'tokens' => ['device' => $T['device'], 'hook' => $T['hook']],
        'users' => [['name' => 'Ja', 'token' => $T['u1']], ['name' => 'Ola', 'token' => $T['u2']]],
        'location' => ['name' => '', 'lat' => null, 'lon' => null],
        'data_dir' => "$tmp/data",
    ];
    if ($lock !== LOCK_ABSENT) {
        $c['app_lock'] = $lock;
    }
    file_put_contents("$tmp/config.php", '<?php return ' . var_export($c, true) . ";\n");
}
writeConfig(LOCK_ABSENT);

$port = random_int(20000, 40000);
$env = array_merge(getenv(), ['RLCD_CONFIG' => "$tmp/config.php"]);
// no opcache: the config changes between requests
$proc = proc_open(
    ['php', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/lock_router.php"],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $root,
    $env,
);
register_shutdown_function(function () use ($proc, $tmp) {
    proc_terminate($proc);
    exec('rm -r ' . escapeshellarg($tmp));
});
usleep(400000);

/** [status, headers as lowercase name => [values], body]. Redirects are not followed. */
function req(string $method, string $path, array $headers = []): array
{
    global $port;
    $lines = '';
    foreach ($headers as $name => $value) {
        $lines .= "$name: $value\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $lines, 'content' => '', 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    return parseResponse(http_get_last_response_headers() ?? [], (string)$body);
}

function parseResponse(array $lines, string $body): array
{
    preg_match('#HTTP/\S+ (\d+)#', $lines[0] ?? '', $m);
    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($name))][] = trim($value);
    }
    return [(int)($m[1] ?? 0), $headers, $body];
}

function header1(array $r, string $name): string
{
    return $r[1][$name][0] ?? '';
}

/** The device cookie a response sets, or null. */
function cookieOf(array $r): ?string
{
    foreach ($r[1]['set-cookie'] ?? [] as $line) {
        if (preg_match('/^' . APPLOCK_COOKIE . '=([0-9a-f]{64});/', $line, $m)) {
            return $m[1];
        }
    }
    return null;
}

/** Headers of a browser, with an optional device cookie and token. */
function browser(?string $cookie = null, ?string $token = null): array
{
    $h = ['User-Agent' => 'Mozilla/5.0 (test)', 'Sec-Fetch-Site' => 'same-origin'];
    if ($cookie !== null) {
        $h['Cookie'] = APPLOCK_COOKIE . "=$cookie";
    }
    if ($token !== null) {
        $h['X-Token'] = $token;
    }
    return $h;
}

function page(string $rel, array $headers = []): array
{
    return req('GET', "/app/sub/$rel", $headers);
}

function me(array $headers): array
{
    return req('GET', '/app/sub/api.php?a=me', $headers);
}

function poll(): int
{
    global $T;
    return req('GET', '/app/sub/api.php?a=poll&rev=-1', ['X-Token' => $T['device']])[0];
}

function apiError(array $r): string
{
    return (string)(json_decode($r[2], true)['error'] ?? '');
}

/** The 404 the gate gives for everything it does not serve: no cookie, never cached. */
function hidden(array $r): bool
{
    return $r[0] === 404 && str_contains($r[2], '<h1>Not Found</h1>') && header1($r, 'cache-control') === 'no-store'
        && cookieOf($r) === null;
}

/** The devices of devices.json, or null without the file. */
function devices(): ?array
{
    global $REG;
    return is_file($REG) ? (json_decode((string)file_get_contents($REG), true)['devices'] ?? null) : null;
}

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? "  ok   " : "  FAIL ") . "$name\n";
    $fails += $ok ? 0 : 1;
}

$index = (string)file_get_contents("$root/index.html");

echo "lock off: the gate serves the app files and nothing else\n";
$bad = [];
foreach (APPLOCK_FILES as $rel => $type) {
    $r = page($rel, browser());
    if ($r[0] !== 200 || header1($r, 'content-type') !== $type || $r[2] !== file_get_contents("$root/$rel") || cookieOf($r) !== null) {
        $bad[] = $rel;
    }
}
check('every app file -> 200 with its type and content, no cookie ' . implode(', ', $bad), $bad === []);
$r = page('', browser());
check('the folder serves index.html, private and revalidated on every use',
    $r[0] === 200 && $r[2] === $index && header1($r, 'cache-control') === 'private, no-cache' && header1($r, 'vary') === 'Cookie');
$bad = [];
foreach (['config.php', 'config.example.php', 'applock.php', 'gate.php', 'sounds.php', 'api.php/x', 'data/state.json',
    'data/devices.json', 'tests/api_test.php', 'tests/lock_router.php', 'lib/../config.php', '%2e%2e/config.php',
    'lib/%2e%2e/config.php', 'lib/', 'icons/', 'nope.js', 'index.html/', '.htaccess', 'app.js%00', 'APP.JS'] as $rel) {
    if (!hidden(page($rel, browser()))) {
        $bad[] = $rel;
    }
}
check('other files, folders and ../ tricks -> 404 ' . implode(', ', $bad), $bad === []);
check('a path outside the app folder -> 404', hidden(req('GET', '/other/index.html', browser())));
check('POST to the app -> 404', hidden(req('POST', '/app/sub/', browser())));
$r = req('HEAD', '/app/sub/app.js', browser());
check('HEAD -> 200 with the type and no body', $r[0] === 200 && header1($r, 'content-type') === APPLOCK_JS && $r[2] === '');
$r = page('?utm=1', browser());
check('a query string is ignored', $r[0] === 200 && $r[2] === $index);
$r = req('GET', '/app/sub?x=1', browser());
check('the folder without its slash -> 301 to the folder', $r[0] === 301 && header1($r, 'location') === '/app/sub/?x=1');
$r = page('app.css', browser() + ['If-Modified-Since' => gmdate('D, d M Y H:i:s', (int)filemtime("$root/app.css")) . ' GMT']);
check('If-Modified-Since -> 304 without a body', $r[0] === 304 && $r[2] === '');
check('a wrong SCRIPT_NAME after the rewrite -> 404', hidden(page('', browser() + ['X-Test-Script-Name' => '/app/sub/lib/api.js'])));
$r = me(browser(null, $T['u1']));
check('a browser logs in without a cookie and nothing is stored', $r[0] === 200 && cookieOf($r) === null && !file_exists($REG));
writeConfig(['devices' => 0]);
$r = me(browser(null, $T['u1']));
check("'devices' => 0 is off as well", $r[0] === 200 && cookieOf($r) === null && !file_exists($REG) && page('', browser())[0] === 200);

echo "two places, both free\n";
writeConfig(['devices' => 2]);
$r = page('', browser());
check('a stranger sees the app while places are free; the gate hands out no cookie', $r[0] === 200 && cookieOf($r) === null);
$r = me(['X-Token' => $T['u1']]);
check('curl with a user token takes no place (the terminal, docs/INSTRUKCJA.md chapter 4)', $r[0] === 200 && cookieOf($r) === null && !file_exists($REG));
check('the board polls as before', poll() === 200);
$r = me(browser(null, str_repeat('z', 32)));
check('a browser with a bad token -> 401 and takes no place', $r[0] === 401 && !file_exists($REG));

echo "taking places\n";
$r = me(browser(null, $T['u1']));
$va = cookieOf($r);
$line = $r[1]['set-cookie'][0] ?? '';
check('browser A logs in and takes the first place', $r[0] === 200 && $va !== null && count(devices() ?? []) === 1);
check('the cookie is HttpOnly, Secure, SameSite=Lax, for the app folder, for 400 days',
    str_contains($line, 'path=/app/sub/;') && str_contains($line, '; secure') && str_contains($line, '; HttpOnly')
    && str_contains($line, 'SameSite=Lax') && str_contains($line, 'Max-Age=' . APPLOCK_COOKIE_DAYS * 86400));
$e = devices()[0] ?? [];
check('devices.json keeps the hash, the name and the date, never the cookie itself',
    ($e['hash'] ?? '') === hash('sha256', (string)$va) && ($e['name'] ?? '') === 'Ja'
    && abs(time() - (int)strtotime((string)($e['added'] ?? ''))) < 60 && !str_contains((string)file_get_contents($REG), (string)$va));
$r = me(browser($va, $T['u1']));
check('A again: served, the same cookie renewed, no new place', $r[0] === 200 && cookieOf($r) === $va && count(devices()) === 1);
$r = me(browser(null, $T['u1']));
check('a cookieless request of Ja seconds after the claim takes nothing', $r[0] === 200 && cookieOf($r) === null && count(devices()) === 1);
$r = me(browser(null, $T['u2']));
$vb = cookieOf($r);
check('browser B (Ola) takes the second place',
    $r[0] === 200 && $vb !== null && $vb !== $va && count(devices()) === 2 && (devices()[1]['name'] ?? '') === 'Ola');

echo "both places taken\n";
$before = file_get_contents($REG);
$r = me(browser(null, $T['u1']));
check('browser C with a valid token -> 404 with the reason, nothing stored',
    $r[0] === 404 && apiError($r) === 'app_lock: all 2 device places are taken by other browsers' && cookieOf($r) === null
    && file_get_contents($REG) === $before);
$r = me(browser(null, $T['u2']));
check('within 10 s of B taking its place a cookieless Ola is served once more, without a cookie',
    $r[0] === 200 && cookieOf($r) === null && file_get_contents($REG) === $before);
$list = devices();
$list[1]['added'] = date('c', time() - APPLOCK_GRACE - 1);
file_put_contents($REG, json_encode(['devices' => $list], JSON_PRETTY_PRINT) . "\n");
$before = file_get_contents($REG);
check('after 10 s the same request -> 404', me(browser(null, $T['u2']))[0] === 404 && file_get_contents($REG) === $before);
$bad = [];
foreach (['', 'index.html', 'app.js', 'lib/api.js', 'manifest.webmanifest', 'icons/icon-192.png', 'sw.js'] as $rel) {
    if (!hidden(page($rel, browser()))) {
        $bad[] = $rel === '' ? '/' : $rel;
    }
}
check('C gets 404 for the app and every file of it ' . implode(', ', $bad), $bad === []);
$r = req('HEAD', '/app/sub/', browser());
check('C: HEAD -> 404 as well', $r[0] === 404 && $r[2] === '');
check('C: the folder without its slash -> 404, no redirect', hidden(req('GET', '/app/sub', browser())));
$r = page('sw.js', browser() + ['Service-Worker' => 'script']);
check('C: the update check of an installed app gets the service worker that removes it, never cached',
    $r[0] === 200 && str_contains($r[2], 'self.registration.unregister()') && str_contains($r[2], "startsWith('tablica-')")
    && header1($r, 'cache-control') === 'no-store' && header1($r, 'content-type') === APPLOCK_JS && cookieOf($r) === null);
check('C: HEAD of the worker with that header -> 404', req('HEAD', '/app/sub/sw.js', browser() + ['Service-Worker' => 'script'])[0] === 404);
check('curl without a cookie -> 404 too', hidden(page('')));
check('curl with a user token still works', me(['X-Token' => $T['u1']])[0] === 200);
check('the board still polls', poll() === 200);
$r = page('', browser($va));
check('A opens the app and its cookie is renewed', $r[0] === 200 && $r[2] === $index && cookieOf($r) === $va);
$r = page('sw.js', browser($va) + ['Service-Worker' => 'script']);
check('A gets the real service worker', $r[0] === 200 && $r[2] === file_get_contents("$root/sw.js"));
$r = me(browser($va, $T['u2']));
check('A may log in as Ola too: the place belongs to the browser', $r[0] === 200 && cookieOf($r) === $va);
check('B opens the app', page('', browser($vb))[0] === 200 && me(browser($vb, $T['u2']))[0] === 200);
$stale = bin2hex(random_bytes(32));
check('a well-formed cookie the list does not know -> 404 at the gate and in the API',
    hidden(page('', browser($stale))) && me(browser($stale, $T['u1']))[0] === 404 && file_get_contents($REG) === $before);
check('a malformed cookie -> 404', hidden(page('', browser('not-a-device'))));

echo "changing the number of places\n";
writeConfig(['devices' => 1]);
check('one place: the oldest browser (A) keeps it, B gets 404',
    page('', browser($va))[0] === 200 && hidden(page('', browser($vb))) && me(browser($vb, $T['u2']))[0] === 404);
writeConfig(['devices' => 2]);
check('two places again: B is back without logging in anew', page('', browser($vb))[0] === 200 && me(browser($vb, $T['u2']))[0] === 200);

echo "the decision is made under the lock\n";
unlink($REG);
$lock = fopen("$tmp/data/.lock", 'c');
flock($lock, LOCK_EX);
// the request reads an empty list, then waits for the lock; meanwhile two other browsers take both places
$sock = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
fwrite($sock, "GET /app/sub/api.php?a=me HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: Mozilla/5.0 (test)\r\nX-Token: {$T['u1']}\r\n\r\n");
usleep(300000);
$taken = [['hash' => hash('sha256', 'x'), 'name' => 'X', 'added' => date('c', time() - 3600)],
    ['hash' => hash('sha256', 'y'), 'name' => 'Y', 'added' => date('c', time() - 3600)]];
file_put_contents($REG, json_encode(['devices' => $taken]) . "\n");
flock($lock, LOCK_UN);
fclose($lock);
$raw = (string)stream_get_contents($sock);
fclose($sock);
[$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
$r = parseResponse(explode("\r\n", $head), $body);
check('a browser waiting for the lock sees the places taken meanwhile: 404, no third place',
    $r[0] === 404 && cookieOf($r) === null && count(devices() ?? []) === 2);

echo "freeing the places\n";
unlink($REG);
check('without devices.json a stranger sees the app again', page('', browser())[0] === 200);
$r = me(browser($va, $T['u1']));
check('A logs in with its old cookie and takes a place under the same value',
    $r[0] === 200 && cookieOf($r) === $va && count(devices() ?? []) === 1 && (devices()[0]['hash'] ?? '') === hash('sha256', $va));

echo "corrupt devices.json\n";
file_put_contents($REG, '{"devices": [');
$r = me(browser($va, $T['u1']));
check('unreadable devices.json: the gate -> 404 even for A, the API -> 500 with the fix, the file stays',
    hidden(page('', browser($va))) && $r[0] === 500 && apiError($r) === APPLOCK_CORRUPT && file_get_contents($REG) === '{"devices": [');
check('...while curl and the board work as before', me(['X-Token' => $T['u1']])[0] === 200 && poll() === 200);
$bad = [];
foreach (['[]', 'null', '{"devices": {"a": {"hash": "' . hash('sha256', $va) . '"}}}', '{"devices": [{"hash": "ABC"}]}',
    '{"devices": [{"name": "Ja"}]}', '{"devices": ["x"]}'] as $i => $json) {
    file_put_contents($REG, $json);
    if (!hidden(page('', browser($va)))) {
        $bad[] = $i;
    }
}
check('devices.json of another shape counts as corrupt ' . implode(', ', $bad), $bad === []);
file_put_contents($REG, " \n");
check('a blank devices.json is an empty list', page('', browser())[0] === 200);

echo "invalid app_lock\n";
$bad = [];
foreach (['2', 2, false, true, [], ['devices' => '2'], ['devices' => -1], ['devices' => 2.0], ['devise' => 2],
    ['devices' => 2, 'mode' => 'x']] as $i => $lock) {
    writeConfig($lock);
    $r = req('GET', '/app/sub/api.php?a=poll&rev=-1', ['X-Token' => $T['device']]);
    if (!hidden(page('', browser())) || $r[0] !== 500 || !str_starts_with(apiError($r), 'app_lock must be')) {
        $bad[] = $i;
    }
}
check('a broken app_lock closes the app (404) and the API (500, every role) ' . implode(', ', $bad), $bad === []);

echo "config.php\n";
writeConfig(['devices' => 2]);
rename("$tmp/config.php", "$tmp/config.off");
check('no config.php -> 404', hidden(page('', browser())));
file_put_contents("$tmp/config.php", "<?php return [\n");
check('a config.php that does not parse -> 404', hidden(page('', browser())));
file_put_contents("$tmp/config.php", "<?php\n");
check('a config.php that returns no array -> 404', hidden(page('', browser())));
rename("$tmp/config.off", "$tmp/config.php");
check('config back: the app opens', page('', browser())[0] === 200);

echo "list of app files\n";
preg_match('/const FILES = \[(.*?)\];/s', (string)file_get_contents("$root/sw.js"), $m);
preg_match_all("/'([^']+)'/", $m[1] ?? '', $f);
$cached = array_map(fn (string $x) => $x === './' ? 'index.html' : $x, $f[1]);
$cached[] = 'sw.js';
$cached = array_values(array_unique($cached));
sort($cached);
$listed = array_keys(APPLOCK_FILES);
sort($listed);
check('the gate serves exactly the files of sw.js plus sw.js itself', $cached === $listed);
check('every listed file exists', array_filter($listed, fn (string $x) => !is_file("$root/$x")) === []);

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
