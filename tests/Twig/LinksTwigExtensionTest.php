<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\GenerateFacebookLink;
use SpeedPuzzling\Web\Services\GenerateInstagramLink;
use SpeedPuzzling\Web\Services\GenerateTwitchLink;
use SpeedPuzzling\Web\Twig\LinksTwigExtension;

final class LinksTwigExtensionTest extends TestCase
{
    private LinksTwigExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new LinksTwigExtension(
            new GenerateInstagramLink(),
            new GenerateFacebookLink(),
            new GenerateTwitchLink(),
        );
    }

    public function testLinkOpensExternallyWithIcon(): void
    {
        $html = (string) $this->extension->generateInstagramLink('speedpuzzler');

        self::assertStringContainsString('href="https://www.instagram.com/speedpuzzler/"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener nofollow"', $html);
        self::assertStringContainsString('bi-box-arrow-up-right', $html);
    }

    public function testApostropheInANameNoLongerEndsTheHref(): void
    {
        // A real production value
        $html = (string) $this->extension->generateInstagramLink("Samantha D'Alessandro");

        self::assertStringContainsString('D&apos;Alessandro', $html);
        self::assertStringNotContainsString("D'Alessandro", $html);
    }

    public function testPlayerInputCannotInjectMarkup(): void
    {
        $instagram = (string) $this->extension->generateInstagramLink('x" onmouseover="alert(1)');
        $facebook = (string) $this->extension->generateFacebookLink('https://facebook.com/"><script>alert(1)</script>');
        $twitch = (string) $this->extension->generateTwitchLink('<img src=x onerror=alert(1)>');

        foreach ([$instagram, $facebook, $twitch] as $html) {
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('" onmouseover', $html);
        }
    }

    public function testFacebookNameWithoutUrlStaysPlainText(): void
    {
        self::assertSame('Jan Mikeš', $this->extension->generateFacebookLink('Jan Mikeš'));
    }
}
