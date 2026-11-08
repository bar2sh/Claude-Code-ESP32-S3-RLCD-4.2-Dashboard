<?php
// Copy to config.php on the server and fill in. Never commit config.php.
// Generate each token with:  php -r 'echo bin2hex(random_bytes(24)), "\n";'
return [
    'tokens' => [
        'device' => '',   // goes into firmware/main/secrets.h
        'hook'   => '',   // goes into ~/.config/rlcd/env on the Mac
    ],
    // People who use the PWA. The name is shown on the board as the sender.
    'users' => [
        ['name' => 'Ja',   'token' => ''],
        // ['name' => 'Ola', 'token' => ''],
    ],
    // Weather on the dashboard. Leave lat/lon null to hide weather.
    'location' => ['name' => '', 'lat' => null, 'lon' => null],
    // Device lock of the app. 0 = off. 2 = only the first two browsers that log in can open the app,
    // every other browser gets 404. Delete data/devices.json to free the places. The board, the hook
    // and the terminal are not affected.
    'app_lock' => ['devices' => 0],
    // Optional: a folder outside public_html is even safer, e.g. '/home/USER/rlcd-data'
    'data_dir' => __DIR__ . '/data',
];
