<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\SarahDoesPuzzlesFormat;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A results source of Riverbend (created by PLAYER_WITH_STRIPE, maintained by PLAYER_WITH_FAVORITES) whose sheet is
 * served by a fake Google (GoogleSheetReader over a MockHttpClient) - for the results import page's tests. Made-up
 * events and teams only (the repository is public).
 */
trait ResultsImportScenario
{
    public const string HEADER = 'Name,Time,Team Size,Pieces placed,Contest Name,Location,City and State,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling';

    /**
     * @var array<array-key, array{title: string, csv: null|string}> gid => tab; a null CSV = Google does not give it
     */
    private array $fakeSheetTabs = [];

    private bool $fakeSheetShared = true;

    private function createResultsSource(ContainerInterface $container, string $spreadsheetId = 'test-spreadsheet-results-import-0123'): EventResultsSheet
    {
        $entityManager = $container->get(EntityManagerInterface::class);
        $organization = $entityManager->find(Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);
        $creator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        assert($organization !== null && $creator !== null);

        $source = new EventResultsSheet(Uuid::uuid7(), $spreadsheetId, $organization, $creator, 'America/Chicago', 'us', new DateTimeImmutable('2026-11-01 10:00:00'));
        $entityManager->persist($source);
        $entityManager->flush();

        return $source;
    }

    /**
     * Google from now on answers with $this->fakeSheetTabs (read at the time of each request).
     */
    private function fakeGoogle(ContainerInterface $container): void
    {
        $container->set(GoogleSheetReader::class, new GoogleSheetReader(new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'htmlview')) {
                if ($this->fakeSheetShared === false) {
                    return new MockResponse('<html>sign in</html>', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]);
                }

                $page = '<script>';

                foreach ($this->fakeSheetTabs as $gid => $tab) {
                    $page .= sprintf('items.push({name: "%s", pageUrl: "x", gid: "%s",initialSheet: false});', addcslashes($tab['title'], '"/\\'), $gid);
                }

                return new MockResponse($page . '</script>', ['response_headers' => ['content-type' => 'text/html']]);
            }

            foreach ($this->fakeSheetTabs as $gid => $tab) {
                if (str_ends_with($url, 'gid=' . $gid)) {
                    return $tab['csv'] === null
                        ? new MockResponse('', ['http_code' => 500])
                        : new MockResponse($tab['csv'], ['response_headers' => ['content-type' => 'text/csv']]);
                }
            }

            return new MockResponse('', ['http_code' => 404]);
        })));
    }

    /**
     * @param list<string> $rows CSV lines after the header
     */
    private static function sheetCsv(array $rows): string
    {
        return implode("\r\n", [self::HEADER, ...$rows]);
    }

    /**
     * Imports one tab the way the sync does (ApplyEventResultsTab) - what is stored before the page compares.
     */
    private static function applyTab(ContainerInterface $container, EventResultsSheet $source, string $gid, string $title, string $csv): void
    {
        $event = new SarahDoesPuzzlesFormat()->parseTab(new SheetSnapshotTab($gid, $title, $csv));
        $container->get(MessageBusInterface::class)->dispatch(new ApplyEventResultsTab($source->id->toString(), $event));
        $container->get(EntityManagerInterface::class)->clear();
    }
}
