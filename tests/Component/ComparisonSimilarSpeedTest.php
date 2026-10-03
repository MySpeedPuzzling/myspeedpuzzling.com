<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Component\ComparisonSimilarSpeed;
use SpeedPuzzling\Web\Query\FindSimilarSpeedPuzzler;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * "Someone at your speed" in the add sheet of the compare page (docs/features/player-comparison.md D8).
 * PLAYER_WITH_STRIPE is a member, PLAYER_REGULAR is not.
 */
final class ComparisonSimilarSpeedTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use ComparisonSeeding;

    private KernelBrowser $client;

    private string $candidate = '';

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testFreeAccountsCannotRoll(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_REGULAR);
        $component = $this->mount();

        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('[data-testid="comparison-similar-locked"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-similar-roll"]'));

        // Calling the action directly does not help either
        $component->call('roll');
        $crawler = $component->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-testid="comparison-similar-candidate"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-similar-locked"]'));
        self::assertNull($this->state($component)->seed);
    }

    public function testMembersRollSomebodyAtTheirSpeed(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSimilarPlayer();
        $component = $this->mount();

        // Idle: the dice, nothing drawn yet
        $crawler = $component->render()->crawler();
        self::assertSame('Someone at your speed', trim($crawler->filter('.cmp-similar-title')->text()));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-similar-roll"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-similar-candidate"]'));

        $component->call('roll');
        self::assertNotNull($this->state($component)->seed);

        $crawler = $component->render()->crawler();
        $card = $crawler->filter('[data-testid="comparison-similar-candidate"]');
        self::assertCount(1, $card);
        self::assertSame('Mia Match', trim($card->filter('.cmp-similar-name')->text()));
        self::assertStringContainsString('#MIA', $card->filter('.cmp-similar-meta')->text());
        self::assertSame('Top 38% at 500 pieces, you are top 40%', trim($card->filter('[data-testid="comparison-similar-skill"]')->text()));
        self::assertSame('6 puzzles you both solved', trim($card->filter('[data-testid="comparison-similar-shared"]')->text()));
        self::assertSame('Solved 2 puzzles in the last 30 days', trim($card->filter('[data-testid="comparison-similar-recent"]')->text()));
        self::assertStringContainsString('Proficient', $card->filter('.player-chip-tier')->text());
        self::assertCount(1, $crawler->filter('[data-testid="comparison-similar-add"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-similar-roll-again"]'));
        self::assertStringContainsString($this->candidate, (string) $crawler->filter('a.cmp-similar-profile')->attr('href'));
        self::assertStringContainsString('Picked from players with public profiles who take part in rankings.', $crawler->text());
    }

    public function testTheLineUpIsNeverSuggested(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSimilarPlayer();
        $component = $this->mount([$this->candidate]);

        // Every roll is a new seed; the fixtures' players may come up through the baseline fallback, Mia never
        for ($roll = 0; $roll < 5; $roll++) {
            $component->call('roll');
            $crawler = $component->render()->crawler();

            self::assertStringNotContainsString('Mia Match', $crawler->text());
        }
    }

    public function testNobodyAtYourSpeed(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSimilarPlayer();

        // A line-up already holding everybody the query could ever suggest
        $query = self::getContainer()->get(FindSimilarSpeedPuzzler::class);
        $everybody = [];

        while (($found = $query->find(PlayerFixture::PLAYER_WITH_STRIPE, $everybody, 'seed')) !== null && count($everybody) < 100) {
            $everybody[] = $found->playerId;
        }

        self::assertContains($this->candidate, $everybody);

        $component = $this->mount($everybody);
        $component->call('roll');
        $crawler = $component->render()->crawler();

        self::assertCount(0, $crawler->filter('[data-testid="comparison-similar-candidate"]'));
        self::assertStringContainsString('Nobody new at your speed right now', $crawler->filter('[data-testid="comparison-similar-nobody"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="comparison-similar-add"]'));
    }

    public function testAddingEmitsTheSubjectUpToThePage(): void
    {
        TestingLogin::asPlayer($this->client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSimilarPlayer();
        $component = $this->mount();

        $component->call('roll');
        $component->call('add');
        $rendered = $component->render();

        $event = $component->getEmittedEvent($rendered, ComparisonSimilarSpeed::EVENT_ADD_SUBJECT);
        self::assertNotNull($event);
        self::assertSame(['ref' => 'p-' . $this->candidate], $event['data']);

        // Back to the dice, with a confirmation; the added player is never drawn again
        $crawler = $rendered->crawler();
        self::assertSame('Mia Match is in your line-up now.', trim($crawler->filter('[data-testid="comparison-similar-added"]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-similar-roll"]'));
        $state = $this->state($component);
        self::assertNull($state->seed);
        self::assertContains($this->candidate, $state->excludedPlayerIds);
    }

    /**
     * @param list<string> $excludedPlayerIds
     */
    private function mount(array $excludedPlayerIds = []): TestLiveComponent
    {
        $component = $this->createLiveComponent('ComparisonSimilarSpeed', ['excludedPlayerIds' => $excludedPlayerIds], $this->client);
        $component->setRouteLocale('en');

        return $component;
    }

    private function state(TestLiveComponent $component): ComparisonSimilarSpeed
    {
        $state = $component->component();
        assert($state instanceof ComparisonSimilarSpeed);

        return $state;
    }

    /**
     * The viewer at the 60th percentile; Mia at 62.5 shares six of the viewer's puzzles, two of them solved last week.
     */
    private function seedSimilarPlayer(): void
    {
        $now = self::getContainer()->get(ClockInterface::class)->now();

        $this->seedSkill(PlayerFixture::PLAYER_WITH_STRIPE, 60.0, SkillTier::Proficient->value);
        $this->candidate = $this->seedPlayer('Mia Match', code: 'mia');
        $this->seedSkill($this->candidate, 62.5, SkillTier::Proficient->value);

        for ($i = 0; $i < 6; $i++) {
            $puzzle = $this->seedPuzzle(500);
            $this->seedTime(PlayerFixture::PLAYER_WITH_STRIPE, $puzzle, 2000, $now->modify('-40 days'));
            $this->seedTime($this->candidate, $puzzle, 2100, $now->modify($i < 2 ? '-5 days' : '-50 days'));
        }
    }
}
