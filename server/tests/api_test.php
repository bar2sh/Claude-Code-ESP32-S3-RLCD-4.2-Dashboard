<?php
declare(strict_types=1);
// Run: php server/tests/api_test.php   (starts php -S on a random port with a temp config)

$root = dirname(__DIR__);
require "$root/sounds.php"; // pure helpers are also tested directly, without HTTP
$tmp = sys_get_temp_dir() . '/rlcd-test-' . bin2hex(random_bytes(4));
mkdir("$tmp/data", 0700, true);
$T = ['device' => str_repeat('d', 32), 'hook' => str_repeat('h', 32), 'u1' => str_repeat('a', 32), 'u2' => str_repeat('b', 32)];
// The flag file switches the location on, so one server instance covers both weather cases.
file_put_contents("$tmp/config.php", '<?php $c = ' . var_export([
    'tokens' => ['device' => $T['device'], 'hook' => $T['hook']],
    'users' => [['name' => 'Ja', 'token' => $T['u1']], ['name' => 'Ola', 'token' => $T['u2']]],
    'location' => ['name' => '', 'lat' => null, 'lon' => null],
    'data_dir' => "$tmp/data",
], true) . ";\n"
    . 'if (is_file(__DIR__ . "/with-location")) $c["location"] = ["name" => "Dom", "lat" => 52.23, "lon" => 21.01];' . "\n"
    . 'return $c;');

$port = random_int(20000, 40000);
$env = array_merge(getenv(), ['RLCD_CONFIG' => "$tmp/config.php"]);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
register_shutdown_function(function () use ($proc, $tmp) {
    proc_terminate($proc);
    exec('rm -r ' . escapeshellarg($tmp));
});
usleep(400000);

function req(string $method, string $query, ?string $token, ?string $body = null, string $type = 'application/json'): array
{
    global $port;
    $headers = "Content-Type: $type\r\n" . ($token ? "X-Token: $token\r\n" : '');
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => $body ?? '', 'ignore_errors' => true]]);
    $raw = file_get_contents("http://127.0.0.1:$port/api.php?$query", false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', http_get_last_response_headers()[0], $m);
    return [(int)$m[1], json_decode((string)$raw, true), $raw];
}

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? "  ok   " : "  FAIL ") . "$name\n";
    $fails += $ok ? 0 : 1;
}

// auth
check('no token -> 401', req('GET', 'a=poll', null)[0] === 401);
check('user token cannot poll as device', req('GET', 'a=poll', $T['u1'])[0] === 401);
check('device token cannot post message', req('POST', 'a=message', $T['device'], '{"text":"x"}')[0] === 401);
check('hook token cannot set view', req('POST', 'a=view', $T['hook'], '{"view":"message"}')[0] === 401);
check('me returns user name', req('GET', 'a=me', $T['u2'])[1]['name'] === 'Ola');

// poll & rev
[$c, $p] = req('GET', 'a=poll&rev=-1&mode=listen&bat=3900', $T['device']);
check('first poll changed with dashboard view', $c === 200 && $p['changed'] === true && $p['view'] === 'dashboard');
$rev = $p['rev'];
check('poll with current rev unchanged and no payload', ($r = req('GET', "a=poll&rev=$rev", $T['device'])[1])['changed'] === false && !isset($r['view']));
check('weather null without location', array_key_exists('weather', $r) && $r['weather'] === null);
// A cached reading (fresh here, so nothing goes to Open-Meteo) must say when it was read: the server also
// sends the old reading while Open-Meteo is down, and the board shows the age from this time.
touch("$tmp/with-location");
$readAt = time() - 600;
file_put_contents("$tmp/data/weather.json", json_encode(['fetched' => $readAt,
    'data' => ['place' => 'Dom', 'temp' => 7, 'code' => 3, 'max' => 9, 'min' => 2, 'rain' => 40]]));
$w = req('GET', "a=poll&rev=$rev", $T['device'])[1]['weather'] ?? null;
check('cached weather carries the time it was read', is_array($w) && ($w['at'] ?? null) === $readAt && $w['temp'] === 7);
unlink("$tmp/with-location");

// message
[$c, $m] = req('POST', 'a=message', $T['u2'], json_encode(['text' => "Obiad gotowy! Zażółć gęślą jaźń", 'sound' => 'dinner']));
check('message accepted', $c === 200 && $m['rev'] === $rev + 1 && $m['id'] === 1);
$p = req('GET', "a=poll&rev=$rev", $T['device'])[1];
check('message reaches device with sender, sound and UTF-8', $p['changed'] && $p['view'] === 'message'
    && $p['message']['from'] === 'Ola' && $p['message']['sound'] === 'dinner' && str_contains($p['message']['text'], 'gęślą'));
check('empty message rejected', req('POST', 'a=message', $T['u1'], '{"text":"   "}')[0] === 400);
check('too long message rejected', req('POST', 'a=message', $T['u1'], json_encode(['text' => str_repeat('ż', 281)]))[0] === 400);
check('280 chars accepted', req('POST', 'a=message', $T['u1'], json_encode(['text' => str_repeat('ż', 280)]))[0] === 200);
check('unknown sound rejected', req('POST', 'a=message', $T['u1'], '{"text":"x","sound":"boom"}')[0] === 400);
check('non-JSON body rejected', req('POST', 'a=message', $T['u1'], 'text=x')[0] === 400);
check('control chars stripped', (function () use ($T) {
    req('POST', 'a=message', $T['u1'], json_encode(['text' => "a\x07b\nc"]));
    return req('GET', 'a=state', $T['u1'])[1]['message']['text'] === "ab\nc";
})());

