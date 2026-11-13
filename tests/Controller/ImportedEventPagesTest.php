<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Services\EventResultsImport\SarahDoesPuzzlesTabParser;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An event imported from a sheet without "Start time" and "Time limit" (docs/features/event-results-import/README.md
 * "Format"): its round knows only its day and has no limit - every page showing it renders, without a made-up time or
 * limit. The event page answered 500 on production once (the side panel named the zone of a time it did not have).
 * Made-up event and teams.
 */
final class ImportedEventPagesTest extends WebTestCase
{
    private const string HEADER = 'Name,Time,Team Size,Contest Name,Location,City and State,Brand,Puzzle Title,Date,Competition Type,Link to MySpeedPuzzling';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $organization = $entityManager->find(Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);
        $creator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($organization);
        self::assertNotNull($creator);

        $sheet = new EventResultsSheet(
            id: Uuid::uuid7(),
            spreadsheetId: 'test-spreadsheet-pages-0123456789',
            organization: $organization,
            actingPlayer: $creator,
            timezone: 'America/Chicago',
            countryCode: 'us',
            addedAt: new DateTimeImmutable('2026-11-01 10:00:00'),
        );
        $entityManager->persist($sheet);
        $entityManager->flush();

        $tab = new SarahDoesPuzzlesTabParser()->parse(implode("\n", [
            self::HEADER,
            '"Puzzle Pals",1:10:13,4,Maple Street Puzzle Night,Maple Street Taproom,"Springfield, MN",Some Brand,Some Puzzle,10/5/26,Teams,https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01,
            '"Edge Lords",1:12:04,3,,,,,,,,',
        ]));
        self::assertSame([], $tab->problems);

        $container->get(MessageBusInterface::class)->dispatch(new ApplyEventResultsTab($sheet->id->toString(), '515151', 'Maple Street 10/5', $tab));
        $entityManager->clear();
    }

    public function testEveryPageShowingTheEventRendersWithoutATimeOrALimit(): void
    {
        $event = self::getContainer()->get(Connection::class)->fetchAssociative(
            "SELECT c.slug, cr.slug AS round_slug FROM competition c JOIN competition_round cr ON cr.competition_id = c.id WHERE c.name = 'Maple Street Puzzle Night'",
        );
        self::assertIsArray($event);
        self::assertIsString($event['slug']);
        self::assertIsString($event['round_slug']);

        $this->browser->request('GET', '/en/events/' . $event['slug']);
        self::assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Maple Street Puzzle Night', $content);
        self::assertStringNotContainsString('ev-round-limit', $content);
        self::assertStringNotContainsString('data-header-round-time', $content);

        $this->browser->request('GET', '/en/events/' . $event['slug'] . '/results/' . $event['round_slug']);
        self::assertResponseIsSuccessful();
        $results = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Edge Lords', $results);
        // The organizer's results are the event's official ones: ranked, without the "not the official results" note
        self::assertStringContainsString('data-round-organizer-results', $results);
        self::assertStringNotContainsString('data-round-unofficial', $results);
        // 1:12:04 is 1:51 behind 1:10:13 - the leaderboard's gap line
        self::assertStringContainsString('lb-gaps', $results);
        self::assertSame(['1', '2'], array_map('trim', $this->browser->getCrawler()->filter('[data-round-result-rank]')->each(static fn ($cell): string => $cell->text())));

        $this->browser->request('GET', '/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG);
        self::assertResponseIsSuccessful();

        $this->browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();

        $this->browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertResponseIsSuccessful();
    }
}
