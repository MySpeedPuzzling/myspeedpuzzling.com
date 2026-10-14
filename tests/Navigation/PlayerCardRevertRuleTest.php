<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The player card's revert rule (docs/features/navigation README §4.8 "Measurement and revert rule", CARD-07): one
 * switch per context kind × input in the registry (NavigationRegistry::PLAYER_CARD_DIRECT, all off at launch), the rule
 * that may turn one on on the registry's `player_card` entry, the switched cells on <body data-player-card-direct> for
 * every viewer alike - read by shouldOpen() (tests/PlayerCardScriptTest.php: a reverted cell navigates).
 */
final class PlayerCardRevertRuleTest extends WebTestCase
{
    public function testEveryContextKindAndInputHasItsSwitchAllOffAtLaunch(): void
    {
        $switches = self::switches();
        self::assertSame(NavClickController::cardContexts(), array_keys($switches));

        foreach ($switches as $kind => $inputs) {
            self::assertSame(NavClickController::CARD_INPUTS, array_keys($inputs), $kind);

            foreach ($inputs as $input => $direct) {
                self::assertFalse($direct, "{$kind} × {$input} reverted - only when the rule held for it, with its numbers in the commit");
            }
        }

        self::assertSame('', NavigationRegistry::playerCardDirect());
    }

    public function testTheSwitchedCellsAreTheBodysPairs(): void
    {
        $flags = self::switches();
        $flags['puzzle']['touch'] = true;
        $flags['leaderboard']['keyboard'] = true;

        self::assertSame('puzzle:touch leaderboard:keyboard', NavigationRegistry::playerCardDirect($flags));
    }

    public function testTheRuleIsOnThePlayerCardsEntry(): void
    {
        $entry = self::getContainer()->get(NavigationRegistry::class)->byId('player_card');
        self::assertNotNull($entry);
        self::assertStringContainsString(NavigationRegistry::PLAYER_CARD_REVERT_RULE, $entry->reason);

        foreach (['300 card opens', '14 days', '70 %', 'within 30 s', 'under 5 %'] as $part) {
            self::assertStringContainsString($part, NavigationRegistry::PLAYER_CARD_REVERT_RULE);
        }
    }

    /**
     * The registry's switches as a table of any shape - the test reads them, it does not know them
     *
     * @return array<string, array<string, bool>>
     */
    private static function switches(): array
    {
        return NavigationRegistry::PLAYER_CARD_DIRECT;
    }

    /**
     * Nothing reverted: no attribute (no byte on any page); the card's URL template is there for every viewer
     */
    public function testNoCellRevertedNoAttribute(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/faq');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('body[data-player-card-url]'));
        self::assertCount(0, $crawler->filter('body[data-player-card-direct]'));
        self::assertStringContainsString("getAttribute('data-player-card-direct')", (string) file_get_contents(__DIR__ . '/../../assets/player_card.js'));
    }
}
