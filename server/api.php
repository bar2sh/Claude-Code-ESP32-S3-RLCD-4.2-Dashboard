<?php
declare(strict_types=1);

// RLCD board API. File-based storage, no database. See docs/SPEC.md.

require __DIR__ . '/sounds.php';
require __DIR__ . '/applock.php';

const WALLPAPER_BYTES = 15000; // 400x300, 1 bit per pixel
const MESSAGE_MAX_CHARS = 280;
const SESSION_TTL = 12 * 3600;
const SESSION_MAX = 8;
const SESSION_TITLE_MAX = 60; // characters, not bytes
const WEATHER_TTL = 1800;
const SESSION_KINDS = ['question', 'plan', 'permission']; // what a waiting session waits for
const SESSION_COUNT_MAX = 100000;                        // turns; plenty, and a runaway value is refused
const TASKS_MAX = 999;
const USAGE_WINDOWS = ['five' => 5 * 3600, 'week' => 7 * 86400]; // seconds a reading stays meaningful
const USAGE_MODEL_MAX = 32;
const VIEWS = ['dashboard', 'message', 'wallpaper', 'sessions'];
const SESSION_STATES = ['working', 'waiting', 'done', 'end'];
const MODES = ['listen', 'ondemand', 'offline'];
const LANGS = ['en', 'pl'];                     // board UI language, first is the default
const POLL_DEFAULT_S = 10;   // seconds between polls in listen mode until someone picks their own interval
const POLL_MIN_S = 5;        // an interval picked in the app: whole seconds from POLL_MIN_S to POLL_MAX_S,
const POLL_MAX_S = 3600;     // the same three numbers as in firmware/main/app.h
const NOTIFY_MODES = ['quiet', 'ready', 'each'];
const NOTIFY_SCOPES = ['sessions', 'all'];
const NOTIFY_ANIMS = ['away', 'alert', 'happy', 'typing', 'idle', 'sleep']; // frames in lib/anim.js
const FETCH_UA = 'rlcd-board/1.0 (self-hosted; imports one sound per user request)';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$configFile = getenv('RLCD_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fail(500, 'config.php missing: copy config.example.php and fill it in');
}
$config = require $configFile;
$dataDir = rtrim($config['data_dir'] ?? (__DIR__ . '/data'), '/');
validateConfig($config);

$action = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ("$method $action") {
    case 'GET poll':
        auth('device');
        handlePoll();
        break;
    case 'GET wallpaper':
        auth('device', 'user');
        handleGetWallpaper();
        break;
    case 'GET me':
        respond(['ok' => true, 'name' => auth('user')]);
        break;
    case 'POST message':
        handleMessage(auth('user'));
        break;
    case 'POST wallpaper':
        auth('user');
        handlePostWallpaper();
        break;
    case 'POST view':
        auth('user');
        handleView();
        break;
    case 'POST settings':
        auth('user');
        handleSettings();
        break;
    case 'POST session':
        auth('hook');
        handleSession();
        break;
    case 'POST usage':
        auth('hook');
        handleUsage();
        break;
    case 'POST notify':
        auth('user');
        handleNotify();
        break;
    case 'GET state':
        auth('user');
        [$s] = withState(fn(array $s) => [$s, false]);
        $s['sessions'] = sessionList($s);
        $s['usage'] = usageOf($s);
        $s['settings'] = settingsOf($s);
        $s['sounds'] = ['builtin' => SOUND_BUILTIN, 'library' => $s['sound_library']];
        unset($s['sound_library'], $s['sound_seq']);
        respond($s);
        break;
    case 'GET sound':
        auth('device', 'user');
        handleGetSound();
        break;
    case 'POST sound':
        handlePostSound(auth('user'));
        break;
    case 'POST sound_delete':
        auth('user');
        handleDeleteSound();
        break;
    case 'GET sound_source':
        auth('user');
        handleSoundSource();
        break;
    default:
        fail(404, 'unknown action');
}

// ---------- handlers ----------

function handlePoll(): void
{
    $clientRev = filter_var($_GET['rev'] ?? -1, FILTER_VALIDATE_INT, ['options' => ['default' => -1]]);
    $mode = in_array($_GET['mode'] ?? '', MODES, true) ? $_GET['mode'] : null;
    $bat = filter_var($_GET['bat'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6000]]);

    [$state] = withState(function (array $s) use ($mode, $bat) {
        $s['device']['last_seen'] = time();
        if ($mode !== null) {
            $s['device']['mode'] = $mode;
        }
        if ($bat !== false) {
            $s['device']['bat_mv'] = $bat;
        }
        return [$s, true]; // device report does not bump rev
    });

    $out = [
        'rev' => $state['rev'],
        'time' => time(),
        'changed' => $clientRev !== $state['rev'],
        'weather' => weather(),
        'settings' => settingsOf($state), // always sent: tiny, and the board then never misses a change
        'usage' => usageOf($state, true), // the same: a reading does not bump rev, the board shows it with the next poll
    ];
    if ($out['changed']) {
        $out['view'] = $state['view'];
        $out['view_seq'] = $state['view_seq'];
        $out['message'] = $state['message'];
        $out['wallpaper_rev'] = $state['wallpaper_rev'];
        $out['sessions'] = sessionList($state);
        $out['notify'] = $state['notify'];
        $out['ev'] = $state['ev'];
    }
    respond($out);
}

