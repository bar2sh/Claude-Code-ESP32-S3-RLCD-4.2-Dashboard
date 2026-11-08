#include "notify.h"
#include <stdio.h>
#include <string.h>

// Condition 3: the event is at most NOTIFY_MAX_AGE_S plus one poll interval old by the server's own clock,
// so a board that polls only every hour (POLL_MAX_S) still notifies an event from just after its previous poll.
static bool fresh(const notify_input_t *in, int64_t at)
{
    int64_t now = in->now, poll = in->poll_s > 0 ? in->poll_s : 0;
    return now > 0 && at > 0 && now >= at && now - at <= NOTIFY_MAX_AGE_S + poll;
}

static const session_t *find_row(const notify_input_t *in, const char *id)
{
    for (int i = 0; id[0] && i < in->row_count; i++) {
        if (strcmp(in->rows[i].id, id) == 0) return &in->rows[i];
    }
    return NULL;
}

static bool any_active(const notify_input_t *in)
{
    for (int i = 0; i < in->row_count; i++) {
        if (in->rows[i].state != SESS_DONE) return true;
    }
    return false;
}

// The title of the session, its alias without one, or "N sesje" after several events since the last sync.
static void row_detail(char *out, size_t n, const notify_input_t *in, const session_t *row, int64_t events)
{
    if (events > 1) notify_sessions_text(out, n, events, in->lang);
    else snprintf(out, n, "%s", row->title[0] ? row->title : row->alias);
}

void notify_sessions_text(char *out, size_t n, int64_t count, app_lang_t lang)
{
    if (lang != LANG_PL) {
        snprintf(out, n, "%lld %s", (long long)count, count == 1 ? "session" : "sessions");
        return;
    }
    int64_t ones = count % 10, tens = count % 100;
    const char *word = "sesji";
    if (count == 1) word = "sesja";
    else if (ones >= 2 && ones <= 4 && (tens < 12 || tens > 14)) word = "sesje";
    snprintf(out, n, "%lld %s", (long long)count, word);
}

notify_t notify_decide(const notify_input_t *in, notify_seen_t *seen)
{
    notify_t out = {.kind = NOTIFY_NONE};
    if (in->set == NULL || in->ev == NULL) {
        seen->known = false; // the next full answer with counters takes them like a first sync
        return out;
    }
    const notify_settings_t *set = in->set;
    const notify_ev_t *ev = in->ev;
    notify_seen_t was = *seen;
    seen->known = true;
    seen->ev = *ev;
    if (!was.known || !in->listen) return out; // the first sync and ON DEMAND take the counters silently
    if (set->mode == NOTIFY_QUIET || in->message_sound) return out;
    if (set->scope == NOTIFY_SCOPE_SESSIONS && !in->on_sessions) return out;

    // "Czeka na Ciebie" first: the row still waits.
    int64_t waits = ev->wait - was.ev.wait;
    const session_t *row = find_row(in, ev->wait_id);
    if (waits > 0 && fresh(in, ev->wait_at) && row && row->state == SESS_WAITING) {
        out.kind = NOTIFY_WAIT;
        row_detail(out.detail, sizeof out.detail, in, row, waits);
        return out;
    }
    if (set->mode == NOTIFY_READY) { // "Gotowe" once no row is active any more
        if (ev->all > was.ev.all && fresh(in, ev->all_at) && !any_active(in)) {
            out.kind = NOTIFY_DONE;
            snprintf(out.detail, sizeof out.detail, "%s",
                     in->lang == LANG_PL ? "Wszystkie sesje skończyły" : "All sessions finished");
        }
        return out;
    }
    if (set->mode != NOTIFY_EACH) return out;
    int64_t dones = ev->done - was.ev.done; // "Gotowe" after every answer: the row is still done
    row = find_row(in, ev->done_id);
    if (dones > 0 && fresh(in, ev->done_at) && row && row->state == SESS_DONE) {
        out.kind = NOTIFY_DONE;
        row_detail(out.detail, sizeof out.detail, in, row, dones);
    }
    return out;
}

int notify_corner_anim(const session_t *rows, int count)
{
    if (count > NOTIFY_CORNER_MAX_ROWS) return -1;
    bool working = false;
    for (int i = 0; i < count; i++) {
        if (rows[i].state == SESS_WAITING) return ANIM_ALERT;
        if (rows[i].state == SESS_WORKING) working = true;
    }
    if (working) return -1; // no corner while a session works: a loop for the whole turn would distract
    return count > 0 ? ANIM_IDLE : ANIM_SLEEP;
}
