#include "net.h"
#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "audio.h"
#include "cJSON.h"
#include "esp_crt_bundle.h"
#include "esp_event.h"
#include "esp_heap_caps.h"
#include "esp_http_client.h"
#include "esp_log.h"
#include "esp_netif.h"
#include "esp_timer.h"
#include "esp_wifi.h"
#include "freertos/FreeRTOS.h"
#include "freertos/event_groups.h"

#if !__has_include("secrets.h")
#error "Skopiuj firmware/main/secrets.h.example do firmware/main/secrets.h i uzupełnij wartości"
#endif
#include "secrets.h"
#include "ui_text.h"

static const char *TAG = "net";

#define BIT_UP BIT0
#define RETRY_US (5 * 1000 * 1000)
#define HTTP_TIMEOUT_MS 8000
#define POLL_BODY_MAX 8192

static EventGroupHandle_t s_ev;
static esp_timer_handle_t s_retry;
static volatile bool s_want;    // Wi-Fi should be connected
static volatile int s_reason;   // last disconnect reason, 0 = none
static char s_body[POLL_BODY_MAX];

static const char *MODE_IDS[MODE_COUNT] = {"listen", "ondemand", "offline"};

bool net_configured(void)
{
    return WIFI_SSID[0] && WIFI_PASS[0] && API_URL[0] && DEVICE_TOKEN[0];
}

static void retry_cb(void *arg)
{
    if (s_want) esp_wifi_connect();
}

static void on_event(void *arg, esp_event_base_t base, int32_t id, void *data)
{
    if (base == WIFI_EVENT && id == WIFI_EVENT_STA_START) {
        if (s_want) esp_wifi_connect();
    } else if (base == WIFI_EVENT && id == WIFI_EVENT_STA_DISCONNECTED) {
        xEventGroupClearBits(s_ev, BIT_UP);
        s_reason = ((wifi_event_sta_disconnected_t *)data)->reason;
        if (s_want) esp_timer_start_once(s_retry, RETRY_US); // fails harmlessly if already armed
    } else if (base == IP_EVENT && id == IP_EVENT_STA_GOT_IP) {
        s_reason = 0;
        xEventGroupSetBits(s_ev, BIT_UP);
    }
}

void net_init(void)
{
    s_ev = xEventGroupCreate();
    const esp_timer_create_args_t t = {.callback = retry_cb, .name = "wifi_retry"};
    ESP_ERROR_CHECK(esp_timer_create(&t, &s_retry));
    ESP_ERROR_CHECK(esp_netif_init());
    ESP_ERROR_CHECK(esp_event_loop_create_default());
    esp_netif_create_default_wifi_sta();
    wifi_init_config_t cfg = WIFI_INIT_CONFIG_DEFAULT();
    ESP_ERROR_CHECK(esp_wifi_init(&cfg));
    ESP_ERROR_CHECK(esp_wifi_set_storage(WIFI_STORAGE_RAM)); // credentials never written to NVS
    ESP_ERROR_CHECK(esp_event_handler_register(WIFI_EVENT, ESP_EVENT_ANY_ID, on_event, NULL));
    ESP_ERROR_CHECK(esp_event_handler_register(IP_EVENT, IP_EVENT_STA_GOT_IP, on_event, NULL));
    ESP_ERROR_CHECK(esp_wifi_set_mode(WIFI_MODE_STA));
    esp_wifi_set_country_code("PL", true); // channels 1-13

    wifi_config_t wc = {0};
    strlcpy((char *)wc.sta.ssid, WIFI_SSID, sizeof wc.sta.ssid);
    strlcpy((char *)wc.sta.password, WIFI_PASS, sizeof wc.sta.password);
    wc.sta.threshold.authmode = WIFI_AUTH_WPA2_PSK; // WPA2 or better, WPA3-SAE when the router offers it
    wc.sta.sae_pwe_h2e = WPA3_SAE_PWE_BOTH;
    wc.sta.pmf_cfg.required = false;
    ESP_ERROR_CHECK(esp_wifi_set_config(WIFI_IF_STA, &wc));
}

void net_wifi_on(void)
{
    if (!net_configured() || s_want) return;
    s_want = true;
    s_reason = 0;
    ESP_LOGI(TAG, "Wi-Fi on");
    esp_wifi_start(); // STA_START event connects
}