function handleGetWallpaper(): void
{
    global $dataDir;
    $file = "$dataDir/wallpaper.bin";
    if (!is_file($file) || filesize($file) !== WALLPAPER_BYTES) {
        fail(404, 'no wallpaper');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . WALLPAPER_BYTES);
    readfile($file);
    exit;
}

function handleMessage(string $from): void
{
    $body = jsonBody();
    $text = $body['text'] ?? null;
    $sound = $body['sound'] ?? 'none';
    if (!is_string($text) || !mb_check_encoding($text, 'UTF-8')) {
        fail(400, 'text must be a UTF-8 string');
    }
    $text = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text));
    $len = mb_strlen($text, 'UTF-8');
    if ($len < 1 || $len > MESSAGE_MAX_CHARS) {
        fail(400, 'text must have 1-' . MESSAGE_MAX_CHARS . ' chars');
    }
    $allowed = true;
    [$s] = withState(function (array $s) use ($text, $sound, $from, &$allowed) {
        if (!soundAllowed($sound, $s['sound_library'])) { // checked under the lock: a sound may be deleted meanwhile
            $allowed = false;
            return [$s, false];
        }
        $id = ($s['message']['id'] ?? 0) + 1;
        $s['message'] = ['id' => $id, 'text' => $text, 'from' => $from, 'sound' => $sound, 'ts' => time()];
        return [bump(setView($s, 'message')), true];
    });
    if (!$allowed) {
        fail(400, 'sound must be a built-in tone (' . implode(', ', SOUND_BUILTIN) . ') or an id from the sound library');
    }
    respond(['ok' => true, 'rev' => $s['rev'], 'id' => $s['message']['id']]);
}

function handlePostWallpaper(): void
{
    global $dataDir;
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) !== WALLPAPER_BYTES) {
        fail(400, 'wallpaper must be exactly ' . WALLPAPER_BYTES . ' bytes');
    }
    [$s] = withState(function (array $s) use ($raw, $dataDir) {
        atomicWrite("$dataDir/wallpaper.bin", $raw);
        $s['wallpaper_rev']++;
        return [bump(setView($s, 'wallpaper')), true];
    });
    respond(['ok' => true, 'rev' => $s['rev'], 'wallpaper_rev' => $s['wallpaper_rev']]);
}

function handleView(): void
{
    $view = jsonBody()['view'] ?? null;
    if (!in_array($view, VIEWS, true)) {
        fail(400, 'view must be one of: ' . implode(', ', VIEWS));
    }
    [$s] = withState(fn(array $s) => [bump(setView($s, $view)), true]);
    respond(['ok' => true, 'rev' => $s['rev']]);
}

/**
 * Partial update: {lang?, poll_s?}. poll_s null goes back to the default interval, a number is the user's own.
 * Every given field must be valid, or nothing is saved.
 */
function handleSettings(): void
{
    $body = jsonBody();
    $new = [];
    if (array_key_exists('lang', $body)) {
        if (!in_array($body['lang'], LANGS, true)) {
            fail(400, 'lang must be one of: ' . implode(', ', LANGS));
        }
        $new['lang'] = $body['lang'];
    }
    if (array_key_exists('poll_s', $body)) {
        $poll = $body['poll_s'];
        if ($poll !== null && !pollPicked($poll)) { // strict: "30", 30.0 and true are refused
            fail(400, 'poll_s must be null (default) or whole seconds from ' . POLL_MIN_S . ' to ' . POLL_MAX_S);
        }
        $new['poll_s'] = $poll === POLL_DEFAULT_S ? null : $poll; // the default typed in by hand is still the default
    }
    if ($new === []) {
        fail(400, 'settings need lang or poll_s');
    }
    [$s] = withState(function (array $s) use ($new) {
        $old = storedSettings($s);
        $merged = array_replace($old, $new);
        if ($merged === $old) {
            return [$s, false]; // nothing changed: no rev bump, the board has nothing to fetch
        }
        $s['settings'] = $merged;
        return [bump($s), true];
    });
    respond(['ok' => true, 'rev' => $s['rev'], 'settings' => settingsOf($s)]);
}

