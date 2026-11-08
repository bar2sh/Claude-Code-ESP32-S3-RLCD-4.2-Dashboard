// Wi-Fi station and HTTPS calls to server/api.php. Called from the main task only.
#pragma once
#include <stdbool.h>
#include "app.h"
#include "notify.h"

typedef struct {
    int32_t rev;
    int64_t time; // server unix time, 0 if missing
    bool changed;
    weather_t weather; // valid=false when the server has none
    // sent on every poll; -1 when missing or not one the board knows (it then keeps its own)
    int lang;          // app_lang_t
    int poll_s;
    usage_t usage;     // all zero when the server sends none (an older api.php)
    // filled only when changed:
    char view[16];
    int32_t view_seq;
    message_t msg;
    int32_t wallpaper_rev;
    session_t sessions[MAX_SESSIONS];
    int session_count;
    bool has_notify; // false: an older api.php or broken notify/ev fields, so no notifications (fail-closed)
    notify_settings_t notify;
    notify_ev_t ev;
} poll_data_t;

void net_init(void);
bool net_configured(void);  // secrets.h has SSID, password, API URL and token
void net_wifi_on(void);     // connects in the background and reconnects on loss
void net_wifi_off(void);
bool net_wifi_wait(int timeout_ms);
bool net_wifi_is_up(void);

// All return false on failure and put a short reason, in the board language, in g_app.net_error.
bool net_poll(poll_data_t *out);
bool net_fetch_wallpaper(uint8_t *buf); // exactly WALLPAPER_BYTES
// Downloads a library sound into a new PSRAM buffer; pass it on to audio_play_pcm.
bool net_fetch_sound(const char *id, int16_t **pcm, size_t *samples);
