#include "board.h"
#include <string.h>
#include "driver/gpio.h"
#include "esp_adc/adc_oneshot.h"
#include "esp_adc/adc_cali.h"
#include "esp_adc/adc_cali_scheme.h"
#include "esp_log.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"

static const char *TAG = "board";
static i2c_master_bus_handle_t s_bus;
static i2c_master_dev_handle_t s_shtc3, s_rtc;
static adc_oneshot_unit_handle_t s_adc;
static adc_cali_handle_t s_cali;
static bool s_cali_ok;

#define SHTC3_ADDR 0x70
#define PCF85063_ADDR 0x51
#define SHTC3_SELF_HEAT_C 4.0f // same correction as Waveshare's demo

void board_init(void)
{
    i2c_master_bus_config_t bus_cfg = {
        .i2c_port = I2C_NUM_0,
        .sda_io_num = PIN_I2C_SDA,
        .scl_io_num = PIN_I2C_SCL,
        .clk_source = I2C_CLK_SRC_DEFAULT,
        .glitch_ignore_cnt = 7,
        .flags.enable_internal_pullup = true,
    };
    ESP_ERROR_CHECK(i2c_new_master_bus(&bus_cfg, &s_bus));
    i2c_device_config_t dev = {.dev_addr_length = I2C_ADDR_BIT_LEN_7, .scl_speed_hz = 400000};
    dev.device_address = SHTC3_ADDR;
    ESP_ERROR_CHECK(i2c_master_bus_add_device(s_bus, &dev, &s_shtc3));
    dev.device_address = PCF85063_ADDR;
    ESP_ERROR_CHECK(i2c_master_bus_add_device(s_bus, &dev, &s_rtc));

    adc_oneshot_unit_init_cfg_t unit = {.unit_id = ADC_UNIT_1};
    ESP_ERROR_CHECK(adc_oneshot_new_unit(&unit, &s_adc));
    adc_oneshot_chan_cfg_t ch = {.bitwidth = ADC_BITWIDTH_12, .atten = ADC_ATTEN_DB_12};
    ESP_ERROR_CHECK(adc_oneshot_config_channel(s_adc, ADC_CHANNEL_3, &ch)); // GPIO4
    adc_cali_curve_fitting_config_t cal = {.unit_id = ADC_UNIT_1, .atten = ADC_ATTEN_DB_12, .bitwidth = ADC_BITWIDTH_12};
    s_cali_ok = adc_cali_create_scheme_curve_fitting(&cal, &s_cali) == ESP_OK;

    gpio_config_t io = {
        .pin_bit_mask = (1ULL << PIN_KEY) | (1ULL << PIN_BOOT),
        .mode = GPIO_MODE_INPUT,
        .pull_up_en = GPIO_PULLUP_ENABLE,
    };
    gpio_config(&io);
}

i2c_master_bus_handle_t board_i2c_bus(void) { return s_bus; }

// ---------- buttons ----------

#define BTN_POLL_MS 20
#define BTN_LONG_MS 2000
#define BTN_DEBOUNCE_MS 40

static btn_cb_t s_cb;

typedef struct {
    int pin;
    btn_event_t ev_short, ev_long;
    int held_ms;
    bool long_fired;
} btn_t;

static void buttons_task(void *arg)
{
    btn_t btns[2] = {
        {PIN_KEY, BTN_KEY_SHORT, BTN_KEY_LONG, 0, false},
        {PIN_BOOT, BTN_BOOT_SHORT, BTN_BOOT_LONG, 0, false},
    };
    // Ignore buttons held during boot until released once.
    while (gpio_get_level(PIN_KEY) == 0 || gpio_get_level(PIN_BOOT) == 0) {
        vTaskDelay(pdMS_TO_TICKS(BTN_POLL_MS));
    }
    for (;;) {
        for (int i = 0; i < 2; i++) {
            btn_t *b = &btns[i];
            bool down = gpio_get_level(b->pin) == 0;
            if (down) {
                b->held_ms += BTN_POLL_MS;
                if (!b->long_fired && b->held_ms >= BTN_LONG_MS) {
                    b->long_fired = true;
                    s_cb(b->ev_long);
                }
            } else {
                if (!b->long_fired && b->held_ms >= BTN_DEBOUNCE_MS) {
                    s_cb(b->ev_short);
                }
                b->held_ms = 0;
                b->long_fired = false;
            }
        }
        vTaskDelay(pdMS_TO_TICKS(BTN_POLL_MS));
    }
}

void board_buttons_start(btn_cb_t cb)
{
    s_cb = cb;
    xTaskCreate(buttons_task, "buttons", 3072, NULL, 5, NULL);
}

// ---------- SHTC3 ----------