function handleSession(): void
{
    $body = jsonBody();
    $alias = $body['alias'] ?? null;
    $sid = $body['sid'] ?? null;
    $st = $body['state'] ?? null;
    if (!is_string($alias) || !preg_match('/^[a-z0-9-]{1,24}$/D', $alias)) {
        fail(400, 'alias must match [a-z0-9-]{1,24}');
    }
    if ($sid !== null && (!is_string($sid) || !preg_match('/^[a-z0-9]{8}$/D', $sid))) {
        fail(400, 'sid must match [a-z0-9]{8}');
    }
    if (!in_array($st, SESSION_STATES, true)) {
        fail(400, 'state must be one of: ' . implode(', ', SESSION_STATES));
    }
    $title = sessionTitle($body['title'] ?? null);
    $extra = sessionExtras($body);
    // One row per Claude Code session (sid); a hook without sid keeps one row per project (alias).
    // ":" never occurs in an alias, so the two kinds of keys cannot collide.
    $key = $sid !== null ? "s:$sid" : $alias;
    [$s] = withState(function (array $s) use ($key, $sid, $alias, $title, $st, $extra) {
        $before = json_encode(sessionList($s, false));
        $evBefore = $s['ev'];
        $live = pruneSessions($s['sessions']); // a row past its 12 h counts as gone, so its next report starts fresh
        $was = $live[$key]['state'] ?? ($sid !== null ? ($live[$alias]['state'] ?? null) : null);
        if ($sid !== null) {
            unset($s['sessions'][$alias]); // the project's row from before the hook sent sid
        }
        if ($st === 'end') {
            unset($s['sessions'][$key]);
        } else {
            $old = $live[$key] ?? [];
            $row = ['alias' => $alias, 'title' => $title, 'state' => $st, 'ts' => time()] + $extra;
            if (isset($extra['turns'])) { // a new turn starts when the count grows; the server's clock stamps it
                $row['turn_ts'] = ($extra['turns'] > ($old['turns'] ?? 0) || !isset($old['turn_ts'])) ? time() : $old['turn_ts'];
            }
            foreach (['model', 'ctx'] as $k) { // these come from the status line, not from this report
                if (isset($old[$k])) {
                    $row[$k] = $old[$k];
                }
            }
            $s['sessions'][$key] = $row;
        }
        $s['sessions'] = array_slice(pruneSessions($s['sessions']), 0, SESSION_MAX, true);
        $s['ev'] = countEvents($s['ev'], (string)$key, $was, $st, $s['sessions']);
        $after = json_encode(sessionList($s, false));
        return [$after !== $before || $s['ev'] !== $evBefore ? bump($s) : $s, true];
    });
    respond(['ok' => true, 'rev' => $s['rev']]);
}

/**
 * The optional numbers and kinds of a session report; anything absent is simply left out (fail-closed:
 * no "0/0", no guessed kind). A present field of the wrong type refuses the whole report.
 * turns, agents: counts. kind: what a waiting session waits for. tasks: {d, t}, done of total.
 */
function sessionExtras(array $body): array
{
    $out = [];
    foreach (['turns' => SESSION_COUNT_MAX, 'agents' => 99] as $k => $max) {
        if (array_key_exists($k, $body)) {
            if (!is_int($body[$k]) || $body[$k] < 0 || $body[$k] > $max) {
                fail(400, "$k must be a whole number from 0 to $max");
            }
            $out[$k] = $body[$k];
        }
    }
    if (array_key_exists('kind', $body)) {
        if (!in_array($body['kind'], SESSION_KINDS, true)) {
            fail(400, 'kind must be one of: ' . implode(', ', SESSION_KINDS));
        }
        $out['kind'] = $body['kind'];
    }
    if (array_key_exists('tasks', $body)) {
        $t = $body['tasks'];
        if (!is_array($t) || !is_int($t['d'] ?? null) || !is_int($t['t'] ?? null) || $t['t'] < 1 || $t['t'] > TASKS_MAX
            || $t['d'] < 0 || $t['d'] > $t['t']) {
            fail(400, 'tasks must be {d, t}: whole numbers, 0 <= d <= t <= ' . TASKS_MAX . ', t >= 1');
        }
        $out['tasks'] = ['d' => $t['d'], 't' => $t['t']];
    }
    return $out;
}

/**
 * Plan limits, one reading for the whole account: {five: {p, r}, week: {p, r}} (percent used, epoch seconds
 * of the reset), written by the status line script. The newest report wins; the server's clock stamps it.
 * Also {hit: true} from the StopFailure hook ("rate_limit"), and, with a sid, model and ctx (percent of the
 * context window) for that session's row. Does not bump rev: the board reads usage from every poll.
 */
