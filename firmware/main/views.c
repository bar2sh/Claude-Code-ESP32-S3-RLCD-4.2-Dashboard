#include "views.h"
#include <stdio.h>
#include <string.h>
#include <sys/time.h>
#include "board.h"
#include "display.h"
#include "esp_timer.h"
#include "ui_text.h"

#define STATUS_US (10 * 1000000LL)   // status screen closes by itself after 10 s
#define TOAST_US (4 * 1000000LL)
#define NOTIFY_US (6 * 1000000LL)    // a session notification closes by itself after 6 s
#define WEATHER_STALE_S (3 * 3600)   // older: header shows the reading time
#define WEATHER_MAX_AGE_S (24 * 3600) // older: not shown at all
#define LIMIT_Y 285                  // plan limit bar along the bottom of the sessions view, 14 px tall, 1 px from the edge
#define LIMIT_H 14
#define LIMIT_WINDOW_S (5 * 3600)    // a reading older than the window says nothing and is not shown
#define CORNER_X (SCREEN_W - 4 - ANIM_SIZE) // corner animation of the sessions view, above the limit bar
#define CORNER_Y (LIMIT_Y - 1 - ANIM_SIZE)

static void draw_dashboard(void);
static void draw_message(void);
static void draw_wallpaper(void);
static void draw_sessions(void);
static void draw_clipped(u8g2_t *u, int x, int y, int max_w, const char *text);
static void draw_limit_bar(u8g2_t *u);
static void draw_anim(u8g2_t *u, anim_id_t id, int64_t start_us, int x, int y, int scale);

const view_def_t VIEWS[VIEW_COUNT] = {
    [VIEW_DASHBOARD] = {"dashboard", STR_VIEW_DASHBOARD, draw_dashboard},
    [VIEW_MESSAGE] = {"message", STR_VIEW_MESSAGE, draw_message},
    [VIEW_WALLPAPER] = {"wallpaper", STR_VIEW_WALLPAPER, draw_wallpaper},
    [VIEW_SESSIONS] = {"sessions", STR_VIEW_SESSIONS, draw_sessions},
};

static char s_toast[64];
static int64_t s_toast_until;  // esp_timer time in us
static int64_t s_status_until; // 0 = status screen closed

static notify_kind_t s_notify_kind;
static char s_notify_detail[sizeof ((notify_t *)0)->detail];
static anim_id_t s_notify_anim;
static int64_t s_notify_start; // esp_timer time of frame 0
static int64_t s_notify_until; // 0 = no notification on screen

// The animation drawn by the last views_render, -1 = none: only then are frames redrawn.
static int s_drawn_anim = -1;
static int64_t s_drawn_start;
static int s_drawn_frame;

int view_from_id(const char *id)
{
    for (int i = 0; i < VIEW_COUNT; i++)
        if (id && strcmp(VIEWS[i].id, id) == 0) return i;
    return -1;
}


static bool clock_valid(struct tm *tm)
{
    time_t now = time(NULL);
    localtime_r(&now, tm);
    return tm->tm_year + 1900 >= 2025;
}


static void format_age(char *out, size_t n, int64_t since)
{
    int64_t s = (int64_t)time(NULL) - since;
    if (s < 60) snprintf(out, n, "%s", tr(STR_AGE_NOW));
    else if (s < 3600) snprintf(out, n, "%d min", (int)(s / 60));
    else snprintf(out, n, "%d h", (int)(s / 3600));
}

// Top bar: mode on the left, time and battery on the right. 22 px tall.
static void draw_topbar(u8g2_t *u)
{
    u8g2_SetFont(u, u8g2_font_helvB10_te);
    char left[48];
    const char *net = g_app.mode == MODE_OFFLINE ? "" : (g_app.net_error[0] ? tr(STR_TOPBAR_NET_ERROR) : "");
    snprintf(left, sizeof left, "%s%s", mode_name(g_app.mode), net);
    u8g2_DrawUTF8(u, 6, 15, left);

    char right[32], power[8];
    struct tm tm;
    if (g_app.bat_mv > 0) snprintf(power, sizeof power, "%d%%", battery_pct(g_app.bat_mv));
    else snprintf(power, sizeof power, "USB");
    if (clock_valid(&tm)) snprintf(right, sizeof right, "%02d:%02d   %s", tm.tm_hour, tm.tm_min, power);
    else snprintf(right, sizeof right, "--:--   %s", power);
    draw_right(u, SCREEN_W - 6, 15, right);
    u8g2_DrawHLine(u, 0, 21, SCREEN_W);
}