// view
check('view set', req('POST', 'a=view', $T['u1'], '{"view":"sessions"}')[0] === 200);
check('unknown view rejected', req('POST', 'a=view', $T['u1'], '{"view":"games"}')[0] === 400);
$seq = req('GET', 'a=state', $T['u1'])[1]['view_seq'];
$rev = req('GET', 'a=state', $T['u1'])[1]['rev'];
req('POST', 'a=view', $T['u1'], '{"view":"sessions"}');
$p = req('GET', "a=poll&rev=$rev", $T['device'])[1];
check('same view set again reaches device with new view_seq', $p['changed'] && $p['view'] === 'sessions' && $p['view_seq'] === $seq + 1);

// wallpaper
check('no wallpaper -> 404', req('GET', 'a=wallpaper', $T['device'])[0] === 404);
check('wrong size wallpaper rejected', req('POST', 'a=wallpaper', $T['u1'], str_repeat("\xff", 14999), 'application/octet-stream')[0] === 400);
$img = random_bytes(15000);
[$c, $w] = req('POST', 'a=wallpaper', $T['u1'], $img, 'application/octet-stream');
check('wallpaper stored', $c === 200 && $w['wallpaper_rev'] === 1);
check('device downloads identical bytes', req('GET', 'a=wallpaper', $T['device'])[2] === $img);

// sessions
$before = req('GET', 'a=state', $T['u1'])[1]['rev'];
check('session working', req('POST', 'a=session', $T['hook'], '{"alias":"tablica","state":"working"}')[0] === 200);
check('same state again does not bump rev', req('POST', 'a=session', $T['hook'], '{"alias":"tablica","state":"working"}')[1]['rev'] === $before + 1);
check('state change bumps rev', req('POST', 'a=session', $T['hook'], '{"alias":"tablica","state":"waiting"}')[1]['rev'] === $before + 2);
check('bad alias rejected (path-like)', req('POST', 'a=session', $T['hook'], '{"alias":"Users/x","state":"working"}')[0] === 400);
check('alias with trailing newline rejected', req('POST', 'a=session', $T['hook'], '{"alias":"ab\n","state":"working"}')[0] === 400);
check('bad state rejected', req('POST', 'a=session', $T['hook'], '{"alias":"a","state":"sleep"}')[0] === 400);

// one row per session (sid) with its title from the Claude app
$sess = fn() => array_column(req('GET', 'a=state', $T['u1'])[1]['sessions'], null, 'id');
check('bad sid rejected', req('POST', 'a=session', $T['hook'], '{"alias":"tablica","sid":"../x","state":"working"}')[0] === 400);
$before = req('GET', 'a=state', $T['u1'])[1]['rev'];
req('POST', 'a=session', $T['hook'], json_encode(['alias' => 'tablica', 'sid' => 'aaaa1111', 'title' => 'Waveshare ESP32-S3-RLCD-4.2 możliwości', 'state' => 'working'], JSON_UNESCAPED_UNICODE));
req('POST', 'a=session', $T['hook'], json_encode(['alias' => 'tablica', 'sid' => 'bbbb2222', 'title' => 'Tytuły sesji', 'state' => 'waiting']));
$s = $sess();
check('two sessions of one project get two rows', ($s['s:aaaa1111']['state'] ?? '') === 'working' && ($s['s:bbbb2222']['state'] ?? '') === 'waiting'
    && $s['s:aaaa1111']['alias'] === 'tablica');
check('title keeps Polish letters', $s['s:aaaa1111']['title'] === 'Waveshare ESP32-S3-RLCD-4.2 możliwości');
check('the old per-project row goes once the hook sends sid', !isset($s['tablica']));
check('poll carries id and title', in_array('Tytuły sesji', array_column(req('GET', 'a=poll&rev=-1', $T['device'])[1]['sessions'], 'title'), true));
$r = req('POST', 'a=session', $T['hook'], '{"alias":"tablica","sid":"bbbb2222","title":"Nowy tytuł","state":"waiting"}')[1]['rev'];
check('title change alone bumps rev', $r === $before + 3 && $sess()['s:bbbb2222']['title'] === 'Nowy tytuł');
check('same title and state again does not bump rev', req('POST', 'a=session', $T['hook'], '{"alias":"tablica","sid":"bbbb2222","title":"Nowy tytuł","state":"waiting"}')[1]['rev'] === $r);
req('POST', 'a=session', $T['hook'], json_encode(['alias' => 'tablica', 'sid' => 'cccc3333', 'title' => "  zła\x07\n linia\u{202E}" . str_repeat('ż', 80), 'state' => 'done']));
$t = $sess()['s:cccc3333']['title'];
check('title loses control and format chars, one line', !preg_match('/[\p{Cc}\p{Cf}]/u', $t) && str_starts_with($t, 'zła linia ż'));
check('title cut to 60 characters, not bytes', mb_strlen($t, 'UTF-8') === 60 && mb_check_encoding($t, 'UTF-8'));
req('POST', 'a=session', $T['hook'], '{"alias":"tablica","sid":"dddd4444","title":12,"state":"done"}');
check('non-string title becomes empty', $sess()['s:dddd4444']['title'] === '');
check('invalid UTF-8 title is invalid JSON: 400, no row',
    req('POST', 'a=session', $T['hook'], "{\"alias\":\"tablica\",\"sid\":\"eeee5555\",\"title\":\"\xC3\x28\",\"state\":\"done\"}")[0] === 400 && !isset($sess()['s:eeee5555']));
req('POST', 'a=session', $T['hook'], '{"alias":"tablica","sid":"aaaa1111","state":"end"}');
check('end removes only that session', !isset($sess()['s:aaaa1111']) && isset($sess()['s:bbbb2222']));
check('alias s-<8 chars> cannot hit a sid row', req('POST', 'a=session', $T['hook'], '{"alias":"s-bbbb2222","state":"end"}')[0] === 200 && isset($sess()['s:bbbb2222']));
for ($i = 0; $i < 10; $i++) {
    req('POST', 'a=session', $T['hook'], json_encode(['alias' => "p-$i", 'state' => 'working']));
}
check('at most 8 sessions kept', count(req('GET', 'a=state', $T['u1'])[1]['sessions']) === 8);
req('POST', 'a=session', $T['hook'], '{"alias":"p-9","state":"end"}');
check('end removes session', !in_array('p-9', array_column(req('GET', 'a=state', $T['u1'])[1]['sessions'], 'alias'), true));