function handleUsage(): void
{
    $body = jsonBody();
    $now = time();
    $reading = [];
    foreach (array_keys(USAGE_WINDOWS) as $w) {
        if (!array_key_exists($w, $body)) {
            continue;
        }
        $x = $body[$w];
        if (!is_array($x) || !is_int($x['p'] ?? null) && !is_float($x['p'] ?? null) || !is_int($x['r'] ?? null)
            || $x['p'] < 0 || $x['p'] > 100 || $x['r'] < $now - 86400 || $x['r'] > $now + USAGE_WINDOWS[$w] + 3600) {
            fail(400, "$w must be {p: percent 0-100, r: reset time in epoch seconds within its window}");
        }
        $reading[$w] = ['p' => (int)round($x['p']), 'r' => $x['r']];
    }
    $hit = $body['hit'] ?? null;
    if ($hit !== null && $hit !== true) {
        fail(400, 'hit must be true');
    }
    $sid = $body['sid'] ?? null;
    $model = $body['model'] ?? null;
    $ctx = $body['ctx'] ?? null;
    if ($sid !== null && (!is_string($sid) || !preg_match('/^[a-z0-9]{8}$/D', $sid))) {
        fail(400, 'sid must match [a-z0-9]{8}');
    }
    if ($model !== null && (!is_string($model) || !preg_match('/^[A-Za-z0-9 ._-]{1,' . USAGE_MODEL_MAX . '}$/D', $model))) {
        fail(400, 'model must be 1-' . USAGE_MODEL_MAX . ' characters: letters, digits, space . _ -');
    }
    if ($ctx !== null && (!is_int($ctx) || $ctx < 0 || $ctx > 100)) {
        fail(400, 'ctx must be a whole number from 0 to 100');
    }
    if (!$reading && $hit === null && $model === null && $ctx === null) {
        fail(400, 'nothing to report: send five, week, hit, or sid with model or ctx');
    }
    if (($model !== null || $ctx !== null) && $sid === null) {
        fail(400, 'model and ctx need the sid of their session');
    }
    withState(function (array $s) use ($reading, $hit, $sid, $model, $ctx, $now) {
        $u = usageStored($s);
        foreach ($reading as $w => $x) {
            $u[$w] = $x + ['at' => $now]; // each window has its own age: a report may carry only one
        }
        if (isset($reading['five'])) {
            $u['hit_at'] = 0; // a newer reading of the 5 hour window replaces the notice that the limit was hit
        }
        if ($hit === true) {
            $u['hit_at'] = $now;
        }
        $s['usage'] = $u;
        $rev = false;
        $key = $sid !== null ? "s:$sid" : null;
        if ($key !== null && isset($s['sessions'][$key])) { // never creates a row
            foreach (['model' => $model, 'ctx' => $ctx] as $k => $v) {
                if ($v !== null && ($s['sessions'][$key][$k] ?? null) !== $v) {
                    $s['sessions'][$key][$k] = $v;
                    $rev = true;
                }
            }
        }
        return [$rev ? bump($s) : $s, true];
    });
    respond(['ok' => true]);
}

/** The stored reading with every field valid: {five?: {p, r, at}, week?: same, hit_at}. */
function usageStored(array $s): array
{
    $u = is_array($s['usage'] ?? null) ? $s['usage'] : [];
    $out = ['hit_at' => is_int($u['hit_at'] ?? null) ? $u['hit_at'] : 0];
    foreach (array_keys(USAGE_WINDOWS) as $w) {
        $x = $u[$w] ?? null;
        if (is_array($x) && is_int($x['p'] ?? null) && is_int($x['r'] ?? null) && is_int($x['at'] ?? null) && $x['p'] >= 0 && $x['p'] <= 100) {
            $out[$w] = ['p' => $x['p'], 'r' => $x['r'], 'at' => $x['at']];
        }
    }
    return $out;
}

/**
 * Usage as the app and the board get it: only readings that still say something (fail-closed). A reading older
 * than its window is left out; one whose reset time has passed stays, with "expired": true, so the app can say
 * "renewed" instead of a percentage. Every reading carries "left" (seconds to the reset by the server's clock, so a
 * phone with a wrong clock still shows the right time) and "age", seconds since the report.
 * $board=true: the five hour window and the hit flag only, the board shows nothing else.
 */
function usageOf(array $s, bool $board = false): array
{
    $u = usageStored($s);
    $now = time();
    $out = [];
    foreach (USAGE_WINDOWS as $w => $span) {
        if ($board && $w !== 'five') {
            continue;
        }
        if (!isset($u[$w]) || $now - $u[$w]['at'] > $span) {
            continue;
        }
        $out[$w] = ['p' => $u[$w]['p'], 'r' => $u[$w]['r'], 'left' => max(0, $u[$w]['r'] - $now), 'expired' => $u[$w]['r'] <= $now, 'age' => max(0, $now - $u[$w]['at'])];
    }
    // "limit hit" counts until a newer reading of the 5 hour window arrives (handleUsage clears it)
    $out['hit'] = $u['hit_at'] > 0 && $now - $u['hit_at'] < USAGE_WINDOWS['five'];
    return $out;
}

/**
 * Counts the transitions the board turns into notifications (docs/SPEC.md, "API serwera", "Powiadomienia").
 * The board polls every 10 s, so the server counts here and a short "done" between two prompts is not lost.
 * $was is the row's state before this report (null: a new row), $rows the list after it.
 */
