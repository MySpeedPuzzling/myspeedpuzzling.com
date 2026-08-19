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
        // The wire format of the public API is snake_case (puzzle_id, is_private, ...);
        // the PHP side follows the project's camelCase standard. The converter maps
        // both directions (responses, request inputs, the OpenAPI schema and the
        // propertyPath of validation violations) - adding a DTO property means
        // writing it in camelCase, nothing else. Scoped to API Platform, the rest of
        // the application's serializer is untouched.
        'name_converter' => 'serializer.name_converter.camel_case_to_snake_case',
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
        'show_webby' => false,
        'swagger' => [
            'api_keys' => [],
            'swagger_ui_extra_configuration' => [
                'defaultModelsExpandDepth' => 0,
            ],
        ],
        'oauth' => [
            'enabled' => true,
            'type' => 'oauth2',
            'flow' => 'authorizationCode',
            'tokenUrl' => '/oauth2/token',
            'authorizationUrl' => '/oauth2/authorize',
            'scopes' => [
                'profile:read' => 'Read user profile',
                'email:read' => 'Read user email address',
                'results:read' => 'Read player results',
                'statistics:read' => 'Read player statistics',
                'collections:read' => 'Read player collections',
                'solving-times:write' => 'Create and edit solving times',
                'collections:write' => 'Create, edit, and delete collections and items',
            ],
        ],
    ]);
};