// device report does not change rev
$r1 = req('GET', 'a=state', $T['u1'])[1];
req('GET', 'a=poll&rev=0&mode=offline&bat=3700', $T['device']);
$r2 = req('GET', 'a=state', $T['u1'])[1];
check('device report stored without rev bump', $r2['rev'] === $r1['rev'] && $r2['device']['mode'] === 'offline' && $r2['device']['bat_mv'] === 3700);

// sounds: synthetic WAV fixtures only, nothing is fetched from myinstants
function wavFile(string $pcm, int $rate = 16000, int $channels = 1, int $bits = 16, string $extraChunk = '', int $format = 1): string
{
    $block = intdiv($channels * $bits, 8);
    $fmt = $format === 0xFFFE
        ? 'fmt ' . pack('VvvVVvvvvV', 40, 0xFFFE, $channels, $rate, $rate * $block, $block, $bits, 22, $bits, 4)
            . pack('v', 1) . "\x00\x00\x00\x00\x10\x00\x80\x00\x00\xAA\x00\x38\x9B\x71" // KSDATAFORMAT_SUBTYPE_PCM
        : 'fmt ' . pack('VvvVVvv', 16, $format, $channels, $rate, $rate * $block, $block, $bits);
    $body = 'WAVE' . $fmt . $extraChunk . 'data' . pack('V', strlen($pcm)) . $pcm;
    return 'RIFF' . pack('V', strlen($body)) . $body;
}
function upload(string $name, string $wav, string $token): array
{
    return req('POST', 'a=sound&name=' . rawurlencode($name), $token, $wav, 'audio/wav');
}
function library(string $token): array
{
    return req('GET', 'a=state', $token)[1]['sounds']['library'];
}

$pcm = random_bytes(32000); // 1 s
[$c, $e] = upload('Gadu-gadu', wavFile($pcm), $T['u2']);
check('WAV uploaded as s1 with duration', $c === 200 && $e['id'] === 's1' && $e['ms'] === 1000 && $e['by'] === 'Ola');
$st = req('GET', 'a=state', $T['u1'])[1];
check('state lists built-ins and the library, no internal keys', $st['sounds']['builtin'] === SOUND_BUILTIN
    && $st['sounds']['library'][0]['name'] === 'Gadu-gadu' && $st['sounds']['library'][0]['bytes'] === 32044
    && !isset($st['sound_library']) && !isset($st['sound_seq']));
check('device downloads canonical WAV', req('GET', 'a=sound&id=s1', $T['device'])[2] === wavFile($pcm));
$list = 'LIST' . pack('V', 7) . "INFOabc\x00"; // odd chunk size plus pad byte
[$c, $e] = upload('Bajo jajo', wavFile($pcm, extraChunk: $list), $T['u1']);
check('extra LIST chunk dropped, served with the plain 44-byte header', $c === 200
    && req('GET', "a=sound&id={$e['id']}", $T['u1'])[2] === wavFile($pcm));
check('WAVE_FORMAT_EXTENSIBLE PCM accepted', upload('Ext', wavFile($pcm, format: 0xFFFE), $T['u1'])[0] === 200);
check('exactly 15 s accepted', upload('Długi', wavFile(str_repeat("\x01\x00", 240000)), $T['u1'])[1]['ms'] === 15000);
check('over 15 s -> 413', upload('Za długi', wavFile(str_repeat("\x01\x00", 240001)), $T['u1'])[0] === 413);
check('stereo -> 400', upload('x', wavFile($pcm, channels: 2), $T['u1'])[0] === 400);
check('44.1 kHz -> 400', upload('x', wavFile($pcm, rate: 44100), $T['u1'])[0] === 400);
check('8-bit -> 400', upload('x', wavFile($pcm, bits: 8), $T['u1'])[0] === 400);
check('float format -> 400', upload('x', wavFile($pcm, format: 3), $T['u1'])[0] === 400);
check('not a WAV -> 400', upload('x', random_bytes(2000), $T['u1'])[0] === 400);
check('truncated data chunk -> 400', upload('x', substr(wavFile($pcm), 0, 20000), $T['u1'])[0] === 400);
check('file over upload cap -> 413', upload('x', wavFile(random_bytes(SOUND_UPLOAD_MAX)), $T['u1'])[0] === 413);
check('empty name -> 400', upload('   ', wavFile($pcm), $T['u1'])[0] === 400);
check('name over 24 chars -> 400', upload(str_repeat('ż', 25), wavFile($pcm), $T['u1'])[0] === 400);
check('name whitespace collapsed, control chars removed', upload("  Obiad\t\tgotowy\x07 ", wavFile($pcm), $T['u1'])[1]['name'] === 'Obiad gotowy');
check('device token cannot upload', upload('x', wavFile($pcm), $T['device'])[0] === 401);
check('hook token cannot download', req('GET', 'a=sound&id=s1', $T['hook'])[0] === 401);
check('unknown id -> 404', req('GET', 'a=sound&id=s999', $T['device'])[0] === 404);
check('path-like id -> 400', req('GET', 'a=sound&id=' . rawurlencode('../state'), $T['device'])[0] === 400);
check('id with trailing newline -> 400', req('GET', 'a=sound&id=s1%0A', $T['device'])[0] === 400);
check('upload does not bump rev', (function () use ($T, $pcm) {
    $r = req('GET', 'a=state', $T['u1'])[1]['rev'];
    upload('rev', wavFile($pcm), $T['u1']);
    return req('GET', 'a=state', $T['u1'])[1]['rev'] === $r;
})());

