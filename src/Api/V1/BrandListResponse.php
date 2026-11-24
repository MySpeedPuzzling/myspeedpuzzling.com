<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Symfony\Component\Validator\Constraints\Length;

#[ApiResource(
    shortName: 'BrandList',
    operations: [
        new Get(
            uriTemplate: '/v1/brands',
            openapi: new OpenApiOperation(
                tags: ['Puzzles'],
                summary: 'List the puzzle brands (manufacturers)',
                description: 'Every brand with at least one puzzle in the catalog, by name; "query" searches the names. Send its id as "brand" '
                    . '(or the older "manufacturer") to GET /api/v1/puzzles to list the brand\'s puzzles - the filter takes the id, '
                    . 'or the exact name with letter case ignored. '
                    . 'It is the same id as manufacturer.id on a puzzle card. puzzles_count is the "total" that filter returns. '
                    . 'logo_url is the absolute URL of the brand\'s logo (null without one); slug names the brand\'s page on the '
                    . 'website (/en/puzzle/brand/{slug}). Brands are occasionally merged when a duplicate is found: the duplicate\'s id '
                    . 'then disappears from this list, so refresh a stored id from here now and then.',
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: BrandListResponseProvider::class,
            parameters: [
                'query' => new QueryParameter(
                    key: 'query',
                    schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                    description: 'Brands whose name contains this text, accents and letter case ignored - the closest first: the whole name, a name starting with it, a word starting with it, the rest. Surrounding whitespace is ignored; an empty value lists every brand.',
                    constraints: [new Length(min: 1, max: 100)],
                    castToNativeType: true,
                    castFn: [QueryParameterCaster::class, 'trim'],
                ),
            ],
        ),
    ],
)]
final class BrandListResponse
{
    /** @var array<BrandListItemResponse> */
    public array $brands;

    /**
     * @param array<BrandListItemResponse> $brands
     */
    public function __construct(
        public int $count,
        array $brands,
    ) {
        $this->brands = $brands;
    }
}
