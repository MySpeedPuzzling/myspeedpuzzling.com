<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Collections;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddPuzzleToCollectionControllerTest extends WebTestCase
{
    private const string TURBO_STREAM_ACCEPT = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    public function testRecapContextAnswersWithStreamReplacingTheCta(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request(
            'POST',
            '/en/collections/' . PuzzleIntelligenceFixture::INTEL_PUZZLE_A . '/add',
            [
                'context' => 'recap',
                'collection_puzzle_action_form' => [
                    'collection' => Collection::SYSTEM_ID,
                    'collectionVisibility' => 'private',
                ],
            ],
            server: ['HTTP_ACCEPT' => self::TURBO_STREAM_ACCEPT],
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('target="recap-collection-cta"', $content);
        self::assertStringContainsString('In your collection', $content);
        self::assertStringContainsString('target="toast-container"', $content);
        // Fragments of the puzzle detail page do not exist on the recap page
        self::assertStringNotContainsString('puzzle-badges-', $content);
    }

    public function testNonMemberCreatingCollectionGetsWarningToastInsteadOfSilentRedirect(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request(
            'POST',
            '/en/collections/' . PuzzleIntelligenceFixture::INTEL_PUZZLE_A . '/add',
            [
                'context' => 'recap',
                'collection_puzzle_action_form' => [
                    'collection' => 'Brand new collection',
                    'collectionVisibility' => 'private',
                ],
            ],
            server: ['HTTP_ACCEPT' => self::TURBO_STREAM_ACCEPT],
        );

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('text-bg-warning', $content);
        self::assertStringNotContainsString('target="recap-collection-cta"', $content);
    }
}