// message with a library sound
$rev = req('GET', 'a=state', $T['u1'])[1]['rev'];
check('message with library sound accepted', req('POST', 'a=message', $T['u1'], '{"text":"Obiad!","sound":"s1"}')[0] === 200);
check('device sees the library id', req('GET', "a=poll&rev=$rev", $T['device'])[1]['message']['sound'] === 's1');
check('message with unknown library id rejected', req('POST', 'a=message', $T['u1'], '{"text":"x","sound":"s999"}')[0] === 400);
check('message with built-in tone still accepted', req('POST', 'a=message', $T['u1'], '{"text":"x","sound":"chime"}')[0] === 200);
check('message with non-string sound rejected', req('POST', 'a=message', $T['u1'], '{"text":"x","sound":1}')[0] === 400);

// delete
check('delete sound', req('POST', 'a=sound_delete', $T['u1'], '{"id":"s1"}')[0] === 200);
check('deleted sound -> 404 and gone from the library', req('GET', 'a=sound&id=s1', $T['device'])[0] === 404
    && !in_array('s1', array_column(library($T['u1']), 'id'), true));
check('delete again -> 404', req('POST', 'a=sound_delete', $T['u1'], '{"id":"s1"}')[0] === 404);
check('deleted sound no longer allowed in a message', req('POST', 'a=message', $T['u1'], '{"text":"x","sound":"s1"}')[0] === 400);
check('ids are not reused after delete', upload('Nowy', wavFile($pcm), $T['u1'])[1]['id'] !== 's1');

// library cap
$tiny = wavFile(str_repeat("\x00\x00", 160));
for ($i = count(library($T['u1'])); $i < SOUND_LIBRARY_MAX; $i++) {
    upload("t$i", $tiny, $T['u1']);
}
check('library holds 50 sounds', count(library($T['u1'])) === SOUND_LIBRARY_MAX);
check('51st sound -> 409', upload('full', $tiny, $T['u1'])[0] === 409);

// myinstants proxy: only validation is exercised over HTTP, never a live fetch
check('sound_source rejects other sites before any fetch', req('GET', 'a=sound_source&url=' . rawurlencode('https://example.com/a.mp3'), $T['u1'])[0] === 400);
check('sound_source without url -> 400', req('GET', 'a=sound_source', $T['u1'])[0] === 400);
check('device token cannot use sound_source', req('GET', 'a=sound_source&url=x', $T['device'])[0] === 401);
$gg = 'https://www.myinstants.com/en/instant/gadu-gadu-powiadomienie-84239/';
check('instant page link accepted as is', myinstantsSource($gg) === ['kind' => 'page', 'url' => $gg]);
check('bare host, no slash, query dropped -> canonical page', myinstantsSource('http://myinstants.com/instant/bajo-jajo-47307?utm=x')
    === ['kind' => 'page', 'url' => 'https://www.myinstants.com/instant/bajo-jajo-47307/']);
check('direct mp3 link accepted', myinstantsSource('https://www.myinstants.com/media/sounds/gg.mp3')['kind'] === 'mp3');
$bad = [
    'https://www.myinstants.com.evil.test/en/instant/x/', 'https://evil.test/www.myinstants.com/en/instant/x/',
    'https://user@www.myinstants.com/en/instant/x/', 'https://www.myinstants.com:8443/en/instant/x/',
    'ftp://www.myinstants.com/en/instant/x/', 'https://www.myinstants.com/en/search/?name=x',
    'https://www.myinstants.com/media/sounds/../../etc/passwd.mp3', 'https://www.myinstants.com/media/sounds/a.wav',
    'javascript:alert(1)', '',
];
check('foreign, odd or path-traversal links rejected', array_filter($bad, fn($u) => myinstantsSource($u) !== null) === []);
check('redirect targets: only https on myinstants', myinstantsFetchable('https://www.myinstants.com/en/instant/x/')
    && !myinstantsFetchable('http://www.myinstants.com/x') && !myinstantsFetchable('https://www.myinstants.com@evil.test/')
    && !myinstantsFetchable('https://cdn.evil.test/media/sounds/a.mp3') && !myinstantsFetchable('https://www.myinstants.com/x?y=1'));
$html = '<a href="/media/sounds/../x.mp3"></a><button onclick="play(\'/media/sounds/gadu-gadu.mp3\', \'loader-1\', \'gg-1\')">';
check('mp3 path taken from a synthetic instant page, unsafe paths skipped', myinstantsMp3Path($html) === '/media/sounds/gadu-gadu.mp3');
check('page without a sound -> null', myinstantsMp3Path('<html>nothing here</html>') === null);
check('mp3 magic: ID3 tag and frame sync accepted, HTML rejected', isMp3("ID3\x04\x00") && isMp3("\xFF\xFB\x90\x00")
    && !isMp3('<!DOCTYPE html>') && !isMp3(''));

// board settings: language and listen-mode poll interval. Out: poll_s in force plus poll_default;
// stored: poll_s null for the default, whole seconds 5..3600 for an own interval.
$set = fn(string $lang, int $poll) => ['lang' => $lang, 'poll_s' => $poll, 'poll_default' => 10];
$storedPoll = function () use ($tmp) {
    $st = json_decode(file_get_contents("$tmp/data/state.json"), true);
    return is_array($st['settings'] ?? null) && array_key_exists('poll_s', $st['settings']) ? $st['settings']['poll_s'] : 'missing';
};
$p = req('GET', 'a=poll&rev=-1', $T['device'])[1];
check('poll carries default settings: English, 10 s, the default named', $p['settings'] === $set('en', 10));
$rev = $p['rev'];
check('unchanged poll still carries settings', req('GET', "a=poll&rev=$rev", $T['device'])[1]['settings'] === $set('en', 10));
check('device and hook tokens cannot change settings', req('POST', 'a=settings', $T['device'], '{"lang":"pl"}')[0] === 401
    && req('POST', 'a=settings', $T['hook'], '{"lang":"pl"}')[0] === 401);