static void draw_unconfigured_hint(u8g2_t *u, int y)
{
    u8g2_SetFont(u, u8g2_font_helvR10_te);
    draw_centered(u, y, tr(STR_HINT_UNCONFIGURED));
}

static void draw_dashboard(void)
{
    u8g2_t *u = display_u8g2();
    draw_topbar(u);
    struct tm tm;
    char buf[96];

    // Big clock
    u8g2_SetFont(u, u8g2_font_logisoso58_tn);
    if (clock_valid(&tm)) snprintf(buf, sizeof buf, "%02d:%02d", tm.tm_hour, tm.tm_min);
    else snprintf(buf, sizeof buf, "--:--");
    draw_centered(u, 96, buf);

    u8g2_SetFont(u, u8g2_font_helvR18_te);
    if (clock_valid(&tm)) {
        snprintf(buf, sizeof buf, tr(STR_FMT_DATE), tr_day(tm.tm_wday), tm.tm_mday, tr_month(tm.tm_mon));
        draw_centered(u, 130, buf);
    } else {
        draw_centered(u, 130, tr(STR_WAITING_FOR_TIME));
    }
    u8g2_DrawHLine(u, 20, 146, SCREEN_W - 40);

    // Left column: room climate
    u8g2_SetFont(u, u8g2_font_helvR10_te);
    u8g2_DrawUTF8(u, 24, 168, tr(STR_ROOM));
    u8g2_SetFont(u, u8g2_font_helvB24_te);
    if (g_app.climate_valid) snprintf(buf, sizeof buf, "%.0f°C", g_app.temp_c);
    else snprintf(buf, sizeof buf, "--");
    u8g2_DrawUTF8(u, 24, 202, buf);
    u8g2_SetFont(u, u8g2_font_helvR12_te);
    if (g_app.climate_valid) {
        snprintf(buf, sizeof buf, tr(STR_FMT_HUMIDITY), g_app.hum_pct);
        u8g2_DrawUTF8(u, 24, 224, buf);
    }

    // Right column: outdoor weather. Once it is not fresh the header shows when it was read.
    const weather_t *w = &g_app.weather;
    bool clock_ok = clock_valid(&tm);
    int64_t age = clock_ok ? (int64_t)time(NULL) - w->ts : -1; // -1: unknown
    int x = 210;
    u8g2_SetFont(u, u8g2_font_helvR10_te);
    if (w->valid && age <= WEATHER_MAX_AGE_S) {
        if (age >= 0 && age <= WEATHER_STALE_S) {
            snprintf(buf, sizeof buf, tr(STR_FMT_OUTDOORS), w->place[0] ? " · " : "", w->place);
        } else {
            struct tm r;
            time_t ts = (time_t)w->ts;
            localtime_r(&ts, &r);
            if (clock_ok && r.tm_yday == tm.tm_yday && r.tm_year == tm.tm_year)
                snprintf(buf, sizeof buf, tr(STR_FMT_WEATHER_AT), r.tm_hour, r.tm_min);
            else
                snprintf(buf, sizeof buf, tr(STR_FMT_WEATHER_AT_DAY), r.tm_mday, r.tm_mon + 1, r.tm_hour, r.tm_min);
        }
        u8g2_DrawUTF8(u, x, 168, buf);
        u8g2_SetFont(u, u8g2_font_helvB24_te);
        snprintf(buf, sizeof buf, "%d°C", w->temp);
        u8g2_DrawUTF8(u, x, 202, buf);
        u8g2_SetFont(u, u8g2_font_helvR12_te);
        u8g2_DrawUTF8(u, x, 224, tr_weather(w->code));
        snprintf(buf, sizeof buf, tr(STR_FMT_WEATHER_RANGE), w->tmin, w->tmax, w->rain);
        u8g2_DrawUTF8(u, x, 242, buf);
    } else {
        u8g2_DrawUTF8(u, x, 168, tr(STR_WEATHER));
        u8g2_SetFont(u, u8g2_font_helvR12_te);
        u8g2_DrawUTF8(u, x, 196, tr(STR_NO_DATA));
        u8g2_SetFont(u, u8g2_font_helvR10_te);
        if (w->valid) u8g2_DrawUTF8(u, x, 214, tr(STR_WEATHER_TOO_OLD));
        else if (g_app.last_sync > 0) u8g2_DrawUTF8(u, x, 214, tr(STR_CHECK_LOCATION));
    }

    // Bottom line: Claude sessions summary
    int working = 0, waiting = 0, done = 0;
    for (int i = 0; i < g_app.session_count; i++) {
        if (g_app.sessions[i].state == SESS_WORKING) working++;
        else if (g_app.sessions[i].state == SESS_WAITING) waiting++;
        else done++;
    }
    u8g2_DrawHLine(u, 0, 252, SCREEN_W);
    u8g2_SetFont(u, u8g2_font_helvB12_te);
    if (!g_app.configured) {
        draw_unconfigured_hint(u, 274);
    } else if (g_app.session_count == 0) {
        u8g2_SetFont(u, u8g2_font_helvR12_te);
        draw_centered(u, 274, tr(STR_CLAUDE_NO_SESSIONS));
    } else {
        snprintf(buf, sizeof buf, tr(STR_FMT_CLAUDE_SUMMARY), working, waiting, done);
        draw_centered(u, 274, buf);
    }
}