void net_wifi_off(void)
{
    if (!s_want) return;
    s_want = false;
    esp_timer_stop(s_retry);
    esp_wifi_stop();
    xEventGroupClearBits(s_ev, BIT_UP);
    ESP_LOGI(TAG, "Wi-Fi off");
}

bool net_wifi_wait(int timeout_ms)
{
    return xEventGroupWaitBits(s_ev, BIT_UP, pdFALSE, pdTRUE, pdMS_TO_TICKS(timeout_ms)) & BIT_UP;
}

bool net_wifi_is_up(void) { return xEventGroupGetBits(s_ev) & BIT_UP; }

static void set_error(const char *text)
{
    snprintf(g_app.net_error, sizeof g_app.net_error, "%s", text);
    ESP_LOGW(TAG, "%s", text);
}

static void set_wifi_error(void)
{
    switch (s_reason) {
    case WIFI_REASON_NO_AP_FOUND:
    case WIFI_REASON_NO_AP_FOUND_W_COMPATIBLE_SECURITY:
    case WIFI_REASON_NO_AP_FOUND_IN_AUTHMODE_THRESHOLD:
    case WIFI_REASON_NO_AP_FOUND_IN_RSSI_THRESHOLD:
        set_error(tr(STR_ERR_WIFI_NO_AP));
        break;
    case WIFI_REASON_AUTH_FAIL:
    case WIFI_REASON_4WAY_HANDSHAKE_TIMEOUT:
    case WIFI_REASON_HANDSHAKE_TIMEOUT:
        set_error(tr(STR_ERR_WIFI_PASSWORD));
        break;
    default:
        set_error(tr(STR_ERR_WIFI_NO_LINK));
    }
}

// ---------- HTTP ----------

typedef struct {
    char *buf;
    int len, cap;
    bool overflow;
} body_t;

static esp_err_t on_http(esp_http_client_event_t *e)
{
    body_t *b = e->user_data;
    if (e->event_id == HTTP_EVENT_ON_CONNECTED) {
        b->len = 0; // fresh body after a redirect or reconnect
        b->overflow = false;
    } else if (e->event_id == HTTP_EVENT_ON_DATA && esp_http_client_get_status_code(e->client) == 200) {
        if (b->len + e->data_len > b->cap) {
            b->overflow = true;
        } else {
            memcpy(b->buf + b->len, e->data, e->data_len);
            b->len += e->data_len;
        }
    }
    return ESP_OK;
}

// Returns the HTTP status, or -1 when the server could not be reached.
static int http_get(const char *url, body_t *body)
{
    esp_http_client_config_t cfg = {
        .url = url,
        .timeout_ms = HTTP_TIMEOUT_MS,
        .crt_bundle_attach = esp_crt_bundle_attach,
        .event_handler = on_http,
        .user_data = body,
    };
    esp_http_client_handle_t c = esp_http_client_init(&cfg);
    if (!c) return -1;
    esp_http_client_set_header(c, "X-Token", DEVICE_TOKEN);
    esp_err_t err = esp_http_client_perform(c);
    int status = err == ESP_OK ? esp_http_client_get_status_code(c) : -1;
    if (err != ESP_OK) ESP_LOGW(TAG, "GET failed: %s", esp_err_to_name(err));
    esp_http_client_cleanup(c);
    return status;
}

static bool check_status(int status)
{
    if (status == 200) return true;
    if (status == -1) set_error(tr(STR_ERR_NO_SERVER));
    else if (status == 401) set_error(tr(STR_ERR_BAD_TOKEN));
    else if (status == 404) set_error(tr(STR_ERR_BAD_URL));
    else {
        char t[48];
        snprintf(t, sizeof t, tr(STR_FMT_ERR_SERVER), status);
        set_error(t);
    }
    return false;
}

// ---------- JSON helpers ----------

static const char *jstr(const cJSON *o, const char *k)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(o, k);
    return cJSON_IsString(v) ? v->valuestring : NULL;
}

static bool jnum(const cJSON *o, const char *k, double *out)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(o, k);
    if (!cJSON_IsNumber(v)) return false;
    *out = v->valuedouble;
    return true;
}

static void copy_str(char *dst, size_t n, const char *src) { snprintf(dst, n, "%s", src ? src : ""); }

