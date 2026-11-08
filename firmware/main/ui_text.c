#include "ui_text.h"
#include <string.h>

// {English, Polish}. Keep the same printf arguments in both columns.
static const char *const T[STR_COUNT][LANG_COUNT] = {
    [STR_MODE_LISTEN] = {"LISTEN", "NASŁUCH"},
    [STR_MODE_ONDEMAND] = {"ON DEMAND", "NA ŻĄDANIE"},
    [STR_MODE_OFFLINE] = {"OFFLINE", "OFFLINE"},
    [STR_VIEW_DASHBOARD] = {"Dashboard", "Pulpit"},
    [STR_VIEW_MESSAGE] = {"Message", "Wiadomość"},
    [STR_VIEW_WALLPAPER] = {"Wallpaper", "Tapeta"},
    [STR_VIEW_SESSIONS] = {"Claude sessions", "Sesje Claude"},

    [STR_SYNC_FAILED] = {"Sync failed", "Błąd synchronizacji"},
    [STR_NO_CHANGES] = {"No changes", "Bez zmian"},
    [STR_UPDATED] = {"Updated", "Zaktualizowano"},
    [STR_TOAST_UNCONFIGURED] = {"Network not set up: secrets.h", "Sieć nieskonfigurowana: secrets.h"},
    [STR_TOAST_OFFLINE] = {"Offline mode: network off", "Tryb offline: sieć wyłączona"},
    [STR_TOAST_WIFI_CONNECTING] = {"Wi-Fi: connecting...", "Wi-Fi: łączę się..."},
    [STR_TOAST_CONNECTING] = {"Connecting to the server...", "Łączę z serwerem..."},
    [STR_FMT_MODE] = {"Mode: %s", "Tryb: %s"},
    [STR_FMT_VIEW] = {"View: %s", "Widok: %s"},

    [STR_TOPBAR_NET_ERROR] = {" · network error", " · błąd sieci"},
    [STR_HINT_UNCONFIGURED] = {"Fill in firmware/main/secrets.h to turn on the network",
                               "Uzupełnij firmware/main/secrets.h, by włączyć sieć"},
    [STR_WAITING_FOR_TIME] = {"waiting for the time from the network", "czekam na czas z sieci"},
    [STR_FMT_DATE] = {"%s, %d %s", "%s, %d %s"},
    [STR_ROOM] = {"Indoors", "W pokoju"},
    [STR_FMT_HUMIDITY] = {"humidity %.0f%%", "wilgotność %.0f%%"},
    [STR_FMT_OUTDOORS] = {"Outdoors%s%s", "Na zewnątrz%s%s"},
    [STR_FMT_WEATHER_AT] = {"Weather at %02d:%02d", "Pogoda z %02d:%02d"},
    [STR_FMT_WEATHER_AT_DAY] = {"Weather of %d.%02d, %02d:%02d", "Pogoda z %d.%02d, %02d:%02d"},
    [STR_FMT_WEATHER_RANGE] = {"%d to %d°C, rain %d%%", "od %d do %d°C, opady %d%%"},
    [STR_WEATHER] = {"Weather", "Pogoda"},
    [STR_NO_DATA] = {"no data", "brak danych"},
    [STR_WEATHER_TOO_OLD] = {"last reading over a day ago", "ostatni odczyt ponad dobę temu"},
    [STR_CHECK_LOCATION] = {"check location in config.php", "sprawdź location w config.php"},
    [STR_CLAUDE_NO_SESSIONS] = {"Claude: no active sessions", "Claude: brak aktywnych sesji"},
    [STR_FMT_CLAUDE_SUMMARY] = {"Claude: %d working · %d waiting · %d done",
                                "Claude: %d pracuje · %d czeka · %d gotowe"},
    [STR_NO_MESSAGE] = {"No message", "Brak wiadomości"},
    [STR_FMT_FROM] = {"From: %s", "Od: %s"},
    [STR_NO_WALLPAPER] = {"No wallpaper", "Brak tapety"},
    [STR_UPLOAD_WALLPAPER] = {"Upload one from the app", "Wgraj ją z aplikacji"},
    [STR_SESSIONS_TITLE] = {"Claude Code sessions", "Sesje Claude Code"},
    [STR_NOTIFY_WAIT] = {"Waiting for you", "Czeka na Ciebie"},
    [STR_NOTIFY_DONE] = {"Done", "Gotowe"},
    [STR_NOTIFY_CLOSE] = {"KEY or BOOT closes", "KEY albo BOOT zamyka"},
    [STR_NO_SESSIONS] = {"No active sessions", "Brak aktywnych sesji"},
    [STR_NET_UNCONFIGURED] = {"Network not set up", "Sieć nieskonfigurowana"},
    [STR_SESS_WORKING] = {"working", "pracuje"},
    [STR_SESS_WAITING] = {"waiting", "czeka"},
    [STR_SESS_DONE] = {"done", "gotowe"},
    [STR_AGE_NOW] = {"now", "teraz"},
    [STR_FMT_LIMIT] = {"5 h %d%% · resets in %s · read %s ago", "5 h %d%% · reset za %s · odczyt sprzed %s"},
    [STR_FMT_LIMIT_RENEWED] = {"5 h renewed · read %s ago", "5 h odnowiony · odczyt sprzed %s"},
    [STR_LIMIT_HIT] = {"5 h limit reached", "5 h limit wyczerpany"},
    [STR_FMT_LIMIT_HIT_RESET] = {"5 h limit reached · resets in %s", "5 h limit wyczerpany · reset za %s"},
    [STR_UNDER_MINUTE] = {"<1 min", "<1 min"},

    [STR_STATUS_TITLE] = {"Device status", "Stan urządzenia"},
    [STR_FMT_MODE_EVERY] = {"Mode: %s, every %s", "Tryb: %s, co %s"},
    [STR_FMT_WIFI] = {"Wi-Fi: %s", "Wi-Fi: %s"},
    [STR_WIFI_UNCONFIGURED] = {"not set up", "nieskonfigurowane"},
    [STR_WIFI_CONNECTED] = {"connected", "połączone"},
    [STR_WIFI_CONNECTING] = {"connecting", "łączę się"},
    [STR_WIFI_OFF] = {"off", "wyłączone"},
    [STR_FMT_BATTERY] = {"Battery: %d%% (%.2f V)", "Bateria: %d%% (%.2f V)"},
    [STR_POWER_USB] = {"Power: USB, no battery", "Zasilanie: USB, bez akumulatora"},
    [STR_FMT_SYNC_AT] = {"Last sync: %02d:%02d:%02d", "Synchronizacja: %02d:%02d:%02d"},
    [STR_SYNC_NEVER] = {"Last sync: not yet", "Synchronizacja: jeszcze nie było"},
    [STR_FMT_ERROR] = {"Error: %s", "Błąd: %s"},
    [STR_STATUS_FOOTER] = {"KEY: mode · BOOT: refresh · hold BOOT: view",
                           "KEY: tryb · BOOT: odśwież · przytrzymaj BOOT: widok"},

    [STR_ERR_WALLPAPER_MEMORY] = {"wallpaper: out of memory", "tapeta: brak pamięci"},
    [STR_ERR_WIFI_NO_AP] = {"Wi-Fi: network not found", "Wi-Fi: nie widzę sieci"},
    [STR_ERR_WIFI_PASSWORD] = {"Wi-Fi: wrong password?", "Wi-Fi: złe hasło?"},
    [STR_ERR_WIFI_NO_LINK] = {"Wi-Fi: no connection", "Wi-Fi: brak połączenia"},
    [STR_ERR_NO_SERVER] = {"cannot reach the server", "brak połączenia z serwerem"},
    [STR_ERR_BAD_TOKEN] = {"server: wrong device token", "serwer: zły token urządzenia"},
    [STR_ERR_BAD_URL] = {"server: wrong API_URL", "serwer: zły adres API_URL"},
    [STR_FMT_ERR_SERVER] = {"server: error %d", "serwer: błąd %d"},
    [STR_ERR_REPLY_TOO_BIG] = {"bad server reply (too big)", "zła odpowiedź serwera (za duża)"},
    [STR_ERR_BAD_REPLY] = {"bad server reply", "zła odpowiedź serwera"},
    [STR_ERR_WALLPAPER_SIZE] = {"wallpaper: wrong file size", "tapeta: zły rozmiar pliku"},
    [STR_ERR_SOUND_MEMORY] = {"sound: out of memory", "dźwięk: brak pamięci"},
    [STR_ERR_SOUND_MISSING] = {"sound: not in the library", "dźwięk: nie ma go w bibliotece"},
    [STR_ERR_SOUND_FORMAT] = {"sound: bad file format", "dźwięk: zły format pliku"},
};