static void draw_message(void)
{
    u8g2_t *u = display_u8g2();
    draw_topbar(u);
    char buf[96];
    if (g_app.msg.id == 0) {
        u8g2_SetFont(u, u8g2_font_helvR18_te);
        draw_centered(u, 160, tr(STR_NO_MESSAGE));
        return;
    }
    u8g2_SetFont(u, u8g2_font_helvB14_te);
    snprintf(buf, sizeof buf, tr(STR_FMT_FROM), g_app.msg.from);
    u8g2_DrawUTF8(u, 14, 46, buf);
    struct tm tm;
    time_t ts = (time_t)g_app.msg.ts;
    localtime_r(&ts, &tm);
    u8g2_SetFont(u, u8g2_font_helvR12_te);
    snprintf(buf, sizeof buf, "%02d:%02d", tm.tm_hour, tm.tm_min);
    draw_right(u, SCREEN_W - 14, 46, buf);
    u8g2_DrawHLine(u, 14, 54, SCREEN_W - 28);

    // Largest font that fits.
    const uint8_t *fonts[] = {u8g2_font_helvB24_te, u8g2_font_helvB18_te, u8g2_font_helvB14_te,
                              u8g2_font_helvR12_te, u8g2_font_helvR10_te};
    for (int i = 0; i < 5; i++) {
        u8g2_SetFont(u, fonts[i]);
        int first = 54 + u8g2_GetAscent(u) + 12;
        if (draw_wrapped(u, 14, first, SCREEN_W - 28, LIMIT_Y - 4, g_app.msg.text, false) >= 0 || i == 4) {
            draw_wrapped(u, 14, first, SCREEN_W - 28, LIMIT_Y - 4, g_app.msg.text, true);
            break;
        }
    }
}

static void draw_wallpaper(void)
{
    u8g2_t *u = display_u8g2();
    if (!g_app.has_wallpaper) {
        draw_topbar(u);
        u8g2_SetFont(u, u8g2_font_helvR18_te);
        draw_centered(u, 150, tr(STR_NO_WALLPAPER));
        u8g2_SetFont(u, u8g2_font_helvR12_te);
        draw_centered(u, 180, tr(STR_UPLOAD_WALLPAPER));
        return;
    }
    draw_wallpaper_image(u, g_app.wallpaper);
}

