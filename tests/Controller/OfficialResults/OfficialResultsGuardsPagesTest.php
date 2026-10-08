<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The organiser pages tell why official results stop a change (docs/features/competitions-management/official-results.md).
 * Pairs/teams are edited in the participants spreadsheet now - its write path has the team guards
 * (participants-spreadsheet.md, `team_has_result`, `team_result_line_up_changed`).
 */
final class OfficialResultsGuardsPagesTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testDeletingARoundWithOfficialResultsAsksFirstListingThem(): void
    {
        $url = '/en/delete-event-round/' . OfficialResultsFixture::ROUND_GROUP_B;

        $crawler = $this->browser->request('POST', $url, ['_token' => 'any']);

        self::assertResponseStatusCodeSame(422);
        $list = $crawler->filter('[data-confirm-official-results] li');
        self::assertCount(3, $list);
        self::assertStringContainsString('Gina Quick', $list->eq(0)->text());
        self::assertStringContainsString('1:05:00', $list->eq(0)->text());
        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]));

        $hash = (string) $crawler->filter('input[name="confirm_official_results_hash"]')->attr('value');

        // A changed list is asked about again
        $this->database->executeStatement('UPDATE competition_participant_round SET result_seconds = 5100 WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_IVAN]);
        $this->browser->request('POST', $url, ['_token' => 'any', 'confirm_official_results' => '1', 'confirm_official_results_hash' => $hash]);
        self::assertResponseStatusCodeSame(422);
        $hash = (string) $this->browser->getCrawler()->filter('input[name="confirm_official_results_hash"]')->attr('value');

        $this->browser->request('POST', $url, ['_token' => 'any', 'confirm_official_results' => '1', 'confirm_official_results_hash' => $hash]);

        self::assertResponseRedirects('/en/manage-event-rounds/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]));
    }

    public function testARoundWithoutOfficialResultsIsDeletedAsBefore(): void
    {
        $this->browser->request('POST', '/en/delete-event-round/' . OfficialResultsFixture::ROUND_PAIRS_FINAL, ['_token' => 'any']);

        self::assertResponseRedirects();
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_PAIRS_FINAL]));
    }
}
