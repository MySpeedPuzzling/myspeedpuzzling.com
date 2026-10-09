<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The platform of an organization's social link, read from its host (docs/features/organizations/README.md, D9/P13) -
 * the icon and the accessible name of the link. Anything not listed is `Other` (a generic link icon, the host as its
 * name).
 */
enum SocialLinkPlatform: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case Discord = 'discord';
    case Youtube = 'youtube';
    case Tiktok = 'tiktok';
    case X = 'x';
    case Threads = 'threads';
    case Bluesky = 'bluesky';
    case Linkedin = 'linkedin';
    case Reddit = 'reddit';
    case Twitch = 'twitch';
    case Whatsapp = 'whatsapp';
    case Pinterest = 'pinterest';
    case Other = 'other';

    /**
     * Registrable hosts of each platform - a host matches itself and its subdomains
     */
    private const array HOSTS = [
        'instagram' => ['instagram.com'],
        'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
        'discord' => ['discord.gg', 'discord.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'tiktok' => ['tiktok.com'],
        'x' => ['x.com', 'twitter.com'],
        'threads' => ['threads.net', 'threads.com'],
        'bluesky' => ['bsky.app'],
        'linkedin' => ['linkedin.com'],
        'reddit' => ['reddit.com'],
        'twitch' => ['twitch.tv'],
        'whatsapp' => ['wa.me', 'whatsapp.com'],
        'pinterest' => ['pinterest.com', 'pin.it'],
    ];

    public static function fromUrl(string $url): self
    {
        $host = self::hostOf($url);

        if ($host === '') {
            return self::Other;
        }

        foreach (self::HOSTS as $platform => $hosts) {
            foreach ($hosts as $platformHost) {
                if ($host === $platformHost || str_ends_with($host, '.' . $platformHost)) {
                    return self::from($platform);
                }
            }
        }

        return self::Other;
    }

    /**
     * The host lower-cased, without `www.` / `m.` / `mobile.` in front - '' when the URL has none
     */
    public static function hostOf(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (is_string($host) === false || $host === '') {
            return '';
        }

        $host = strtolower(rtrim($host, '.'));

        return (string) preg_replace('/^(www|m|mobile)\./', '', $host);
    }

    /**
     * A Bootstrap Icons class (loaded site-wide). Bootstrap Icons 1.11 has no Bluesky icon - the generic link icon.
     */
    public function iconClass(): string
    {
        return match ($this) {
            self::Instagram => 'bi-instagram',
            self::Facebook => 'bi-facebook',
            self::Discord => 'bi-discord',
            self::Youtube => 'bi-youtube',
            self::Tiktok => 'bi-tiktok',
            self::X => 'bi-twitter-x',
            self::Threads => 'bi-threads',
            self::Linkedin => 'bi-linkedin',
            self::Reddit => 'bi-reddit',
            self::Twitch => 'bi-twitch',
            self::Whatsapp => 'bi-whatsapp',
            self::Pinterest => 'bi-pinterest',
            self::Bluesky, self::Other => 'bi-link-45deg',
        };
    }

    /**
     * The brand name - the link's accessible name; null for `Other` (the host is used instead)
     */
    public function label(): null|string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::Discord => 'Discord',
            self::Youtube => 'YouTube',
            self::Tiktok => 'TikTok',
            self::X => 'X',
            self::Threads => 'Threads',
            self::Bluesky => 'Bluesky',
            self::Linkedin => 'LinkedIn',
            self::Reddit => 'Reddit',
            self::Twitch => 'Twitch',
            self::Whatsapp => 'WhatsApp',
            self::Pinterest => 'Pinterest',
            self::Other => null,
        };
    }
}