static void draw_sessions(void)
{
    u8g2_t *u = display_u8g2();
    draw_topbar(u);
    u8g2_SetFont(u, u8g2_font_helvB14_te);
    u8g2_DrawUTF8(u, 14, 46, tr(STR_SESSIONS_TITLE));
    // The corner shows what the sessions do; a 7th row would run into it, so above 6 rows it is gone.
    int corner = notify_corner_anim(g_app.sessions, g_app.session_count);
    if (corner >= 0) draw_anim(u, (anim_id_t)corner, 0, CORNER_X, CORNER_Y, 1);
    if (g_app.session_count == 0) {
        u8g2_SetFont(u, u8g2_font_helvR14_te);
        draw_centered(u, 160, g_app.configured ? tr(STR_NO_SESSIONS) : tr(STR_NET_UNCONFIGURED));
        draw_limit_bar(u);
        return;
    }
    // Short labels leave the title most of the row; a waiting row is also inverted, so "waiting" is enough.
    static const str_id_t labels[] = {STR_SESS_WORKING, STR_SESS_WAITING, STR_SESS_DONE};
    int y = 60, row_h = 27; // 8 rows end at 276, above the limit bar
    char age[16];
    for (int i = 0; i < g_app.session_count && y + row_h < LIMIT_Y; i++, y += row_h) {
        const session_t *s = &g_app.sessions[i];
        bool hi = s->state == SESS_WAITING;
        if (hi) {
            u8g2_DrawBox(u, 8, y, SCREEN_W - 16, row_h - 3);
            u8g2_SetDrawColor(u, 0);
        }
        u8g2_SetFont(u, u8g2_font_helvR12_te);
        format_age(age, sizeof age, s->since);
        char right[64];
        if (s->tasks_t > 0) // task progress first: it is short, and the title takes what is left
            snprintf(right, sizeof right, "%u/%u · %s · %s", s->tasks_d, s->tasks_t, tr(labels[s->state]), age);
        else snprintf(right, sizeof right, "%s · %s", tr(labels[s->state]), age);
        int right_w = u8g2_GetUTF8Width(u, right);
        draw_right(u, SCREEN_W - 16, y + 20, right);
        u8g2_SetFont(u, u8g2_font_helvB12_te);
        draw_clipped(u, 16, y + 20, SCREEN_W - 16 - right_w - 12 - 16, s->title[0] ? s->title : s->alias);
        u8g2_SetDrawColor(u, 1);
    }
    draw_limit_bar(u);
}

// Draws text with its baseline at y; if it is wider than max_w, cuts it at a character boundary and ends it with an ellipsis.
static void draw_clipped(u8g2_t *u, int x, int y, int max_w, const char *text)
{
    if (u8g2_GetUTF8Width(u, text) <= max_w) {
        u8g2_DrawUTF8(u, x, y, text);
        return;
    }
    const char *dots = u8g2_IsGlyph(u, 0x2026) ? "…" : "..."; // the helv _te fonts end at U+02BD
    char buf[sizeof ((session_t *)0)->title + 4];
    size_t n = strlen(text);
    if (n > sizeof buf - 4) n = sizeof buf - 4;
    for (;;) {
        while (n > 0 && ((unsigned char)text[n] & 0xC0) == 0x80) n--; // never split a UTF-8 sequence
        size_t k = n;
        while (k > 0 && text[k - 1] == ' ') k--;                    // no space before the ellipsis
        snprintf(buf, sizeof buf, "%.*s%s", (int)k, text, dots);
        if (n == 0 || u8g2_GetUTF8Width(u, buf) <= max_w) break;
        n--;
    }
    u8g2_DrawUTF8(u, x, y, buf);
}

// "1 h 20 min", "7 min", "<1 min".
static void format_span(char *out, size_t n, int64_t s)
{
    if (s < 60) snprintf(out, n, "%s", tr(STR_UNDER_MINUTE));
    else if (s < 3600) snprintf(out, n, "%d min", (int)(s / 60));
    else snprintf(out, n, "%d h %d min", (int)(s / 3600), (int)(s % 3600 / 60));
}

