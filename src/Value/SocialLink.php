<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One social link of an organization: the URL as typed, its platform (icon + name) and its host (the accessible name
 * of an `Other` link).
 */
readonly final class SocialLink
{
    public function __construct(
        public string $url,
        public SocialLinkPlatform $platform,
        public string $host,
    ) {
    }

    public static function fromUrl(string $url): self
    {
        return new self($url, SocialLinkPlatform::fromUrl($url), SocialLinkPlatform::hostOf($url));
    }

    /**
     * What a screen reader says for the icon link: the platform's name, else the host
     */
    public function name(): string
    {
        return $this->platform->label() ?? ($this->host !== '' ? $this->host : $this->url);
    }
}
