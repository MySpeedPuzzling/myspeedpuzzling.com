<?php declare(strict_types=1);

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

return static function (\Symfony\Config\FrameworkConfig $config) {
    $config->mailer()->dsn(env('MAILER_DSN'));

    $config->mailer()->envelope()->sender('robot@speedpuzzling.cz');

    $config->mailer()->header('From', 'MySpeedPuzzling <robot@speedpuzzling.cz>');
};
