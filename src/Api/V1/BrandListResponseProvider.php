<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetApiBrandList;
use SpeedPuzzling\Web\Results\ApiBrandListItem;
use SpeedPuzzling\Web\Services\UploaderHelper;

/**
 * GET /api/v1/brands - where a client finds the brand id GET /api/v1/puzzles filters by (docs/features/api/README.md
 * "Brands"). The catalog is public: any authenticated token, no membership gate.
 *
 * @implements ProviderInterface<BrandListResponse>
 */
final readonly class BrandListResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetApiBrandList $getApiBrandList,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BrandListResponse
    {
        $brands = array_map(
            fn (ApiBrandListItem $item): BrandListItemResponse => new BrandListItemResponse(
                id: $item->id,
                name: $item->name,
                slug: $item->slug,
                logoUrl: $item->logo !== null ? $this->uploaderHelper->getPublicPath($item->logo) : null,
                puzzlesCount: $item->puzzlesCount,
            ),
            $this->getApiBrandList->all($this->search($operation)),
        );

        return new BrandListResponse(
            count: count($brands),
            brands: $brands,
        );
    }

    private function search(Operation $operation): null|string
    {
        $value = $operation->getParameters()?->get('query')?->getValue();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
