#!/bin/sh
# Claude Code hook: reports this project's session state to the RLCD board server.
# ~/.claude/settings.json calls it as "rlcd-hook.sh working|waiting|done|end|limit|agent-start|agent-stop"
# (see settings-snippet.json).
# "rlcd-hook.sh check", run by hand in a project folder, tests the setup and prints what it sends.
#
# Sends an alias, never the project path. Aliases come from ~/.config/rlcd/aliases, one
# "<project path> <alias>" per line; any other project shows as "p-" + 4 hex digits of a checksum of its path.
# With the alias go the first 8 characters of the session id ("sid", one board row per session) and the
# session title from the Claude app (last "custom-title" entry in the transcript), which the board shows
# instead of the alias. Only changes of state or title go out (the server counts "since" from every update;
# what was sent last is cached in ~/.cache/rlcd), in the background, so Claude Code never waits.
#
# Besides the state, only numbers and kinds go out, never content (docs/SPEC.md, "Prywatność"): the turn count,
# how many subagents work, what the session waits for (question, plan or permission) and "done of total" of the
# task list (TodoWrite, TaskCreate/TaskUpdate or the Claude app's update_plan; step names stay on the Mac).
# The task list needs jq; without it the session simply has no progress. "limit" (StopFailure, rate_limit)
# reports that the usage limit is hit and closes the turn as done.
# In hook mode it prints nothing and always exits 0: UserPromptSubmit output would land in Claude's context.

LC_ALL=C # byte-wise [a-z] ranges and string lengths
export LC_ALL
state=$1
case $state in working | waiting | done | end | check | limit | agent-start | agent-stop) ;; *) exit 0 ;; esac

quit() { # $1: why nothing was sent; only "check" shows it
	[ "$state" = check ] || exit 0
	printf 'rlcd-hook: %s\n' "$1" >&2
	exit 1
}

input=
if [ "$state" != check ] && [ ! -t 0 ]; then
	input=$(cat) # read the whole event so Claude Code never blocks writing it
fi
if [ "$state" = waiting ]; then
	# Permission prompts, questions and plan approvals mean "waiting"; the idle reminder after a finished reply does not.
	case $input in *'"notification_type"'*'"idle_prompt"'* | *'"notification_type"'*'"auth_success"'*) exit 0 ;; esac
fi

# session_id and transcript_path open every event, so only its start is searched (a tool input can be huge).
meta=$(printf '%s' "$input" | head -c 4096)
field() { # $1: key; prints the first "key":"value" in the event's start, if the value has no escapes
	rest=${meta#*\"$1\":\"}
	[ "$rest" != "$meta" ] || return 0
	v=${rest%%\"*}
	case $v in *\\*) ;; *) printf '%s' "$v" ;; esac
}
sid=$(field session_id | tr -d -- - | cut -c1-8)
case $sid in ????????) ;; *) sid= ;; esac
case $sid in *[!a-z0-9]*) sid= ;; esac
event=$(field hook_event_name)
tool=$(field tool_name)
agent=$(field agent_id) # set when the event fires inside a subagent

# Title of the session as the Claude app shows it: the last "custom-title" entry of the transcript.
# It goes out still JSON-escaped, as the transcript has it; anything that is not a clean JSON string
# fragment is dropped and the board shows the alias instead.
title=
transcript=$(field transcript_path)
if [ -n "$sid" ] && [ -f "$transcript" ] && [ -r "$transcript" ]; then
	line=$(grep -F '"type":"custom-title"' "$transcript" 2>/dev/null | tail -n 1)
	rest=${line#*\"customTitle\":\"}
	if [ "$rest" != "$line" ]; then
		title=$(printf '%s\n' "$rest" | sed -E 's/^(([^"\\]|\\.)*)".*$/\1/')
		[ "$title" != "$rest" ] || title= # no closing quote
		left=$(printf '%s' "$title" | sed -E 's/\\(["\\/bfnrt]|u[0-9a-fA-F]{4})//g')
		case $left in *\\* | *[[:cntrl:]]*) title= ;; esac
		[ ${#title} -le 600 ] || title= # bytes; the server keeps 60 characters anyway
	fi
fi

conf=${XDG_CONFIG_HOME:-$HOME/.config}/rlcd
url=
token=
if [ -r "$conf/env" ]; then
	while IFS= read -r line || [ -n "$line" ]; do
		line=${line#export }
		case $line in
		RLCD_URL=*) url=$(printf '%s' "${line#*=}" | tr -d "\"' \r") ;;
		RLCD_HOOK_TOKEN=*) token=$(printf '%s' "${line#*=}" | tr -d "\"' \r") ;;
		esac
	done <"$conf/env"
fi
[ -n "$url" ] && [ -n "$token" ] || quit "set RLCD_URL and RLCD_HOOK_TOKEN in $conf/env"
case $url in
https://* | http://127.0.0.1[:/]* | http://localhost[:/]*) ;;
*) quit 'RLCD_URL must start with https:// so the token never travels in plain text' ;;
esac