static const char *const DAYS[7][LANG_COUNT] = {
    {"Sunday", "niedziela"},  {"Monday", "poniedziałek"}, {"Tuesday", "wtorek"}, {"Wednesday", "środa"},
    {"Thursday", "czwartek"}, {"Friday", "piątek"},       {"Saturday", "sobota"},
};

static const char *const MONTHS[12][LANG_COUNT] = {
    {"January", "stycznia"},   {"February", "lutego"}, {"March", "marca"},       {"April", "kwietnia"},
    {"May", "maja"},           {"June", "czerwca"},    {"July", "lipca"},        {"August", "sierpnia"},
    {"September", "września"}, {"October", "października"}, {"November", "listopada"}, {"December", "grudnia"},
};

static app_lang_t lang(void) { return (unsigned)g_app.lang < LANG_COUNT ? g_app.lang : LANG_EN; }

// A missing Polish text falls back to English, a missing English one to "?", so nothing draws NULL.
static const char *pick(const char *const row[LANG_COUNT])
{
    const char *s = row[lang()];
    if (!s) s = row[LANG_EN];
    return s ? s : "?";
}

const char *tr(str_id_t id) { return (unsigned)id < STR_COUNT ? pick(T[id]) : "?"; }

const char *tr_day(int wday) { return wday >= 0 && wday < 7 ? pick(DAYS[wday]) : "?"; }

