<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

#[ApiResource(
    shortName: 'CurrentUser',
    operations: [
        new Get(
            uriTemplate: '/v1/me',
            openapi: new OpenApiOperation(
                tags: ['My Profile'],
                summary: 'Profile of the token owner',
                description: 'has_active_membership and the three opt-out flags (time_predictions_opted_out, ranking_opted_out, streak_opted_out) '
                    . 'tell an app why a members-only block elsewhere in the API is null: not a member, or the player opted out on the website. '
                    . 'membership_ends_at is the ISO-8601 end of the current membership (for a Stripe subscription: the end of the paid billing period, '
                    . 'which renews), null when the owner has no active membership.',
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_PROFILE:READ')",
            provider: CurrentUserResponseProvider::class,
        ),
    ],
)]
final class CurrentUserResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public null|string $email,
        public string $code,
        public null|string $avatar,
        public null|string $country,
        public null|string $city,
        public null|string $bio,
        public null|string $facebook,
        public null|string $instagram,
        public bool $is_private,
        public bool $has_active_membership,
        public null|string $membership_ends_at,
        public bool $time_predictions_opted_out,
        public bool $ranking_opted_out,
        public bool $streak_opted_out,
    ) {
    }
}
