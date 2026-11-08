// Speaker output through the ES8311 codec. Sounds play in their own task, callers never wait.
#pragma once
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#define AUDIO_SAMPLE_RATE 16000
#define SOUND_MAX_MS 15000
// Custom sounds from the library: WAV, PCM 16-bit, mono, 16 kHz, at most SOUND_MAX_MS.
#define SOUND_MAX_BYTES (AUDIO_SAMPLE_RATE * 2 * (SOUND_MAX_MS / 1000))
#define SOUND_ID_LEN 12 // fits "s" + digits, matches message_t.sound

bool audio_init(void);                 // false when the codec does not answer; sounds are then skipped
bool audio_is_custom(const char *id);  // "s<digits>": a sound from the library on the server
void audio_play(const char *name);     // built-in: chime, bell, dinner, alert; "none", "" and unknown are silent
void audio_play_pcm(int16_t *pcm, size_t samples); // takes ownership of the buffer and frees it
