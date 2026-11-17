<?php
declare(strict_types=1);
// Run once on your computer:  php tools/push-keys.php
// Prints the "push" block for server/config.php: the key pair that signs the app's push notifications.
// The private key stays in config.php. New keys end every subscription: each phone has to switch push on again.

require dirname(__DIR__) . '/server/push.php';

[$d, $point] = pushNewKey();
echo "    // Push notifications of the app. subject: your contact for the push service, mailto:... or https://...\n";
echo "    'push' => [\n";
echo "        'public'  => '" . pushB64($point) . "',\n";
echo "        'private' => '" . pushB64($d) . "',\n";
echo "        'subject' => '',\n";
echo "    ],\n";