[$c, $r] = req('POST', 'a=settings', $T['u1'], '{"lang":"pl","poll_s":45}');
check('valid settings saved, rev bumped', $c === 200 && $r['settings'] === $set('pl', 45) && $r['rev'] === $rev + 1);
check('an own interval is stored as its seconds', $storedPoll() === 45);
$rev = $r['rev'];
$p = req('GET', "a=poll&rev=" . ($rev - 1), $T['device'])[1];
check('board sees the change on its next poll', $p['changed'] === true && $p['settings'] === $set('pl', 45));
check('state shows settings to the app', req('GET', 'a=state', $T['u2'])[1]['settings'] === $set('pl', 45));
[$c, $r] = req('POST', 'a=settings', $T['u1'], '{"poll_s":3600}');
check('partial update keeps the other field; 3600 s is accepted', $c === 200 && $r['settings'] === $set('pl', 3600));
[$c, $r] = req('POST', 'a=settings', $T['u1'], '{"poll_s":5}');
check('5 s is accepted', $c === 200 && $r['settings'] === $set('pl', 5));
$rev = $r['rev'];
check('same value again: ok, no rev bump', ($r = req('POST', 'a=settings', $T['u1'], '{"poll_s":5}'))[0] === 200 && $r[1]['rev'] === $rev);
[$c, $r] = req('POST', 'a=settings', $T['u1'], '{"poll_s":null}');
check('null goes back to the default, rev bumped', $c === 200 && $r['settings'] === $set('pl', 10) && $r['rev'] === $rev + 1);
check('the default is stored as null, not as a number', $storedPoll() === null);
$rev = $r['rev'];
[$c, $r] = req('POST', 'a=settings', $T['u1'], '{"poll_s":10}');
check('the default typed in by hand stays the default: no rev bump, stored null', $c === 200 && $r['rev'] === $rev
    && $r['settings'] === $set('pl', 10) && $storedPoll() === null);
check('the default again: ok, no rev bump', ($r = req('POST', 'a=settings', $T['u1'], '{"poll_s":null}'))[0] === 200 && $r[1]['rev'] === $rev);
$rev = req('POST', 'a=settings', $T['u1'], '{"poll_s":900}')[1]['rev'];
$bad = ['{"lang":"de"}', '{"lang":"PL"}', '{"poll_s":4}', '{"poll_s":0}', '{"poll_s":3601}', '{"poll_s":"30"}',
    '{"poll_s":30.0}', '{"poll_s":1e3}', '{"poll_s":true}', '{"poll_s":-10}', '{"poll_s":[30]}',
    '{}', '{"other":1}', '[1]', '{"lang":"en","poll_s":4}', '{"lang":"de","poll_s":30}'];
$codes = array_map(fn($b) => req('POST', 'a=settings', $T['u1'], $b)[0], $bad);
check('invalid settings rejected with 400', $codes === array_fill(0, count($bad), 400));
check('rejected request changes nothing (no partial save)', req('GET', 'a=state', $T['u1'])[1]['settings'] === $set('pl', 900)
    && req('GET', 'a=state', $T['u1'])[1]['rev'] === $rev);
$errs = array_map(fn($b) => req('POST', 'a=settings', $T['u1'], $b)[1]['error'] ?? null,
    ['{"poll_s":4}', '{"poll_s":3601}', '{"poll_s":"30"}', '{"poll_s":30.5}', '{"poll_s":true}']);
check('error names the bounds', $errs === array_fill(0, 5, 'poll_s must be null (default) or whole seconds from 5 to 3600'));
$st = json_decode(file_get_contents("$tmp/data/state.json"), true);
$stored = function (mixed $settings) use ($tmp, $st, $T) {
    $st['settings'] = $settings;
    file_put_contents("$tmp/data/state.json", json_encode($st));
    return req('GET', 'a=poll&rev=-1', $T['device'])[1]['settings'];
};
check('unknown stored values fall back to defaults', $stored(['lang' => 'xx', 'poll_s' => 4]) === $set('en', 10));
check('a stored interval that is not an int is the default', $stored(['lang' => 'pl', 'poll_s' => '300']) === $set('pl', 10)
    && $stored(['lang' => 'pl', 'poll_s' => 30.5]) === $set('pl', 10));
check('stored settings that are not an object -> defaults', $stored('garbage') === $set('en', 10));
check('an interval saved from the old five buttons stays the own one', $stored(['lang' => 'pl', 'poll_s' => 300]) === $set('pl', 300));
$stored(['lang' => 'pl', 'poll_s' => 10]);
$rev = req('GET', 'a=state', $T['u1'])[1]['rev'];
check('a stored 10 from before own intervals is the default: null saves nothing',
    ($r = req('POST', 'a=settings', $T['u1'], '{"poll_s":null}'))[0] === 200 && $r[1]['rev'] === $rev);
unset($st['settings']);
file_put_contents("$tmp/data/state.json", json_encode($st));
check('state from before settings existed -> defaults', req('GET', 'a=state', $T['u1'])[1]['settings'] === $set('en', 10));
// notifications: settings (POST notify) and event counters (ev), docs/SPEC.md "Powiadomienia o sesjach Claude"
$NOTIFY_DEFAULT = ['mode' => 'quiet', 'scope' => 'all', 'done' => ['sound' => 'bell', 'anim' => 'happy'], 'wait' => ['sound' => 'chime', 'anim' => 'away']];
$EV_ZERO = ['done' => 0, 'done_at' => 0, 'done_id' => '', 'all' => 0, 'all_at' => 0, 'wait' => 0, 'wait_at' => 0, 'wait_id' => ''];
$saved = json_decode(file_get_contents("$tmp/data/state.json"), true);
$rewrite = function (array $patch) use ($tmp, $saved): void { // state.json as a server without notifications left it, plus $patch
    $s = $saved;
    unset($s['notify'], $s['ev']);
    file_put_contents("$tmp/data/state.json", json_encode(array_replace($s, ['sessions' => []], $patch)));
};
$rewrite([]);
$st = req('GET', 'a=state', $T['u1'])[1];
check('state.json from before notifications: default settings and zero counters', $st['notify'] === $NOTIFY_DEFAULT && $st['ev'] === $EV_ZERO
    && count($st['sounds']['library']) === SOUND_LIBRARY_MAX);
