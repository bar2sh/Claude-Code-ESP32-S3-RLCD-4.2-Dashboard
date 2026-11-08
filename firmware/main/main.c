// Main loop: buttons, the three network modes, sensors and drawing. Only this task
// writes g_app; the button and audio tasks just pass messages.
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/time.h>
#include <time.h>
#include "app.h"
#include "audio.h"
#include "board.h"
#include "display.h"
#include "esp_heap_caps.h"
#include "esp_log.h"
#include "esp_timer.h"
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include "net.h"
#include "notify.h"
#include "store.h"
#include "ui_text.h"
#include "views.h"

static const char *TAG = "main";

#define SENSOR_US (60 * 1000000LL)     // room climate and battery
#define WIFI_GRACE_US (20 * 1000000LL) // time to connect before NASŁUCH reports a Wi-Fi error
#define ONDEMAND_WAIT_MS 15000         // NA ŻĄDANIE: how long BOOT waits for Wi-Fi
#define SOUND_MAX_AGE_S 1800           // older messages arrive silently, e.g. after a long offline
#define WEATHER_SAVE_S 1800            // refresh the stored reading time at most this often
#define CLOCK_MIN_VALID 1700000000     // earlier is not a real server time
#define LOOP_WAIT_MS 250               // button wait per loop turn; shorter while an animation is on screen
#define TZ_POLAND "CET-1CEST,M3.5.0,M10.5.0/3"

app_state_t g_app;

static QueueHandle_t s_buttons;
static poll_data_t s_poll; // too big for the stack
static bool s_dirty;
static int64_t s_next_poll_us;
static int64_t s_listen_since_us;
static int64_t s_next_sensor_us;
static int64_t s_weather_saved;
static notify_seen_t s_seen; // session event counters of the last full poll

// TOLD: something changed and the screen already explains the outcome (a toast or a notification).
typedef enum { SYNC_FAIL, SYNC_SAME, SYNC_CHANGED, SYNC_TOLD } sync_t;

const char *mode_name(app_mode_t m)
{
    return (unsigned)m < MODE_COUNT ? tr((str_id_t)(STR_MODE_LISTEN + m)) : "?";
}

static void on_button(btn_event_t ev)
{
    xQueueSend(s_buttons, &ev, 0); // runs in the button task; a full queue drops the press
}

static int64_t now_s(void) { return (int64_t)time(NULL); }

// The server's clock is the reference; the RTC keeps the time across power cuts.
static void set_clock(int64_t server_time)
{
    if (server_time < CLOCK_MIN_VALID) return;
    int64_t diff = server_time - now_s();
    if (diff >= -2 && diff <= 2) return;
    struct timeval tv = {.tv_sec = (time_t)server_time};
    settimeofday(&tv, NULL);
    if (!rtc_write((time_t)server_time)) ESP_LOGW(TAG, "RTC write failed");
    ESP_LOGI(TAG, "clock set from the server, was off by %lld s", (long long)diff);
}

static void read_sensors(void)
{
    float t, h;
    g_app.climate_valid = climate_read(&t, &h);
    if (g_app.climate_valid) {
        g_app.temp_c = t;
        g_app.hum_pct = h;
    }
    g_app.bat_mv = BATTERY_FITTED ? battery_mv() : 0; // 0 = USB power, no cell
    s_dirty = true;
}

static bool same_weather(const weather_t *a, const weather_t *b)
{
    return a->valid == b->valid && a->temp == b->temp && a->tmin == b->tmin && a->tmax == b->tmax
        && a->rain == b->rain && a->code == b->code && strcmp(a->place, b->place) == 0;
}

// Each reading comes with the time the server took it, also an old one it keeps while Open-Meteo
// is down. A server without weather leaves the last reading here. Either way the dashboard shows
// the age of the reading, so nothing old passes for current.
static void apply_weather(const weather_t *w)
{
    if (!w->valid) return;
    weather_t fresh = *w;
    int64_t now = now_s();
    if (fresh.ts > now) fresh.ts = now; // the board clock may trail the server by up to 2 s
    bool differs = !same_weather(&fresh, &g_app.weather);
    g_app.weather = fresh;
    if (differs || fresh.ts - s_weather_saved >= WEATHER_SAVE_S) {
        store_save_weather();
        s_weather_saved = fresh.ts;
    }
}

