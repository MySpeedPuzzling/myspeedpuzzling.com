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
        public null|string $email,
        public null|string $country,
        public null|string $city,
        public null|UploadedFile $avatar,
        public null|string $bio,
        public null|string $facebook,
        public null|string $instagram,
        public bool $allowDirectMessages = true,
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
            avatar: $formData->avatar,
            bio: $formData->bio,
            facebook: $formData->facebook,
            instagram: $formData->instagram,
            allowDirectMessages: $formData->allowDirectMessages,
        );
    }
}
