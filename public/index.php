<?php

declare(strict_types=1);

use SpeedPuzzling\Web\SymfonyApplicationKernel;

// Check for Panther database override BEFORE runtime loads env
if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev') === 'test') {
    $pantherDbFile = dirname(__DIR__) . '/var/panther_db_url.txt';
    if (file_exists($pantherDbFile)) {
        $url = trim(file_get_contents($pantherDbFile));
        if ($url !== '') {
            $_ENV['DATABASE_URL'] = $url;
            $_SERVER['DATABASE_URL'] = $url;
            putenv('DATABASE_URL=' . $url);
        }
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new SymfonyApplicationKernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