function countEvents(array $ev, string $id, ?string $was, string $st, array $rows): array
{
    $active = fn(?string $state): bool => $state === 'working' || $state === 'waiting';
    $now = time();
    if ($st === 'done' && $active($was)) {
        $ev['done']++;
        $ev['done_at'] = $now;
        $ev['done_id'] = $id;
        if (!array_filter($rows, fn(array $x) => $active($x['state'] ?? null))) {
            $ev['all']++; // the last active session finished; "end" and expiry never get here
            $ev['all_at'] = $now;
        }
    }
    if ($st === 'waiting' && $was !== 'waiting') {
        $ev['wait']++;
        $ev['wait_at'] = $now;
        $ev['wait_id'] = $id;
    }
    return $ev;
}

/** One set of notification settings for the whole board; every field is required. */
function handleNotify(): void
{
    $body = jsonBody();
    $mode = $body['mode'] ?? null;
    $scope = $body['scope'] ?? null;
    if (!in_array($mode, NOTIFY_MODES, true)) {
        fail(400, 'mode must be one of: ' . implode(', ', NOTIFY_MODES));
    }
    if (!in_array($scope, NOTIFY_SCOPES, true)) {
        fail(400, 'scope must be one of: ' . implode(', ', NOTIFY_SCOPES));
    }
    $notify = ['mode' => $mode, 'scope' => $scope];
    $soundError = fn(string $kind): string => "$kind.sound must be a built-in tone (" . implode(', ', SOUND_BUILTIN) . ') or an id from the sound library';
    foreach (['done', 'wait'] as $kind) {
        $x = $body[$kind] ?? null;
        if (!is_array($x)) {
            fail(400, "$kind must be an object");
        }
        $sound = $x['sound'] ?? null;
        if (!is_string($sound) || !in_array($sound, SOUND_BUILTIN, true) && !preg_match(SOUND_ID_RE, $sound)) {
            fail(400, $soundError($kind));
        }
        if (!in_array($x['anim'] ?? null, NOTIFY_ANIMS, true)) {
            fail(400, "$kind.anim must be one of: " . implode(', ', NOTIFY_ANIMS));
        }
        $notify[$kind] = ['sound' => $sound, 'anim' => $x['anim']];
    }
    $missing = null;
    [$s] = withState(function (array $s) use ($notify, &$missing) {
        foreach (['done', 'wait'] as $kind) {
            if (!soundAllowed($notify[$kind]['sound'], $s['sound_library'])) { // under the lock: a sound may be deleted meanwhile
                $missing = $kind;
                return [$s, false];
            }
        }
        $s['notify'] = $notify;
        return [bump($s), true]; // the board picks the settings up with the next full poll; the view stays
    });
    if ($missing !== null) {
        fail(400, $soundError($missing));
    }
    respond(['ok' => true, 'rev' => $s['rev']]);
}

/**
 * Session title as the Claude app shows it: valid UTF-8, one line, no control or format characters,
 * at most SESSION_TITLE_MAX characters. Anything else gives "" and the board shows the alias.
 */
function sessionTitle(mixed $title): string
{
    if (!is_string($title) || !mb_check_encoding($title, 'UTF-8')) {
        return '';
    }
    $title = (string)preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\s]+/u', ' ', $title);
    return trim(mb_substr(trim($title), 0, SESSION_TITLE_MAX, 'UTF-8'));
}

// ---------- sound library (data/sounds/<id>.wav, list in state.json) ----------

/** Body: the WAV file itself. Query: name. The PWA converts any audio to the board's format before upload. */
function handlePostSound(string $by): void
{
    global $dataDir;
    $name = soundName($_GET['name'] ?? null);
    if ($name === null) {
        fail(400, 'name must have 1-' . SOUND_NAME_MAX . ' chars');
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > SOUND_UPLOAD_MAX) {
        fail(413, 'file larger than ' . SOUND_UPLOAD_MAX . ' bytes');
    }
    $raw = file_get_contents('php://input', false, null, 0, SOUND_UPLOAD_MAX + 1);
    if ($raw === false || strlen($raw) > SOUND_UPLOAD_MAX) {
        fail(413, 'file larger than ' . SOUND_UPLOAD_MAX . ' bytes');
    }
    $wav = wavCanonical($raw);
    if (!isset($wav['wav'])) {
        fail($wav['code'], $wav['error']);
    }
    $dir = "$dataDir/sounds";
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        fail(500, 'cannot create sounds dir');
    }
    $entry = null;
    withState(function (array $s) use ($name, $by, $wav, $dir, &$entry) {
        if (count($s['sound_library']) >= SOUND_LIBRARY_MAX) {
            return [$s, false];
        }
        $s['sound_seq']++;
        $entry = ['id' => 's' . $s['sound_seq'], 'name' => $name, 'ms' => $wav['ms'], 'bytes' => strlen($wav['wav']), 'by' => $by, 'ts' => time()];
        atomicWrite("$dir/{$entry['id']}.wav", $wav['wav']);
        $s['sound_library'][] = $entry; // no rev bump: the board only fetches a sound when a message names it
        return [$s, true];
    });
    if ($entry === null) {
        fail(409, 'sound library is full (' . SOUND_LIBRARY_MAX . '): delete a sound first');
    }
    respond(['ok' => true] + $entry);
}

