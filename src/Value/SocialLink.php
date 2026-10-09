<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One social link of an organization: the URL as typed, its platform (icon + name) and its host (the accessible name
 * of an `Other` link). The second link of the same name is "… 2" (SocialLinks::links()), so two icon links never read
 * the same.
 */
readonly final class SocialLink
{
    public function __construct(
        public string $url,
        public SocialLinkPlatform $platform,
        public string $host,
        // 2 for the second link of the same name, 3 for the third, …
        public int $ordinal = 1,
    ) {
    }

    public static function fromUrl(string $url, int $ordinal = 1): self
    {
        return new self($url, SocialLinkPlatform::fromUrl($url), SocialLinkPlatform::hostOf($url), $ordinal);
    }

    /**
     * What a screen reader says for the icon link: the platform's name, else the host - numbered from the second link
     * of the same name on
     */
    public function name(): string
    {
        $name = self::baseName($this->platform, $this->host, $this->url);

        return $this->ordinal > 1 ? $name . ' ' . $this->ordinal : $name;
    }

    public static function baseName(SocialLinkPlatform $platform, string $host, string $url): string
    {
        return $platform->label() ?? ($host !== '' ? $host : $url);
    }
}