static uint8_t crc8(const uint8_t *d, int n)
{
    uint8_t crc = 0xFF;
    for (int i = 0; i < n; i++) {
        crc ^= d[i];
        for (int b = 0; b < 8; b++) crc = (crc & 0x80) ? (crc << 1) ^ 0x31 : (crc << 1);
    }
    return crc;
}

static esp_err_t shtc3_cmd(uint16_t cmd)
{
    uint8_t b[2] = {cmd >> 8, cmd & 0xFF};
    return i2c_master_transmit(s_shtc3, b, 2, 50);
}

bool climate_read(float *temp_c, float *hum_pct)
{
    shtc3_cmd(0x3517); // wake up (first call may NACK while asleep)
    vTaskDelay(pdMS_TO_TICKS(1));
    if (shtc3_cmd(0x3517) != ESP_OK && shtc3_cmd(0x3517) != ESP_OK) return false;
    vTaskDelay(pdMS_TO_TICKS(1));
    if (shtc3_cmd(0x7866) != ESP_OK) return false; // normal mode, T first, no clock stretch
    vTaskDelay(pdMS_TO_TICKS(15));
    uint8_t r[6];
    esp_err_t err = i2c_master_receive(s_shtc3, r, 6, 50);
    shtc3_cmd(0xB098); // sleep
    if (err != ESP_OK || crc8(r, 2) != r[2] || crc8(r + 3, 2) != r[5]) return false;
    uint16_t rt = (r[0] << 8) | r[1], rh = (r[3] << 8) | r[4];
    *temp_c = 175.0f * rt / 65536.0f - 45.0f - SHTC3_SELF_HEAT_C;
    *hum_pct = 100.0f * rh / 65536.0f;
    return true;
}

// ---------- battery ----------

int battery_mv(void)
{
    int raw = 0, mv = 0, sum = 0;
    for (int i = 0; i < 8; i++) {
        if (adc_oneshot_read(s_adc, ADC_CHANNEL_3, &raw) != ESP_OK) return 0;
        if (s_cali_ok) adc_cali_raw_to_voltage(s_cali, raw, &mv);
        else mv = raw * 3300 / 4095;
        sum += mv;
    }
    return (sum / 8) * 3; // 1:3 divider on the board
}

int battery_pct(int mv)
{
    // Linear between 3.0 V and 4.12 V, same bounds as Waveshare's demo.
    if (mv < 3000) return 0;
    if (mv > 4120) return 100;
    return (mv - 3000) * 100 / 1120;
}

// ---------- PCF85063 RTC (stores UTC) ----------

static uint8_t bcd2dec(uint8_t v) { return (v >> 4) * 10 + (v & 0x0F); }
static uint8_t dec2bcd(int v) { return (uint8_t)(((v / 10) << 4) | (v % 10)); }

bool rtc_read(time_t *out)
{
    uint8_t reg = 0x04, r[7];
    if (i2c_master_transmit_receive(s_rtc, &reg, 1, r, 7, 50) != ESP_OK) return false;
    if (r[0] & 0x80) return false; // OS flag: oscillator stopped, time invalid
    struct tm tm = {
        .tm_sec = bcd2dec(r[0] & 0x7F),
        .tm_min = bcd2dec(r[1] & 0x7F),
        .tm_hour = bcd2dec(r[2] & 0x3F),
        .tm_mday = bcd2dec(r[3] & 0x3F),
        .tm_mon = bcd2dec(r[5] & 0x1F) - 1,
        .tm_year = bcd2dec(r[6]) + 100,
    };
    // Convert as UTC: temporarily the process TZ may be local, so compute manually.
    int y = tm.tm_year + 1900, m = tm.tm_mon + 1;
    if (y < 2025) return false;
    int64_t days = 0;
    for (int yy = 1970; yy < y; yy++) days += ((yy % 4 == 0 && yy % 100 != 0) || yy % 400 == 0) ? 366 : 365;
    static const int mdays[] = {31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31};
    for (int mm = 1; mm < m; mm++) days += mdays[mm - 1] + (mm == 2 && ((y % 4 == 0 && y % 100 != 0) || y % 400 == 0));
    days += tm.tm_mday - 1;
    *out = (time_t)(days * 86400 + tm.tm_hour * 3600 + tm.tm_min * 60 + tm.tm_sec);
    return true;
}

bool rtc_write(time_t t)
{
    struct tm tm;
    gmtime_r(&t, &tm);
    uint8_t b[8] = {
        0x04,
        dec2bcd(tm.tm_sec), dec2bcd(tm.tm_min), dec2bcd(tm.tm_hour),
        dec2bcd(tm.tm_mday), (uint8_t)tm.tm_wday, dec2bcd(tm.tm_mon + 1), dec2bcd(tm.tm_year - 100),
    };
    bool ok = i2c_master_transmit(s_rtc, b, sizeof b, 50) == ESP_OK;
    if (!ok) ESP_LOGW(TAG, "RTC write failed");
    return ok;
}
