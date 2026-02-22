<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;

#[ApiResource(
    shortName: 'CurrentUser',
    operations: [
        new Get(
            uriTemplate: '/v1/me',
            security: "is_granted('ROLE_OAUTH2_PROFILE:READ')",
            provider: CurrentUserResponseProvider::class,
        ),
    ],
)]
final class CurrentUserResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public string $code,
        public null|string $avatar,
        public null|string $country,
        public null|string $city,
        public null|string $bio,
        public null|string $facebook,
        public null|string $instagram,
        public bool $is_private,
        public bool $has_active_membership,
    ) {
    }
}
