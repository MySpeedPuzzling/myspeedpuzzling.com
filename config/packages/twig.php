<?php

declare(strict_types=1);

use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (\Symfony\Config\TwigConfig $twig): void {
    $twig->formThemes(['bootstrap_5_layout.html.twig']);

    $twig->date([
        'timezone' => 'Europe/Prague',
    ]);

    $twig->global('ga_tracking')
        ->value(env('GA_TRACKING'));

    $twig->global('logged_user')
        ->value(service(RetrieveLoggedUserProfile::class));

    $twig->global('get_notifications')
        ->value(service(GetNotifications::class));

    $twig->path('%kernel.project_dir%/public/img', 'images');
    $twig->path('%kernel.project_dir%/public/css', 'styles');
};