// A whole number from 0 to 2^53 - 1, the range JSON numbers carry exactly.
static bool jcount(const cJSON *o, const char *k, int64_t *out)
{
    double v;
    if (!jnum(o, k, &v) || !(v >= 0 && v <= 9007199254740991.0)) return false;
    *out = (int64_t)v;
    return true;
}

static int find_id(const char *const *ids, int count, const char *id)
{
    for (int i = 0; id && i < count; i++) {
        if (strcmp(ids[i], id) == 0) return i;
    }
    return -1;
}

static bool parse_alert(const cJSON *o, notify_alert_t *a, anim_id_t fallback)
{
    const char *sound = jstr(o, "sound"), *anim = jstr(o, "anim");
    if (!cJSON_IsObject(o) || !sound || strlen(sound) >= sizeof a->sound || !anim) return false;
    copy_str(a->sound, sizeof a->sound, sound);
    int id = anim_find(anim);
    a->anim = id >= 0 ? (anim_id_t)id : fallback; // an animation newer than this firmware: the default one
    return true;
}

// "notify" and "ev" of a full poll answer (docs/SPEC.md, "Powiadomienia o sesjach Claude"). Anything
// missing or wrong, e.g. from an older api.php, turns notifications off; the poll itself still works.
static bool parse_notify(const cJSON *j, poll_data_t *p)
{
    static const char *const MODES[] = {"quiet", "ready", "each"}; // notify_mode_t
    static const char *const SCOPES[] = {"sessions", "all"};       // notify_scope_t
    const cJSON *n = cJSON_GetObjectItemCaseSensitive(j, "notify");
    const cJSON *e = cJSON_GetObjectItemCaseSensitive(j, "ev");
    int mode = find_id(MODES, 3, jstr(n, "mode")), scope = find_id(SCOPES, 2, jstr(n, "scope"));
    if (!cJSON_IsObject(n) || !cJSON_IsObject(e) || mode < 0 || scope < 0) return false;
    if (!parse_alert(cJSON_GetObjectItemCaseSensitive(n, "done"), &p->notify.done, ANIM_HAPPY)
        || !parse_alert(cJSON_GetObjectItemCaseSensitive(n, "wait"), &p->notify.wait, ANIM_AWAY)) {
        return false;
    }
    p->notify.mode = (notify_mode_t)mode;
    p->notify.scope = (notify_scope_t)scope;
    notify_ev_t *ev = &p->ev;
    const char *done_id = jstr(e, "done_id"), *wait_id = jstr(e, "wait_id");
    if (!jcount(e, "done", &ev->done) || !jcount(e, "done_at", &ev->done_at) || !jcount(e, "all", &ev->all)
        || !jcount(e, "all_at", &ev->all_at) || !jcount(e, "wait", &ev->wait) || !jcount(e, "wait_at", &ev->wait_at)
        || !done_id || !wait_id) {
        return false;
    }
    copy_str(ev->done_id, sizeof ev->done_id, done_id);
    copy_str(ev->wait_id, sizeof ev->wait_id, wait_id);
    return true;
}

static void parse_weather(const cJSON *w, weather_t *out)
{
    memset(out, 0, sizeof *out);
    double temp, tmin, tmax, rain, code, at;
    if (!cJSON_IsObject(w) || !jnum(w, "temp", &temp) || !jnum(w, "min", &tmin) || !jnum(w, "max", &tmax)
        || !jnum(w, "rain", &rain) || !jnum(w, "code", &code) || !jnum(w, "at", &at)) {
        return; // null or incomplete, including a reading of unknown age: no weather (fail-closed)
    }
    out->valid = true;
    copy_str(out->place, sizeof out->place, jstr(w, "place"));
    out->temp = (int)lround(temp);
    out->tmin = (int)lround(tmin);
    out->tmax = (int)lround(tmax);
    out->rain = (int)lround(rain);
    out->code = (int)code;
    out->ts = (int64_t)at; // when the server asked Open-Meteo
}

// Fail-closed per field: a missing, mistyped or unknown value is -1, so the board keeps what it has.
static void parse_settings(const cJSON *s, poll_data_t *p)
{
    p->lang = -1;
    p->poll_s = -1;
    if (!cJSON_IsObject(s)) return; // older server without settings
    p->lang = lang_from_id(jstr(s, "lang"));
    double v;
    if (jnum(s, "poll_s", &v) && v >= POLL_MIN_S && v <= POLL_MAX_S && v == (double)(int)v) p->poll_s = (int)v;
}