const char *tr_month(int mon) { return mon >= 0 && mon < 12 ? pick(MONTHS[mon]) : "?"; }

const char *tr_weather(int code)
{
    static const char *const W[][LANG_COUNT] = {
        {"clear sky", "bezchmurnie"},       {"partly cloudy", "małe zachmurzenie"},
        {"overcast", "pochmurno"},          {"fog", "mgła"},
        {"drizzle", "mżawka"},              {"rain", "deszcz"},
        {"snow", "śnieg"},                  {"rain showers", "przelotny deszcz"},
        {"snow showers", "przelotny śnieg"}, {"thunderstorm", "burza"},
    };
    int i = code == 0                      ? 0
            : code >= 1 && code <= 2       ? 1
            : code == 3                    ? 2
            : code == 45 || code == 48     ? 3
            : code >= 51 && code <= 57     ? 4
            : code >= 61 && code <= 67     ? 5
            : code >= 71 && code <= 77     ? 6
            : code >= 80 && code <= 82     ? 7
            : code >= 85 && code <= 86     ? 8
            : code >= 95                   ? 9
                                           : -1;
    return i < 0 ? "" : pick(W[i]);
}

int lang_from_id(const char *id)
{
    if (!id) return -1;
    if (strcmp(id, "en") == 0) return LANG_EN;
    if (strcmp(id, "pl") == 0) return LANG_PL;
    return -1;
}

bool poll_interval_ok(int seconds) { return seconds >= POLL_MIN_S && seconds <= POLL_MAX_S; }
