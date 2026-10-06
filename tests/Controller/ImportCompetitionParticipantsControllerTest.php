<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportCompetitionParticipantsControllerTest extends WebTestCase
{
    private const string MANAGE_URL = '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_WJPC_2024;
    private const string IMPORT_URL = '/en/import-event-participants/' . CompetitionFixture::COMPETITION_WJPC_2024;

    public function testCsvUploadIsToldToUseXlsx(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $csv = tempnam(sys_get_temp_dir(), 'test_csv_');
        assert(is_string($csv));
        file_put_contents($csv, "name,round_names\nCsv Puzzler,Final Round\n");

        $this->upload($browser, new UploadedFile($csv, 'participants.csv', 'text/csv', null, true));
        unlink($csv);

        $this->assertResponseRedirects(self::MANAGE_URL);
        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Please upload an .xlsx file – CSV is not supported yet.');
    }

    public function testPageHelpDocumentsRoundAndTeamColumns(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::MANAGE_URL);

        $this->assertResponseIsSuccessful();
        $columns = $crawler->filter('details table code')->each(static fn ($node): string => $node->text());
        self::assertContains('round_names', $columns);
        self::assertContains('team_name', $columns);
    }

    private function upload(KernelBrowser $browser, UploadedFile $file): void
    {
        $crawler = $browser->request('GET', self::MANAGE_URL);
        $tokenInput = $crawler->filter('form[action="' . self::IMPORT_URL . '"] input[name$="[_token]"]');
        /** @var string $tokenName */
        $tokenName = $tokenInput->attr('name');
        $formName = substr($tokenName, 0, (int) strpos($tokenName, '['));

        $browser->request(
            'POST',
            self::IMPORT_URL,
            [$formName => ['_token' => $tokenInput->attr('value')]],
            [$formName => ['file' => $file]],
        );
    }
}
