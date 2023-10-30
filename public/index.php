<?php

declare(strict_types=1);

use SpeedPuzzling\Web\SymfonyApplicationKernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new SymfonyApplicationKernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
