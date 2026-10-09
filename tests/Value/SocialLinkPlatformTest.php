<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SocialLink;
use SpeedPuzzling\Web\Value\SocialLinkPlatform;

final class SocialLinkPlatformTest extends TestCase
{
    /**
     * @return iterable<string, array{string, SocialLinkPlatform}>
     */
    public static function urls(): iterable
    {
        yield 'instagram' => ['https://instagram.com/lanternclub', SocialLinkPlatform::Instagram];
        yield 'instagram www' => ['https://www.instagram.com/lanternclub/', SocialLinkPlatform::Instagram];
        yield 'facebook' => ['https://facebook.com/lanternclub', SocialLinkPlatform::Facebook];
        yield 'facebook mobile' => ['https://m.facebook.com/lanternclub', SocialLinkPlatform::Facebook];
        yield 'facebook web subdomain' => ['https://web.facebook.com/groups/lanternclub', SocialLinkPlatform::Facebook];
        yield 'fb.com' => ['https://fb.com/lanternclub', SocialLinkPlatform::Facebook];
        yield 'fb.me' => ['https://fb.me/lanternclub', SocialLinkPlatform::Facebook];
        yield 'discord invite' => ['https://discord.gg/lanternclub', SocialLinkPlatform::Discord];
        yield 'discord.com' => ['https://discord.com/invite/lanternclub', SocialLinkPlatform::Discord];
        yield 'youtube' => ['https://www.youtube.com/@lanternclub', SocialLinkPlatform::Youtube];
        yield 'youtube mobile' => ['https://m.youtube.com/@lanternclub', SocialLinkPlatform::Youtube];
        yield 'youtu.be' => ['https://youtu.be/abc123', SocialLinkPlatform::Youtube];
        yield 'tiktok' => ['https://www.tiktok.com/@lanternclub', SocialLinkPlatform::Tiktok];
        yield 'x' => ['https://x.com/lanternclub', SocialLinkPlatform::X];
        yield 'twitter' => ['https://twitter.com/lanternclub', SocialLinkPlatform::X];
        yield 'twitter mobile' => ['https://mobile.twitter.com/lanternclub', SocialLinkPlatform::X];
        yield 'threads.net' => ['https://www.threads.net/@lanternclub', SocialLinkPlatform::Threads];
        yield 'threads.com' => ['https://threads.com/@lanternclub', SocialLinkPlatform::Threads];
        yield 'bluesky' => ['https://bsky.app/profile/lanternclub.example', SocialLinkPlatform::Bluesky];
        yield 'linkedin' => ['https://www.linkedin.com/company/lanternclub', SocialLinkPlatform::Linkedin];
        yield 'linkedin country subdomain' => ['https://de.linkedin.com/company/lanternclub', SocialLinkPlatform::Linkedin];
        yield 'reddit' => ['https://www.reddit.com/r/lanternclub', SocialLinkPlatform::Reddit];
        yield 'reddit old' => ['https://old.reddit.com/r/lanternclub', SocialLinkPlatform::Reddit];
        yield 'twitch' => ['https://www.twitch.tv/lanternclub', SocialLinkPlatform::Twitch];
        yield 'whatsapp wa.me' => ['https://wa.me/15550100', SocialLinkPlatform::Whatsapp];
        yield 'whatsapp channel' => ['https://chat.whatsapp.com/abc', SocialLinkPlatform::Whatsapp];
        yield 'whatsapp.com' => ['https://whatsapp.com/channel/abc', SocialLinkPlatform::Whatsapp];
        yield 'pinterest' => ['https://www.pinterest.com/lanternclub', SocialLinkPlatform::Pinterest];
        yield 'pin.it' => ['https://pin.it/abc', SocialLinkPlatform::Pinterest];
        yield 'upper case host' => ['https://WWW.Instagram.COM/lanternclub', SocialLinkPlatform::Instagram];
        yield 'own website' => ['https://lantern-club.example', SocialLinkPlatform::Other];
        yield 'look-alike host' => ['https://notinstagram.com/lanternclub', SocialLinkPlatform::Other];
        yield 'platform name in the path' => ['https://lantern.example/instagram.com', SocialLinkPlatform::Other];
        yield 'no host' => ['not a url', SocialLinkPlatform::Other];
    }

    #[DataProvider('urls')]
    public function testThePlatformComesFromTheHost(string $url, SocialLinkPlatform $platform): void
    {
        self::assertSame($platform, SocialLinkPlatform::fromUrl($url));
    }

    public function testTheHostIsLowerCasedWithoutCommonPrefixes(): void
    {
        self::assertSame('instagram.com', SocialLinkPlatform::hostOf('https://WWW.Instagram.com/x'));
        self::assertSame('facebook.com', SocialLinkPlatform::hostOf('https://m.facebook.com/x'));
        self::assertSame('twitter.com', SocialLinkPlatform::hostOf('https://mobile.twitter.com/x'));
        self::assertSame('lantern-club.example', SocialLinkPlatform::hostOf('https://lantern-club.example/'));
        self::assertSame('', SocialLinkPlatform::hostOf('nothing here'));
    }

    public function testEveryPlatformHasAnIconAndOnlyOtherHasNoLabel(): void
    {
        foreach (SocialLinkPlatform::cases() as $platform) {
            self::assertStringStartsWith('bi-', $platform->iconClass());

            if ($platform === SocialLinkPlatform::Other) {
                self::assertNull($platform->label());
            } else {
                self::assertNotNull($platform->label());
            }
        }

        self::assertSame('bi-instagram', SocialLinkPlatform::Instagram->iconClass());
        self::assertSame('bi-twitter-x', SocialLinkPlatform::X->iconClass());
        self::assertSame('X', SocialLinkPlatform::X->label());
        self::assertSame('YouTube', SocialLinkPlatform::Youtube->label());
        // Bootstrap Icons 1.11 has no Bluesky icon - the generic link icon, the name stays
        self::assertSame('bi-link-45deg', SocialLinkPlatform::Bluesky->iconClass());
        self::assertSame('Bluesky', SocialLinkPlatform::Bluesky->label());
        self::assertSame('bi-link-45deg', SocialLinkPlatform::Other->iconClass());
    }

    public function testALinksAccessibleNameIsThePlatformElseTheHost(): void
    {
        self::assertSame('Instagram', SocialLink::fromUrl('https://www.instagram.com/lanternclub')->name());
        self::assertSame('lantern-club.example', SocialLink::fromUrl('https://www.lantern-club.example/about')->name());
    }
}
