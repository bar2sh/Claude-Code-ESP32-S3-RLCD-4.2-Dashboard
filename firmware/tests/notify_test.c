// Host test of the notification decision (docs/SPEC.md, "Zachowanie płytki", "Powiadomienia o sesjach").
// From the repository root, on a Mac or Linux, no ESP-IDF needed (one command line):
//     cc -std=c11 -Wall -Wextra -Werror -pedantic -o /tmp/notify_test
//         firmware/tests/notify_test.c firmware/main/notify.c && /tmp/notify_test
#include <stdio.h>
#include <string.h>
#include "../main/notify.h"

static int s_ok, s_fail;

#define CHECK(cond)                                         \
    do {                                                    \
        if (cond) {                                         \
            s_ok++;                                         \
        } else {                                            \
            s_fail++;                                       \
            printf("FAIL line %d: %s\n", __LINE__, #cond);  \
        }                                                   \
    } while (0)

#define T0 1793991500 // "time" of the poll answers

// One server and one board: what the next full poll answer carries and what the board saw last time.
static notify_settings_t s_set;
static notify_ev_t s_ev;
static session_t s_rows[MAX_SESSIONS];
static int s_count;
static notify_seen_t s_seen;
static bool s_listen, s_on_sessions, s_message_sound, s_old_server;
static int64_t s_now;

static session_t *row(const char *id)
{
    for (int i = 0; i < s_count; i++) {
        if (strcmp(s_rows[i].id, id) == 0) return &s_rows[i];
    }
    return NULL;
}

static void add_row(const char *id, const char *alias, const char *title, sess_state_t state)
{
    session_t *r = &s_rows[s_count++];
    memset(r, 0, sizeof *r);
    snprintf(r->id, sizeof r->id, "%s", id);
    snprintf(r->alias, sizeof r->alias, "%s", alias);
    snprintf(r->title, sizeof r->title, "%s", title);
    r->state = state;
}

static bool active_left(void)
{
    for (int i = 0; i < s_count; i++) {
        if (s_rows[i].state != SESS_DONE) return true;
    }
    return false;
}

// The row ends an answer `age` seconds before the poll, counted as POST session counts it.
static void finish(const char *id, int64_t age)
{
    row(id)->state = SESS_DONE;
    s_ev.done++;
    s_ev.done_at = s_now - age;
    snprintf(s_ev.done_id, sizeof s_ev.done_id, "%s", id);
    if (!active_left()) {
        s_ev.all++;
        s_ev.all_at = s_now - age;
    }
}

static void start_waiting(const char *id, int64_t age)
{
    row(id)->state = SESS_WAITING;
    s_ev.wait++;
    s_ev.wait_at = s_now - age;
    snprintf(s_ev.wait_id, sizeof s_ev.wait_id, "%s", id);
}

static app_lang_t s_lang = LANG_PL; // the cases below check the Polish texts; test_lang_and_interval the rest
static int s_poll_s;                // 0: the bare NOTIFY_MAX_AGE_S limit

static notify_t poll_answer(void)
{
    notify_input_t in = {
        .listen = s_listen,
        .on_sessions = s_on_sessions,
        .message_sound = s_message_sound,
        .now = s_now,
        .poll_s = s_poll_s,
        .lang = s_lang,
        .set = s_old_server ? NULL : &s_set,
        .ev = s_old_server ? NULL : &s_ev,
        .rows = s_rows,
        .row_count = s_count,
    };
    return notify_decide(&in, &s_seen);
}

static bool is(notify_t n, notify_kind_t kind, const char *detail)
{
    return n.kind == kind && strcmp(n.detail, detail) == 0;
}

// A board in LISTEN on the Sesje Claude view that already synced once; two sessions work:
// one with a title from the Claude app, one known only by its alias.
static void start(notify_mode_t mode, notify_scope_t scope)
{
    s_set = (notify_settings_t){mode, scope, {"bell", ANIM_HAPPY}, {"chime", ANIM_AWAY}};
    s_ev = (notify_ev_t){.done = 10, .done_at = T0 - 3600, .done_id = "s:9829fa3f", .all = 4, .all_at = T0 - 3600,
                         .wait = 3, .wait_at = T0 - 4000, .wait_id = "blog"};
    s_count = 0;
    add_row("s:9829fa3f", "cosik", "Powiadomienia na płytce", SESS_WORKING);
    add_row("blog", "blog", "", SESS_WORKING);
    s_listen = true;
    s_on_sessions = true;
    s_message_sound = false;
    s_old_server = false;
    s_now = T0;
    memset(&s_seen, 0, sizeof s_seen);
    CHECK(poll_answer().kind == NOTIFY_NONE && s_seen.known);
}

static void test_first_sync(void)
{
    // After a restart the board takes the counters silently, even with fresh events in them.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    memset(&s_seen, 0, sizeof s_seen);
    finish("blog", 5);
    start_waiting("s:9829fa3f", 5);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    CHECK(s_seen.known && s_seen.ev.done == 11 && s_seen.ev.wait == 4);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    finish("s:9829fa3f", 5);
    CHECK(is(poll_answer(), NOTIFY_DONE, "Powiadomienia na płytce"));
}

static void test_each(void)
{
    // Prymus: "Gotowe" after every answer, the session's title or its alias, once per event.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("s:9829fa3f", 3);
    CHECK(is(poll_answer(), NOTIFY_DONE, "Powiadomienia na płytce"));
    CHECK(poll_answer().kind == NOTIFY_NONE);
    finish("blog", 3);
    CHECK(is(poll_answer(), NOTIFY_DONE, "blog"));
    CHECK(poll_answer().kind == NOTIFY_NONE);
}

static void test_ready(void)
{
    // Gotowy na kolejne zadania: "Gotowe" only when the last active session finishes.
    start(NOTIFY_READY, NOTIFY_SCOPE_ALL);
    finish("s:9829fa3f", 3); // blog still works: done grows, all does not
    CHECK(poll_answer().kind == NOTIFY_NONE);
    finish("blog", 3);
    CHECK(is(poll_answer(), NOTIFY_DONE, "Wszystkie sesje skończyły"));
    CHECK(poll_answer().kind == NOTIFY_NONE);
    start_waiting("blog", 2); // "Czeka na Ciebie" works in this mode too
    CHECK(is(poll_answer(), NOTIFY_WAIT, "blog"));
}

static void test_quiet(void)
{
    // Condition 1: Cichy never notifies, and the counters are remembered all the same.
    start(NOTIFY_QUIET, NOTIFY_SCOPE_ALL);
    start_waiting("blog", 1);
    finish("s:9829fa3f", 1);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    CHECK(s_seen.ev.wait == 4 && s_seen.ev.done == 11);
    s_set.mode = NOTIFY_EACH; // switching to Prymus does not bring the skipped events back
    CHECK(poll_answer().kind == NOTIFY_NONE);
}

static void test_counter_grew(void)
{
    // Condition 2: the right counter has to grow; a row that keeps waiting is no new event.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    start_waiting("blog", 1);
    CHECK(is(poll_answer(), NOTIFY_WAIT, "blog"));
    s_ev.wait_at = s_now;
    CHECK(poll_answer().kind == NOTIFY_NONE);
    // A counter below the one seen, e.g. a new state.json on the server: nothing plays, the new
    // value is remembered and the next event plays again.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    row("s:9829fa3f")->state = SESS_DONE;
    s_ev.done = 2;
    s_ev.done_at = s_now;
    CHECK(poll_answer().kind == NOTIFY_NONE);
    CHECK(s_seen.ev.done == 2);
    finish("blog", 1);
    CHECK(is(poll_answer(), NOTIFY_DONE, "blog"));
}

static void test_age(void)
{
    // Condition 3: at most 120 s by the answer's own "time".
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 120);
    CHECK(is(poll_answer(), NOTIFY_DONE, "blog"));
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 121);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    CHECK(s_seen.ev.done == 11);
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    start_waiting("blog", 121);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    start(NOTIFY_READY, NOTIFY_SCOPE_ALL);
    finish("s:9829fa3f", 1);
    finish("blog", 121);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", -1); // an event after the answer's time: a clock that went back
    CHECK(poll_answer().kind == NOTIFY_NONE);
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 1);
    s_now = 0; // an answer without "time"
    CHECK(poll_answer().kind == NOTIFY_NONE);
}

