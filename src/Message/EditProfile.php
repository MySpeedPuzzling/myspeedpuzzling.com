<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\FormData\EditProfileFormData;

readonly final class EditProfile
{
    public function __construct(
        public string $playerId,
        public null|string $name,
        public null|string $email,
        public null|string $country,
        public null|string $city,
    ) {
    }

    public static function fromFormData(string $playerId, EditProfileFormData $formData): self
    {
        return new self(
            playerId: $playerId,
            name: $formData->name,
            email: $formData->email,
            country: $formData->country,
            city: $formData->city,
        );
    }
}
