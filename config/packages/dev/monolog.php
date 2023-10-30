<?php

declare(strict_types=1);

use Monolog\Level;
use Sentry\State\HubInterface;
use Symfony\Config\MonologConfig;

return static function (MonologConfig $monologConfig): void {
    $monologConfig->handler('main')
        ->type('stream')
        ->path('%kernel.logs_dir%/%kernel.environment%.log')
        ->level('debug')
        ->channels()
            ->elements(['!event']);

    $monologConfig->handler('console')
        ->type('console')
        ->processPsr3Messages(false)
        ->channels()
            ->elements(['!event', '!doctrine', '!console']);

    $monologConfig->handler('sentry')
        ->type('sentry')
        ->level(Level::Warning->value)
        ->hubId(HubInterface::class);
};
