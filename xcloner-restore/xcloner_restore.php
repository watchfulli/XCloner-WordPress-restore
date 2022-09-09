<?php

require __DIR__ . '/lib/vendor/autoload.php';
require __DIR__ . '/lib/class-xcloner-restore.php';

use Watchful\XClonerRestore\Xcloner_Restore;

header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN'] ?? '*');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Access-Control-Allow-Headers, Origin, Accept, X-Requested-With, Content-Type, Access-Control-Request-Method, Access-Control-Request-Headers, Authorization');
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

if (!defined('XCLONER_RESTORE_SCRIPT_PATH')) {
    define('XCLONER_RESTORE_SCRIPT_PATH', __FILE__);
}

if (!defined('XCLONER_RESTORE_LIB_PATH')) {
    define('XCLONER_RESTORE_LIB_PATH', dirname(__FILE__) . DS . 'lib');
}

if (!defined('XCLONER_RESTORE_ARCHIVE_PATH')) {
    define('XCLONER_RESTORE_ARCHIVE_PATH', dirname(__FILE__) . DS . 'archives');
}

if (version_compare(phpversion(), Xcloner_Restore::MINIMUM_PHP_VERSION, '<')) {
    Xcloner_Restore::send_response(500, sprintf(("XCloner requires minimum PHP version %s in order to run correctly. We have detected your version as %s"), Xcloner_Restore::MINIMUM_PHP_VERSION, phpversion()));
    exit;
}

$xcloner_restore = new Xcloner_Restore();

try {
    $xcloner_restore->init();
} catch (Exception $e) {
    $xcloner_restore->send_response(417, $e->getMessage());
}
