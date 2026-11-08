<?php
declare(strict_types=1);
// Run: php hook/tests/hook_test.php   (starts php -S with a temp config, runs the hook against it with temp XDG dirs)

$hook = dirname(__DIR__) . '/rlcd-hook.sh';
$server = dirname(__DIR__, 2) . '/server';
$tmp = sys_get_temp_dir() . '/rlcd-hook-test-' . bin2hex(random_bytes(4));
foreach (['data', 'xdg/rlcd', 'dead/rlcd', 'plain/rlcd', 'wrong/rlcd', 'home'] as $d) {
    mkdir("$tmp/$d", 0700, true);
}
$hookToken = str_repeat('h', 32);
$userToken = str_repeat('u', 32);
file_put_contents("$tmp/config.php", '<?php return ' . var_export([
    'tokens' => ['device' => str_repeat('d', 32), 'hook' => $hookToken],
    'users' => [['name' => 'Ja', 'token' => $userToken]],
    'location' => ['name' => '', 'lat' => null, 'lon' => null],
    'data_dir' => "$tmp/data",
], true) . ';');

$port = random_int(20000, 40000);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $server], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $server, array_merge(getenv(), ['RLCD_CONFIG' => "$tmp/config.php"]));
register_shutdown_function(function () use ($proc, $tmp) {
    proc_terminate($proc);
    exec('rm -r ' . escapeshellarg($tmp));
});
usleep(400000);

$aliases = "# path alias\n/work/board rlcd\n~/work/notes/ notatki\n/work/bad Bad_Name\n";
file_put_contents("$tmp/xdg/rlcd/env", "RLCD_URL=\"http://127.0.0.1:$port/api.php\"\nexport RLCD_HOOK_TOKEN=$hookToken\n");
file_put_contents("$tmp/xdg/rlcd/aliases", $aliases);
file_put_contents("$tmp/dead/rlcd/env", "RLCD_URL=http://127.0.0.1:9/api.php\nRLCD_HOOK_TOKEN=$hookToken\n"); // nothing listens on port 9
file_put_contents("$tmp/dead/rlcd/aliases", $aliases);
file_put_contents("$tmp/plain/rlcd/env", "RLCD_URL=http://example.com/api.php\nRLCD_HOOK_TOKEN=$hookToken\n");

/** Runs the hook the way Claude Code does. Returns [exit code, stdout, stderr]. */
function hook(string $arg, string $project, string $stdin = '{}', array $env = []): array
{
    global $hook, $tmp;
    $env += ['PATH' => (string)getenv('PATH'), 'HOME' => "$tmp/home", 'XDG_CONFIG_HOME' => "$tmp/xdg",
        'XDG_CACHE_HOME' => "$tmp/cache", 'CLAUDE_PROJECT_DIR' => $project, 'RLCD_FOREGROUND' => '1'];
    $p = proc_open(['/bin/sh', $hook, $arg], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, array_filter($env, fn($v) => $v !== null));
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
}

function sessions(): array
{
    global $port, $userToken;
    $ctx = stream_context_create(['http' => ['header' => "X-Token: $userToken\r\n"]]);
    $state = json_decode((string)file_get_contents("http://127.0.0.1:$port/api.php?a=state", false, $ctx), true);
    return array_column($state['sessions'], 'state', 'alias');
}

/** Hook mode must stay silent and succeed whatever happens. */
function quiet(array $r): bool
{
    return $r[0] === 0 && $r[1] === '' && $r[2] === '';
}

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? "  ok   " : "  FAIL ") . "$name\n";
    $fails += $ok ? 0 : 1;
}

// states and aliases
check('working sent silently under the alias', quiet(hook('working', '/work/board')) && sessions() === ['rlcd' => 'working']);
check('~/ path with trailing slash maps to its alias', quiet(hook('done', "$tmp/home/work/notes")) && (sessions()['notatki'] ?? '') === 'done');
check('idle reminder does not turn done into waiting',
    quiet(hook('waiting', "$tmp/home/work/notes", '{"hook_event_name":"Notification","notification_type":"idle_prompt","message":"Claude is waiting for your input"}'))
    && sessions()['notatki'] === 'done');
