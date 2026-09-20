<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Solo / Pair / Team picker of the add/edit time form (docs/features/pairs-and-teams/README.md) -
 * what the server renders and accepts. How it behaves in a browser is tests/Panther/CoPuzzlerPicker.
 */
final class CoPuzzlerPickerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testWhileTheFlagIsOffOnlyAdminsGetThePicker(): void
    {
        $this->overrideFeatureFlagEnv('PAIRS_TEAMS_PICKER_PUBLIC', false);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-controller="copuzzler-picker"]');
        $this->assertSelectorExists('#puzzler-input-template');

        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller="copuzzler-picker"]');
        $this->assertSelectorNotExists('#puzzler-input-template');
    }

    public function testFreshFormIsSoloWithNothingSelected(): void
    {
        $crawler = $this->requestAddForm(self::pickerClient(PlayerFixture::PLAYER_WITH_STRIPE));

        self::assertSame('true', $crawler->filter('.copuzzler-switch [data-mode="solo"]')->attr('aria-checked'));
        self::assertSame('false', $crawler->filter('.copuzzler-switch [data-mode="pair"]')->attr('aria-checked'));
        self::assertSame('false', $crawler->filter('.copuzzler-switch [data-mode="team"]')->attr('aria-checked'));
        self::assertCount(3, $crawler->filter('.copuzzler-switch [role="radio"]'));
        self::assertCount(0, $crawler->filter('input[name="group_players[]"]'));
        self::assertNotNull($crawler->filter('.copuzzler-card')->attr('hidden'));

        // Nothing in the picker may submit the form it lives in
        $buttonTypes = $crawler->filter('.copuzzler-picker button')->each(static fn(Crawler $button): null|string => $button->attr('type'));
        self::assertNotSame([], $buttonTypes);
        self::assertSame(['button'], array_values(array_unique($buttonTypes)));
    }

    public function testSoloFormCostsNoQueryForThePicker(): void
    {
        $this->overrideFeatureFlagEnv('PAIRS_TEAMS_PICKER_PUBLIC', false);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        // The first request of a visit also stores the player's locale - count the second one
        $browser->request('GET', '/en/puzzle-add');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();
        $withOldRows = $this->queryCount($browser);

        self::ensureKernelShutdown();
        $browser = self::pickerClient(PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/puzzle-add');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        // The old rows listed the player's favorites on every render; the picker asks for its
        // suggestions only once somebody says they did not puzzle alone
        self::assertSame($withOldRows - 1, $this->queryCount($browser));
    }

    public function testSubmittedGroupAndTeamNameComeBackAfterAValidationError(): void
    {
        $browser = self::pickerClient(PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->requestAddForm($browser);

        // No puzzle chosen: the form is refused, the group must survive the round trip
        $crawler = $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->formData($crawler, puzzle: null),
            'group_players' => ['#ADMIN', 'Babička Marie'],
            'team_name' => 'Thursday club',
        ]);

        $this->assertResponseStatusCodeSame(422);

        self::assertSame(
            ['#ADMIN', 'Babička Marie'],
            $crawler->filter('input[name="group_players[]"]')->each(static fn(Crawler $input): null|string => $input->attr('value')),
        );
        self::assertSame('Thursday club', $crawler->filter('input[name="team_name"]')->attr('value'));
        self::assertSame('true', $crawler->filter('.copuzzler-switch [data-mode="team"]')->attr('aria-checked'));
        self::assertNull($crawler->filter('.copuzzler-card')->attr('hidden'));

        $initial = $this->initialState($crawler);
        self::assertSame(['Admin User', 'Babička Marie'], array_column($initial['chips'], 'label'));
        self::assertSame([false, true], array_column($initial['chips'], 'guest'));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $initial['chips'][0]['key']);
        self::assertNull($initial['tracker']);
    }

    public function testTeamNameIsSavedWithTheTime(): void
    {
        $browser = self::pickerClient(PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->requestAddForm($browser);

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->formData($crawler, puzzle: PuzzleFixture::PUZZLE_1500_01),
            'group_players' => ['#ADMIN', 'Babička Marie'],
            'team_name' => '  Thursday   club ',
        ]);

        $this->assertResponseRedirects();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        /** @var array{name: null|string, size: int, named_by_id: null|string}|false $team */
        $team = $database->fetchAssociative(
            <<<SQL
SELECT team.name, team.size, team.named_by_id
FROM puzzle_solving_time time
INNER JOIN puzzling_team team ON team.id = time.puzzling_team_id
WHERE time.player_id = :playerId AND time.puzzle_id = :puzzleId AND time.puzzlers_count = 3
SQL,
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzleId' => PuzzleFixture::PUZZLE_1500_01],
        );

        self::assertNotFalse($team);
        self::assertSame('Thursday club', $team['name']);
        self::assertSame(3, $team['size']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $team['named_by_id']);
    }

    public function testEditFormStartsInTheModeOfTheStoredGroup(): void
    {
        // TIME_12: tracked by PLAYER_REGULAR, with PLAYER_PRIVATE
        $browser = self::pickerClient(PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_12);

        $this->assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('.copuzzler-switch [data-mode="pair"]')->attr('aria-checked'));

        $initial = $this->initialState($crawler);
        self::assertCount(1, $initial['chips']);
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $initial['chips'][0]['key']);
        self::assertNull($initial['tracker'], 'Editing your own time: you are implied, not a chip');
        self::assertNull($initial['viewerKey']);
    }

    public function testMemberEditingSomebodyElsesTimeSeesTheTrackerLocked(): void
    {
        $browser = self::pickerClient(PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_12);

        $this->assertResponseIsSuccessful();

        $initial = $this->initialState($crawler);
        self::assertNotNull($initial['tracker']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $initial['tracker']['key']);
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $initial['viewerKey']);
        // The editor is an ordinary chip: they may remove themselves
        self::assertSame([PlayerFixture::PLAYER_PRIVATE], array_column($initial['chips'], 'key'));
    }

    public function testSuggestionsNeedASignedInPlayer(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/my-co-puzzlers.json');

        $this->assertResponseRedirects();
    }

    public function testSuggestionsListPairsAndPeople(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        // The first request of a visit also stores the player's locale - count the second one
        $browser->request('GET', '/en/my-co-puzzlers.json');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/my-co-puzzlers.json');

        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 4, 'Co-puzzler suggestions: account + profile (every signed-in request) + teams with members + favorites');
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        /** @var array{teams: list<array<string, mixed>>, people: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['teams']);
        self::assertSame(2, $payload['teams'][0]['size']);
        self::assertSame(2, $payload['teams'][0]['count']);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], $payload['teams'][0]['members']);

        $regular = array_values(array_filter($payload['people'], static fn(array $person): bool => $person['key'] === PlayerFixture::PLAYER_REGULAR));
        self::assertCount(1, $regular);
        self::assertSame('#PLAYER1', $regular[0]['value']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $regular[0]['label']);
        self::assertSame(2, $regular[0]['pairCount']);
    }

    public function testPairWithABlockedPlayerIsNotSuggested(): void
    {
        // PLAYER_REGULAR has blocked PLAYER_PRIVATE - their two pair times stay in the history, the
        // picker just stops bringing them up
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/my-co-puzzlers.json');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $content);
        self::assertStringNotContainsString('PLAYER2', $content);
    }

    public function testPlayerSearchAnswersInThePickersShape(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/player-search-autocomplete/?format=co-puzzler&query=admin');

        $this->assertResponseIsSuccessful();

        /** @var list<array<string, mixed>> $people */
        $people = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNotSame([], $people);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $people[0]['key']);
        self::assertSame('#ADMIN', $people[0]['value']);
        self::assertSame('Admin User', $people[0]['label']);
        self::assertFalse($people[0]['guest']);
    }

    private function pickerClient(string $playerId): KernelBrowser
    {
        $this->overrideFeatureFlagEnv('PAIRS_TEAMS_PICKER_PUBLIC', true);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function requestAddForm(KernelBrowser $browser): Crawler
    {
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller="copuzzler-picker"]');

        return $crawler;
    }

    /**
     * @return array<string, string>
     */
    private function formData(Crawler $crawler, null|string $puzzle): array
    {
        $csrfToken = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $formData = [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'timeHours' => '5',
            'timeMinutes' => '7',
            'timeSeconds' => '0',
            'finishedAt' => '12.07.2026',
            'collection' => '__system_collection__',
        ];

        if ($puzzle !== null) {
            $formData['puzzle'] = $puzzle;
        }

        return $formData;
    }

    /**
     * @return array{chips: list<array<string, mixed>>, tracker: null|array<string, mixed>, viewerKey: null|string}
     */
    private function initialState(Crawler $crawler): array
    {
        /** @var array{chips: list<array<string, mixed>>, tracker: null|array<string, mixed>, viewerKey: null|string} $state */
        $state = json_decode($crawler->filter('[data-copuzzler-picker-target="initial"]')->text(null, false), true, flags: JSON_THROW_ON_ERROR);

        return $state;
    }
}
