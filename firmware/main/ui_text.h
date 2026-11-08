// Board UI texts in English and Polish. The language comes from the server (poll "settings.lang")
// and is kept in NVS; English until the first sync. The fonts have no "…", so texts end with "...".
#pragma once
#include "app.h"

typedef enum {
    // modes and views
    STR_MODE_LISTEN,
    STR_MODE_ONDEMAND,
    STR_MODE_OFFLINE,
    STR_VIEW_DASHBOARD,
    STR_VIEW_MESSAGE,
    STR_VIEW_WALLPAPER,
    STR_VIEW_SESSIONS,
    // toasts
    STR_SYNC_FAILED,
    STR_NO_CHANGES,
    STR_UPDATED,
    STR_TOAST_UNCONFIGURED,
    STR_TOAST_OFFLINE,
    STR_TOAST_WIFI_CONNECTING,
    STR_TOAST_CONNECTING,
    STR_FMT_MODE,
    STR_FMT_VIEW,
    // dashboard, message, wallpaper and sessions views
    STR_TOPBAR_NET_ERROR,
    STR_HINT_UNCONFIGURED,
    STR_WAITING_FOR_TIME,
    STR_FMT_DATE,
    STR_ROOM,
    STR_FMT_HUMIDITY,
    STR_FMT_OUTDOORS,
    STR_FMT_WEATHER_AT,
    STR_FMT_WEATHER_AT_DAY,
    STR_FMT_WEATHER_RANGE,
    STR_WEATHER,
    STR_NO_DATA,
    STR_WEATHER_TOO_OLD,
    STR_CHECK_LOCATION,
    STR_CLAUDE_NO_SESSIONS,
    STR_FMT_CLAUDE_SUMMARY,
    STR_NO_MESSAGE,
    STR_FMT_FROM,
    STR_NO_WALLPAPER,
    STR_UPLOAD_WALLPAPER,
    STR_SESSIONS_TITLE,
    STR_NOTIFY_WAIT,
    STR_NOTIFY_DONE,
    STR_NOTIFY_CLOSE,
    STR_NO_SESSIONS,
    STR_NET_UNCONFIGURED,
    STR_SESS_WORKING,
    STR_SESS_WAITING,
    STR_SESS_DONE,
    STR_AGE_NOW,
    // plan limit bar at the bottom of the sessions view
    STR_FMT_LIMIT,
    STR_FMT_LIMIT_RENEWED,
    STR_LIMIT_HIT,
    STR_FMT_LIMIT_HIT_RESET,
    STR_UNDER_MINUTE,
    // status screen
    STR_STATUS_TITLE,
    STR_FMT_MODE_EVERY,
    STR_FMT_WIFI,
    STR_WIFI_UNCONFIGURED,
    STR_WIFI_CONNECTED,
    STR_WIFI_CONNECTING,
    STR_WIFI_OFF,
    STR_FMT_BATTERY,
    STR_POWER_USB,
    STR_FMT_SYNC_AT,
    STR_SYNC_NEVER,
    STR_FMT_ERROR,
    STR_STATUS_FOOTER,
    // errors in g_app.net_error (at most 47 bytes)
    STR_ERR_WALLPAPER_MEMORY,
    STR_ERR_WIFI_NO_AP,
    STR_ERR_WIFI_PASSWORD,
    STR_ERR_WIFI_NO_LINK,
    STR_ERR_NO_SERVER,
    STR_ERR_BAD_TOKEN,
    STR_ERR_BAD_URL,
    STR_FMT_ERR_SERVER,
    STR_ERR_REPLY_TOO_BIG,
    STR_ERR_BAD_REPLY,
    STR_ERR_WALLPAPER_SIZE,
    STR_ERR_SOUND_MEMORY,
    STR_ERR_SOUND_MISSING,
    STR_ERR_SOUND_FORMAT,
    STR_COUNT
} str_id_t;

const char *tr(str_id_t id);          // the text in g_app.lang; never NULL
const char *tr_day(int wday);         // 0 = Sunday, as in struct tm
const char *tr_month(int mon);        // 0 = January, in the form that follows a day number
const char *tr_weather(int wmo_code); // short WMO weather text, "" for unknown codes

int lang_from_id(const char *id);     // "en" / "pl" -> app_lang_t, -1 if unknown
bool poll_interval_ok(int seconds);   // POLL_MIN_S..POLL_MAX_S (app.h), as server/api.php accepts
