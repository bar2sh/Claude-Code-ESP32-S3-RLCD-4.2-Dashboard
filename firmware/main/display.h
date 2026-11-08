#pragma once
#include <stdbool.h>
#include "u8g2.h"

#define SCREEN_W 400
#define SCREEN_H 300

void display_init(void);
u8g2_t *display_u8g2(void);
void display_present(void); // send the frame buffer to the panel

// Draws UTF-8 text wrapped to width w, starting with the baseline at y.
// Returns the y of the next line, or -1 if the text did not fit in max_y.
// With draw=false it only measures.
int draw_wrapped(u8g2_t *u, int x, int y, int w, int max_y, const char *text, bool draw);
void draw_centered(u8g2_t *u, int y, const char *text);
void draw_right(u8g2_t *u, int right_x, int y, const char *text);

// The panel runs with display inversion on (command 0x21 in components/u8g2_st7305/u8g2_st7305.c),
// so u8g2 colour 1 shows light and the views are light on dark. A wallpaper (docs/SPEC.md, "Format
// tapety": 1 bit per pixel, 1 = black) is drawn the other way round, so it looks as in the app
// preview, black on white: the whole screen in colour 1, then each black pixel in colour 0.
// Leaves the draw colour at 1. Inline, so firmware/tests/wallpaper_test.c runs it on a Mac.
static inline void draw_wallpaper_image(u8g2_t *u, const uint8_t *bits)
{
    u8g2_SetDrawColor(u, 1);
    u8g2_DrawBox(u, 0, 0, SCREEN_W, SCREEN_H);
    u8g2_SetDrawColor(u, 0);
    for (int y = 0; y < SCREEN_H; y++) {
        for (int xb = 0; xb < SCREEN_W / 8; xb++) {
            uint8_t b = *bits++;
            if (!b) continue;
            for (int bit = 0; bit < 8; bit++)
                if (b & (0x80 >> bit)) u8g2_DrawPixel(u, xb * 8 + bit, y);
        }
    }
    u8g2_SetDrawColor(u, 1);
}
