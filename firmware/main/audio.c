#include "audio.h"
#include <ctype.h>
#include <math.h>
#include <stdlib.h>
#include <string.h>
#include "board.h"
#include "driver/i2s_std.h"
#include "es8311_codec.h"
#include "esp_codec_dev.h"
#include "esp_codec_dev_defaults.h"
#include "esp_log.h"
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include "freertos/task.h"

static const char *TAG = "audio";

#define VOLUME 85        // codec output volume 0-100; Waveshare's demo uses 90-100
#define TONE_LEVEL 0.35f // built-in tones, fraction of full scale
#define FADE_MS 5        // soft start and end against clicks
#define CHUNK_FRAMES 256
#define QUEUE_LEN 4

typedef struct {
    uint16_t hz;    // 0 = pause
    uint16_t ms;
    uint16_t decay; // fade time constant in ms, 0 = flat tone
} note_t;

typedef struct {
    const char *name;
    const note_t *notes;
    int count;
} tone_t;

static const note_t CHIME[] = {{1319, 250, 120}, {1047, 400, 160}};
static const note_t BELL[] = {{880, 800, 260}, {0, 120, 0}, {880, 800, 260}};
static const note_t DINNER[] = {
    {523, 180, 0}, {659, 180, 0}, {784, 180, 0}, {1047, 300, 140}, {0, 250, 0},
    {523, 180, 0}, {659, 180, 0}, {784, 180, 0}, {1047, 300, 140},
};
static const note_t ALERT[] = {
    {1000, 120, 0}, {0, 80, 0}, {1000, 120, 0}, {0, 80, 0},
    {1000, 120, 0}, {0, 80, 0}, {1000, 120, 0},
};
#define TONE(n, a) {n, a, (int)(sizeof a / sizeof a[0])}
static const tone_t TONES[] = {
    TONE("chime", CHIME), TONE("bell", BELL), TONE("dinner", DINNER), TONE("alert", ALERT),
};

typedef struct {
    const tone_t *tone; // built-in sound, NULL for a clip
    int16_t *pcm;       // clip samples, freed after playback
    size_t samples;
} job_t;

static QueueHandle_t s_queue;
static esp_codec_dev_handle_t s_dev;
static int16_t s_frames[CHUNK_FRAMES * 2]; // stereo frames, audio task only
static int s_fill;

static void flush(void)
{
    if (s_fill == 0) return;
    esp_codec_dev_write(s_dev, s_frames, s_fill * 2 * (int)sizeof(int16_t));
    s_fill = 0;
}

// The ES8311 plays one slot; both slots carry the same mono signal.
static void emit(int16_t v)
{
    s_frames[2 * s_fill] = v;
    s_frames[2 * s_fill + 1] = v;
    if (++s_fill == CHUNK_FRAMES) flush();
}

static void play_note(const note_t *n)
{
    const int total = AUDIO_SAMPLE_RATE * n->ms / 1000;
    const int fade = AUDIO_SAMPLE_RATE * FADE_MS / 1000;
    const float two_pi = 2.0f * (float)M_PI;
    const float step = two_pi * n->hz / AUDIO_SAMPLE_RATE;
    const float k = n->decay ? expf(-1000.0f / (n->decay * (float)AUDIO_SAMPLE_RATE)) : 1.0f;
    float env = 1.0f, phase = 0.0f;
    for (int i = 0; i < total; i++) {
        float v = 0.0f;
        if (n->hz) {
            float edge = 1.0f;
            if (i < fade) edge = (float)i / fade;
            else if (total - i < fade) edge = (float)(total - i) / fade;
            v = sinf(phase) * env * edge * TONE_LEVEL;
            phase += step;
            if (phase > two_pi) phase -= two_pi;
            env *= k;
        }
        emit((int16_t)(v * 32767.0f));
    }
}

static void play_clip(const int16_t *pcm, size_t samples)
{
    const size_t fade = AUDIO_SAMPLE_RATE * FADE_MS / 1000;
    for (size_t i = 0; i < samples; i++) {
        int32_t v = pcm[i];
        if (i < fade) v = v * (int32_t)i / (int32_t)fade;
        else if (samples - i < fade) v = v * (int32_t)(samples - i) / (int32_t)fade;
        emit((int16_t)v);
    }
}

static void audio_task(void *arg)
{
    job_t job;
    for (;;) {
        if (xQueueReceive(s_queue, &job, portMAX_DELAY) != pdTRUE) continue;
        esp_codec_dev_sample_info_t fs = {
            .bits_per_sample = 16,
            .channel = 2,
            .sample_rate = AUDIO_SAMPLE_RATE,
        };
        if (esp_codec_dev_open(s_dev, &fs) != ESP_CODEC_DEV_OK) {
            ESP_LOGW(TAG, "codec open failed");
            free(job.pcm);
            continue;
        }
        esp_codec_dev_set_out_vol(s_dev, VOLUME);
        if (job.tone) {
            for (int i = 0; i < job.tone->count; i++) play_note(&job.tone->notes[i]);
        } else {
            play_clip(job.pcm, job.samples);
        }
        for (int i = 0; i < AUDIO_SAMPLE_RATE / 20; i++) emit(0); // 50 ms drains the DMA before the amp goes off
        flush();
        esp_codec_dev_close(s_dev);
        free(job.pcm);
    }
}

