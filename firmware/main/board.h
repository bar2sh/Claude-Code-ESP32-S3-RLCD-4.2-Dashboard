// Board peripherals: buttons, I2C sensors (SHTC3, PCF85063 RTC), battery ADC.
// Pins verified against Waveshare's own ESP-IDF examples (factory, 09_LVGL_V9_Test).
#pragma once
#include <stdbool.h>
#include <time.h>
#include "driver/i2c_master.h"

#define PIN_LCD_MOSI 12
#define PIN_LCD_SCK  11
#define PIN_LCD_DC   5
#define PIN_LCD_CS   40
#define PIN_LCD_RST  41
#define PIN_I2C_SDA  13
#define PIN_I2C_SCL  14
#define PIN_KEY      18 // active low
#define PIN_BOOT     0  // active low; strapping pin, only read after boot
#define PIN_I2S_MCLK 16
#define PIN_I2S_BCLK 9
#define PIN_I2S_WS   45
#define PIN_I2S_DOUT 8
#define PIN_PA_EN    46

// Set to 1 once an 18650 cell is fitted. Without a cell the charger holds the
// battery pin near 4.1 V, so the ADC cannot tell "no cell" from "full cell";
// with 0 the board reports bat_mv = 0 and shows "USB" instead of a percentage.
#define BATTERY_FITTED 0

typedef enum { BTN_KEY_SHORT, BTN_KEY_LONG, BTN_BOOT_SHORT, BTN_BOOT_LONG } btn_event_t;
typedef void (*btn_cb_t)(btn_event_t ev);

void board_init(void);
i2c_master_bus_handle_t board_i2c_bus(void);
void board_buttons_start(btn_cb_t cb);

bool climate_read(float *temp_c, float *hum_pct);
int battery_mv(void);
int battery_pct(int mv);

bool rtc_read(time_t *out);   // returns false if RTC lost power or is absent
bool rtc_write(time_t t);
