<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ApiUsage;

use ApiPlatform\Metadata\Exception\OperationNotFoundException;
use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The request type of a public API request for the usage statistics: HTTP method +
 * URI template, e.g. "GET /api/v1/players/{playerId}/results". A bounded set (one
 * value per API Platform operation), never the concrete URL.
 *
 * Read from the operation the router matched (`_api_resource_class` +
 * `_api_operation_name` route defaults, set before the firewall runs - so a 401 or
 * 403 still has its request type), not from the route name (some operations have
 * custom ones) and not from the `_api_operation` attribute (error rendering
 * replaces it). The metadata lookup is cached by API Platform.
 */
final readonly class ApiUsageOperation
{
    // config/routes/api_platform.php imports the API Platform routes under this prefix
    private const string ROUTE_PREFIX = '/api';

    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
    ) {
    }

    public function forRequest(Request $request): string
    {
        $method = $request->getMethod();
        $resourceClass = $request->attributes->get('_api_resource_class');
        $operationName = $request->attributes->get('_api_operation_name');

        if (!is_string($resourceClass) || !is_string($operationName) || !class_exists($resourceClass)) {
            return $method . ' (unknown)';
        }

        try {
            $operation = $this->resourceMetadataFactory->create($resourceClass)->getOperation($operationName);
        } catch (OperationNotFoundException | ResourceClassNotFoundException) {
            return $method . ' (unknown)';
        }

        $uriTemplate = $operation instanceof HttpOperation ? $operation->getUriTemplate() : null;

        if ($uriTemplate === null || $uriTemplate === '') {
            return $method . ' (unknown)';
        }

        return $method . ' ' . self::ROUTE_PREFIX . str_replace('{._format}', '', $uriTemplate);
    }
}
