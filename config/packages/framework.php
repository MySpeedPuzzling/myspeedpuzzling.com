<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'csrf_protection' => true,
        'session' => [
            'handler_id' => PdoSessionHandler::class,
            'cookie_secure' => 'auto',
            'cookie_samesite' => 'lax',
            'storage_factory_id' => 'session.storage.factory.native',
        ],
        'php_errors' => [
            'log' => true,
        ],
        'trusted_headers' => ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-prefix'],
        'trusted_proxies' => '127.0.0.1,REMOTE_ADDR',
    ]);
};