$rewrite(['notify' => ['mode' => 'each', 'done' => ['sound' => 'dinner']], 'ev' => ['wait' => 4]]);
$st = req('GET', 'a=state', $T['u1'])[1];
check('partial notify and ev filled up from the defaults, field by field', $st['notify'] === ['mode' => 'each', 'scope' => 'all',
    'done' => ['sound' => 'dinner', 'anim' => 'happy'], 'wait' => $NOTIFY_DEFAULT['wait']] && $st['ev'] === array_replace($EV_ZERO, ['wait' => 4]));
$rewrite([]);

$good = ['mode' => 'ready', 'scope' => 'all', 'done' => ['sound' => 'bell', 'anim' => 'happy'], 'wait' => ['sound' => 'chime', 'anim' => 'away']];
$notify = fn(array $body, string $token = '') => req('POST', 'a=notify', $token ?: $T['u1'], json_encode($body));
check('hook and device tokens cannot set notify', $notify($good, $T['hook'])[0] === 401 && $notify($good, $T['device'])[0] === 401);
$tone = 'must be a built-in tone (none, chime, bell, dinner, alert) or an id from the sound library';
$anims = 'must be one of: away, alert, happy, typing, idle, sleep';
$rejected = [ // the expected message => bodies that get it
    'mode must be one of: quiet, ready, each' => [['mode' => 'loud'] + $good, array_diff_key($good, ['mode' => 1]), ['mode' => 'Quiet'] + $good],
    'scope must be one of: sessions, all' => [['scope' => 'board'] + $good, array_diff_key($good, ['scope' => 1])],
    'done must be an object' => [['done' => 'bell'] + $good, array_diff_key($good, ['done' => 1])],
    'wait must be an object' => [['wait' => null] + $good],
    "done.sound $tone" => [['done' => ['sound' => 'gong', 'anim' => 'happy']] + $good, ['done' => ['anim' => 'happy']] + $good,
        ['done' => ['sound' => 7, 'anim' => 'happy']] + $good, ['done' => ['sound' => 's1', 'anim' => 'happy']] + $good], // s1 was deleted above
    "wait.sound $tone" => [['wait' => ['sound' => 's999999', 'anim' => 'away']] + $good],
    "done.anim $anims" => [['done' => ['sound' => 'bell', 'anim' => 'clawd']] + $good, ['done' => ['sound' => 'bell']] + $good],
    "wait.anim $anims" => [['wait' => ['sound' => 'chime', 'anim' => 'RING']] + $good],
];
$wrong = [];
foreach ($rejected as $msg => $bodies) {
    foreach ($bodies as $body) {
        [$c, $r] = $notify($body);
        if ($c !== 400 || ($r['error'] ?? '') !== $msg) {
            $wrong[] = "$msg <- " . json_encode($body) . " got $c " . ($r['error'] ?? '');
        }
    }
}
check('every invalid notify -> 400 with its message' . ($wrong ? ': ' . implode(' | ', $wrong) : ''), $wrong === []);
$v = req('GET', 'a=state', $T['u1'])[1];
check('rejected notify changes nothing', $v['notify'] === $NOTIFY_DEFAULT && $v['rev'] === $st['rev']);

$lib = library($T['u1'])[0]['id']; // a sound from the library
[$c, $r] = $notify(['mode' => 'each', 'scope' => 'sessions', 'extra' => 'x', 'done' => ['sound' => $lib, 'anim' => 'alert', 'x' => 1],
    'wait' => ['sound' => 'none', 'anim' => 'typing']]);
$n = req('GET', 'a=state', $T['u1'])[1];
$want = ['mode' => 'each', 'scope' => 'sessions', 'done' => ['sound' => $lib, 'anim' => 'alert'], 'wait' => ['sound' => 'none', 'anim' => 'typing']];
check('notify saved: ok and rev + 1', $c === 200 && $r === ['ok' => true, 'rev' => $v['rev'] + 1] && $n['rev'] === $v['rev'] + 1);
check('notify stores exactly the four fields; view and view_seq stay', $n['notify'] === $want && $n['view'] === $v['view'] && $n['view_seq'] === $v['view_seq']);
$p = req('GET', 'a=poll&rev=-1', $T['device'])[1];
check('full poll carries notify and ev', $p['notify'] === $want && $p['ev'] === $EV_ZERO);
check('unchanged poll carries neither', ($q = req('GET', "a=poll&rev={$p['rev']}", $T['device'])[1])['changed'] === false && !isset($q['notify']) && !isset($q['ev']));

// counters: what each hook report does to ev (rows of one project, one per Claude Code session)
$t0 = time();
$hook = fn(string $sid, string $state, string $title = '') => req('POST', 'a=session', $T['hook'],
    json_encode(['alias' => 'tablica', 'sid' => $sid, 'title' => $title, 'state' => $state]))[1]['rev'];
