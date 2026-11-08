#!/bin/sh
# Claude Code status line: prints one short line and reports the plan limits to the RLCD board server.
# ~/.claude/settings.json: "statusLine": {"type": "command", "command": "$HOME/.claude/hooks/rlcd-statusline.sh"}
# (see settings-snippet.json). Needs jq. Claude Code pipes a JSON document into it after every reply.
#
# Only numbers go out (docs/SPEC.md, "Prywatność"): the 5 hour and weekly usage in percent with the time they
# reset, and for this session the model name and how full the context window is. The fields rate_limits.*
# exist only on Pro, Max and Ultra plans and only after the first reply of a session; without them nothing
# about limits is sent and the line shows none. The report goes out in the background (curl -m 3) and only
# when a number changed (cache in ~/.cache/rlcd/usage-<session>). Always exits 0; the token is never printed.

LC_ALL=C
export LC_ALL
input=$(cat)
command -v jq >/dev/null 2>&1 || {
	printf 'cosik: install jq'
	exit 0
}

# five_p five_r week_p week_r ctx model sid, "-" for what is missing; percentages rounded to whole numbers
fields=$(printf '%s' "$input" | jq -r '
	def pct: if type == "number" and . >= 0 and . <= 100 then round else "-" end;
	def at: if type == "number" and . > 0 then floor else "-" end;
	[ (.rate_limits.five_hour.used_percentage | pct), (.rate_limits.five_hour.resets_at | at),
	  (.rate_limits.seven_day.used_percentage | pct), (.rate_limits.seven_day.resets_at | at),
	  (.context_window.used_percentage | pct),
	  ((.model.display_name // "") | if test("^[A-Za-z0-9 ._-]{1,32}$") then gsub(" "; "_") else "-" end),
	  ((.session_id // "") | gsub("-"; "") | .[0:8] | if test("^[a-z0-9]{8}$") then . else "-" end) ] | join(" ")' 2>/dev/null) || fields=
[ -n "$fields" ] || {
	printf 'cosik'
	exit 0
}
# shellcheck disable=SC2086
set -- $fields
five_p=$1 five_r=$2 week_p=$3 week_r=$4 ctx=$5 model=$6 sid=$7

line=
[ "$five_p" = - ] || line="5h ${five_p}%"
[ "$week_p" = - ] || line="$line 7d ${week_p}%"
[ "$ctx" = - ] || line="$line ctx ${ctx}%"
printf '%s' "${line# }"

[ "$five_p$week_p" != -- ] || [ "$ctx" != - ] || exit 0 # nothing worth reporting
conf=${XDG_CONFIG_HOME:-$HOME/.config}/rlcd
url= token=
if [ -r "$conf/env" ]; then
	while IFS= read -r l || [ -n "$l" ]; do
		l=${l#export }
		case $l in
		RLCD_URL=*) url=$(printf '%s' "${l#*=}" | tr -d "\"' \r") ;;
		RLCD_HOOK_TOKEN=*) token=$(printf '%s' "${l#*=}" | tr -d "\"' \r") ;;
		esac
	done <"$conf/env"
fi
[ -n "$url" ] && [ -n "$token" ] || exit 0
case $url in https://* | http://127.0.0.1[:/]* | http://localhost[:/]*) ;; *) exit 0 ;; esac

cache=${XDG_CACHE_HOME:-$HOME/.cache}/rlcd
file=$cache/usage-$sid
last=
[ -r "$file" ] && last=$(cat "$file")
[ "$fields" = "$last" ] && exit 0

body='{'
sep=
add() {
	body="$body$sep$1"
	sep=,
}
[ "$five_p" = - ] || [ "$five_r" = - ] || add "\"five\":{\"p\":$five_p,\"r\":$five_r}"
[ "$week_p" = - ] || [ "$week_r" = - ] || add "\"week\":{\"p\":$week_p,\"r\":$week_r}"
if [ "$sid" != - ]; then
	add "\"sid\":\"$sid\""
	[ "$model" = - ] || add "\"model\":\"$(printf '%s' "$model" | tr _ ' ')\""
	[ "$ctx" = - ] || add "\"ctx\":$ctx"
fi
body="$body}"
[ "$body" != '{}' ] || exit 0

send() {
	printf 'X-Token: %s\n' "$token" |
		curl -sS --fail-with-body -m 3 -H @- -H 'Content-Type: application/json' \
			--data-binary "$body" "$url?a=usage" || return
	mkdir -p "$cache" 2>/dev/null
	printf '%s' "$fields" >"$file"
}
if [ -n "$RLCD_FOREGROUND" ]; then
	send >/dev/null 2>&1
else
	send </dev/null >/dev/null 2>&1 &
fi
exit 0
