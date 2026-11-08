idf_component_register(
    SRCS "main.c" "board.c" "display.c" "views.c" "net.c" "audio.c" "store.c" "ui_text.c" "notify.c" "anim_frames.c"
    INCLUDE_DIRS "."
    REQUIRES
        u8g2_st7305
        esp_driver_gpio
        esp_driver_i2c
        esp_driver_i2s
        esp_adc
        esp_wifi
        esp_netif
        esp_event
        esp_http_client
        esp-tls
        mbedtls
        nvs_flash
        json
        esp_timer
)
