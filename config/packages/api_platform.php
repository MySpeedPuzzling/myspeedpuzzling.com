<?php

declare(strict_types=1);

use ApiPlatform\Metadata\Exception\InvalidArgumentException as ApiPlatformInvalidArgumentException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;

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
        // API Platform 5 maps Doctrine's UniqueConstraintViolationException to a 422 whose detail is the raw database
        // error (SQL, constraint names). The public API keeps answering it with a plain 500 - the list is 4.x's default
        'exception_to_status' => [
            SerializerExceptionInterface::class => 400,
            ApiPlatformInvalidArgumentException::class => 400,
            OptimisticLockException::class => 409,
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