dir=${CLAUDE_PROJECT_DIR:-$PWD}
[ "$dir" = / ] || dir=${dir%/}
name=
if [ -r "$conf/aliases" ]; then
	while IFS= read -r line || [ -n "$line" ]; do
		case $line in '' | '#'*) continue ;; esac
		path=${line% *}
		case $path in '~/'*) path=$HOME/${path#'~/'} ;; esac
		[ "${path%/}" = "$dir" ] && name=${line##* }
	done <"$conf/aliases"
fi
case $name in *[!a-z0-9-]*) bad=1 ;; *) bad= ;; esac
[ ${#name} -le 24 ] || bad=1
if [ -n "$bad" ]; then
	[ "$state" = check ] && printf "rlcd-hook: alias '%s' ignored: use 1-24 characters a-z, 0-9 or -\n" "$name" >&2
	name=
fi
if [ -z "$name" ]; then
	sum=$(printf '%s' "$dir" | cksum)
	name=p-$(printf '%04x' $((${sum%% *} % 65536)))
fi

cache=${XDG_CACHE_HOME:-$HOME/.cache}/rlcd
key=$name # cache file: one per session, or per project when the event names no session
[ -z "$sid" ] || key=s-$sid
last=
[ -r "$cache/$key" ] && last=$(cat "$cache/$key")

# Per-session numbers, kept next to the state cache: "<turns> <subagents working> <waiting kind>" in .x and
# the task list as a JSON array of statuses in .t ("null" once it cannot be followed, see progress()).
xturns=0 xagents=0 xkind=-
if [ -n "$sid" ] && [ -r "$cache/$key.x" ]; then
	read -r xturns xagents xkind <"$cache/$key.x"
fi
case $xturns in '' | *[!0-9]*) xturns=0 ;; esac
case $xagents in '' | *[!0-9]*) xagents=0 ;; esac
case $xkind in question | plan | permission) ;; *) xkind=- ;; esac
tf=$cache/$key.t

# Applies one task-list tool call to the stored statuses ($cur) and prints the new array, or null when the
# call names a task that is not in the list (the list cannot be followed then and shows no progress).
# TaskCreate ids are taken as 1, 2, 3... in creation order.
# shellcheck disable=SC2016
JQ_PROGRESS='
def st: if . == "completed" or . == "in_progress" or . == "pending" or . == "deleted" then . else "pending" end;
def num: if type == "number" then . elif type == "string" then (tonumber? // null) else null end;
.tool_name as $tool | (.tool_input // {}) as $in |
if $tool == "TodoWrite" then
	if ($in.todos | type) == "array" then [$in.todos[] | (.status // "pending") | st] else $cur end
elif $tool == "TaskCreate" then
	if $cur == null then null else $cur + ["pending"] end
elif $tool == "TaskUpdate" then
	if ($in.status // null) == null then $cur
	else ($in.taskId | num) as $i |
		if $cur == null or $i == null or $i < 1 or $i > ($cur | length) then null
		else $cur | .[$i - 1] = ($in.status | st) end
	end
elif $tool == "mcp__ccd_turn__update_plan" then
	if ($in.items | type) == "array" then [$in.items[] | (.status // "pending") | st]
	elif $cur == null then null
	else $cur
		| if $in.advance == true then
			(index("in_progress") as $a | if $a != null then .[$a] = "completed" else . end)
			| (index("pending") as $p | if $p != null then .[$p] = "in_progress" else . end)
		else . end
		| reduce (($in.done // [])[] | num) as $n (.; if $n != null and $n >= 1 and $n <= length then .[$n - 1] = "completed" else . end)
		| (($in.start | num) as $n | if $n != null and $n >= 1 and $n <= length then .[$n - 1] = "in_progress" else . end)
	end
else $cur end'

progress() {
	command -v jq >/dev/null 2>&1 || return 0
	cur='[]'
	[ -r "$tf" ] && cur=$(cat "$tf")
	new=$(printf '%s' "$input" | jq -c --argjson cur "$cur" "$JQ_PROGRESS" 2>/dev/null) || return 0
	[ -n "$new" ] || return 0
	mkdir -p "$cache" 2>/dev/null
	printf '%s' "$new" >"$tf"
}

# "done of total" as {"d":2,"t":5}, empty when there is no list; deleted tasks do not count.
task_counts() {
	[ -r "$tf" ] && command -v jq >/dev/null 2>&1 || return 0
	jq -c 'if type == "array" then [.[] | select(. != "deleted")] as $a
		| if ($a | length) > 0 then {d: ($a | map(select(. == "completed")) | length), t: ($a | length)} else empty end
		else empty end' "$tf" 2>/dev/null
}

if [ -n "$sid" ] && [ "$state" != check ]; then
	case $state in
	working)
		if [ "$event" = UserPromptSubmit ]; then
			xturns=$((xturns + 1))
			xagents=0
			# a list that was finished in an earlier turn is not progress of this one
			if [ -r "$tf" ] && command -v jq >/dev/null 2>&1; then
				jq -e 'type == "array" and length > 0 and all(.[]; . == "completed" or . == "deleted")' "$tf" >/dev/null 2>&1 && rm -f "$tf"
			fi
			xkind=-
		else
			xkind=-
			if [ "$event" = PostToolUse ] && [ -z "$agent" ]; then
				case $tool in TodoWrite | TaskCreate | TaskUpdate | mcp__ccd_turn__update_plan) progress ;; esac
			fi
		fi
		;;
	waiting)
		case $tool in
		AskUserQuestion) xkind=question ;;
		ExitPlanMode) xkind=plan ;;
		*) case $input in *'"notification_type"'*'"permission_prompt"'*) xkind=permission ;; *) xkind=- ;; esac ;;
		esac
		;;
	done | limit)
		xagents=0
		xkind=-
		;;
	agent-start) xagents=$((xagents + 1)) ;;
	agent-stop) [ "$xagents" -gt 0 ] && xagents=$((xagents - 1)) ;;
	esac
