#pragma once
#include "app.h"
#include "ui_text.h"
#include "notify.h"

typedef struct {
    const char *id;    // name used by the server API ("dashboard", ...)
    str_id_t label;    // shown on the board, through tr()
    void (*draw)(void);
} view_def_t;

extern const view_def_t VIEWS[VIEW_COUNT];

int view_from_id(const char *id); // -1 if unknown
void views_render(void);            // draws g_app.view (+ overlays) and presents
void views_toggle_status(void);     // KEY long press: status screen for 10 s, again closes it
void views_close_status(void);
void views_toast(const char *text); // one-line notice at the bottom for 4 s
bool views_overlay_expired(void);   // true once when an overlay timed out: redraw then

// Session notification: full screen for 6 s over everything else, including the status screen.
void views_notify(notify_kind_t kind, const char *detail, anim_id_t anim);
bool views_notify_close(void);       // true when it was open, so the button press only closed it
// Animations move only while one is on screen (the notification or the corner of the sessions view).
int views_frame_wait_ms(int max_ms); // how long the main loop may wait before the next frame is due
bool views_frame_due(void);          // true when the frame on screen is out of date: redraw then