static bool fetch_wallpaper(void)
{
    uint8_t *buf = heap_caps_malloc(WALLPAPER_BYTES, MALLOC_CAP_SPIRAM);
    if (!buf) {
        snprintf(g_app.net_error, sizeof g_app.net_error, "%s", tr(STR_ERR_WALLPAPER_MEMORY));
        return false;
    }
    if (!net_fetch_wallpaper(buf)) {
        free(buf);
        return false;
    }
    free(g_app.wallpaper);
    g_app.wallpaper = buf;
    g_app.has_wallpaper = true;
    return true;
}

// Settings come with every poll. Values the board does not know (-1) keep the current ones.
static void apply_settings(const poll_data_t *p)
{
    bool changed = false;
    if (p->lang >= 0 && p->lang < LANG_COUNT && (app_lang_t)p->lang != g_app.lang) {
        g_app.lang = (app_lang_t)p->lang;
        changed = true;
    }
    if (p->poll_s > 0 && poll_interval_ok(p->poll_s) && p->poll_s != g_app.poll_s) {
        g_app.poll_s = p->poll_s; // the next poll_tick schedules with it
        changed = true;
    }
    if (!changed) return;
    store_save_settings();
    s_dirty = true;
    ESP_LOGI(TAG, "settings: lang %d, poll every %d s", g_app.lang, g_app.poll_s);
}

// Returns true when a new message arrived.
static bool apply_poll(const poll_data_t *p)
{
    set_clock(p->time);
    g_app.last_sync = now_s();
    apply_weather(&p->weather);
    apply_settings(p);
    const usage_t *a = &g_app.usage, *b = &p->usage; // field by field: the struct has padding
    // times within 2 s are the same reading: the server's clock ticks between the fields
    if (a->valid != b->valid || a->pct != b->pct || llabs(a->reset_at - b->reset_at) > 2 || llabs(a->read_at - b->read_at) > 2
        || a->hit != b->hit) {
        g_app.usage = p->usage;
        s_dirty = true;
    }
    if (!p->changed) return false;

    int v = view_from_id(p->view); // unknown ids come from apps newer than this firmware
    if (v >= 0 && p->view_seq != g_app.view_seq) {
        g_app.view = (app_view_t)v;
        g_app.view_seq = p->view_seq;
        store_save_view();
        views_close_status();
        views_notify_close();
    }

    // id 0 means the server has no message; the board then clears its copy too.
    bool fresh = false;
    if (p->msg.id != g_app.msg.id || p->msg.ts != g_app.msg.ts) {
        g_app.msg = p->msg;
        store_save_message();
        fresh = p->msg.id != 0;
    }

    bool complete = true;
    if (p->wallpaper_rev > 0 && (p->wallpaper_rev != g_app.wallpaper_rev || !g_app.has_wallpaper)) {
        if (fetch_wallpaper()) {
            g_app.wallpaper_rev = p->wallpaper_rev;
            store_save_wallpaper();
        } else {
            complete = false; // rev -1 makes the next poll bring everything again
        }
    }

    memcpy(g_app.sessions, p->sessions, sizeof g_app.sessions);
    g_app.session_count = p->session_count;
    g_app.rev = complete ? p->rev : -1;
    return fresh;
}

// The message just stored has a sound and is new enough for it to play.
static bool message_sound_due(void)
{
    const char *sound = g_app.msg.sound;
    if (!sound[0] || strcmp(sound, "none") == 0) return false;
    int64_t age = (s_poll.time ? s_poll.time : now_s()) - g_app.msg.ts;
    if (age > SOUND_MAX_AGE_S) {
        ESP_LOGI(TAG, "message is %lld s old, no sound", (long long)age);
        return false;
    }
    return true;
}

// A built-in tone, or a library sound downloaded into PSRAM each time. False when the download
// failed: chime played instead and g_app.net_error says why.
static bool play_sound(const char *sound)
{
    if (!audio_is_custom(sound)) {
        audio_play(sound);
        return true;
    }
    int16_t *pcm;
    size_t samples;
    if (net_fetch_sound(sound, &pcm, &samples)) {
        audio_play_pcm(pcm, samples);
        return true;
    }
    audio_play("chime");
    return false;
}

