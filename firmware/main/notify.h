// Session notifications (docs/SPEC.md, "Powiadomienia o sesjach Claude"): whether a full poll answer
// shows "Gotowe" or "Czeka na Ciebie", and which animation plays in the corner of the session list.
// Pure functions without hardware or network, so firmware/tests/notify_test.c runs them on a Mac.
#pragma once
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "anim_frames.h" // only anim_id_t: notify.c does not use the frames
#include "app.h"

typedef enum { NOTIFY_QUIET = 0, NOTIFY_READY, NOTIFY_EACH } notify_mode_t;
typedef enum { NOTIFY_SCOPE_SESSIONS = 0, NOTIFY_SCOPE_ALL } notify_scope_t;
typedef enum { NOTIFY_NONE = 0, NOTIFY_DONE, NOTIFY_WAIT } notify_kind_t;

#define NOTIFY_MAX_AGE_S 120     // plus the poll interval: an older event, e.g. after a network break, is skipped
#define NOTIFY_CORNER_MAX_ROWS 6 // the list starts at y = 60 with 29 px rows: rows 7 and 8 reach the corner

typedef struct {
    char sound[12]; // as the sound of a message: "none", a built-in tone or an id from the sound library
    anim_id_t anim;
} notify_alert_t;

// The settings from the server (state.json "notify"), one set for the whole board.
typedef struct {
    notify_mode_t mode;
    notify_scope_t scope;
    notify_alert_t done; // "Gotowe"
    notify_alert_t wait; // "Czeka na Ciebie"
} notify_settings_t;

// The event counters the server keeps (state.json "ev"). They only grow; *_at is unix time.
typedef struct {
    int64_t done, done_at, all, all_at, wait, wait_at;
    char done_id[sizeof ((session_t *)0)->id]; // the row of the last event, as session_t.id
    char wait_id[sizeof ((session_t *)0)->id];
} notify_ev_t;

// The counters of the last comparison, kept in RAM only, like the session list.
typedef struct {
    bool known; // false until the first full answer with counters: that one notifies nothing
    notify_ev_t ev;
} notify_seen_t;

typedef struct {
    bool listen;                  // the board is in MODE_LISTEN, the only mode that notifies
    bool on_sessions;             // the view after this answer is VIEW_SESSIONS
    bool message_sound;           // a new message plays its sound in this sync: it wins
    int64_t now;                  // "time" of the answer, unix seconds
    int poll_s;                   // seconds between polls: an event may be that much older than NOTIFY_MAX_AGE_S
    app_lang_t lang;              // language of notify_t.detail
    const notify_settings_t *set; // NULL with ev = NULL: an older api.php without notify and ev
    const notify_ev_t *ev;
    const session_t *rows; // the session list of the same answer
    int row_count;
} notify_input_t;

typedef struct {
    notify_kind_t kind;
    char detail[sizeof ((session_t *)0)->title]; // the line under the title, not yet clipped to the screen
} notify_t;

// Compares the counters of a full poll answer with the ones seen last time and remembers the new ones
// in *seen, also when nothing plays, so a skipped event does not come back later.
notify_t notify_decide(const notify_input_t *in, notify_seen_t *seen);

// A count of sessions: "1 session", "3 sessions"; in Polish with its plural: "1 sesja", "3 sesje", "5 sesji".
void notify_sessions_text(char *out, size_t n, int64_t count, app_lang_t lang);

// The anim_id_t for the corner of the session list, by its most important state; -1 when the list has
// more than NOTIFY_CORNER_MAX_ROWS rows and needs the corner itself.
int notify_corner_anim(const session_t *rows, int count);
