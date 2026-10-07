<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    /** @var list<string> */
    private array $files = [];

    public function testCsvUploadGoesToThePreview(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->upload($browser, $this->file("name;round_names\nAlex Example;Solo, Pair\n", 'participants.csv', 'text/csv'));

        self::assertResponseStatusCodeSame(303);
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^' . preg_quote(self::IMPORT_URL, '#') . '/[0-9a-f]{32}$#', $location);
    }

    public function testXlsxUploadGoesToThePreview(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['name', 'country'], ['Alex Example', 'cz']]);
        $path = $this->path();
        (new Xlsx($spreadsheet))->save($path);

        $this->upload($browser, new UploadedFile($path, 'participants.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith(self::IMPORT_URL . '/', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testJunkIsRefusedWithAMessage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->upload($browser, $this->file("PK\x03\x04" . str_repeat('garbage', 20), 'participants.xlsx', 'application/zip'));

        self::assertResponseRedirects(self::MANAGE_URL, 303);
        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'The file could not be read.');
    }

    public function testUnknownFileTypeIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->upload($browser, $this->file("name\nAlex Example\n", 'participants.md', 'text/plain'));

        self::assertResponseRedirects(self::MANAGE_URL, 303);
        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Please upload an .xlsx workbook or a .csv, .tsv or .txt file.');
    }

    public function testAWorkbookNamedCsvIsRefusedWithAMessage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['name', 'country'], ['Alex Example', 'cz']]);
        $path = $this->path();
        (new Xlsx($spreadsheet))->save($path);

        $this->upload($browser, new UploadedFile($path, 'participants.csv', 'text/csv', null, true));

        self::assertResponseRedirects(self::MANAGE_URL, 303);
        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'The file could not be read.');
    }

    public function testNonMaintainerIsForbidden(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', self::IMPORT_URL, [], [
            'excel_import_form' => ['file' => $this->file("name\nAlex Example\n", 'participants.csv', 'text/csv')],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPageHelpDocumentsRoundAndTeamColumns(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::MANAGE_URL);

        $this->assertResponseIsSuccessful();

        // The file input has a label
        $input = $crawler->filter('form[action="' . self::IMPORT_URL . '"] input[type="file"]');
        self::assertCount(1, $input);
        self::assertStringContainsString('Participant list', $crawler->filter('label[for="' . $input->attr('id') . '"]')->text());

        $columns = $crawler->filter('details table code')->each(static fn ($node): string => $node->text());
        self::assertContains('round_names', $columns);
        self::assertContains('team_name', $columns);
        self::assertContains('team_name: <round>', $columns);
        self::assertContains('participant_id', $columns);
    }

    private function file(string $content, string $name, string $mimeType): UploadedFile
    {
        $path = $this->path();
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mimeType, null, true);
    }

    private function path(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'test_import_');
        $this->files[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
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
