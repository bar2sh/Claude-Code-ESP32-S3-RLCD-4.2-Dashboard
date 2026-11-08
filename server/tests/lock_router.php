<?php
declare(strict_types=1);
// Router of php -S for lock_test.php. It mounts the app in a subfolder, /app/sub/, as a shared host
// would, and plays .htaccess: api.php runs as it is, every other request goes to gate.php.
// X-Test-Script-Name stands for a host that reports a wrong SCRIPT_NAME after the rewrite.

$lockTestRoot = dirname(__DIR__);
if (parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/app/sub/api.php') {
    $_SERVER['SCRIPT_NAME'] = '/app/sub/api.php';
    require "$lockTestRoot/api.php";
} else {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['HTTP_X_TEST_SCRIPT_NAME'] ?? '/app/sub/gate.php';
    require "$lockTestRoot/gate.php";
}