// Returns true when it showed a toast.
static bool play_message_sound(void)
{
    char before[sizeof g_app.net_error];
    memcpy(before, g_app.net_error, sizeof before);
    if (play_sound(g_app.msg.sound)) return false;
    // The message itself arrived, only its sound did not: the reason in a toast.
    views_toast(g_app.net_error);
    memcpy(g_app.net_error, before, sizeof before);
    return true;
}

// Session notifications after a full poll (docs/SPEC.md, "Powiadomienia o sesjach Claude").
// Returns true when it showed one. A failed sound download leaves its reason in g_app.net_error
// for the status screen; the next successful poll clears it.
static bool notify_sessions(bool message_sound)
{
    const poll_data_t *p = &s_poll;
    notify_input_t in = {
        .listen = g_app.mode == MODE_LISTEN,
        .on_sessions = g_app.view == VIEW_SESSIONS,
        .message_sound = message_sound,
        .now = p->time,
        .poll_s = g_app.poll_s,
        .lang = g_app.lang,
        .set = p->has_notify ? &p->notify : NULL,
        .ev = p->has_notify ? &p->ev : NULL,
        .rows = g_app.sessions,
        .row_count = g_app.session_count,
    };
    notify_t n = notify_decide(&in, &s_seen);
    if (n.kind == NOTIFY_NONE) return false;
    const notify_alert_t *a = n.kind == NOTIFY_WAIT ? &p->notify.wait : &p->notify.done;
    ESP_LOGI(TAG, "notification %s: %s", n.kind == NOTIFY_WAIT ? "wait" : "done", n.detail);
    views_notify(n.kind, n.detail, a->anim);
    views_render(); // on screen before its sound starts
    play_sound(a->sound);
    return true;
}

static sync_t sync_now(void)
{
    s_dirty = true;
    if (!net_poll(&s_poll)) return SYNC_FAIL;
    bool fresh = apply_poll(&s_poll);
    bool message_sound = fresh && message_sound_due(); // a message that sounds wins over a notification
    // Every full poll goes through notify_decide, so the counters stay in step even when nothing shows.
    if (s_poll.changed && notify_sessions(message_sound)) return SYNC_TOLD;
    if (fresh) {
        views_render(); // the message is on screen before its sound starts
        if (message_sound && play_message_sound()) return SYNC_TOLD;
    }
    return s_poll.changed ? SYNC_CHANGED : SYNC_SAME;
}

static void report_sync(sync_t r)
{
    if (r == SYNC_TOLD) return;
    if (r == SYNC_FAIL || g_app.net_error[0]) {
        views_toast(g_app.net_error[0] ? g_app.net_error : tr(STR_SYNC_FAILED));
    } else {
        views_toast(tr(r == SYNC_SAME ? STR_NO_CHANGES : STR_UPDATED));
    }
}

static void start_mode(void)
{
    if (g_app.mode == MODE_LISTEN && g_app.configured) {
        net_wifi_on();
        s_listen_since_us = esp_timer_get_time();
        s_next_poll_us = 0;
    } else {
        net_wifi_off();
    }
}

static void poll_tick(int64_t now_us)
{
    if (g_app.mode != MODE_LISTEN || !g_app.configured || now_us < s_next_poll_us) return;
    if (!g_app.wifi_up && now_us - s_listen_since_us < WIFI_GRACE_US) return; // still connecting
    sync_now();
    s_next_poll_us = esp_timer_get_time() + g_app.poll_s * 1000000LL;
}

static void on_boot_short(void)
{
    if (!g_app.configured) {
        views_toast(tr(STR_TOAST_UNCONFIGURED));
        return;
    }
    switch (g_app.mode) {
    case MODE_OFFLINE:
        views_toast(tr(STR_TOAST_OFFLINE));
        break;
    case MODE_LISTEN:
        if (!g_app.wifi_up) {
            views_toast(tr(STR_TOAST_WIFI_CONNECTING));
            break;
        }
        report_sync(sync_now());
        s_next_poll_us = esp_timer_get_time() + g_app.poll_s * 1000000LL;
        break;
    default: { // MODE_ONDEMAND: Wi-Fi only for this one sync
        views_toast(tr(STR_TOAST_CONNECTING));
        views_render();
        net_wifi_on();
        g_app.wifi_up = net_wifi_wait(ONDEMAND_WAIT_MS);
        sync_t r = sync_now(); // without Wi-Fi net_poll names the reason
        net_wifi_off();
        g_app.wifi_up = false;
        report_sync(r);
        break;
    }
    }
}