function handleGetSound(): void
{
    global $dataDir;
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match(SOUND_ID_RE, $id)) {
        fail(400, 'id must look like s12');
    }
    $file = "$dataDir/sounds/$id.wav";
    if (!is_file($file)) {
        fail(404, 'no such sound');
    }
    header('Content-Type: audio/wav');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

function handleDeleteSound(): void
{
    global $dataDir;
    $id = jsonBody()['id'] ?? null;
    if (!is_string($id) || !preg_match(SOUND_ID_RE, $id)) {
        fail(400, 'id must look like s12');
    }
    $found = false;
    withState(function (array $s) use ($id, $dataDir, &$found) {
        $left = array_values(array_filter($s['sound_library'], fn($x) => $x['id'] !== $id));
        $found = count($left) !== count($s['sound_library']);
        if ($found) {
            @unlink("$dataDir/sounds/$id.wav");
            $s['sound_library'] = $left;
        }
        return [$s, $found];
    });
    if (!$found) {
        fail(404, 'no such sound');
    }
    respond(['ok' => true]);
}

/**
 * Fetches the MP3 behind a myinstants link for the PWA, which converts it in the browser and uploads the WAV.
 * One request per user action, honest User-Agent; if the site refuses, the user uploads the file instead.
 */
function handleSoundSource(): void
{
    $src = myinstantsSource((string)($_GET['url'] ?? ''));
    if ($src === null) {
        fail(400, 'url must be a myinstants.com instant page or a /media/sounds/*.mp3 link');
    }
    if (!function_exists('curl_init')) {
        fail(501, 'cURL is not enabled on this hosting: download the sound and upload the file instead');
    }
    $mp3Url = $src['url'];
    if ($src['kind'] === 'page') {
        $path = myinstantsMp3Path(fetchMyinstants($src['url'], 1_000_000));
        if ($path === null) {
            fail(502, 'no sound found on that page');
        }
        $mp3Url = 'https://www.myinstants.com' . $path;
    }
    $mp3 = fetchMyinstants($mp3Url, 2_000_000);
    if (!isMp3($mp3)) {
        fail(502, 'myinstants did not return an MP3 file');
    }
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($mp3));
    echo $mp3;
    exit;
}

/** GET over https from myinstants only; follows at most 3 redirects, each checked to stay on the site. */
function fetchMyinstants(string $url, int $maxBytes): string
{
    for ($hop = 0; $hop <= 3; $hop++) {
        if (!myinstantsFetchable($url)) {
            fail(502, 'myinstants redirected to another site');
        }
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => FETCH_UA,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0; // aborts the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $done = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = curl_error($ch);
        if ($tooBig) {
            fail(502, "myinstants file is larger than $maxBytes bytes");
        }
        if ($done === false) {
            fail(502, "myinstants did not answer: $error");
        }
        if ($status >= 300 && $status < 400 && $next !== '') {
            $url = $next;
            continue;
        }
        if ($status === 403 || $status === 429) {
            fail(502, "myinstants refused the request (HTTP $status): download the sound and upload the file instead");
        }
        if ($status !== 200) {
            fail(502, "myinstants answered HTTP $status");
        }
        return $body;
    }
    fail(502, 'too many redirects from myinstants');
}

// ---------- weather (Open-Meteo, cached) ----------

function weather(): ?array
{
    global $config, $dataDir;
    $loc = $config['location'] ?? [];
    if (!is_numeric($loc['lat'] ?? null) || !is_numeric($loc['lon'] ?? null)) {
        return null; // fail-closed: no location configured, no weather
    }
    $cache = "$dataDir/weather.json";
    $cached = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    // 'at' is when Open-Meteo was asked: the board shows the age of the reading from it, not from this poll.
    $last = is_array($cached['data'] ?? null) ? $cached['data'] + ['at' => (int)($cached['fetched'] ?? 0)] : null;
    if ($last && time() - $last['at'] < WEATHER_TTL) {
        return $last;
    }
    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => $loc['lat'],
        'longitude' => $loc['lon'],
        'current' => 'temperature_2m,weather_code',
        'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
        'timezone' => 'Europe/Warsaw',
        'forecast_days' => 1,
    ]);
    $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 4]]));
    $j = $raw ? json_decode($raw, true) : null;
    if (!is_array($j) || !isset($j['current']['temperature_2m'])) {
        return $last; // Open-Meteo down: the old reading goes out with its own time, so it cannot pass for current
    }
    $data = [
        'place' => (string)($loc['name'] ?? ''),
        'temp' => round((float)$j['current']['temperature_2m']),
        'code' => (int)$j['current']['weather_code'],
        'max' => round((float)($j['daily']['temperature_2m_max'][0] ?? 0)),
        'min' => round((float)($j['daily']['temperature_2m_min'][0] ?? 0)),
        'rain' => (int)($j['daily']['precipitation_probability_max'][0] ?? 0),
    ];
    $at = time();
    atomicWrite($cache, json_encode(['fetched' => $at, 'data' => $data], JSON_UNESCAPED_UNICODE));
    return $data + ['at' => $at];
}

