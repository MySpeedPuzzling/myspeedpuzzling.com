<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\PlayerSolvedPuzzles;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DifficultyTier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Difficulty on the profile results: the tier on every thumbnail and the difficulty filter, both members only -
 * the viewer's membership decides, never the profile owner's. Asserted on the rendered answer: membership is
 * known only inside the Live request.
 */
final class PlayerSolvedPuzzlesDifficultyTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testMemberSeesTheTierOnEveryThumbnail(): void
    {
        $crawler = $this->results($this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE))->render()->crawler();

        $rows = $this->rows($crawler);
        self::assertGreaterThan(0, $rows->count());
        self::assertCount($rows->count(), $crawler->filter('.ps-table [data-testid="difficulty-corner"]'));
        self::assertNotSame([$this->unknown()], array_values(array_unique($this->cornerNames($crawler))), 'Some of the player\'s puzzles are rated');

        self::assertGreaterThan(0, $crawler->filter('input[data-model="difficulty[]"]')->count());
        self::assertCount(0, $crawler->filter('input[data-model="difficulty[]"][disabled]'));
    }

    public function testMemberNarrowsTheResultsToTiers(): void
    {
        $component = $this->results($this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE));
        $crawler = $component->render()->crawler();
        $allRows = $this->rows($crawler)->count();

        $rated = array_values(array_diff($this->cornerNames($crawler), [$this->unknown()]));
        self::assertNotSame([], $rated);
        $tier = $this->tierNamed($rated[0]);

        $component->set('difficulty', [(string) $tier->value]);
        $crawler = $component->render()->crawler();

        self::assertGreaterThan(0, $this->rows($crawler)->count());
        self::assertSame([$rated[0]], array_values(array_unique($this->cornerNames($crawler))));
        self::assertSame('1', $crawler->filter('#filtersDropdown sup')->text());
        self::assertCount(1, $crawler->filter('input[data-model="difficulty[]"][value="' . $tier->value . '"][checked]'));

        // "0" = not rated yet
        $component->set('difficulty', ['0']);
        $names = $this->cornerNames($component->render()->crawler());
        self::assertSame([], array_values(array_diff($names, [$this->unknown()])));

        $component->call('resetFilters');
        $crawler = $component->render()->crawler();
        self::assertCount($allRows, $this->rows($crawler));
        self::assertCount(0, $crawler->filter('#filtersDropdown sup'));
        self::assertSame([], $this->component($component)->difficulty);
    }

    public function testMemberIsOfferedTheTiersThePlayerHasAndTheSelectedOne(): void
    {
        $component = $this->results($this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE));
        $crawler = $component->render()->crawler();

        $offered = $this->offered($crawler);
        $shown = array_map(fn (string $name): string => $this->valueNamed($name), $this->cornerNames($crawler));
        self::assertSame([], array_values(array_diff($shown, $offered)), 'Every tier on a thumbnail has its chip');

        $missing = array_values(array_diff(['1', '2', '3', '4', '5', '6', '0'], $offered));
        self::assertNotSame([], $missing, 'The fixture player does not have a result in every tier');

        // A tier the player has nothing in still shows while it is selected
        $component->set('difficulty', [$missing[0]]);
        $crawler = $component->render()->crawler();
        self::assertContains($missing[0], $this->offered($crawler));
        self::assertCount(0, $this->rows($crawler)->filter('[data-testid="difficulty-corner"]'));
    }

    public function testUnknownValuesAreDropped(): void
    {
        $component = $this->results($this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE));

        $component->set('difficulty', ['9', 'x', '3', '3', '0', '-1', '12']);

        self::assertSame(['0', '3'], $this->component($component)->difficulty);
    }

    public function testWithoutMembershipThereAreNoTiersAndTheFilterIsIgnored(): void
    {
        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            $component = $this->results($viewer === null ? self::createClient() : $this->signedIn($viewer));
            $crawler = $component->render()->crawler();
            $allRows = $this->rows($crawler)->count();

            self::assertGreaterThan(0, $allRows);
            self::assertCount(0, $crawler->filter('[data-testid="difficulty-corner"]'));
            self::assertCount(7, $crawler->filter('input[data-model="difficulty[]"][disabled]'));

            $component->set('difficulty', ['6']);
            $crawler = $component->render()->crawler();
            self::assertCount($allRows, $this->rows($crawler));
            self::assertCount(0, $crawler->filter('#filtersDropdown sup'));

            self::ensureKernelShutdown();
        }
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        TestingLogin::asPlayer($client, $playerId);

        return $client;
    }

    private function results(KernelBrowser $client): TestLiveComponent
    {
        $component = $this->createLiveComponent('PlayerSolvedPuzzles', ['playerId' => PlayerFixture::PLAYER_REGULAR], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    private function component(TestLiveComponent $component): PlayerSolvedPuzzles
    {
        $results = $component->component();
        self::assertInstanceOf(PlayerSolvedPuzzles::class, $results);

        return $results;
    }

    private function rows(Crawler $crawler): Crawler
    {
        return $crawler->filter('.ps-table tbody tr')->reduce(static fn (Crawler $row): bool => $row->filter('td.ps-image')->count() > 0);
    }

    /**
     * @return list<string> tier name on each row's thumbnail
     */
    private function cornerNames(Crawler $crawler): array
    {
        return $this->rows($crawler)->filter('[data-testid="difficulty-corner"]')->each(static fn (Crawler $corner): string => (string) $corner->attr('title'));
    }

    /**
     * @return list<string>
     */
    private function offered(Crawler $crawler): array
    {
        return $crawler->filter('input[data-model="difficulty[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
    }

    private function unknown(): string
    {
        return $this->translator()->trans('puzzle_intelligence.difficulty.tiers.unknown', locale: 'en');
    }

    private function tierNamed(string $name): DifficultyTier
    {
        foreach (DifficultyTier::cases() as $tier) {
            if ($this->translator()->trans($tier->translationKey(), locale: 'en') === $name) {
                return $tier;
            }
        }

        self::fail('No tier is called ' . $name);
    }

    private function valueNamed(string $name): string
    {
        return $name === $this->unknown() ? '0' : (string) $this->tierNamed($name)->value;
    }

    private function translator(): TranslatorInterface
    {
        return self::getContainer()->get(TranslatorInterface::class);
    }
}
