<?php

declare(strict_types=1);

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
        ->value(service(\SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile::class));
};
