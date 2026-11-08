#include "store.h"
#include <string.h>
#include "app.h"
#include "esp_log.h"
#include "nvs.h"
#include "nvs_flash.h"
#include "ui_text.h"

static const char *TAG = "store";
static const char *NS = "rlcd";
#define LAYOUT_VERSION 1 // bump when a persisted struct changes, old blobs are then ignored

static nvs_handle_t s_nvs;
static bool s_ok;

void store_init(void)
{
    esp_err_t err = nvs_flash_init();
    if (err == ESP_ERR_NVS_NO_FREE_PAGES || err == ESP_ERR_NVS_NEW_VERSION_FOUND) {
        ESP_ERROR_CHECK(nvs_flash_erase());
        err = nvs_flash_init();
    }
    ESP_ERROR_CHECK(err);
    s_ok = nvs_open(NS, NVS_READWRITE, &s_nvs) == ESP_OK;
    if (!s_ok) ESP_LOGE(TAG, "nvs_open failed, state will not persist");
}

static bool get_blob(const char *key, void *out, size_t size)
{
    size_t len = size;
    return nvs_get_blob(s_nvs, key, out, &len) == ESP_OK && len == size;
}

static void commit(esp_err_t err, const char *what)
{
    if (err == ESP_OK) err = nvs_commit(s_nvs);
    if (err != ESP_OK) ESP_LOGW(TAG, "saving %s failed: %s", what, esp_err_to_name(err));
}

void store_load(void)
{
    if (!s_ok) return;
    uint8_t ver = 0;
    nvs_get_u8(s_nvs, "ver", &ver);
    if (ver != LAYOUT_VERSION) {
        ESP_LOGI(TAG, "new storage layout, starting clean");
        nvs_erase_all(s_nvs);
        nvs_set_u8(s_nvs, "ver", LAYOUT_VERSION);
        nvs_commit(s_nvs);
        return;
    }
    uint8_t u8;
    if (nvs_get_u8(s_nvs, "mode", &u8) == ESP_OK && u8 < MODE_COUNT) g_app.mode = (app_mode_t)u8;
    if (nvs_get_u8(s_nvs, "view", &u8) == ESP_OK && u8 < VIEW_COUNT) g_app.view = (app_view_t)u8;
    nvs_get_i32(s_nvs, "view_seq", &g_app.view_seq);
    // Settings keys came later without a layout bump: missing or unknown values keep the defaults.
    if (nvs_get_u8(s_nvs, "lang", &u8) == ESP_OK && u8 < LANG_COUNT) g_app.lang = (app_lang_t)u8;
    uint16_t u16;
    if (nvs_get_u16(s_nvs, "poll_s", &u16) == ESP_OK && poll_interval_ok(u16)) g_app.poll_s = u16;

    message_t m;
    if (get_blob("msg", &m, sizeof m)) {
        m.text[sizeof m.text - 1] = 0;
        m.from[sizeof m.from - 1] = 0;
        m.sound[sizeof m.sound - 1] = 0;
        g_app.msg = m;
    }
    weather_t w;
    if (get_blob("weather", &w, sizeof w)) {
        w.place[sizeof w.place - 1] = 0;
        g_app.weather = w;
    }
    if (g_app.wallpaper && get_blob("wallpaper", g_app.wallpaper, WALLPAPER_BYTES)) {
        nvs_get_i32(s_nvs, "wp_rev", &g_app.wallpaper_rev);
        g_app.has_wallpaper = true;
    }
}

void store_save_mode(void)
{
    if (s_ok) commit(nvs_set_u8(s_nvs, "mode", (uint8_t)g_app.mode), "mode");
}

void store_save_settings(void)
{
    if (!s_ok) return;
    esp_err_t err = nvs_set_u8(s_nvs, "lang", (uint8_t)g_app.lang);
    if (err == ESP_OK) err = nvs_set_u16(s_nvs, "poll_s", (uint16_t)g_app.poll_s);
    commit(err, "settings");
}

void store_save_view(void)
{
    if (!s_ok) return;
    esp_err_t err = nvs_set_u8(s_nvs, "view", (uint8_t)g_app.view);
    if (err == ESP_OK) err = nvs_set_i32(s_nvs, "view_seq", g_app.view_seq);
    commit(err, "view");
}

void store_save_message(void)
{
    if (s_ok) commit(nvs_set_blob(s_nvs, "msg", &g_app.msg, sizeof g_app.msg), "message");
}

void store_save_weather(void)
{
    if (s_ok) commit(nvs_set_blob(s_nvs, "weather", &g_app.weather, sizeof g_app.weather), "weather");
}

void store_save_wallpaper(void)
{
    if (!s_ok || !g_app.wallpaper) return;
    esp_err_t err = nvs_set_blob(s_nvs, "wallpaper", g_app.wallpaper, WALLPAPER_BYTES);
    if (err == ESP_OK) err = nvs_set_i32(s_nvs, "wp_rev", g_app.wallpaper_rev);
    commit(err, "wallpaper");
}