// Plan limit of the 5 hour window as a bar along the bottom with its text inside (XOR, so it reads on black and
// on white). Fail-closed: no bar without a clock, without a reading younger than the window, or when a turn did not
// stop on the limit; a window that has reset says "renewed" without a percentage; the age of the reading is always named.
static void draw_limit_bar(u8g2_t *u)
{
    struct tm tm;
    if (!clock_valid(&tm)) return;
    const usage_t *l = &g_app.usage;
    int64_t now = (int64_t)time(NULL);
    bool fresh = l->valid && l->read_at <= now + 60 && now - l->read_at <= LIMIT_WINDOW_S;
    char text[96], a[24], b[24];
    int fill = 0;
    if (l->hit) {
        fill = 100;
        if (fresh && l->reset_at > now) {
            format_span(a, sizeof a, l->reset_at - now);
            snprintf(text, sizeof text, tr(STR_FMT_LIMIT_HIT_RESET), a);
        } else {
            snprintf(text, sizeof text, "%s", tr(STR_LIMIT_HIT));
        }
    } else if (!fresh) {
        return;
    } else if (l->reset_at <= now) {
        format_span(a, sizeof a, now - l->read_at);
        snprintf(text, sizeof text, tr(STR_FMT_LIMIT_RENEWED), a);
    } else {
        fill = l->pct;
        format_span(a, sizeof a, l->reset_at - now);
        format_span(b, sizeof b, now - l->read_at);
        snprintf(text, sizeof text, tr(STR_FMT_LIMIT), l->pct, a, b);
    }
    u8g2_SetDrawColor(u, 0);
    u8g2_DrawBox(u, 0, LIMIT_Y - 2, SCREEN_W, LIMIT_H + 3); // clears what lies under it (the wallpaper)
    u8g2_SetDrawColor(u, 1);
    u8g2_DrawFrame(u, 4, LIMIT_Y, SCREEN_W - 8, LIMIT_H);
    int w = (SCREEN_W - 12) * fill / 100;
    if (w > 0) u8g2_DrawBox(u, 6, LIMIT_Y + 2, w, LIMIT_H - 4);
    u8g2_SetFont(u, u8g2_font_helvR10_te);
    u8g2_SetDrawColor(u, 2);
    u8g2_DrawUTF8(u, 10, LIMIT_Y + 11, text);
    u8g2_SetDrawColor(u, 1);
}

// The frame on screen at now_us of an animation that started at start_us; animations loop.
// left_us: how much longer that frame shows.
static int frame_at(const anim_t *a, int64_t start_us, int64_t now_us, int64_t *left_us)
{
    int64_t loop_us = 0; // anim2c.mjs checks 1-255 frames of 1-65535 ms, so never 0
    for (int i = 0; i < a->count; i++) loop_us += a->ms[i] * 1000LL;
    int64_t t = now_us > start_us ? (now_us - start_us) % loop_us : 0;
    for (int i = 0; i < a->count; i++) {
        int64_t d = a->ms[i] * 1000LL;
        if (t < d) {
            *left_us = d - t;
            return i;
        }
        t -= d;
    }
    *left_us = a->ms[0] * 1000LL; // not reached: t < loop_us
    return 0;
}

static bool frame_px(const uint8_t *frame, int x, int y)
{
    return frame[y * (ANIM_SIZE / 8) + x / 8] & (0x80 >> (x % 8));
}

// One 64x64 frame, 1 bits black, each pixel a scale x scale square: one box per run of black pixels.
static void draw_frame(u8g2_t *u, int x, int y, int scale, const uint8_t *frame)
{
    for (int row = 0; row < ANIM_SIZE; row++) {
        for (int col = 0; col < ANIM_SIZE;) {
            if (!frame_px(frame, col, row)) {
                col++;
                continue;
            }
            int run = col;
            while (col < ANIM_SIZE && frame_px(frame, col, row)) col++;
            u8g2_DrawBox(u, x + run * scale, y + row * scale, (col - run) * scale, scale);
        }
    }
}