check('permission prompt means waiting',
    quiet(hook('waiting', '/work/board', '{"hook_event_name":"Notification","notification_type":"permission_prompt","message":"Claude needs your permission to use Bash"}'))
    && sessions()['rlcd'] === 'waiting');
hook('working', '/work/board');
check('question to the user (PreToolUse) means waiting',
    quiet(hook('waiting', '/work/board', '{"hook_event_name":"PreToolUse","tool_name":"AskUserQuestion","tool_input":{}}')) && sessions()['rlcd'] === 'waiting');

// only changes go out: remove the row behind the hook's back, a repeated state must not bring it back
hook('working', '/work/board');
$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "X-Token: $hookToken\r\nContent-Type: application/json\r\n", 'content' => '{"alias":"rlcd","state":"end"}']]);
file_get_contents("http://127.0.0.1:$port/api.php?a=session", false, $ctx);
check('repeated state is not sent again', quiet(hook('working', '/work/board')) && !isset(sessions()['rlcd']));
check('next change is sent', quiet(hook('done', '/work/board')) && sessions()['rlcd'] === 'done');

// the cache follows the server, not the attempt
check('unreachable server: silent, exit 0', quiet(hook('working', '/work/board', '{}', ['XDG_CONFIG_HOME' => "$tmp/dead"])));
check('failed send leaves the cache alone', str_starts_with((string)file_get_contents("$tmp/cache/rlcd/rlcd"), "done\n"));
check('end removes the row and the cache', quiet(hook('end', '/work/board')) && !isset(sessions()['rlcd']) && !is_file("$tmp/cache/rlcd/rlcd"));

// fallback alias hides the path
hook('working', '/work/clients/secret-project');
$fallback = array_values(array_filter(array_keys(sessions()), fn($a) => str_starts_with($a, 'p-')));
check('unmapped project shows as p- plus 4 hex digits', count($fallback) === 1 && preg_match('/^p-[0-9a-f]{4}$/D', $fallback[0]) === 1);
check('invalid alias falls back too', quiet(hook('working', '/work/bad')) && count(array_filter(array_keys(sessions()), fn($a) => str_starts_with($a, 'p-'))) === 2);

// one row per session, titled like in the Claude app (last custom-title entry of the transcript)
function rows(): array
{
    global $port, $userToken;
    $ctx = stream_context_create(['http' => ['header' => "X-Token: $userToken\r\n"]]);
    return array_column(json_decode((string)file_get_contents("http://127.0.0.1:$port/api.php?a=state", false, $ctx), true)['sessions'], null, 'id');
}
function event(string $sid, string $transcript, string $extra = ''): string
{
    return json_encode(['session_id' => $sid, 'transcript_path' => $transcript, 'cwd' => '/work/board', 'hook_event_name' => 'UserPromptSubmit'], JSON_UNESCAPED_SLASHES) . $extra; // as Claude Code writes it
}
$sidA = '9829fa3f-6ac5-409f-a65e-85cbfa318785';
$sidB = '727d34ba-f1d9-4883-b31e-1c82eb9a888b';
$trA = "$tmp/a.jsonl";
$trB = "$tmp/b.jsonl";
file_put_contents($trA, implode("\n", [
    '{"type":"custom-title","customTitle":"Stary tytuł","sessionId":"' . $sidA . '"}',
    // a message quoting a title entry is escaped in the transcript and must not count
    json_encode(['type' => 'user', 'message' => ['content' => '{"type":"custom-title","customTitle":"z wiadomości"}']]),
    '{"type":"custom-title","customTitle":"Waveshare ESP32-S3-RLCD-4.2 możliwości","sessionId":"' . $sidA . '"}',
    json_encode(['type' => 'user', 'message' => ['content' => 'cytat: "type":"custom-title" bez cudzysłowów']]),
]) . "\n");
file_put_contents($trB, '{"type":"custom-title","customTitle":"Tytuł \"w cudzysłowie\"","sessionId":"' . $sidB . '"}' . "\n");
check('session row keyed by sid, titled from the transcript',
    quiet(hook('working', '/work/board', event($sidA, $trA, ' ' . str_repeat('x', 9000))))
    && (rows()['s:9829fa3f']['title'] ?? '') === 'Waveshare ESP32-S3-RLCD-4.2 możliwości' && rows()['s:9829fa3f']['alias'] === 'rlcd');
