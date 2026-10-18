<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\FormData\EditProfileFormData;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditProfile
{
    public function __construct(
        public string $playerId,
        public null|string $name,
        public null|string $country,
        public null|string $city,
        public null|UploadedFile $avatar,
        public null|string $bio,
        public null|string $facebook,
        public null|string $instagram,
        public null|string $twitch,
        // The player's choice in the settings (docs/features/player-timezone.md): null clears it, an unknown value is
        // ignored. No default on purpose - every form that sends EditProfile must carry the field, or it would clear it
        public null|string $timezone,
    ) {
    }

    public static function fromFormData(string $playerId, EditProfileFormData $formData): self
    {
        return new self(
            playerId: $playerId,
            name: $formData->name,
            country: $formData->country,
            city: $formData->city,
            avatar: $formData->avatar,
            bio: $formData->bio,
            facebook: $formData->facebook,
            instagram: $formData->instagram,
            twitch: $formData->twitch,
            timezone: $formData->timezone,
        );
    }
}