// The 5 hour window and the limit notice. A reading is kept as absolute times, so the board counts on by itself.
static void parse_usage(const cJSON *u, int64_t server_time, usage_t *out)
{
    memset(out, 0, sizeof *out);
    if (!cJSON_IsObject(u)) return;
    out->hit = cJSON_IsTrue(cJSON_GetObjectItemCaseSensitive(u, "hit"));
    const cJSON *f = cJSON_GetObjectItemCaseSensitive(u, "five");
    double pct, left, age;
    if (cJSON_IsObject(f) && jnum(f, "p", &pct) && jnum(f, "left", &left) && jnum(f, "age", &age) && pct >= 0 && pct <= 100
        && left >= 0 && age >= 0) {
        int64_t t = server_time ? server_time : (int64_t)time(NULL);
        out->valid = true;
        out->pct = (int)(pct + 0.5);
        out->reset_at = t + (int64_t)left;
        out->read_at = t - (int64_t)age;
    }
}

static bool parse_poll(const cJSON *j, poll_data_t *p)
{
    double rev, t;
    const cJSON *changed = cJSON_GetObjectItemCaseSensitive(j, "changed");
    if (!jnum(j, "rev", &rev) || !cJSON_IsBool(changed)) return false;
    p->rev = (int32_t)rev;
    p->time = jnum(j, "time", &t) ? (int64_t)t : 0;
    p->changed = cJSON_IsTrue(changed);
    parse_weather(cJSON_GetObjectItemCaseSensitive(j, "weather"), &p->weather);
    parse_settings(cJSON_GetObjectItemCaseSensitive(j, "settings"), p);
    parse_usage(cJSON_GetObjectItemCaseSensitive(j, "usage"), p->time, &p->usage);
    p->has_notify = false;
    if (!p->changed) return true;

    double seq, wp;
    const char *view = jstr(j, "view");
    if (!view || !jnum(j, "view_seq", &seq) || !jnum(j, "wallpaper_rev", &wp)) return false;
    copy_str(p->view, sizeof p->view, view);
    p->view_seq = (int32_t)seq;
    p->wallpaper_rev = (int32_t)wp;

    const cJSON *m = cJSON_GetObjectItemCaseSensitive(j, "message");
    double id, ts;
    memset(&p->msg, 0, sizeof p->msg);
    if (cJSON_IsObject(m) && jnum(m, "id", &id) && jnum(m, "ts", &ts)) {
        p->msg.id = (uint32_t)id;
        p->msg.ts = (int64_t)ts;
        copy_str(p->msg.text, sizeof p->msg.text, jstr(m, "text"));
        copy_str(p->msg.from, sizeof p->msg.from, jstr(m, "from"));
        copy_str(p->msg.sound, sizeof p->msg.sound, jstr(m, "sound"));
    }

    p->session_count = 0;
    const cJSON *s;
    cJSON_ArrayForEach(s, cJSON_GetObjectItemCaseSensitive(j, "sessions"))
    {
        if (p->session_count >= MAX_SESSIONS) break;
        const char *alias = jstr(s, "alias"), *state = jstr(s, "state");
        double age;
        if (!alias || !state || !jnum(s, "age", &age)) continue;
        session_t *x = &p->sessions[p->session_count];
        copy_str(x->id, sizeof x->id, jstr(s, "id")); // older server: no id, "" matches no event
        copy_str(x->alias, sizeof x->alias, alias);
        copy_str(x->title, sizeof x->title, jstr(s, "title")); // older server: no title, "" shows the alias
        if (strcmp(state, "working") == 0) x->state = SESS_WORKING;
        else if (strcmp(state, "waiting") == 0) x->state = SESS_WAITING;
        else if (strcmp(state, "done") == 0) x->state = SESS_DONE;
        else continue;
        x->since = (p->time ? p->time : (int64_t)time(NULL)) - (int64_t)age;
        const cJSON *tk = cJSON_GetObjectItemCaseSensitive(s, "tasks");
        double td, tt;
        x->tasks_d = x->tasks_t = 0; // no list unless the numbers are believable: 1 <= total <= 999, done <= total
        if (cJSON_IsObject(tk) && jnum(tk, "d", &td) && jnum(tk, "t", &tt) && tt >= 1 && tt <= 999 && td >= 0 && td <= tt
            && td == (int)td && tt == (int)tt) {
            x->tasks_d = (uint16_t)td;
            x->tasks_t = (uint16_t)tt;
        }
        p->session_count++;
    }
    p->has_notify = parse_notify(j, p);
    if (!p->has_notify) ESP_LOGW(TAG, "poll: no valid notify and ev, so no session notifications");
    return true;
}