// Draws the current frame and remembers it, so the main loop knows when the next one is due.
static void draw_anim(u8g2_t *u, anim_id_t id, int64_t start_us, int x, int y, int scale)
{
    int64_t left_us;
    int f = frame_at(&ANIMS[id], start_us, esp_timer_get_time(), &left_us);
    draw_frame(u, x, y, scale, ANIMS[id].frames[f]);
    s_drawn_anim = id;
    s_drawn_start = start_us;
    s_drawn_frame = f;
}

// Full screen: the animation at scale 2 (128x128) on top, the title and the session below it.
static void draw_notify(void)
{
    u8g2_t *u = display_u8g2();
    draw_anim(u, s_notify_anim, s_notify_start, (SCREEN_W - 2 * ANIM_SIZE) / 2, 26, 2);
    u8g2_SetFont(u, u8g2_font_helvB24_te);
    draw_centered(u, 198, tr(s_notify_kind == NOTIFY_WAIT ? STR_NOTIFY_WAIT : STR_NOTIFY_DONE));
    u8g2_SetFont(u, u8g2_font_helvR14_te);
    if (u8g2_GetUTF8Width(u, s_notify_detail) <= SCREEN_W - 32) draw_centered(u, 232, s_notify_detail);
    else draw_clipped(u, 16, 232, SCREEN_W - 32, s_notify_detail);
    u8g2_SetFont(u, u8g2_font_helvR10_te);
    draw_centered(u, 290, tr(STR_NOTIFY_CLOSE));
}

static void draw_status_screen(void)
{
    u8g2_t *u = display_u8g2();
    char buf[96];
    u8g2_SetFont(u, u8g2_font_helvB18_te);
    u8g2_DrawUTF8(u, 16, 34, tr(STR_STATUS_TITLE));
    u8g2_DrawHLine(u, 16, 44, SCREEN_W - 32);
    u8g2_SetFont(u, u8g2_font_helvR14_te);
    int y = 74;
    if (g_app.mode == MODE_LISTEN) {
        char every[24];
        int p = g_app.poll_s;
        if (p < 60 || p % 60) snprintf(every, sizeof every, "%d s", p);
        else snprintf(every, sizeof every, "%d min", p / 60);
        snprintf(buf, sizeof buf, tr(STR_FMT_MODE_EVERY), mode_name(g_app.mode), every);
    } else {
        snprintf(buf, sizeof buf, tr(STR_FMT_MODE), mode_name(g_app.mode));
    }
    u8g2_DrawUTF8(u, 16, y, buf), y += 28;
    snprintf(buf, sizeof buf, tr(STR_FMT_VIEW), tr(VIEWS[g_app.view].label));
    u8g2_DrawUTF8(u, 16, y, buf), y += 28;
    const char *wifi = !g_app.configured          ? tr(STR_WIFI_UNCONFIGURED)
                       : g_app.wifi_up             ? tr(STR_WIFI_CONNECTED)
                       : g_app.mode == MODE_LISTEN ? tr(STR_WIFI_CONNECTING)
                                                   : tr(STR_WIFI_OFF);
    snprintf(buf, sizeof buf, tr(STR_FMT_WIFI), wifi);
    u8g2_DrawUTF8(u, 16, y, buf), y += 28;
    if (g_app.bat_mv > 0)
        snprintf(buf, sizeof buf, tr(STR_FMT_BATTERY), battery_pct(g_app.bat_mv), g_app.bat_mv / 1000.0);
    else snprintf(buf, sizeof buf, "%s", tr(STR_POWER_USB));
    u8g2_DrawUTF8(u, 16, y, buf), y += 28;
    if (g_app.last_sync > 0) {
        struct tm tm;
        time_t t = (time_t)g_app.last_sync;
        localtime_r(&t, &tm);
        snprintf(buf, sizeof buf, tr(STR_FMT_SYNC_AT), tm.tm_hour, tm.tm_min, tm.tm_sec);
    } else {
        snprintf(buf, sizeof buf, "%s", tr(STR_SYNC_NEVER));
    }
    u8g2_DrawUTF8(u, 16, y, buf), y += 28;
    if (g_app.net_error[0]) {
        snprintf(buf, sizeof buf, tr(STR_FMT_ERROR), g_app.net_error);
        u8g2_DrawUTF8(u, 16, y, buf);
    }
    u8g2_SetFont(u, u8g2_font_helvR10_te);
    draw_centered(u, 270, tr(STR_STATUS_FOOTER));
}