static void test_state_holds(void)
{
    // Condition 4: by the time the board asks, the row has to be in the same state.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    start_waiting("blog", 5);
    row("blog")->state = SESS_WORKING; // answered in the Claude app already
    CHECK(poll_answer().kind == NOTIFY_NONE);
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    start_waiting("blog", 5);
    s_count = 1; // the waiting session ended and its row is gone
    CHECK(poll_answer().kind == NOTIFY_NONE);
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 5);
    row("blog")->state = SESS_WORKING; // already on the next prompt
    CHECK(poll_answer().kind == NOTIFY_NONE);
    start(NOTIFY_READY, NOTIFY_SCOPE_ALL);
    finish("blog", 5);
    finish("s:9829fa3f", 5);
    row("blog")->state = SESS_WORKING; // all finished, but one works again
    CHECK(poll_answer().kind == NOTIFY_NONE);
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 5);
    s_ev.done_id[0] = '\0'; // no row id at all
    CHECK(poll_answer().kind == NOTIFY_NONE);
}

static void test_scope(void)
{
    // Condition 5: "Tylko ekran Sesje Claude" skips the event on other views for good.
    start(NOTIFY_EACH, NOTIFY_SCOPE_SESSIONS);
    s_on_sessions = false;
    finish("blog", 1);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    s_on_sessions = true;
    CHECK(poll_answer().kind == NOTIFY_NONE);
    finish("s:9829fa3f", 1);
    CHECK(is(poll_answer(), NOTIFY_DONE, "Powiadomienia na płytce"));
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL); // "Wszystkie ekrany"
    s_on_sessions = false;
    start_waiting("blog", 1);
    CHECK(is(poll_answer(), NOTIFY_WAIT, "blog"));
}

