<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEditable;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/competitions-management/README.md "Duplicating an event": a draft with the organiser's details, rounds
 * and page content - none of what people did (participants, entries, teams, results), no round puzzles; a fresh slug;
 * approved at once only under an approved organization by its team; an edition's copy stays in its series.
 */
final class DuplicateCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAOneTimeEventIsCopiedWithItsRoundsButNothingPeopleDid(): void
    {
        $source = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $this->database->executeStatement(
            "UPDATE competition SET description = 'Bring a pen.', results_link = 'https://example.com/results', registration_link = 'https://example.com/register' WHERE id = :id",
            ['id' => $source],
        );
        $this->database->executeStatement("UPDATE competition_round SET results_link = 'https://example.com/round' WHERE competition_id = :id", ['id' => $source]);

        $copy = $this->duplicate($source, PlayerFixture::PLAYER_WITH_STRIPE);

        $row = $this->competition($copy);
        self::assertSame('Results Cup', $row['name']);
        self::assertTrue($row['is_draft']);
        self::assertNotSame('results-cup', $row['slug']);
        self::assertStringStartsWith('results-cup-', (string) $row['slug']);
        self::assertSame('Bring a pen.', $row['description']);
        self::assertSame('https://example.com/register', $row['registration_link']);
        self::assertNull($row['results_link']);
        self::assertSame('Brno', $row['location']);
        self::assertSame('cz', $row['location_country_code']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $row['added_by_player_id']);
        // No organization: waits for approval like a new event, and nobody was told - it is a draft
        self::assertNull($row['approved_at']);
        self::assertNull($row['submitted_at']);
        self::assertNull($row['series_id']);

        self::assertSame($this->rounds($source), $this->rounds($copy));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id AND results_link IS NOT NULL', $copy));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id AND results_published_at IS NOT NULL', $copy));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = :id', $copy));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id', $copy));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_participant_round cpr JOIN competition_round cr ON cr.id = cpr.round_id WHERE cr.competition_id = :id', $copy));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_team ct JOIN competition_round cr ON cr.id = ct.round_id WHERE cr.competition_id = :id', $copy));

        // The source keeps everything
        self::assertSame(4, $this->countRows('SELECT COUNT(*) FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = :id', $source));
        self::assertSame(9, $this->countRows('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id', $source));
    }

    public function testEveryCopyGetsItsOwnSlug(): void
    {
        $first = $this->duplicate(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_WITH_STRIPE);
        $second = $this->duplicate(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_WITH_STRIPE);

        $slugs = [$this->competition($first)['slug'], $this->competition($second)['slug'], 'results-cup'];
        self::assertSame($slugs, array_values(array_unique($slugs)));
    }

    public function testSomebodyElsesCopyKeepsTheCreatorOnTheTeam(): void
    {
        $copy = $this->duplicate(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_ADMIN);

        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->competition($copy)['added_by_player_id']);
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_STRIPE],
            $this->database->fetchFirstColumn('SELECT player_id FROM competition_maintainer WHERE competition_id = :id', ['id' => $copy]),
        );
    }

    public function testAnEditionsCopyIsANewDraftEditionOfTheSameSeries(): void
    {
        // A maintainer of the organization the series is under
        $copy = $this->duplicate(OrganizationFixture::EDITION_LANTERN_1, PlayerFixture::PLAYER_WITH_FAVORITES);
        $source = $this->competition(OrganizationFixture::EDITION_LANTERN_1);

        $row = $this->competition($copy);
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $row['series_id']);
        self::assertTrue($row['is_draft']);
        self::assertSame($source['name'], $row['name']);
        self::assertNotSame($source['slug'], $row['slug']);
        self::assertSame($source['date_from'], $row['date_from']);
        // An edition's approval is its series'
        self::assertNull($row['approved_at']);
        self::assertNull($row['organization_id']);
    }

    public function testACopyUnderAnApprovedOrganizationByItsTeamIsApprovedAtOnce(): void
    {
        $copy = $this->duplicate(OrganizationFixture::COMPETITION_RIVERBEND_OPEN, PlayerFixture::PLAYER_WITH_FAVORITES);

        $row = $this->competition($copy);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $row['organization_id']);
        self::assertNotNull($row['approved_at']);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $row['approved_by_player_id']);
        self::assertTrue($row['is_draft']);
        self::assertSame('Residents of Riverbend Valley', $row['eligibility']);
    }

    public function testSomebodyWhoCannotEditTheEventGetsNoCopy(): void
    {
        $before = $this->countRows('SELECT COUNT(*) FROM competition WHERE name = :id', 'Results Cup');

        try {
            $this->duplicate(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_REGULAR);
            self::fail('Only whoever may edit the event copies it');
        } catch (CompetitionNotEditable) {
        }

        self::assertSame($before, $this->countRows('SELECT COUNT(*) FROM competition WHERE name = :id', 'Results Cup'));
    }

    public function testANewFirstDayMovesTheRoundsAndTheLastDay(): void
    {
        $source = $this->competition(OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        $sourceFrom = new DateTimeImmutable((string) $source['date_from']);

        $copy = $this->duplicate(
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            PlayerFixture::PLAYER_WITH_STRIPE,
            name: 'Results Cup Autumn',
            dateFrom: $sourceFrom->modify('+7 days'),
            location: 'Ostrava',
        );

        $row = $this->competition($copy);
        self::assertSame('Results Cup Autumn', $row['name']);
        self::assertStringStartsWith('results-cup-autumn', (string) $row['slug']);
        self::assertSame('Ostrava', $row['location']);
        self::assertSame($sourceFrom->modify('+7 days')->format('Y-m-d'), (new DateTimeImmutable((string) $row['date_to']))->format('Y-m-d'));

        $sourceStarts = $this->database->fetchFirstColumn('SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        $copyStarts = $this->database->fetchFirstColumn('SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => $copy]);
        self::assertCount(5, $copyStarts);

        foreach ($sourceStarts as $index => $start) {
            self::assertSame(
                (new DateTimeImmutable((string) $start))->modify('+7 days')->format('Y-m-d H:i'),
                (new DateTimeImmutable((string) $copyStarts[$index]))->format('Y-m-d H:i'),
            );
        }
    }

    public function testPageSectionsAreCopiedWithTheirOwnPictures(): void
    {
        $source = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $filesystem = self::getContainer()->get(Filesystem::class);
        $picture = 'competition-pages/' . $source . '/hall.jpg';
        $filesystem->write($picture, 'jpeg bytes');

        $this->messageBus->dispatch(new AddPageSection(Uuid::uuid7(), $source, null, PageSectionType::Faq, 'Questions', [
            'items' => [['question' => 'Parking?', 'answer' => 'Behind the hall.']],
        ]));
        $this->messageBus->dispatch(new AddPageSection(Uuid::uuid7(), $source, null, PageSectionType::Gallery, 'Photos', [
            'images' => [
                ['path' => $picture, 'caption' => 'The hall'],
                // Gone from the storage - left out of the copy
                ['path' => 'competition-pages/' . $source . '/missing.jpg', 'caption' => 'Lost'],
            ],
        ]));

        $copy = $this->duplicate($source, PlayerFixture::PLAYER_WITH_STRIPE);

        /** @var list<array{type: string, title: string, content: string}> $sections */
        $sections = $this->database->fetchAllAssociative('SELECT type, title, content FROM competition_page_section WHERE competition_id = :id ORDER BY position', ['id' => $copy]);
        self::assertSame(['faq', 'gallery'], array_column($sections, 'type'));
        self::assertEquals(['items' => [['question' => 'Parking?', 'answer' => 'Behind the hall.']]], json_decode($sections[0]['content'], true));

        $copiedPicture = 'competition-pages/' . $copy . '/hall.jpg';
        self::assertEquals(['images' => [['path' => $copiedPicture, 'caption' => 'The hall']]], json_decode($sections[1]['content'], true));
        self::assertSame('jpeg bytes', $filesystem->read($copiedPicture));
        self::assertTrue($filesystem->fileExists($picture));
    }

    private function duplicate(
        string $sourceId,
        string $playerId,
        null|string $name = null,
        null|DateTimeImmutable $dateFrom = null,
        null|string $location = null,
    ): string {
        $copyId = Uuid::uuid7();

        $this->messageBus->dispatch(new DuplicateCompetition(
            newCompetitionId: $copyId,
            sourceCompetitionId: $sourceId,
            actingPlayerId: $playerId,
            name: $name,
            dateFrom: $dateFrom,
            location: $location,
        ));

        return $copyId->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function competition(string $id): array
    {
        $row = $this->database->fetchAssociative('SELECT * FROM competition WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rounds(string $competitionId): array
    {
        /** @var list<array<string, mixed>> $rounds */
        $rounds = $this->database->fetchAllAssociative(
            'SELECT name, slug, category, minutes_limit, starts_at, timezone, reveal_delay_minutes, team_size, table_numbers_off, badge_background_color, badge_text_color
            FROM competition_round WHERE competition_id = :id ORDER BY starts_at, name',
            ['id' => $competitionId],
        );

        return $rounds;
    }

    private function countRows(string $sql, string $id): int
    {
        return (int) $this->database->fetchOne($sql, ['id' => $id]);
    }
}
