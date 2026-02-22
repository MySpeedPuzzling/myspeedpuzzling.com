<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $configurator->extension('api_platform', [
        'title' => 'MySpeedPuzzling API',
        'version' => '1.0.0',
        'mapping' => [
            'paths' => ['%kernel.project_dir%/src/Api'],
        ],
        'formats' => [
            'json' => ['mime_types' => ['application/json']],
        ],
        'error_formats' => [
            'json' => ['mime_types' => ['application/json']],
            'problem' => ['mime_types' => ['application/problem+json']],
        ],
        'defaults' => [
            'stateless' => true,
            'cache_headers' => [
                'vary' => ['Content-Type', 'Authorization', 'Origin'],
            ],
            'normalization_context' => [
                'skip_null_values' => false,
            ],
        ],
        'enable_docs' => true,
        'enable_entrypoint' => false,
        'oauth' => [
            'enabled' => true,
            'type' => 'oauth2',
            'flow' => 'authorizationCode',
            'tokenUrl' => '/oauth2/token',
            'authorizationUrl' => '/oauth2/authorize',
            'scopes' => [
                'profile:read' => 'Read user profile',
                'results:read' => 'Read player results',
                'statistics:read' => 'Read player statistics',
                'collections:read' => 'Read player collections',
            ],
        ],
    ]);
};