// ---------- state ----------

function defaultState(): array
{
    return [
        'rev' => 1,
        'view' => 'dashboard',
        'view_seq' => 0,
        'message' => ['id' => 0, 'text' => '', 'from' => '', 'sound' => 'none', 'ts' => 0],
        'wallpaper_rev' => 0,
        'sessions' => [],
        'device' => ['last_seen' => 0, 'mode' => null, 'bat_mv' => null],
        'usage' => [],         // plan limits, see handleUsage(); empty until a status line reports
        'sound_library' => [], // [{id, name, ms, bytes, by, ts}], oldest first
        'sound_seq' => 0,      // ids are never reused, so an old message never plays a newer sound
        'settings' => ['lang' => LANGS[0], 'poll_s' => null], // null: POLL_DEFAULT_S
        'notify' => [          // quiet until someone turns it on in the app
            'mode' => 'quiet',
            'scope' => 'all',
            'done' => ['sound' => 'bell', 'anim' => 'happy'],
            'wait' => ['sound' => 'chime', 'anim' => 'away'],
        ],
        'ev' => [              // event counters, only ever grow; see countEvents()
            'done' => 0, 'done_at' => 0, 'done_id' => '',
            'all' => 0, 'all_at' => 0,
            'wait' => 0, 'wait_at' => 0, 'wait_id' => '',
        ],
    ];
}

/** An interval someone may pick: whole seconds within the bounds, an int and nothing else. */
function pollPicked(mixed $v): bool
{
    return is_int($v) && $v >= POLL_MIN_S && $v <= POLL_MAX_S;
}

/**
 * The stored settings with every field valid; a missing or unknown value falls back to the default.
 * poll_s is null for the default. A stored POLL_DEFAULT_S counts as the default too: state files from
 * before own intervals hold it, and it is the same interval either way.
 */
function storedSettings(array $s): array
{
    $stored = is_array($s['settings'] ?? null) ? $s['settings'] : [];
    $poll = $stored['poll_s'] ?? null;
    return [
        'lang' => in_array($stored['lang'] ?? null, LANGS, true) ? $stored['lang'] : LANGS[0],
        'poll_s' => pollPicked($poll) && $poll !== POLL_DEFAULT_S ? $poll : null,
    ];
}

/**
 * Settings as the board and the app get them. poll_s is always the interval in force (the board reads only
 * lang and poll_s); poll_default lets the app name the default, and poll_s !== poll_default means an own one.
 */
function settingsOf(array $s): array
{
    $stored = storedSettings($s);
    return [
        'lang' => $stored['lang'],
        'poll_s' => $stored['poll_s'] ?? POLL_DEFAULT_S,
        'poll_default' => POLL_DEFAULT_S,
    ];
}

/** state.json filled up from the defaults, one level deeper for notify and ev: a file from before notifications has neither. */
function withDefaults(array $decoded): array
{
    $d = defaultState();
    $s = array_replace($d, $decoded);
    foreach (['notify', 'ev'] as $k) {
        $s[$k] = array_replace($d[$k], is_array($s[$k]) ? $s[$k] : []);
    }
    foreach (['done', 'wait'] as $k) {
        $s['notify'][$k] = array_replace($d['notify'][$k], is_array($s['notify'][$k]) ? $s['notify'][$k] : []);
    }
    return $s;
}

