<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'AddCollectionItem',
    operations: [
        new Post(
            uriTemplate: '/v1/me/collections/{collectionId}/items',
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:WRITE')",
            output: CollectionItemResponse::class,
            processor: AddCollectionItemProcessor::class,
        ),
    ],
)]
final class AddCollectionItemInput
{
    #[Assert\NotBlank]
    public string $puzzle_id = '';

    #[Assert\Length(max: 500)]
    public null|string $comment = null;
}
