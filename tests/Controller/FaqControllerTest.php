<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FaqControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/faq');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/faq');

        $this->assertResponseIsSuccessful();
    }

    public function testSolveTimePlaceholdersAreSubstituted(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/faq');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        // The 1000-piece answer is fed live from the solve time distribution.
        // A leaked placeholder would be published to users and, worse, into the
        // schema.org FAQPage markup below.
        self::assertStringNotContainsString('%median_1000%', $content);
        self::assertStringNotContainsString('%fast_1000%', $content);
    }

    public function testFaqStructuredDataIsValidJson(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/faq');

        $this->assertResponseIsSuccessful();

        $jsonLdBlocks = $crawler->filter('script[type="application/ld+json"]');

        self::assertGreaterThan(0, $jsonLdBlocks->count());

        $faqPageFound = false;

        foreach ($jsonLdBlocks as $block) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $block->textContent, true, 512, JSON_THROW_ON_ERROR);

            if (($decoded['@type'] ?? null) === 'FAQPage') {
                $faqPageFound = true;

                self::assertIsArray($decoded['mainEntity']);
                self::assertNotEmpty($decoded['mainEntity']);
            }
        }

        self::assertTrue($faqPageFound, 'FAQ page must expose FAQPage structured data');
    }
}
