<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\OAuth2ApplicationType;
use Symfony\Component\Validator\Constraints as Assert;

final class RequestApiAccessFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public null|string $clientName = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    public null|string $clientDescription = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    public null|string $purpose = null;

    public OAuth2ApplicationType $applicationType = OAuth2ApplicationType::Confidential;

    /** @var array<string> */
    public array $scopes = ['profile:read'];

    #[Assert\Length(max: 5000)]
    public null|string $redirectUris = null;

    /**
     * @return array<string>
     */
    public function getRedirectUrisAsArray(): array
    {
        if ($this->redirectUris === null || $this->redirectUris === '') {
            return [];
        }

        return array_filter(array_map('trim', explode("\n", $this->redirectUris)));
    }
}