bool net_poll(poll_data_t *p)
{
    if (!net_wifi_is_up()) {
        set_wifi_error();
        return false;
    }
    char url[384];
    snprintf(url, sizeof url, "%s?a=poll&rev=%ld&mode=%s&bat=%d", API_URL, (long)g_app.rev,
             MODE_IDS[g_app.mode], g_app.bat_mv > 0 ? g_app.bat_mv : 0);
    body_t body = {.buf = s_body, .cap = sizeof s_body - 1};
    if (!check_status(http_get(url, &body))) return false;
    if (body.overflow) {
        set_error(tr(STR_ERR_REPLY_TOO_BIG));
        return false;
    }
    s_body[body.len] = 0;
    cJSON *j = cJSON_Parse(s_body);
    bool ok = j && parse_poll(j, p);
    cJSON_Delete(j);
    if (!ok) {
        set_error(tr(STR_ERR_BAD_REPLY));
        return false;
    }
    g_app.net_error[0] = 0;
    return true;
}

bool net_fetch_wallpaper(uint8_t *buf)
{
    char url[384];
    snprintf(url, sizeof url, "%s?a=wallpaper", API_URL);
    // A longer body sets overflow, a shorter one fails the length check.
    body_t body = {.buf = (char *)buf, .cap = WALLPAPER_BYTES};
    if (!check_status(http_get(url, &body))) return false;
    if (body.overflow || body.len != WALLPAPER_BYTES) {
        set_error(tr(STR_ERR_WALLPAPER_SIZE));
        return false;
    }
    return true;
}

#define WAV_HEADER 44

static uint32_t le32(const uint8_t *p) { return p[0] | p[1] << 8 | p[2] << 16 | (uint32_t)p[3] << 24; }
static uint16_t le16(const uint8_t *p) { return (uint16_t)(p[0] | p[1] << 8); }

// The server stores every sound as a WAV with the plain 44-byte header: PCM, 16-bit, mono,
// 16 kHz. Returns the sample count, 0 for anything else.
static size_t wav_samples(const uint8_t *b, int len)
{
    if (len <= WAV_HEADER || memcmp(b, "RIFF", 4) != 0 || memcmp(b + 8, "WAVEfmt ", 8) != 0
        || le32(b + 16) != 16 || le16(b + 20) != 1 || le16(b + 22) != 1
        || le32(b + 24) != AUDIO_SAMPLE_RATE || le16(b + 34) != 16 || memcmp(b + 36, "data", 4) != 0) {
        return 0;
    }
    uint32_t data = le32(b + 40);
    if (data != (uint32_t)(len - WAV_HEADER) || data % 2 || data > SOUND_MAX_BYTES) return 0;
    return data / 2;
}

bool net_fetch_sound(const char *id, int16_t **pcm, size_t *samples)
{
    *pcm = NULL;
    *samples = 0;
    if (!net_wifi_is_up()) {
        set_wifi_error();
        return false;
    }
    const int cap = WAV_HEADER + SOUND_MAX_BYTES;
    uint8_t *buf = heap_caps_malloc(cap, MALLOC_CAP_SPIRAM);
    if (!buf) {
        set_error(tr(STR_ERR_SOUND_MEMORY));
        return false;
    }
    char url[384];
    snprintf(url, sizeof url, "%s?a=sound&id=%s", API_URL, id);
    body_t body = {.buf = (char *)buf, .cap = cap};
    int status = http_get(url, &body);
    bool ok = status == 200;
    if (status == 404) set_error(tr(STR_ERR_SOUND_MISSING)); // deleted after the message went out
    else if (!ok) check_status(status);
    size_t n = 0;
    if (ok) {
        n = body.overflow ? 0 : wav_samples(buf, body.len);
        ok = n > 0;
        if (!ok) set_error(tr(STR_ERR_SOUND_FORMAT));
    }
    if (!ok) {
        free(buf);
        return false;
    }
    memmove(buf, buf + WAV_HEADER, n * 2);
    *pcm = (int16_t *)buf;
    *samples = n;
    return true;
}
