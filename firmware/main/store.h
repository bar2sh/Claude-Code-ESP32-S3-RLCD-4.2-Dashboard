// Persistence of the board state in NVS, so offline mode and reboots keep the last content.
#pragma once

void store_init(void);           // NVS flash init (erases on layout change)
void store_load(void);           // fills the persisted fields of g_app
void store_save_mode(void);
void store_save_settings(void);  // lang + poll_s
void store_save_view(void);      // view + view_seq
void store_save_message(void);
void store_save_weather(void);
void store_save_wallpaper(void); // wallpaper bytes + wallpaper_rev
