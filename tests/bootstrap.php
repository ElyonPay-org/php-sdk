<?php

declare(strict_types=1);

// Composer's autoloader when installed; a plain PSR-4 fallback otherwise, so the suite also runs
// with any PHPUnit binary (`phpunit --bootstrap tests/bootstrap.php tests`).
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';

    return;
}

spl_autoload_register(static function (string $class): void {
    foreach (['ElyonPay\\Tests\\' => __DIR__ . '/', 'ElyonPay\\' => __DIR__ . '/../src/'] as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