/** Runs $fn under an exclusive lock. $fn returns [newState, shouldSave]; returns [state]. */
function withState(callable $fn): array
{
    global $dataDir;
    if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
        fail(500, 'cannot create data dir');
    }
    $lock = fopen("$dataDir/.lock", 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        fail(500, 'cannot lock state');
    }
    try {
        $file = "$dataDir/state.json";
        $state = defaultState();
        if (is_file($file)) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (!is_array($decoded)) {
                fail(500, 'state.json is corrupt');
            }
            $state = withDefaults($decoded);
        }
        [$new, $save] = $fn($state);
        if ($save) {
            atomicWrite($file, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        return [$new];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function bump(array $s): array
{
    $s['rev']++;
    return $s;
}

/** Every view assignment counts, even of the same view, so the board follows the app after a local button change. */
function setView(array $s, string $view): array
{
    $s['view'] = $view;
    $s['view_seq']++;
    return $s;
}

function pruneSessions(array $sessions): array
{
    $now = time();
    $sessions = array_filter($sessions, fn($x) => $now - ($x['ts'] ?? 0) < SESSION_TTL);
    uasort($sessions, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $sessions;
}

/** With $age=false the list leaves out the age, so comparing two lists shows only changes worth a new rev. */
function sessionList(array $s, bool $age = true): array
{
    $now = time();
    $out = [];
    foreach (array_slice(pruneSessions($s['sessions']), 0, SESSION_MAX, true) as $key => $x) {
        $row = [
            'id' => (string)$key,
            'alias' => (string)($x['alias'] ?? $key), // rows saved before titles existed are keyed by alias
            'title' => (string)($x['title'] ?? ''),
            'state' => $x['state'],
        ];
        // numbers and kinds from the hook; a field the hook never sent stays out (no "0/0", no guessed kind)
        foreach (['turns', 'agents', 'kind', 'tasks', 'model', 'ctx'] as $k) {
            if (isset($x[$k])) {
                $row[$k] = $x[$k];
            }
        }
        if (isset($x['turn_ts']) && $x['state'] === 'working') {
            $row['turn_age'] = max(0, $now - $x['turn_ts']);
        }
        if (!$age) {
            unset($row['turn_age']); // it moves every second and must not bump rev
        }
        $out[] = $age ? $row + ['age' => $now - $x['ts']] : $row;
    }
    return $out;
}

function atomicWrite(string $path, string $content): void
{
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        fail(500, 'write failed');
    }
}

// ---------- auth & http ----------

function validateConfig(array $config): void
{
    foreach (['device', 'hook'] as $role) {
        if (strlen((string)($config['tokens'][$role] ?? '')) < 24) {
            fail(500, "token '$role' missing or shorter than 24 chars");
        }
    }
    $users = $config['users'] ?? [];
    if (!is_array($users) || $users === []) {
        fail(500, 'config needs at least one entry in users');
    }
    foreach ($users as $u) {
        if (strlen((string)($u['token'] ?? '')) < 24 || trim((string)($u['name'] ?? '')) === '') {
            fail(500, 'each user needs a name and a token of 24+ chars');
        }
    }
    $lockError = applockConfigError($config);
    if ($lockError !== null) {
        fail(500, $lockError);
    }
}

/** Accepts one of the roles 'device', 'hook', 'user'. Returns the user's name for 'user', else the role. */
function auth(string ...$roles): string
{
    global $config;
    $given = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
    if ($given === '') {
        fail(401, 'bad token');
    }
    foreach ($roles as $role) {
        if ($role === 'user') {
            foreach ($config['users'] as $u) {
                if (hash_equals((string)$u['token'], $given)) {
                    $name = mb_substr(trim((string)$u['name']), 0, 20);
                    deviceLock($name);
                    return $name;
                }
            }
        } elseif (hash_equals((string)$config['tokens'][$role], $given)) {
            return $role;
        }
    }
    fail(401, 'bad token');
}

/**
 * app_lock for a browser with a valid user token (docs/SPEC.md, "Blokada urządzeń"): a known device renews
 * its cookie, a new one takes a free place under the state lock, and without a place the answer is 404.
 * The board, the hook and the terminal are not browsers and never take a place.
 */
function deviceLock(string $name): void
{
    global $config, $dataDir;
    $limit = applockLimit($config);
    if ($limit === 0 || !applockFromBrowser()) {
        return;
    }
    $cookie = applockCookie();
    $devices = applockRead($dataDir);
    if ($devices === null) {
        fail(500, APPLOCK_CORRUPT);
    }
    if ($cookie !== null && applockKnown($devices, $limit, $cookie)) {
        applockSetCookie($cookie);
        return;
    }
    if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
        fail(500, 'cannot create data dir');
    }
    $lock = fopen("$dataDir/.lock", 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        fail(500, 'cannot lock state');
    }
    try {
        // again under the lock: a parallel request may have taken a place meanwhile
        $devices = applockRead($dataDir);
        if ($devices === null) {
            fail(500, APPLOCK_CORRUPT);
        }
        if ($cookie !== null && applockKnown($devices, $limit, $cookie)) {
            applockSetCookie($cookie);
            return;
        }
        // A cookieless request of the same person seconds after a claim is that browser before its cookie
        // arrived: it is served and takes nothing, so one browser never holds two places.
        $last = $devices === [] ? null : $devices[count($devices) - 1];
        $at = is_string($last['added'] ?? null) ? strtotime($last['added']) : false;
        $age = $at === false ? -1 : time() - $at;
        if ($cookie === null && ($last['name'] ?? null) === $name && $age >= 0 && $age < APPLOCK_GRACE) {
            return;
        }
        if (count($devices) >= $limit) {
            fail(404, "app_lock: all $limit device places are taken by other browsers");
        }
        // A well-formed cookie the list does not know (the list was deleted) keeps its value.
        $value = $cookie ?? bin2hex(random_bytes(32));
        $devices[] = ['hash' => applockHash($value), 'name' => $name, 'added' => date('c')];
        $json = json_encode(['devices' => $devices], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            fail(500, 'write failed');
        }
        atomicWrite("$dataDir/" . APPLOCK_FILE, $json . "\n");
        applockSetCookie($value);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 4096) {
        fail(413, 'body too large');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail(400, 'body must be a JSON object');
    }
    return $data;
}

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $code, string $msg): never
{
    respond(['ok' => false, 'error' => $msg], $code);
}
