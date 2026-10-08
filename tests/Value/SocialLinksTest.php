<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SocialLinkPlatform;
use SpeedPuzzling\Web\Value\SocialLinks;

final class SocialLinksTest extends TestCase
{
    public function testAStringIsSplitOnLineBreaksTrimmedAndWithoutEmptyLines(): void
    {
        $links = SocialLinks::fromInput("  https://www.instagram.com/lanternclub  \r\n\n https://discord.gg/lanternclub\n   \n");

        self::assertSame(['https://www.instagram.com/lanternclub', 'https://discord.gg/lanternclub'], $links->urls);
    }

    public function testRepeatsAreDroppedCaseInsensitivelyKeepingTheFirstAndTheOrder(): void
    {
        $links = SocialLinks::fromInput([
            'https://discord.gg/lanternclub',
            'https://www.instagram.com/LanternClub',
            'https://DISCORD.gg/lanternclub',
            'https://www.instagram.com/lanternclub',
            '',
        ]);

        self::assertSame(['https://discord.gg/lanternclub', 'https://www.instagram.com/LanternClub'], $links->urls);
    }

    public function testAtMostTenLinks(): void
    {
        $ten = array_map(static fn (int $number): string => 'https://lantern' . $number . '.example', range(1, 10));
        self::assertCount(SocialLinks::MAX, SocialLinks::fromInput($ten)->urls);

        $this->expectException(InvalidArgumentException::class);
        SocialLinks::fromInput([...$ten, 'https://lantern11.example']);
    }

    public function testOnlyWebAddresses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SocialLinks::fromInput(['https://www.instagram.com/lanternclub', 'javascript:alert(1)']);
    }

    public function testAnAddressWithoutHostIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SocialLinks(['https://']);
    }

    public function testFtpIsNotAWebAddress(): void
    {
        self::assertFalse(SocialLinks::isWebAddress('ftp://lantern.example'));
        self::assertFalse(SocialLinks::isWebAddress('lantern.example'));
        self::assertTrue(SocialLinks::isWebAddress('HTTP://lantern.example'));
        self::assertTrue(SocialLinks::isWebAddress('https://lantern.example/club'));
    }

    public function testLinksCarryTheirPlatformAndHost(): void
    {
        $links = SocialLinks::fromInput("https://www.instagram.com/lanternclub\nhttps://lantern-club.example")->links();

        self::assertCount(2, $links);
        self::assertSame('https://www.instagram.com/lanternclub', $links[0]->url);
        self::assertSame(SocialLinkPlatform::Instagram, $links[0]->platform);
        self::assertSame('instagram.com', $links[0]->host);
        self::assertSame(SocialLinkPlatform::Other, $links[1]->platform);
        self::assertSame('lantern-club.example', $links[1]->host);
    }

    public function testNoLinks(): void
    {
        self::assertSame([], SocialLinks::fromInput('')->urls);
        self::assertSame([], SocialLinks::fromInput([])->links());
    }
}
