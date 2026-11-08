// Shared application state. Only the main task writes it.
#pragma once
#include <stdbool.h>
#include <stdint.h>
#include <time.h>

typedef enum { MODE_LISTEN = 0, MODE_ONDEMAND, MODE_OFFLINE, MODE_COUNT } app_mode_t;

// View ids. To add a new app: add an id here, an entry in VIEWS (views.c) and a draw
// function, then add the same id to VIEWS in server/api.php.
typedef enum { VIEW_DASHBOARD = 0, VIEW_MESSAGE, VIEW_WALLPAPER, VIEW_SESSIONS, VIEW_COUNT } app_view_t;

// Board UI language, set from the app (server "settings.lang"). English until the first sync.
typedef enum { LANG_EN = 0, LANG_PL, LANG_COUNT } app_lang_t;

// NASŁUCH interval in seconds: POLL_DEFAULT_S until the server says otherwise (settings.poll_s), which is
// whole seconds from POLL_MIN_S to POLL_MAX_S. The same three numbers are in server/api.php.
#define POLL_DEFAULT_S 10
#define POLL_MIN_S 5
#define POLL_MAX_S 3600

typedef enum { SESS_WORKING = 0, SESS_WAITING, SESS_DONE } sess_state_t;

#define MAX_SESSIONS 8
#define MSG_MAX_BYTES (280 * 4 + 1)
#define WALLPAPER_BYTES 15000

typedef struct {
    char id[25];            // the server's row id: "s:" + 8 characters of the session id, or the alias
    char alias[25];
    char title[60 * 4 + 1]; // session title from the Claude app, up to 60 UTF-8 characters; "" = show the alias
    sess_state_t state;
    int64_t since; // unix time when the state began (server age converted)
    uint16_t tasks_d, tasks_t; // task list: done of total; tasks_t 0 = the session has no list (nothing is shown)
} session_t;

// Plan limit of the 5 hour window, from the poll's "usage" (sent with every poll). valid: the server has a
// reading; the board shows it only while it is younger than the window. hit: a turn stopped on the limit.
typedef struct {
    bool valid;
    int pct;
    int64_t reset_at; // unix time the window resets
    int64_t read_at;  // unix time of the reading
    bool hit;
} usage_t;

typedef struct {
    bool valid;
    char place[33];
    int temp, tmin, tmax, rain, code;
    int64_t ts; // unix time the server asked Open-Meteo for this reading
} weather_t;

typedef struct {
    uint32_t id; // 0 = no message yet
    char text[MSG_MAX_BYTES];
    char from[64];
    char sound[12];
    int64_t ts;
} message_t;

typedef struct {
    // persisted in NVS (store.c)
    app_mode_t mode;
    app_view_t view;
    int32_t view_seq;   // server's count of view assignments already applied
    message_t msg;
    weather_t weather;
    int32_t wallpaper_rev;
    bool has_wallpaper;
    uint8_t *wallpaper; // WALLPAPER_BYTES in PSRAM, 1 bit per pixel, 1 = black
    app_lang_t lang;
    int poll_s;         // seconds between polls in NASŁUCH, always POLL_MIN_S..POLL_MAX_S

    // runtime only
    int32_t rev;        // last server rev applied, -1 = ask for everything
    session_t sessions[MAX_SESSIONS];
    int session_count;
    usage_t usage;
    bool wifi_up;
    int64_t last_sync;  // unix time of the last successful poll, 0 = never
    char net_error[48]; // short reason when the last sync failed, "" = fine
    float temp_c, hum_pct;
    bool climate_valid;
    int bat_mv;
    bool configured;    // secrets.h filled in
} app_state_t;

extern app_state_t g_app;

const char *mode_name(app_mode_t m);
