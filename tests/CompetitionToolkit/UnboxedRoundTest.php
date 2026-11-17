<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ChangeRoundUnboxed;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\Query\GetCompetitionPuzzles;
use SpeedPuzzling\Web\Query\GetEventOfferChoices;
use SpeedPuzzling\Web\Query\GetMarketplaceListings;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Results\VisibleRoundPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Unboxed" rounds (docs/features/competitions-management/world-series-rules.md §7 + W2-a): no box picture of any of the
 * round's puzzles - secret or not - on any surface of the event until the round's results are first published; names
 * follow the normal reveal. Every surface is read before (a canary: the pictures are there), while unboxed (none of
 * them), after the publish (all back) and after an unpublish (they stay).
 *
 * CompetitionApiFixture's future round: "API Hidden Entirely" (secret), "API Hidden Image" (image only), "API Visible"
 * (not secret) - read after the round's automatic reveal, when all three would show their pictures.
 */
final class UnboxedRoundTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use ReadsRoundAutomaticReveal;

    private const array PICTURES = [
        CompetitionApiFixture::IMAGE_VISIBLE,
        CompetitionApiFixture::IMAGE_HIDDEN_IMAGE,
        CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY,
    ];

    private const string ROUND = CompetitionApiFixture::ROUND_FUTURE;
    private const string COMPETITION = CompetitionApiFixture::COMPETITION_API;

    private KernelBrowser $browser;
    private Connection $database;
    private string $displayToken;
    private string $participantId;
    private string $sellerId;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);

        $this->database->executeStatement("UPDATE competition_round SET slug = 'future-round', puzzle_format = 'pick', puzzles_to_solve = 1 WHERE id = :id", ['id' => self::ROUND]);
        $this->database->executeStatement('UPDATE competition SET competitor_pages = true, tag_id = :tag WHERE id = :id', ['id' => self::COMPETITION, 'tag' => TagFixture::TAG_ONLINE]);
        $this->database->executeStatement('INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tag, :puzzle)', ['tag' => TagFixture::TAG_ONLINE, 'puzzle' => CompetitionApiFixture::PUZZLE_VISIBLE]);

        $this->participantId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source, registration_status) VALUES (:id, 'Una Boxed', 'cz', :competition, 'imported', 'registered')",
            ['id' => $this->participantId, 'competition' => self::COMPETITION],
        );
        $this->database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, table_number) VALUES (:id, :participant, :round, 7)',
            ['id' => Uuid::uuid7()->toString(), 'participant' => $this->participantId, 'round' => self::ROUND],
        );

        // A time linked to the event (the event page's "solved" list) and a seller's listing of the round's puzzle
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET puzzle_id = :puzzle, competition_id = :competition WHERE id = :id',
            ['puzzle' => CompetitionApiFixture::PUZZLE_VISIBLE, 'competition' => self::COMPETITION, 'id' => PuzzleSolvingTimeFixture::TIME_01],
        );
        $this->database->executeStatement(
            'UPDATE sell_swap_list_item SET puzzle_id = :puzzle, published_on_marketplace = true WHERE id = :id',
            ['puzzle' => CompetitionApiFixture::PUZZLE_VISIBLE, 'id' => SellSwapListItemFixture::SELLSWAP_01],
        );
        $sellerId = $this->database->fetchOne('SELECT player_id FROM sell_swap_list_item WHERE id = :id', ['id' => SellSwapListItemFixture::SELLSWAP_01]);
        assert(is_string($sellerId));
        $this->sellerId = $sellerId;

        // The marketplace at the event (?event=): an in-person event the seller goes to
        $this->database->executeStatement('UPDATE competition SET is_online = false WHERE id = :id', ['id' => self::COMPETITION]);
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source, registration_status, player_id) VALUES (:id, 'Sal Seller', 'cz', :competition, 'imported', 'registered', :player)",
            ['id' => Uuid::uuid7()->toString(), 'competition' => self::COMPETITION, 'player' => $sellerId],
        );

        $this->displayToken = self::createDisplay(self::COMPETITION, [['type' => 'puzzle', 'props' => ['show' => ['image', 'name', 'brand', 'pieces'], 'max' => 6]]], self::ROUND);

        // After the round's automatic reveal - every puzzle would show its picture now
        self::moveClockTo(self::automaticRevealOf(self::ROUND)->modify('+1 minute'));
    }

    public function testNoPictureAnywhereUntilTheResultsAreFirstPublished(): void
    {
        $this->assertSurfaces(pictures: true, when: 'not unboxed (canary)');

        $this->bus()->dispatch(new ChangeRoundUnboxed(self::COMPETITION, self::ROUND, true));
        $this->assertSurfaces(pictures: false, when: 'unboxed, results not published');

        $this->bus()->dispatch(new PublishRoundResults(self::COMPETITION, self::ROUND));
        $this->assertSurfaces(pictures: true, when: 'after the first publish');

        // Unpublishing never hides them again
        $this->bus()->dispatch(new UnpublishRoundResults(self::COMPETITION, self::ROUND));
        $this->assertSurfaces(pictures: true, when: 'after an unpublish');
    }

    public function testSwitchingItOffBeforeThePublishShowsThePicturesAgain(): void
    {
        $this->bus()->dispatch(new ChangeRoundUnboxed(self::COMPETITION, self::ROUND, true));
        $this->bus()->dispatch(new ChangeRoundUnboxed(self::COMPETITION, self::ROUND, false));

        $this->assertSurfaces(pictures: true, when: 'switched off again');
    }

    public function testTheOrganiserSwitchesItOnTheRoundPuzzlesPage(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/en/manage-round-puzzles/' . self::ROUND);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-round-unboxed="off"]');
        self::assertCount(1, $form);

        $this->browser->request('POST', '/en/round-unboxed/' . self::ROUND, [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'unboxed' => '1',
        ]);
        self::assertResponseRedirects('/en/manage-round-puzzles/' . self::ROUND, 303);
        self::assertTrue((bool) $this->database->fetchOne('SELECT unboxed FROM competition_round WHERE id = :id', ['id' => self::ROUND]));

        $crawler = $this->browser->request('GET', '/en/manage-round-puzzles/' . self::ROUND);
        self::assertCount(1, $crawler->filter('form[data-round-unboxed="on"]'));

        // The toolkit hub's round formats card says so
        $crawler = $this->browser->request('GET', '/en/manage-event-toolkit/' . self::COMPETITION);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-toolkit-round="%s"] [data-toolkit-round-unboxed]', self::ROUND)));

        // Without the token nothing changes
        $this->browser->request('POST', '/en/round-unboxed/' . self::ROUND, ['_token' => 'wrong', 'unboxed' => '0']);
        self::assertResponseRedirects('/en/manage-round-puzzles/' . self::ROUND, 303);
        self::assertTrue((bool) $this->database->fetchOne('SELECT unboxed FROM competition_round WHERE id = :id', ['id' => self::ROUND]));

        // Another player may not
        $this->browser->restart();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);
        $this->browser->request('POST', '/en/round-unboxed/' . self::ROUND, ['_token' => 'x', 'unboxed' => '0']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testADuplicateKeepsTheRoundUnboxed(): void
    {
        $this->bus()->dispatch(new ChangeRoundUnboxed(self::COMPETITION, self::ROUND, true));
        $copyId = Uuid::uuid7();
        $this->bus()->dispatch(new DuplicateCompetition(
            newCompetitionId: $copyId,
            sourceCompetitionId: self::COMPETITION,
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $unboxed = $this->database->fetchAllKeyValue('SELECT name, unboxed FROM competition_round WHERE competition_id = :id ORDER BY name', ['id' => $copyId->toString()]);
        self::assertSame(['Future Round' => true, 'Past Round' => false], array_map(boolval(...), $unboxed));
    }

    public function testARoundOfAnotherEventIsRefused(): void
    {
        $this->expectException(\Throwable::class);
        $this->bus()->dispatch(new ChangeRoundUnboxed(CompetitionApiFixture::COMPETITION_API_REJECTED, self::ROUND, true));
    }

    /**
     * Every surface of the §1.7 table + W2-a: the pictures are there (and the canary name) or none of them is - while the
     * names stay out as the normal reveal says.
     */
    private function assertSurfaces(bool $pictures, string $when): void
    {
        foreach ($this->surfaces() as $surface => $content) {
            if ($pictures) {
                self::assertStringContainsString(CompetitionApiFixture::IMAGE_VISIBLE, $content, "{$when}: {$surface}");
                continue;
            }

            foreach (self::PICTURES as $picture) {
                self::assertStringNotContainsString($picture, $content, "{$when}: {$surface} shows {$picture}");
            }
        }

        // The names follow the normal reveal - unboxed never hides one
        $visibleNames = array_map(
            static fn (VisibleRoundPuzzle $puzzle): string => $puzzle->puzzleName,
            self::getContainer()->get(RoundPuzzlesVisibleNow::class)->forRound(self::ROUND, self::now())->puzzles,
        );
        foreach (['API Hidden Entirely', 'API Hidden Image', 'API Visible'] as $name) {
            self::assertContains($name, $visibleNames, "{$when}: {$name}");
        }
    }

    /**
     * @return array<string, string>
     */
    private function surfaces(): array
    {
        $container = self::getContainer();
        $surfaces = [];

        // Toolkit surfaces (RoundPuzzlesVisibleNow)
        $surfaces['the one rule'] = (string) json_encode($container->get(RoundPuzzlesVisibleNow::class)->forRound(self::ROUND, self::now()));
        $surfaces['display state'] = $this->guest('/display/' . $this->displayToken . '/state');
        $surfaces['display page'] = $this->guest('/display/' . $this->displayToken);
        $surfaces['competitor page'] = $this->guest('/en/competitor/' . self::COMPETITION . '/p/' . $this->participantId);
        // The state answer says whether a picture shows (imageHidden) - the page takes it from there
        $surfaces['competitor state'] = self::picturesShownByFlag($this->guest('/competitor-state/' . self::COMPETITION . '/p/' . $this->participantId));
        $container->get('analytics_studio_cache')->clear();
        $surfaces['analytics studio state'] = $this->guest('/analytics-studio/' . self::ROUND . '/state');
        $surfaces['event page'] = $this->guest('/en/events/api-reveal-test-competition');

        // The public API (GetEditionRounds)
        $token = OAuth2TestHelper::createAccessToken($this->browser, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, PlayerFixture::PLAYER_REGULAR, ['profile:read']);
        $this->browser->restart();
        $this->browser->request('GET', '/api/v1/competitions/' . self::COMPETITION, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful('API');
        $surfaces['public API'] = (string) $this->browser->getResponse()->getContent();

        // The judge's chips of a pick round (an organiser - the public rule holds for them too)
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', '/en/official-results/rounds/' . self::ROUND);
        self::assertResponseIsSuccessful('judge state');
        $surfaces['judge state'] = (string) $this->browser->getResponse()->getContent();
        $this->browser->restart();

        // W2-a: the surfaces on the SQL rule (RoundPuzzleReveal::sqlImageHidden())
        $surfaces['event page puzzles (tag)'] = (string) json_encode($container->get(GetPuzzleOverview::class)->byTagId(TagFixture::TAG_ONLINE));
        $surfaces['competition puzzles (WJPC hub)'] = (string) json_encode($container->get(GetCompetitionPuzzles::class)->forCompetitions([self::COMPETITION]));
        $surfaces['solved puzzles of the event'] = (string) json_encode($container->get(GetCompetitionPuzzles::class)->solvedPuzzleOverviews(self::COMPETITION, 10));
        $surfaces['marketplace at the event'] = (string) json_encode($container->get(GetMarketplaceListings::class)->search(event: self::COMPETITION));
        $surfaces['marketplace picker'] = (string) json_encode($container->get(GetEventOfferChoices::class)->forPicker($this->sellerId, self::COMPETITION));

        return $surfaces;
    }

    /**
     * The fixture picture of every puzzle the competitor state shows with "imageHidden": false
     */
    private static function picturesShownByFlag(string $json): string
    {
        $pictures = [
            CompetitionApiFixture::PUZZLE_VISIBLE => CompetitionApiFixture::IMAGE_VISIBLE,
            CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE => CompetitionApiFixture::IMAGE_HIDDEN_IMAGE,
            CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY => CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY,
        ];
        $shown = [];
        preg_match_all('/"puzzleId":"([0-9a-f-]{36})"[^}]*"imageHidden":false/', $json, $matches);

        foreach ($matches[1] as $puzzleId) {
            $shown[] = $pictures[$puzzleId] ?? $puzzleId;
        }

        return implode(' ', $shown);
    }

    private function guest(string $url): string
    {
        $this->browser->restart();
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return html_entity_decode((string) $this->browser->getResponse()->getContent(), ENT_QUOTES | ENT_HTML5);
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $bus;
    }

    private static function now(): \DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now();
    }

    private static function moveClockTo(\DateTimeImmutable $moment): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        $clock->sleep($moment->getTimestamp() - $clock->now()->getTimestamp());
    }
}