$ev = fn() => req('GET', 'a=state', $T['u1'])[1]['ev'];
$hook('aaaa0001', 'done');
check('a new row that is done at once counts nothing', $ev() === $EV_ZERO);
$hook('bbbb0002', 'waiting');
$e = $ev();
check('a new waiting row counts wait', $e['wait'] === 1 && $e['wait_id'] === 's:bbbb0002' && $e['wait_at'] >= $t0 && $e['done'] === 0);
$hook('bbbb0002', 'waiting', 'Nowy tytuł');
check('a title change alone counts nothing', $ev() === $e);
$hook('bbbb0002', 'working', 'Nowy tytuł');
$hook('aaaa0001', 'working');
$r = $hook('aaaa0001', 'done');
$e = $ev();
check('working -> done counts done, not all while another session works', $e['done'] === 1 && $e['done_id'] === 's:aaaa0001' && $e['done_at'] >= $t0
    && $e['all'] === 0 && $e['wait'] === 1);
check('done -> done counts nothing and keeps rev', $hook('aaaa0001', 'done') === $r && $ev() === $e);
$hook('bbbb0002', 'waiting', 'Nowy tytuł');
check('working -> waiting counts wait', ($e = $ev())['wait'] === 2 && $e['wait_id'] === 's:bbbb0002' && $e['done'] === 1);
$hook('bbbb0002', 'done', 'Nowy tytuł');
$e = $ev();
check('waiting -> done of the last active row counts done and all', $e['done'] === 2 && $e['done_id'] === 's:bbbb0002' && $e['all'] === 1 && $e['all_at'] >= $t0);
$hook('aaaa0001', 'working');
$r = req('GET', 'a=state', $T['u1'])[1]['rev'];
check('working -> done of the last active row counts done and all, rev up', $hook('aaaa0001', 'done') === $r + 1
    && ($e = $ev())['done'] === 3 && $e['all'] === 2 && $e['wait'] === 2);
$hook('aaaa0001', 'working');
$hook('aaaa0001', 'end');
check('end of the last active session counts nothing', $ev() === $e && count(req('GET', 'a=state', $T['u1'])[1]['sessions']) === 1);
$rewrite(['sessions' => ['tablica' => ['alias' => 'tablica', 'title' => '', 'state' => 'working', 'ts' => time()]], 'ev' => $e]);
$hook('cccc0003', 'done');
check('the first report with sid takes over the per-project row and its state', ($x = $ev())['done'] === 4 && $x['all'] === 3
    && array_column(req('GET', 'a=state', $T['u1'])[1]['sessions'], 'id') === ['s:cccc0003']);
$rewrite(['sessions' => ['s:dddd0004' => ['alias' => 'tablica', 'title' => '', 'state' => 'working', 'ts' => time() - 13 * 3600]], 'ev' => $x]);
$hook('eeee0005', 'working');
check('a row expiring after 12 h counts nothing', $ev() === $x);
$hook('dddd0004', 'done');
check('the expired row reporting done again is a new row: nothing', $ev() === $x);

// session numbers: turns, subagents, waiting kind, task progress; absent stays absent
$rewrite(['sessions' => []]);
$post = fn(array $b) => req('POST', 'a=session', $T['hook'], json_encode(['alias' => 'tablica', 'sid' => 'ffff0001'] + $b));
$row = fn() => array_column(req('GET', 'a=state', $T['u1'])[1]['sessions'], null, 'id')['s:ffff0001'] ?? [];
$post(['state' => 'working']);
$r = $row();
check('a report without numbers has none (no 0/0, no guessed kind)', !array_key_exists('tasks', $r) && !array_key_exists('turns', $r) && !array_key_exists('kind', $r));
$before = req('GET', 'a=poll&rev=-1', $T['device'])[1]['rev'];
check('numbers are accepted', $post(['state' => 'working', 'turns' => 2, 'agents' => 1, 'tasks' => ['d' => 2, 't' => 5]])[0] === 200);
$r = $row();
check('row carries turns, subagents, tasks and the turn age', $r['turns'] === 2 && $r['agents'] === 1 && $r['tasks'] === ['d' => 2, 't' => 5]
    && isset($r['turn_age']) && $r['turn_age'] >= 0);
check('numbers bump rev', req('GET', 'a=poll&rev=-1', $T['device'])[1]['rev'] === $before + 1);
check('same numbers again do not bump rev', $post(['state' => 'working', 'turns' => 2, 'agents' => 1, 'tasks' => ['d' => 2, 't' => 5]])[1]['rev'] === $before + 1);
$post(['state' => 'working', 'turns' => 2]);
check('a later report without tasks drops them', !isset($row()['tasks']));
$post(['state' => 'waiting', 'turns' => 3, 'kind' => 'question']);
check('waiting kind is kept, a new turn restarts the turn clock', $row()['kind'] === 'question' && $row()['turns'] === 3);
$rewrite(['sessions' => ['s:ffff0001' => ['alias' => 'tablica', 'title' => '', 'state' => 'working', 'ts' => time(), 'turns' => 3, 'turn_ts' => time() - 120]]]);
$post(['state' => 'working', 'turns' => 3]);
check('same turn count keeps the turn clock', $row()['turn_age'] >= 120);
$post(['state' => 'done', 'turns' => 3]);
check('a finished session has no turn age', !isset($row()['turn_age']));
foreach ([
    'negative turns' => ['turns' => -1], 'text turns' => ['turns' => '2'], 'float turns' => ['turns' => 1.5], 'too many agents' => ['agents' => 100],
    'unknown kind' => ['kind' => 'password'], 'tasks 0 of 0' => ['tasks' => ['d' => 0, 't' => 0]], 'more done than total' => ['tasks' => ['d' => 3, 't' => 2]],
    'tasks as text' => ['tasks' => '2/5'], 'tasks with a name' => ['tasks' => ['d' => 1, 't' => 2, 'name' => 'x']] + [],
] as $why => $bad) {
    $code = $post(['state' => 'working'] + $bad)[0];
    check("rejected: $why", $why === 'tasks with a name' ? $code === 200 : $code === 400);
}
check('unknown task fields are not stored', !isset($row()['tasks']['name']));
check('poll carries the task progress to the board', in_array(['d' => 1, 't' => 2], array_column(req('GET', 'a=poll&rev=-1', $T['device'])[1]['sessions'], 'tasks'), true));

