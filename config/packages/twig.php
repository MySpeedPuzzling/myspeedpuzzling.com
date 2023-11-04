<?php

declare(strict_types=1);

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

return static function (\Symfony\Config\TwigConfig $twig): void {
    $twig->formThemes(['bootstrap_5_layout.html.twig']);

    $twig->date([
        'timezone' => 'Europe/Prague',
    ]);

    $twig->global('ga_tracking', env('GA_TRACKING'));
};