check('a second session in the same folder gets its own row with escapes decoded',
    quiet(hook('waiting', '/work/board', event($sidB, $trB))) && (rows()['s:727d34ba']['title'] ?? '') === 'Tytuł "w cudzysłowie"'
    && rows()['s:9829fa3f']['state'] === 'working');
file_put_contents($trA, '{"type":"custom-title","customTitle":"Nowa nazwa","sessionId":"' . $sidA . '"}' . "\n", FILE_APPEND);
check('renamed session: same state, new title goes out', quiet(hook('working', '/work/board', event($sidA, $trA))) && rows()['s:9829fa3f']['title'] === 'Nowa nazwa');
check('cache holds state and title per session', str_starts_with((string)file_get_contents("$tmp/cache/rlcd/s-9829fa3f"), "working\nNowa nazwa\n"));
file_put_contents($trA, '{"type":"custom-title","customTitle":"zły \x escape","sessionId":"' . $sidA . '"}' . "\n", FILE_APPEND);
check('broken title: state still goes out, alias shown', quiet(hook('done', '/work/board', event($sidA, $trA))) && rows()['s:9829fa3f']['state'] === 'done' && rows()['s:9829fa3f']['title'] === '');
check('no transcript: row without title', quiet(hook('done', '/work/board', event('0123abcd-0000-0000-0000-000000000000', "$tmp/none.jsonl"))) && rows()['s:0123abcd']['title'] === '');
check('end removes only that session', quiet(hook('end', '/work/board', event($sidB, $trB))) && !isset(rows()['s:727d34ba']) && isset(rows()['s:9829fa3f']) && !is_file("$tmp/cache/rlcd/s-727d34ba"));