bool audio_init(void)
{
    i2s_chan_handle_t tx = NULL;
    i2s_chan_config_t chan = I2S_CHANNEL_DEFAULT_CONFIG(I2S_NUM_0, I2S_ROLE_MASTER);
    chan.auto_clear_after_cb = true; // silence instead of repeating the last buffer
    i2s_std_config_t std = {
        .clk_cfg = I2S_STD_CLK_DEFAULT_CONFIG(AUDIO_SAMPLE_RATE),
        .slot_cfg = I2S_STD_PHILIPS_SLOT_DEFAULT_CONFIG(I2S_DATA_BIT_WIDTH_16BIT, I2S_SLOT_MODE_STEREO),
        .gpio_cfg = {
            .mclk = PIN_I2S_MCLK,
            .bclk = PIN_I2S_BCLK,
            .ws = PIN_I2S_WS,
            .dout = PIN_I2S_DOUT,
            .din = I2S_GPIO_UNUSED,
        },
    };
    if (i2s_new_channel(&chan, &tx, NULL) != ESP_OK || i2s_channel_init_std_mode(tx, &std) != ESP_OK) {
        ESP_LOGE(TAG, "I2S init failed, no sound");
        return false;
    }
    // The codec shares the board's I2C bus with the sensors; the driver serialises access.
    audio_codec_i2s_cfg_t i2s_cfg = {.port = I2S_NUM_0, .tx_handle = tx};
    audio_codec_i2c_cfg_t i2c_cfg = {.port = I2C_NUM_0, .addr = ES8311_CODEC_DEFAULT_ADDR, .bus_handle = board_i2c_bus()};
    const audio_codec_data_if_t *data_if = audio_codec_new_i2s_data(&i2s_cfg);
    const audio_codec_ctrl_if_t *ctrl_if = audio_codec_new_i2c_ctrl(&i2c_cfg);
    const audio_codec_gpio_if_t *gpio_if = audio_codec_new_gpio();
    const audio_codec_if_t *codec_if = NULL;
    if (data_if && ctrl_if && gpio_if) {
        es8311_codec_cfg_t es = {
            .ctrl_if = ctrl_if,
            .gpio_if = gpio_if,
            .codec_mode = ESP_CODEC_DEV_WORK_MODE_DAC,
            .pa_pin = PIN_PA_EN,
            .use_mclk = true,
            .hw_gain = {.pa_gain = 6}, // Waveshare's board config for the RLCD 4.2
        };
        codec_if = es8311_codec_new(&es);
    }
    if (codec_if) {
        esp_codec_dev_cfg_t dev = {.dev_type = ESP_CODEC_DEV_TYPE_OUT, .codec_if = codec_if, .data_if = data_if};
        s_dev = esp_codec_dev_new(&dev);
    }
    if (!s_dev) {
        ESP_LOGE(TAG, "ES8311 codec not answering, no sound");
        return false;
    }
    s_queue = xQueueCreate(QUEUE_LEN, sizeof(job_t));
    if (!s_queue || xTaskCreate(audio_task, "audio", 4096, NULL, 6, NULL) != pdPASS) {
        ESP_LOGE(TAG, "audio task failed, no sound");
        s_queue = NULL;
        return false;
    }
    return true;
}

bool audio_is_custom(const char *id)
{
    if (!id || id[0] != 's' || !id[1] || strlen(id) >= SOUND_ID_LEN) return false;
    for (const char *p = id + 1; *p; p++) {
        if (!isdigit((unsigned char)*p)) return false;
    }
    return true;
}

void audio_play(const char *name)
{
    if (!s_queue || !name) return;
    for (size_t i = 0; i < sizeof TONES / sizeof TONES[0]; i++) {
        if (strcmp(TONES[i].name, name) == 0) {
            job_t job = {.tone = &TONES[i]};
            if (xQueueSend(s_queue, &job, 0) != pdTRUE) ESP_LOGW(TAG, "queue full, %s dropped", name);
            return;
        }
    }
}

void audio_play_pcm(int16_t *pcm, size_t samples)
{
    job_t job = {.pcm = pcm, .samples = samples};
    if (!s_queue || !pcm || xQueueSend(s_queue, &job, 0) != pdTRUE) {
        ESP_LOGW(TAG, "clip dropped");
        free(pcm);
    }
}
