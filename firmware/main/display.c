#include "display.h"
#include <string.h>
#include "board.h"
#include "u8g2_st7305.h"

static u8g2_st7305_t s_lcd;

void display_init(void)
{
    u8g2_st7305_config_t cfg = u8g2_st7305_default_config();
    cfg.mosi_io = PIN_LCD_MOSI;
    cfg.sclk_io = PIN_LCD_SCK;
    cfg.dc_io = PIN_LCD_DC;
    cfg.cs_io = PIN_LCD_CS;
    cfg.reset_io = PIN_LCD_RST;
    cfg.rotation = U8G2_R1; // panel is 300x400 natively, we draw landscape 400x300
    cfg.tile_buf_height = U8G2_ST7305_TILE_BUF_FULL;
    ESP_ERROR_CHECK(u8g2_st7305_init(&s_lcd, &cfg));
    u8g2_t *u = display_u8g2();
    u8g2_SetFontMode(u, 1);
    u8g2_SetDrawColor(u, 1);
    u8g2_ClearBuffer(u);
    u8g2_SendBuffer(u);
}

u8g2_t *display_u8g2(void) { return u8g2_st7305_get_u8g2(&s_lcd); }

void display_present(void) { u8g2_SendBuffer(display_u8g2()); }

void draw_centered(u8g2_t *u, int y, const char *text)
{
    int w = u8g2_GetUTF8Width(u, text);
    u8g2_DrawUTF8(u, (SCREEN_W - w) / 2, y, text);
}

void draw_right(u8g2_t *u, int right_x, int y, const char *text)
{
    u8g2_DrawUTF8(u, right_x - u8g2_GetUTF8Width(u, text), y, text);
}

// Width of the first n bytes of s.
static int width_n(u8g2_t *u, const char *s, int n)
{
    char buf[160];
    if (n >= (int)sizeof buf) n = sizeof buf - 1;
    memcpy(buf, s, n);
    buf[n] = 0;
    return u8g2_GetUTF8Width(u, buf);
}

static int utf8_len(unsigned char c)
{
    return c < 0x80 ? 1 : (c >> 5) == 6 ? 2 : (c >> 4) == 14 ? 3 : 4;
}

int draw_wrapped(u8g2_t *u, int x, int y, int w, int max_y, const char *text, bool draw)
{
    int line_h = u8g2_GetAscent(u) - u8g2_GetDescent(u) + 4;
    const char *p = text;
    while (*p) {
        if (y > max_y) return -1;
        // Find the longest prefix that fits, preferring to break at a space.
        int n = 0, last_space = -1;
        while (p[n] && p[n] != '\n') {
            int step = utf8_len((unsigned char)p[n]);
            if (width_n(u, p, n + step) > w) break;
            if (p[n] == ' ') last_space = n;
            n += step;
        }
        int take = n, skip = 0;
        if (p[n] == '\n') {
            skip = 1;
        } else if (p[n] && last_space > 0) {
            take = last_space;
            skip = 1;
        } else if (n == 0) {
            take = utf8_len((unsigned char)p[0]); // single glyph wider than w
        }
        char line[160];
        if (take >= (int)sizeof line) take = sizeof line - 1;
        memcpy(line, p, take);
        line[take] = 0;
        if (draw) u8g2_DrawUTF8(u, x, y, line);
        p += take + skip;
        y += line_h;
    }
    return y;
}