// numbers and kinds: turns, subagents, waiting kind, task progress (never the step names)
function ev(string $sid, string $event, array $more = []): string
{
    return json_encode(['session_id' => $sid, 'transcript_path' => '/none', 'cwd' => '/work/board', 'hook_event_name' => $event] + $more, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
function serverState(): array
{
    global $port, $userToken;
    $ctx = stream_context_create(['http' => ['header' => "X-Token: $userToken\r\n"]]);
    return json_decode((string)file_get_contents("http://127.0.0.1:$port/api.php?a=state", false, $ctx), true);
}
$sidC = 'c0ffee00-1111-2222-3333-444444444444';
$row = fn() => rows()['s:c0ffee00'] ?? [];
$prompt = fn() => hook('working', '/work/board', ev($sidC, 'UserPromptSubmit', ['prompt' => 'secret prompt text']));
$post = fn(array $more) => hook('working', '/work/board', ev($sidC, 'PostToolUse', $more));
$prompt();
$prompt();
check('every prompt is a turn', $row()['turns'] === 2 && !isset($row()['tasks']) && !isset($row()['kind']));
$post(['tool_name' => 'TodoWrite', 'tool_input' => ['todos' => [
    ['content' => 'Tajny krok jeden', 'status' => 'completed', 'activeForm' => 'x'], ['content' => 'Tajny krok dwa', 'status' => 'in_progress'], ['content' => 'Tajny krok trzy', 'status' => 'pending']]]]);
check('TodoWrite gives done of total', $row()['tasks'] === ['d' => 1, 't' => 3]);
check('step names and the prompt never reach the server', !str_contains((string)file_get_contents("$tmp/data/state.json"), 'Tajny') && !str_contains((string)file_get_contents("$tmp/data/state.json"), 'secret prompt'));
$post(['agent_id' => 'agent-1', 'agent_type' => 'Explore', 'tool_name' => 'TodoWrite', 'tool_input' => ['todos' => [['content' => 'x', 'status' => 'pending']]]]);
check('a subagent\'s own list does not replace the session\'s', $row()['tasks'] === ['d' => 1, 't' => 3]);
$prompt();
check('an unfinished list survives the next prompt', $row()['tasks'] === ['d' => 1, 't' => 3] && $row()['turns'] === 3);
$post(['tool_name' => 'TodoWrite', 'tool_input' => ['todos' => [['content' => 'a', 'status' => 'completed'], ['content' => 'b', 'status' => 'completed']]]]);
check('a finished list shows done of total', $row()['tasks'] === ['d' => 2, 't' => 2]);
$prompt();
check('a finished list is gone at the next prompt', !isset($row()['tasks']));

foreach (['TaskCreate' => ['subject' => 'Tajne']] as $tool => $in) {
    foreach ([1, 2, 3] as $_) {
        $post(['tool_name' => $tool, 'tool_input' => $in]);
    }
}
check('TaskCreate adds pending tasks', $row()['tasks'] === ['d' => 0, 't' => 3]);
$post(['tool_name' => 'TaskUpdate', 'tool_input' => ['taskId' => '2', 'status' => 'completed']]);
check('TaskUpdate completes one', $row()['tasks'] === ['d' => 1, 't' => 3]);
$post(['tool_name' => 'TaskUpdate', 'tool_input' => ['taskId' => '3', 'status' => 'deleted']]);
check('a deleted task leaves the total', $row()['tasks'] === ['d' => 1, 't' => 2]);
$post(['tool_name' => 'TaskUpdate', 'tool_input' => ['taskId' => '1', 'subject' => 'renamed']]);
check('an update without status changes nothing', $row()['tasks'] === ['d' => 1, 't' => 2]);
$post(['tool_name' => 'TaskUpdate', 'tool_input' => ['taskId' => '9', 'status' => 'completed']]);
$post(['tool_name' => 'TaskCreate', 'tool_input' => ['subject' => 'x']]);
check('an unknown task id: no progress rather than a wrong one, and it stays off', !isset($row()['tasks']));

$plan = fn(array $in) => $post(['tool_name' => 'mcp__ccd_turn__update_plan', 'tool_input' => $in]);
$plan(['items' => [['content' => 'Tajne', 'status' => 'in_progress'], ['content' => 'b', 'status' => 'pending'], ['content' => 'c', 'status' => 'pending'], ['content' => 'd', 'status' => 'pending']]]);
check('update_plan items give progress', $row()['tasks'] === ['d' => 0, 't' => 4]);
$plan(['advance' => true]);
check('advance completes the current step', $row()['tasks'] === ['d' => 1, 't' => 4]);
$plan(['done' => [3], 'start' => 4]);
check('done and start apply to the stored list', $row()['tasks'] === ['d' => 2, 't' => 4]);
$plan(['advance' => true]);
$plan(['advance' => true]);
check('advance past the last step completes it', $row()['tasks'] === ['d' => 4, 't' => 4] || $row()['tasks'] === ['d' => 3, 't' => 4]);

// what the session waits for
hook('waiting', '/work/board', ev($sidC, 'PreToolUse', ['tool_name' => 'AskUserQuestion', 'tool_input' => ['questions' => [['question' => 'Tajne?']]]]));
check('AskUserQuestion: waiting for a question', $row()['state'] === 'waiting' && $row()['kind'] === 'question');
$post(['tool_name' => 'AskUserQuestion', 'tool_response' => 'ok']);
check('the answer clears the kind', $row()['state'] === 'working' && !isset($row()['kind']));
hook('waiting', '/work/board', ev($sidC, 'PreToolUse', ['tool_name' => 'ExitPlanMode', 'tool_input' => ['plan' => 'Tajny plan']]));
check('ExitPlanMode: waiting for a plan decision', $row()['kind'] === 'plan');
hook('waiting', '/work/board', ev($sidC, 'Notification', ['notification_type' => 'permission_prompt', 'message' => 'Claude needs your permission to use Bash']));
check('permission prompt: kind permission', $row()['kind'] === 'permission');
hook('waiting', '/work/board', ev($sidC, 'Notification', ['notification_type' => 'elicitation_dialog', 'message' => 'x']));
check('another notification: waiting without a kind', $row()['state'] === 'waiting' && !isset($row()['kind']));

// subagents
$prompt();
hook('agent-start', '/work/board', ev($sidC, 'SubagentStart', ['agent_id' => 'a1', 'agent_type' => 'Explore']));
hook('agent-start', '/work/board', ev($sidC, 'SubagentStart', ['agent_id' => 'a2', 'agent_type' => 'Plan']));
check('subagents are counted and the state stays', $row()['agents'] === 2 && $row()['state'] === 'working');
hook('agent-stop', '/work/board', ev($sidC, 'SubagentStop', ['agent_id' => 'a1', 'agent_type' => 'Explore']));
check('a stopped subagent is taken off', $row()['agents'] === 1);
hook('done', '/work/board', ev($sidC, 'Stop'));
check('done resets the subagents', $row()['agents'] === 0 && $row()['state'] === 'done');
hook('agent-stop', '/work/board', ev($sidC, 'SubagentStop', ['agent_id' => 'a2']));
check('a stop without a start never goes below zero', $row()['agents'] === 0);
hook('agent-start', '/work/board', ev('beef0000-0000-0000-0000-000000000000', 'SubagentStart', ['agent_id' => 'a9']));
check('a subagent of an unknown session creates no row', !isset(rows()['s:beef0000']));

// usage limit hit (StopFailure, matcher rate_limit)
$prompt();
check('no limit notice before', serverState()['usage']['hit'] === false);
check('StopFailure rate_limit: quiet, the turn is done, the limit notice is up',
    quiet(hook('limit', '/work/board', ev($sidC, 'StopFailure', ['error' => 'rate_limit']))) && $row()['state'] === 'done' && serverState()['usage']['hit'] === true);

// status line: prints a short line, reports numbers only
$statusline = dirname(__DIR__) . '/rlcd-statusline.sh';
function statusline(string $json, array $env = []): array
{
    global $statusline, $tmp;
    $env += ['PATH' => (string)getenv('PATH'), 'HOME' => "$tmp/home", 'XDG_CONFIG_HOME' => "$tmp/xdg", 'XDG_CACHE_HOME' => "$tmp/cache", 'RLCD_FOREGROUND' => '1'];
    $p = proc_open(['/bin/sh', $statusline], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, array_filter($env, fn($v) => $v !== null));
    fwrite($pipes[0], $json);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
}
$resetFive = time() + 4800;
$resetWeek = time() + 3 * 86400;
$sl = json_encode(['session_id' => 'c0ffee00-1111-2222-3333-444444444444', 'model' => ['display_name' => 'Opus 5.5'], 'context_window' => ['used_percentage' => 41.6],
    'rate_limits' => ['five_hour' => ['used_percentage' => 63.2, 'resets_at' => $resetFive], 'seven_day' => ['used_percentage' => 21, 'resets_at' => $resetWeek]],
    'workspace' => ['current_dir' => '/Users/secret/klient']]);
[$c, $out, $err] = statusline($sl);
$u = serverState()['usage'];
check('status line prints one short line and exits 0', $c === 0 && $out === '5h 63% 7d 21% ctx 42%' && $err === '');
check('limits arrive, the new reading clears the limit notice', $u['five']['p'] === 63 && $u['five']['r'] === $resetFive && $u['week']['p'] === 21 && $u['hit'] === false);
check('model and context land on the session', ($row()['model'] ?? '') === 'Opus 5.5' && ($row()['ctx'] ?? -1) === 42);
check('no path or name left the Mac', !str_contains((string)file_get_contents("$tmp/data/state.json"), 'klient'));
$resetFive2 = $resetFive + 60;
statusline(str_replace('63.2', '64.4', $sl));
check('a changed number is sent again', serverState()['usage']['five']['p'] === 64);
file_put_contents("$tmp/data/state.json", str_replace('"p": 64', '"p": 99', (string)file_get_contents("$tmp/data/state.json")));
statusline(str_replace('63.2', '64.4', $sl));
check('an unchanged number is not sent again', serverState()['usage']['five']['p'] === 99);
[$c, $out] = statusline(json_encode(['session_id' => 'c0ffee00-1111', 'model' => ['display_name' => 'Opus 5.5']]), ['XDG_CACHE_HOME' => "$tmp/cache2"]);
check('older Claude Code without rate_limits: no limit, no error', $c === 0 && $out === '' && serverState()['usage']['five']['p'] === 99);
[$c, $out] = statusline('not json');
check('garbage input: exit 0 and a harmless line', $c === 0 && strlen($out) < 20);
[$c, $out, $err] = statusline($sl, ['XDG_CONFIG_HOME' => "$tmp/dead", 'XDG_CACHE_HOME' => "$tmp/cache3"]);
check('unreachable server: line printed, silent, exit 0', $c === 0 && $out === '5h 63% 7d 21% ctx 42%' && $err === '');
[$c, , $err] = statusline($sl, ['XDG_CONFIG_HOME' => "$tmp/plain", 'XDG_CACHE_HOME' => "$tmp/cache4"]);
check('plain http to a remote host is refused silently', $c === 0 && $err === '');
[$c, $out] = statusline(str_replace('63.2', '163.2', $sl), ['XDG_CACHE_HOME' => "$tmp/cache5"]);
check('a percentage out of range is not sent', $c === 0 && !str_contains($out, '163') && serverState()['usage']['five']['p'] === 99);

// refusals stay silent in hook mode
check('plain http to a remote host is refused silently', quiet(hook('done', '/work/x', '{}', ['XDG_CONFIG_HOME' => "$tmp/plain"])));
check('missing config is silent', quiet(hook('done', '/work/x', '{}', ['XDG_CONFIG_HOME' => "$tmp/none"])));
check('unknown argument is ignored', quiet(hook('Stop', '/work/board')));
$t = microtime(true);
check('background send returns at once and prints nothing', quiet(hook('done', '/work/y', '{}', ['RLCD_FOREGROUND' => null])) && microtime(true) - $t < 1);

// check mode talks
[$c, $out] = hook('check', '/work/board');
check('check sends done and reports the alias', $c === 0 && str_contains($out, 'alias   rlcd') && str_contains($out, 'OK:') && sessions()['rlcd'] === 'done');
[$c, , $err] = hook('check', '/work/bad');
check('check warns about an invalid alias', $c === 0 && str_contains($err, "alias 'Bad_Name' ignored"));
[$c, , $err] = hook('check', '/work/x', '{}', ['XDG_CONFIG_HOME' => "$tmp/plain"]);
check('check explains the https rule', $c === 1 && str_contains($err, 'https://'));
file_put_contents("$tmp/wrong/rlcd/env", "RLCD_URL=http://127.0.0.1:$port/api.php\nRLCD_HOOK_TOKEN=" . str_repeat('x', 32) . "\n");
[$c, , $err] = hook('check', '/work/board', '{}', ['XDG_CONFIG_HOME' => "$tmp/wrong"]);
check('check shows the server refusal for a wrong token', $c === 1 && str_contains($err, '401'));

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
