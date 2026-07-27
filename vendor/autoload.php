<?php
/**
 * Minimal PSR-4 autoloader for the bundled MessageGlobe PHP SDK.
 *
 * The module ships the SDK's REST source (SMS, Contacts, Lists, Senders and the
 * shared HTTP/Exception/Config classes) under vendor/messageglobe/sdk/src so it
 * works on any PrestaShop hosting without running Composer on the server. The
 * SDK's SMTP EmailClient (and its PHPMailer dependency) is intentionally NOT
 * bundled: the module routes email through its own dependency-free SMTP mailer.
 *
 * To refresh the bundled SDK, copy the upstream src/ tree (excluding Email/ and
 * MessageGlobe.php) over vendor/messageglobe/sdk/src.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

spl_autoload_register(static function ($class) {
    $prefix = 'MessageGlobe\\';
    $baseDir = __DIR__ . '/messageglobe/sdk/src/';

    $length = strlen($prefix);
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relativeClass = substr($class, $length);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