fi
case $state in
limit) state=done ;; # the turn is over; the usage report goes out in the background below
agent-start | agent-stop) # no state of their own: report the session's last one
	state=$(printf '%s' "$last" | head -n 1)
	case $state in working | waiting | done) ;; *) exit 0 ;; esac
	;;
esac

tasks=$(task_counts)
extras="$xturns $xagents $xkind $tasks"
now=$(printf '%s\n%s\n%s' "$state" "$title" "$extras")
if [ -n "$sid" ] && [ "$state" != check ]; then # counters are kept whatever the server says
	mkdir -p "$cache" 2>/dev/null
	if [ "$state" = end ]; then rm -f "$cache/$key.x" "$tf"; else printf '%s %s %s\n' "$xturns" "$xagents" "$xkind" >"$cache/$key.x"; fi
fi
[ "$now" = "$last" ] && [ "$1" != limit ] && exit 0 # e.g. "working" again after every tool call

send() { # $1: state, $2: timeout in seconds; state and title are remembered only once the server took them
	body="{\"alias\":\"$name\",\"state\":\"$1\""
	if [ -n "$sid" ]; then
		body="$body,\"sid\":\"$sid\",\"title\":\"$title\""
		if [ "$1" != end ]; then
			body="$body,\"turns\":$xturns,\"agents\":$xagents"
			[ "$xkind" = - ] || body="$body,\"kind\":\"$xkind\""
			[ -z "$tasks" ] || body="$body,\"tasks\":$tasks"
		fi
	fi
	printf 'X-Token: %s\n' "$token" |
		curl -sS --fail-with-body -m "$2" -H @- -H 'Content-Type: application/json' \
			--data-binary "$body}" "$url?a=session" || return
	mkdir -p "$cache" 2>/dev/null
	if [ "$1" = end ]; then rm -f "$cache/$key"; else printf '%s\n%s\n%s' "$1" "$title" "$extras" >"$cache/$key"; fi
}

send_limit() { # the usage limit is hit: the server shows it until a newer reading arrives
	printf 'X-Token: %s\n' "$token" |
		curl -sS --fail-with-body -m 3 -H @- -H 'Content-Type: application/json' \
			--data-binary '{"hit":true}' "$url?a=usage"
}

if [ "$state" = check ]; then
	printf 'project %s\nalias   %s\nserver  %s\n' "$dir" "$name" "$url"
	printf 'title   (outside a session the board shows the alias; inside one, the session title from the Claude app)\n'
	send done 10 || quit 'the server did not accept the update (reply above)'
	printf '\nOK: the sessions view lists %s as done after the next poll.\n' "$name"
	exit 0
fi
if [ -n "$RLCD_FOREGROUND" ]; then # tests and debugging: wait for the server
	[ "$1" != limit ] || send_limit >/dev/null 2>&1
	send "$state" 3 >/dev/null 2>&1
else
	([ "$1" != limit ] || send_limit; send "$state" 3) </dev/null >/dev/null 2>&1 &
fi
exit 0