static void test_priority(void)
{
    // One notification per sync: "Czeka na Ciebie" before "Gotowe", and "Gotowe" does not follow later.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 2);
    start_waiting("s:9829fa3f", 1);
    CHECK(is(poll_answer(), NOTIFY_WAIT, "Powiadomienia na płytce"));
    CHECK(poll_answer().kind == NOTIFY_NONE);
    // A wait that no longer holds leaves the place to "Gotowe".
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    start_waiting("s:9829fa3f", 2);
    finish("blog", 1);
    row("s:9829fa3f")->state = SESS_WORKING;
    CHECK(is(poll_answer(), NOTIFY_DONE, "blog"));
    // A new message whose sound plays in the same sync wins; the events stay seen.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    s_message_sound = true;
    start_waiting("blog", 1);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    s_message_sound = false;
    CHECK(poll_answer().kind == NOTIFY_NONE);
}

static void test_modes_and_servers(void)
{
    // ON DEMAND: BOOT syncs once and takes the counters silently, LISTEN later does not replay them.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    s_listen = false;
    finish("blog", 1);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    CHECK(s_seen.ev.done == 11);
    s_listen = true;
    CHECK(poll_answer().kind == NOTIFY_NONE);
    // An older api.php without notify and ev: no notifications, and the first answer with counters
    // afterwards is taken like a first sync.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    s_old_server = true;
    finish("blog", 1);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    CHECK(!s_seen.known);
    s_old_server = false;
    CHECK(poll_answer().kind == NOTIFY_NONE);
    finish("s:9829fa3f", 1);
    CHECK(is(poll_answer(), NOTIFY_DONE, "Powiadomienia na płytce"));
}

static void test_detail(void)
{
    // Several events since the last sync: "N sesje" or "N sesji" instead of one title.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 9);
    finish("s:9829fa3f", 1);
    CHECK(is(poll_answer(), NOTIFY_DONE, "2 sesje"));
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    s_ev.done += 4; // four answers in between that the board did not see
    finish("blog", 1);
    CHECK(is(poll_answer(), NOTIFY_DONE, "5 sesji"));
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    start_waiting("blog", 9);
    row("blog")->state = SESS_WORKING;
    start_waiting("s:9829fa3f", 1);
    CHECK(is(poll_answer(), NOTIFY_WAIT, "2 sesje"));
    // The longest title, 60 four-byte characters, comes whole: the screen clips it.
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    char longest[sizeof s_rows[0].title] = "";
    for (int i = 0; i < 60; i++) strcat(longest, "\xF0\x9F\x94\x94");
    snprintf(row("s:9829fa3f")->title, sizeof longest, "%s", longest);
    finish("s:9829fa3f", 1);
    CHECK(is(poll_answer(), NOTIFY_DONE, longest));
}