// usage: plan limits for the whole account
$usage = fn(array $b, ?string $tok = null) => req('POST', 'a=usage', $tok ?? $T['hook'], json_encode($b));
$now = time();
check('usage needs the hook token', $usage(['five' => ['p' => 1, 'r' => $now + 100]], $T['u1'])[0] === 401 && $usage(['five' => ['p' => 1, 'r' => $now + 100]], $T['device'])[0] === 401);
check('no reading yet: usage has only hit=false', req('GET', 'a=state', $T['u1'])[1]['usage'] === ['hit' => false]);
$rev0 = req('GET', 'a=poll&rev=-1', $T['device'])[1]['rev'];
check('both windows accepted', $usage(['five' => ['p' => 63.4, 'r' => $now + 4800], 'week' => ['p' => 21, 'r' => $now + 3 * 86400]])[0] === 200);
$u = req('GET', 'a=state', $T['u1'])[1]['usage'];
check('app gets both windows, rounded, with age and not expired', $u['five']['p'] === 63 && $u['five']['r'] === $now + 4800 && $u['five']['expired'] === false
    && $u['five']['age'] >= 0 && $u['week']['p'] === 21 && $u['hit'] === false);
$pu = req('GET', "a=poll&rev=$rev0", $T['device'])[1];
check('usage does not bump rev, and the board gets only the 5 hour window', $pu['changed'] === false && isset($pu['usage']['five']) && !isset($pu['usage']['week']));
$usage(['five' => ['p' => 70, 'r' => $now + 4800]]);
$u = req('GET', 'a=state', $T['u1'])[1]['usage'];
check('a report with one window keeps the other', $u['five']['p'] === 70 && $u['week']['p'] === 21);
foreach ([
    'percent over 100' => ['five' => ['p' => 101, 'r' => $now + 100]], 'negative percent' => ['five' => ['p' => -1, 'r' => $now + 100]],
    'percent as text' => ['five' => ['p' => '5', 'r' => $now + 100]], 'reset as text' => ['five' => ['p' => 5, 'r' => 'soon']],
    'reset far in the future' => ['five' => ['p' => 5, 'r' => $now + 3 * 86400]], 'reset long gone' => ['week' => ['p' => 5, 'r' => $now - 3 * 86400]],
    'window is not an object' => ['five' => 5], 'empty report' => [], 'unknown only' => ['x' => 1], 'hit false' => ['hit' => false],
    'model without sid' => ['model' => 'Opus'], 'bad sid' => ['sid' => '../x', 'ctx' => 5], 'ctx over 100' => ['sid' => 'ffff0001', 'ctx' => 101],
    'model with markup' => ['sid' => 'ffff0001', 'model' => '<b>x</b>'],
] as $why => $bad) {
    check("usage rejected: $why", $usage($bad)[0] === 400);
}
check('usage unchanged by refused reports', req('GET', 'a=state', $T['u1'])[1]['usage']['five']['p'] === 70);
$post(['state' => 'working', 'turns' => 3]);
$revN = req('GET', 'a=poll&rev=-1', $T['device'])[1]['rev'];
$usage(['sid' => 'ffff0001', 'model' => 'Opus 5.5', 'ctx' => 41]);
check('model and ctx land on the session row and bump rev', $row()['model'] === 'Opus 5.5' && $row()['ctx'] === 41 && req('GET', 'a=poll&rev=-1', $T['device'])[1]['rev'] === $revN + 1);
$usage(['sid' => 'ffff0001', 'model' => 'Opus 5.5', 'ctx' => 41]);
check('the same model and ctx again do not bump rev', req('GET', 'a=poll&rev=-1', $T['device'])[1]['rev'] === $revN + 1);
$post(['state' => 'working', 'turns' => 3]);
check('a session report keeps model and ctx', $row()['model'] === 'Opus 5.5' && $row()['ctx'] === 41);
$usage(['sid' => 'zzzz9999', 'ctx' => 5]);
check('model and ctx of an unknown session create no row', !isset(array_column(req('GET', 'a=state', $T['u1'])[1]['sessions'], null, 'id')['s:zzzz9999']));
$usage(['hit' => true]);
check('limit hit shows until a newer reading', req('GET', 'a=state', $T['u1'])[1]['usage']['hit'] === true && req('GET', 'a=poll&rev=-1', $T['device'])[1]['usage']['hit'] === true);
$usage(['five' => ['p' => 100, 'r' => $now + 3000]]);
check('a newer reading replaces the hit notice', req('GET', 'a=state', $T['u1'])[1]['usage']['hit'] === false);
// fail-closed on old readings: too old for its window it disappears; past its reset it says "expired"
$rewrite(['usage' => ['five' => ['p' => 80, 'r' => $now - 60, 'at' => $now - 3600], 'week' => ['p' => 30, 'r' => $now + 86400, 'at' => $now - 8 * 86400]]]);
$u = req('GET', 'a=state', $T['u1'])[1]['usage'];
check('past its reset: expired flag, percent kept for the app to hide', $u['five']['expired'] === true && $u['five']['age'] >= 3600);
check('older than its window: left out', !isset($u['week']));
$rewrite(['usage' => ['five' => ['p' => 'x', 'r' => 1, 'at' => 1], 'week' => 'junk', 'hit_at' => 'now']]);
check('a damaged usage block gives no reading', req('GET', 'a=state', $T['u1'])[1]['usage'] === ['hit' => false]);

// corrupt state fails closed
file_put_contents("$tmp/data/state.json", '{broken');
check('corrupt state.json -> 500, not reset', req('GET', 'a=state', $T['u1'])[0] === 500 && file_get_contents("$tmp/data/state.json") === '{broken');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