static void handle_button(btn_event_t ev)
{
    if (views_notify_close()) { // the first press, short or long, only closes the notification
        s_dirty = true;
        return;
    }
    char text[64];
    switch (ev) {
    case BTN_KEY_SHORT:
        g_app.mode = (app_mode_t)((g_app.mode + 1) % MODE_COUNT);
        store_save_mode();
        g_app.net_error[0] = 0;
        start_mode();
        snprintf(text, sizeof text, tr(STR_FMT_MODE), mode_name(g_app.mode));
        views_toast(text);
        break;
    case BTN_KEY_LONG:
        views_toggle_status();
        break;
    case BTN_BOOT_SHORT:
        on_boot_short();
        break;
    case BTN_BOOT_LONG:
        views_close_status();
        g_app.view = (app_view_t)((g_app.view + 1) % VIEW_COUNT);
        store_save_view();
        snprintf(text, sizeof text, tr(STR_FMT_VIEW), tr(VIEWS[g_app.view].label));
        views_toast(text);
        break;
    }
    s_dirty = true;
}

void app_main(void)
{
    store_init(); // NVS first: Wi-Fi needs it too
    g_app.rev = -1;
    g_app.lang = LANG_EN;            // until the server or NVS says otherwise
    g_app.poll_s = POLL_DEFAULT_S;
    g_app.wallpaper = heap_caps_calloc(1, WALLPAPER_BYTES, MALLOC_CAP_SPIRAM);
    if (!g_app.wallpaper) ESP_LOGE(TAG, "no PSRAM for the wallpaper");
    store_load();
    s_weather_saved = g_app.weather.ts;

    setenv("TZ", TZ_POLAND, 1);
    tzset();
    board_init();
    time_t rtc;
    if (rtc_read(&rtc)) {
        struct timeval tv = {.tv_sec = rtc};
        settimeofday(&tv, NULL);
    } else {
        ESP_LOGW(TAG, "RTC has no valid time, waiting for the server");
    }
    display_init();
    read_sensors();
    s_next_sensor_us = esp_timer_get_time() + SENSOR_US;
    g_app.configured = net_configured();
    views_render(); // first frame before the slower Wi-Fi and codec setup
    s_dirty = false;

    audio_init();
    net_init(); // always: the Wi-Fi state checks need it even without secrets
    s_buttons = xQueueCreate(8, sizeof(btn_event_t));
    board_buttons_start(on_button);
    start_mode();
    ESP_LOGI(TAG, "ready, mode %s, view %s", mode_name(g_app.mode), VIEWS[g_app.view].id);

    int last_minute = -1;
    for (;;) {
        btn_event_t ev;
        int wait_ms = views_frame_wait_ms(LOOP_WAIT_MS);
        if (xQueueReceive(s_buttons, &ev, pdMS_TO_TICKS(wait_ms)) == pdTRUE) handle_button(ev);

        bool up = net_wifi_is_up();
        if (up != g_app.wifi_up) {
            g_app.wifi_up = up;
            s_dirty = true;
            if (up) s_next_poll_us = 0; // NASŁUCH syncs as soon as Wi-Fi is back
        }
        int64_t now_us = esp_timer_get_time();
        if (now_us >= s_next_sensor_us) {
            read_sensors();
            s_next_sensor_us = now_us + SENSOR_US;
        }
        poll_tick(now_us);

        time_t now = time(NULL);
        struct tm tm;
        localtime_r(&now, &tm);
        if (tm.tm_min != last_minute) {
            last_minute = tm.tm_min;
            s_dirty = true;
        }
        if (views_overlay_expired() || views_frame_due()) s_dirty = true;
        if (s_dirty) {
            s_dirty = false;
            views_render();
        }
    }
}