static void test_plural(void)
{
    static const struct {
        int64_t n;
        const char *text;
    } cases[] = {
        {1, "1 sesja"},   {2, "2 sesje"},   {3, "3 sesje"},     {4, "4 sesje"},     {5, "5 sesji"},
        {11, "11 sesji"}, {12, "12 sesji"}, {14, "14 sesji"},   {21, "21 sesji"},   {22, "22 sesje"},
        {25, "25 sesji"}, {104, "104 sesje"}, {112, "112 sesji"}, {122, "122 sesje"},
    };
    for (size_t i = 0; i < sizeof cases / sizeof cases[0]; i++) {
        char text[24];
        notify_sessions_text(text, sizeof text, cases[i].n, LANG_PL);
        if (strcmp(text, cases[i].text) != 0) printf("  %lld gives \"%s\"\n", (long long)cases[i].n, text);
        CHECK(strcmp(text, cases[i].text) == 0);
    }
    char text[24];
    notify_sessions_text(text, sizeof text, 1, LANG_EN);
    CHECK(strcmp(text, "1 session") == 0);
    notify_sessions_text(text, sizeof text, 5, LANG_EN);
    CHECK(strcmp(text, "5 sessions") == 0);
}

static void test_lang_and_interval(void)
{
    // Board settings: the detail follows the board language.
    s_lang = LANG_EN;
    start(NOTIFY_READY, NOTIFY_SCOPE_ALL);
    finish("s:9829fa3f", 1);
    finish("blog", 1);
    CHECK(is(poll_answer(), NOTIFY_DONE, "All sessions finished"));
    s_lang = LANG_PL;
    // A longer poll interval widens the age limit by one interval, so a 15 min board still notifies.
    s_poll_s = 900;
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 900 + 120);
    CHECK(is(poll_answer(), NOTIFY_DONE, "blog"));
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    finish("blog", 900 + 121);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    s_poll_s = 10;
    start(NOTIFY_EACH, NOTIFY_SCOPE_ALL);
    start_waiting("blog", 131);
    CHECK(poll_answer().kind == NOTIFY_NONE);
    s_poll_s = 0;
}

static void test_corner(void)
{
    // The corner of the Sesje Claude view: the most important state, and no corner above 6 rows.
    // A working session leaves the corner empty: the looping typing dots distracted the user.
    session_t rows[MAX_SESSIONS];
    memset(rows, 0, sizeof rows);
    CHECK(notify_corner_anim(rows, 0) == ANIM_SLEEP);
    for (int i = 0; i < MAX_SESSIONS; i++) rows[i].state = SESS_DONE;
    CHECK(notify_corner_anim(rows, 2) == ANIM_IDLE);
    rows[1].state = SESS_WORKING;
    CHECK(notify_corner_anim(rows, 2) == -1);
    rows[0].state = SESS_WORKING;
    CHECK(notify_corner_anim(rows, 2) == -1);
    rows[0].state = SESS_WAITING;
    CHECK(notify_corner_anim(rows, 2) == ANIM_ALERT);
    rows[0].state = rows[1].state = SESS_DONE;
    CHECK(notify_corner_anim(rows, 6) == ANIM_IDLE);
    CHECK(notify_corner_anim(rows, 7) == -1);
    CHECK(notify_corner_anim(rows, 8) == -1);
}

int main(void)
{
    test_first_sync();
    test_each();
    test_ready();
    test_quiet();
    test_counter_grew();
    test_age();
    test_state_holds();
    test_scope();
    test_priority();
    test_modes_and_servers();
    test_detail();
    test_plural();
    test_lang_and_interval();
    test_corner();
    if (s_fail) {
        printf("%d FAILED, %d ok\n", s_fail, s_ok);
        return 1;
    }
    printf("ALL PASS, %d ok\n", s_ok);
    return 0;
}
