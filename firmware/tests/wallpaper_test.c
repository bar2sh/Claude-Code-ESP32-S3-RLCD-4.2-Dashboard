// Host test of the wallpaper drawing, draw_wallpaper_image in firmware/main/display.h (docs/SPEC.md,
// "Tapeta" and "Format tapety i konwerter"). From the repository root, on a Mac or Linux, no ESP-IDF
// needed (one command line):
//     cc -std=c11 -Wall -Wextra -Werror -pedantic -I firmware/components/u8g2/csrc
//         -o /tmp/wallpaper_test firmware/tests/wallpaper_test.c && /tmp/wallpaper_test
// The three u8g2 calls are stubbed with a buffer of draw colours. What the panel shows for a colour
// depends on the display inversion command the driver sends, so the test reads it from
// firmware/components/u8g2_st7305/u8g2_st7305.c: 0x21 (on) shows colour 1 light, 0x20 shows it dark.
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include "../main/display.h"

static int s_ok, s_fail;

#define CHECK(cond)                                         \
    do {                                                    \
        if (cond) {                                         \
            s_ok++;                                         \
        } else {                                            \
            s_fail++;                                       \
            printf("FAIL line %d: %s\n", __LINE__, #cond);  \
        }                                                   \
    } while (0)

#define ROW_BYTES (SCREEN_W / 8)
#define WP_BYTES (ROW_BYTES * SCREEN_H)
_Static_assert(WP_BYTES == 15000, "SPEC, Format tapety: 15000 B");

#define DRIVER "firmware/components/u8g2_st7305/u8g2_st7305.c"

static u8g2_t s_u8g2;
static uint8_t s_px[SCREEN_H][SCREEN_W]; // the draw colour of each pixel
static uint8_t s_colour;
static int s_outside; // pixels drawn off the screen
static bool s_inverted;

void u8g2_SetDrawColor(u8g2_t *u8g2, uint8_t color)
{
    (void)u8g2;
    s_colour = color;
}

void u8g2_DrawPixel(u8g2_t *u8g2, u8g2_uint_t x, u8g2_uint_t y)
{
    (void)u8g2;
    if (x >= SCREEN_W || y >= SCREEN_H) {
        s_outside++;
        return;
    }
    s_px[y][x] = s_colour;
}

void u8g2_DrawBox(u8g2_t *u8g2, u8g2_uint_t x, u8g2_uint_t y, u8g2_uint_t w, u8g2_uint_t h)
{
    for (int yy = y; yy < y + h; yy++)
        for (int xx = x; xx < x + w; xx++) u8g2_DrawPixel(u8g2, (u8g2_uint_t)xx, (u8g2_uint_t)yy);
}

// 1 when the driver turns display inversion on (0x21), 0 when it turns it off (0x20), -1 when the
// file cannot be read or says neither or both.
static int driver_inversion(void)
{
    static char src[256 * 1024];
    FILE *f = fopen(DRIVER, "rb");
    if (!f) return -1;
    size_t n = fread(src, 1, sizeof src - 1, f);
    fclose(f);
    if (n == sizeof src - 1) return -1;
    src[n] = '\0';
    bool on = strstr(src, "st7305_write_cmd(dev, 0x21)") != NULL;
    bool off = strstr(src, "st7305_write_cmd(dev, 0x20)") != NULL;
    if (on == off) return -1;
    return on ? 1 : 0;
}

static bool shown_dark(uint8_t colour)
{
    return s_inverted ? colour == 0 : colour != 0;
}

static bool wp_black(const uint8_t *wp, int x, int y)
{
    return wp[y * ROW_BYTES + x / 8] & (0x80 >> (x % 8));
}

static void set_black(uint8_t *wp, int x, int y)
{
    wp[y * ROW_BYTES + x / 8] |= (uint8_t)(0x80 >> (x % 8));
}

// Draws the wallpaper on a buffer filled with colour `start` (0 = just after u8g2_ClearBuffer) and
// returns how many pixels the panel would show differently from the wallpaper.
static int wrong_pixels(const uint8_t *wp, uint8_t start)
{
    memset(s_px, start, sizeof s_px);
    s_colour = 1; // views_render sets colour 1 before it draws a view
    s_outside = 0;
    draw_wallpaper_image(&s_u8g2, wp);
    int wrong = 0;
    for (int y = 0; y < SCREEN_H; y++) {
        for (int x = 0; x < SCREEN_W; x++) {
            if (shown_dark(s_px[y][x]) == wp_black(wp, x, y)) continue;
            if (!wrong)
                printf("  first wrong pixel x %d, y %d: wallpaper %s, panel %s\n", x, y,
                       wp_black(wp, x, y) ? "black" : "white", shown_dark(s_px[y][x]) ? "dark" : "light");
            wrong++;
        }
    }
    return wrong;
}

int main(void)
{
    int inversion = driver_inversion();
    CHECK(inversion >= 0); // run from the repository root; the driver sends exactly one of 0x20 and 0x21
    if (inversion < 0) {
        printf("cannot tell the display inversion from " DRIVER "\n");
        return 1;
    }
    s_inverted = inversion == 1;
    printf("display inversion %s (%s)\n", s_inverted ? "on" : "off", s_inverted ? "0x21" : "0x20");

    // White with a black 40x30 block in the top left corner and a black row across the middle. Two
    // single pixels pin the bit order: x 9 is bit 0x40 of the second byte of its row, and the bottom
    // right pixel is the lowest bit of the last byte.
    static uint8_t photo[WP_BYTES];
    for (int y = 0; y < 30; y++)
        for (int x = 0; x < 40; x++) set_black(photo, x, y);
    for (int x = 0; x < SCREEN_W; x++) set_black(photo, x, 150);
    set_black(photo, 9, 40);
    set_black(photo, SCREEN_W - 1, SCREEN_H - 1);

    CHECK(wrong_pixels(photo, 0) == 0); // looks as in the app preview: black pixels dark, the rest light
    CHECK(s_colour == 1);               // the draw colour is back at 1 for the toast and the next view
    CHECK(s_outside == 0);
    CHECK(wrong_pixels(photo, 1) == 0); // a buffer that was not cleared gives the same picture

    static uint8_t white[WP_BYTES], black[WP_BYTES];
    memset(black, 0xFF, sizeof black);
    CHECK(wrong_pixels(white, 0) == 0); // a white wallpaper is a light screen
    CHECK(wrong_pixels(black, 0) == 0); // a black wallpaper is a dark screen

    if (s_fail) {
        printf("%d FAILED, %d ok\n", s_fail, s_ok);
        return 1;
    }
    printf("ALL PASS, %d ok\n", s_ok);
    return 0;
}
