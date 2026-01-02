<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\NamedObject;
use Doctrine\DBAL\Schema\OptionallyNamedObject;

use function preg_match;

/**
 * Custom implementation of RegexSchemaAssetFilter that uses the new DBAL API
 * instead of the deprecated getName() method.
 *
 * This replaces Doctrine\Bundle\DoctrineBundle\Dbal\RegexSchemaAssetFilter
 * to avoid deprecation warnings.
 */
final readonly class RegexSchemaAssetFilter
{
    public function __construct(
        private string $filterExpression,
    ) {
    }

    /** @phpstan-ignore missingType.generics, parameter.internalClass */
    public function __invoke(string|AbstractAsset $assetName): bool
    {
        if ($assetName instanceof AbstractAsset) { // @phpstan-ignore instanceof.internalClass
            if ($assetName instanceof NamedObject) {
                $assetName = $assetName->getObjectName()->toString();
            } elseif ($assetName instanceof OptionallyNamedObject) {
                $name = $assetName->getObjectName();
                $assetName = $name?->toString() ?? '';
            } else {
                // Fallback for any other case - use deprecated method as last resort
                $assetName = $assetName->getName(); // @phpstan-ignore method.internalClass
            }
        }

        return (bool) preg_match($this->filterExpression, $assetName);
    }
}