void views_toggle_status(void)
{
    int64_t now = esp_timer_get_time();
    s_status_until = s_status_until > now ? 0 : now + STATUS_US;
}

void views_close_status(void) { s_status_until = 0; }

void views_toast(const char *text)
{
    snprintf(s_toast, sizeof s_toast, "%s", text);
    s_toast_until = esp_timer_get_time() + TOAST_US;
}

bool views_overlay_expired(void)
{
    int64_t now = esp_timer_get_time();
    bool expired = false;
    if (s_status_until && s_status_until <= now) {
        s_status_until = 0;
        expired = true;
    }
    if (s_toast[0] && s_toast_until <= now) {
        s_toast[0] = 0;
        expired = true;
    }
    if (s_notify_until && s_notify_until <= now) {
        s_notify_until = 0;
        expired = true;
    }
    return expired;
}

void views_notify(notify_kind_t kind, const char *detail, anim_id_t anim)
{
    if (kind == NOTIFY_NONE || (unsigned)anim >= ANIM_COUNT) return;
    s_notify_kind = kind;
    snprintf(s_notify_detail, sizeof s_notify_detail, "%s", detail ? detail : "");
    s_notify_anim = anim;
    s_notify_start = esp_timer_get_time();
    s_notify_until = s_notify_start + NOTIFY_US;
}

bool views_notify_close(void)
{
    bool open = s_notify_until > esp_timer_get_time();
    s_notify_until = 0;
    return open;
}

int views_frame_wait_ms(int max_ms)
{
    if (s_drawn_anim < 0) return max_ms;
    int64_t left_us;
    frame_at(&ANIMS[s_drawn_anim], s_drawn_start, esp_timer_get_time(), &left_us);
    int64_t ms = left_us / 1000 + 1; // rounded up: never wake just before the frame changes
    return ms < max_ms ? (int)ms : max_ms;
}

bool views_frame_due(void)
{
    if (s_drawn_anim < 0) return false;
    int64_t left_us;
    return frame_at(&ANIMS[s_drawn_anim], s_drawn_start, esp_timer_get_time(), &left_us) != s_drawn_frame;
}

void views_render(void)
{
    u8g2_t *u = display_u8g2();
    int64_t now = esp_timer_get_time();
    u8g2_ClearBuffer(u);
    u8g2_SetDrawColor(u, 1);
    s_drawn_anim = -1; // whatever draws an animation sets it again
    bool notify = s_notify_until > now;
    if (notify) draw_notify(); // over everything, the status screen too
    else if (s_status_until > now) draw_status_screen();
    else VIEWS[g_app.view].draw(); // the plan limit bar belongs to the sessions view alone, which draws it itself
    if (!notify && s_toast[0] && s_toast_until > now) {
        u8g2_SetFont(u, u8g2_font_helvB12_te);
        int w = u8g2_GetUTF8Width(u, s_toast) + 24;
        int x = (SCREEN_W - w) / 2;
        u8g2_SetDrawColor(u, 0);
        u8g2_DrawBox(u, x - 2, 238, w + 4, 40);
        u8g2_SetDrawColor(u, 1);
        u8g2_DrawBox(u, x, 240, w, 36);
        u8g2_SetDrawColor(u, 0);
        u8g2_DrawUTF8(u, x + 12, 264, s_toast);
        u8g2_SetDrawColor(u, 1);
    }
    display_present();
}
