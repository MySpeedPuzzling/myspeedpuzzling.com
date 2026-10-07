<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The web flow of docs/features/competitions-management/participant-import-preview.md: preview (GET, state in the
 * URL) → confirm (POST, 303) / start over. Synthetic names only.
 */
final class ParticipantImportFlowTest extends WebTestCase
{
    private const string COMPETITION = CompetitionFixture::COMPETITION_WJPC_2024;
    private const string PARTICIPANTS_URL = '/en/manage-event-participants/' . self::COMPETITION;

    /** "John Regular" is a participant of the event already (fixtures), "Alex Example" is new */
    private const string CSV = "Name,Country,Address,Rounds\nJohn Regular,cz,1 Sample Street,Final Round\nAlex Example,gb,2 Sample Street,Final Round\n";

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        // The stash lives in the (in-memory) object storage of this kernel
        $this->browser->disableReboot();
    }

    public function testPreviewShowsTheMappingAndThePlan(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token));

        $this->assertResponseIsSuccessful();
        $response = $this->browser->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringNotContainsString('competition.participants.', $crawler->filter('main')->text(), 'Untranslated key');

        self::assertStringContainsString('participants.csv', $crawler->filter('turbo-frame#participant-import-preview')->text());
        self::assertSame('advance', $crawler->filter('turbo-frame#participant-import-preview')->attr('data-turbo-action'));

        // Detected mapping; the address column is visibly not imported
        self::assertSame('name', $this->selected($crawler, 'map[0]'));
        self::assertSame('country', $this->selected($crawler, 'map[1]'));
        self::assertSame('ignore', $this->selected($crawler, 'map[2]'));
        self::assertSame('round_names', $this->selected($crawler, 'map[3]'));
        self::assertStringContainsString('Not imported', $crawler->filter('#participant-import-columns tr[data-column="2"]')->text());
        self::assertStringContainsString('1 Sample Street', $crawler->filter('#participant-import-columns tr[data-column="2"]')->text());

        // CSV: encoding + separator selects
        self::assertCount(1, $crawler->filter('select[name="encoding"]'));
        self::assertCount(1, $crawler->filter('select[name="separator"]'));

        // Mode: "Update only" by default
        self::assertNotNull($crawler->filter('#participant-import-mode-update')->attr('checked'));

        // The plan
        self::assertCount(1, $crawler->filter('#participant-import-summary [data-import-action="new"]'));
        // The badge says what the number is (a "New: %count%" message lost its "New:" to the plural selector)
        self::assertSame('1 new', trim($crawler->filter('#participant-import-summary [data-import-action="new"]')->text()));
        self::assertSame('1 unchanged', trim($crawler->filter('#participant-import-summary [data-import-action="unchanged"]')->text()));
        self::assertStringContainsString('Alex Example', $crawler->filter('#participant-import-rows')->text());
        self::assertStringContainsString('On the site but not in the file – kept', $crawler->filter('#participant-import-removals')->text());

        // The confirm form carries the previewed state
        $form = $this->confirmForm($crawler);
        $values = $form->getPhpValues();
        self::assertSame('update', $values['mode']);
        self::assertSame(['name', 'country', 'ignore', 'round_names'], $values['map']);
        self::assertNotSame('', $values['fingerprint']);
        self::assertSame('_top', $crawler->filter('#participant-import-confirm form')->attr('data-turbo-frame'));
    }

    public function testMappingWithoutANameShowsNoPlan(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token));
        $form = $crawler->filter('#participant-import-form')->form();
        $form['map[0]'] = 'ignore';
        $crawler = $this->browser->submit($form);

        $this->assertResponseIsSuccessful();
        self::assertSame('ignore', $this->selected($crawler, 'map[0]'));
        self::assertStringContainsString('Choose the column with the participants', $crawler->filter('.alert-danger')->text());
        self::assertCount(0, $crawler->filter('#participant-import-plan'));
    }

    public function testAnotherEventsTokenIsNotFound(): void
    {
        $token = $this->stash(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', $this->previewUrl($token));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonMaintainerIsForbidden(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', $this->previewUrl($token));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testConfirmWithoutAValidTokenChangesNothing(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $form = $this->confirmForm($this->browser->request('GET', $this->previewUrl($token)));
        $form['_token'] = 'not-a-token';
        $this->browser->submit($form);

        $this->assertResponseStatusCodeSame(303);
        self::assertStringStartsWith($this->previewUrl($token) . '?', (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->participantsCalled('Alex Example'));

        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('The form expired', $crawler->filter('.alert-danger')->text());
        // Still the same preview, nothing applied
        self::assertCount(1, $crawler->filter('#participant-import-confirm form'));
    }

    public function testStalePreviewGoesBackToIt(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $form = $this->confirmForm($this->browser->request('GET', $this->previewUrl($token)));

        // Somebody else changes the event meanwhile
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, self::COMPETITION);
        self::assertNotNull($competition);
        $entityManager->persist(new CompetitionParticipant(Uuid::uuid7(), 'Casey Example', null, $competition));
        $entityManager->flush();

        $this->browser->submit($form);

        $this->assertResponseStatusCodeSame(303);
        self::assertStringStartsWith($this->previewUrl($token) . '?', (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->participantsCalled('Alex Example'));

        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Something changed on the site since the preview', $crawler->filter('.alert-warning')->text());
        self::assertStringContainsString('Casey Example', $crawler->filter('#participant-import-removals')->text());
    }

    public function testConfirmImportsAndAnswersWithTheSummary(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $form = $this->confirmForm($this->browser->request('GET', $this->previewUrl($token)));
        $this->browser->submit($form);

        $this->assertResponseStatusCodeSame(303);
        $this->assertResponseRedirects(self::PARTICIPANTS_URL);
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Import complete:', $crawler->filter('.alert-success')->text());

        // A second click on Confirm imports nothing again
        $this->browser->submit($form);
        $this->assertResponseStatusCodeSame(303);
        $this->assertResponseRedirects(self::PARTICIPANTS_URL);
    }

    public function testFullSyncNeedsTheCheckbox(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token) . '?mode=sync');

        $this->assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('#participant-import-mode-sync')->attr('checked'));
        // Participants of the event that are not in the file
        $removals = $crawler->filter('#participant-import-removals')->text();
        self::assertStringContainsString('Will be removed', $removals);
        self::assertStringContainsString('Jane Unconnected', $removals);
        self::assertCount(1, $crawler->filter('#participant-import-confirm input[name="confirm_removal"][required]'));
        self::assertStringNotContainsString('competition.participants.', $crawler->filter('main')->text(), 'Untranslated key');

        $form = $this->confirmForm($crawler);
        self::assertSame('sync', $form->getPhpValues()['mode']);

        // Without the box ticked (the browser would refuse; the server refuses too)
        $this->browser->submit($form);

        $this->assertResponseStatusCodeSame(303);
        self::assertStringStartsWith($this->previewUrl($token) . '?', (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->participantsCalled('Jane Unconnected'));
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Please tick the box', $crawler->filter('.alert-danger')->text());
        self::assertNotNull($crawler->filter('#participant-import-mode-sync')->attr('checked'));

        $form = $this->confirmForm($crawler);
        /** @var \Symfony\Component\DomCrawler\Field\ChoiceFormField $checkbox */
        $checkbox = $form['confirm_removal'];
        $checkbox->tick();
        $this->browser->submit($form);

        $this->assertResponseStatusCodeSame(303);
        $this->assertResponseRedirects(self::PARTICIPANTS_URL);
    }

    public function testStartOverThrowsTheFileAway(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token));
        $form = $crawler->filter('form[action$="/' . $token . '/discard"]')->form();
        self::assertSame('_top', $crawler->filter('form[action$="/' . $token . '/discard"]')->attr('data-turbo-frame'));

        $this->browser->submit($form);

        $this->assertResponseStatusCodeSame(303);
        $this->assertResponseRedirects(self::PARTICIPANTS_URL);

        $this->browser->request('GET', $this->previewUrl($token));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testConfirmOnAnAlreadyImportedFileSaysSo(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $form = $this->confirmForm($this->browser->request('GET', $this->previewUrl($token)));
        $this->browser->submit($form);
        $this->assertResponseRedirects(self::PARTICIPANTS_URL, 303);

        $this->browser->submit($form);
        $this->assertResponseRedirects(self::PARTICIPANTS_URL, 303);
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('This file was already imported.', $crawler->filter('.alert-info')->text());
        self::assertSame(1, $this->participantsCalled('Alex Example'));
    }

    public function testFullSyncSummaryTellsWhatItRemoved(): void
    {
        // Robin is in both rounds; the file lists the Final Round only
        $robin = $this->addParticipant('Robin Example');
        $this->addToRound($robin, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $this->addToRound($robin, CompetitionRoundFixture::ROUND_WJPC_FINAL);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $token = $this->stash(self::COMPETITION, self::CSV . "Robin Example,cz,3 Sample Street,Final Round\n");
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $form = $this->confirmForm($this->browser->request('GET', $this->previewUrl($token) . '?mode=sync'));
        /** @var \Symfony\Component\DomCrawler\Field\ChoiceFormField $checkbox */
        $checkbox = $form['confirm_removal'];
        $checkbox->tick();
        $this->browser->submit($form);

        $this->assertResponseRedirects(self::PARTICIPANTS_URL, 303);
        $flash = $this->browser->followRedirect()->filter('.alert-success')->text();
        // Jane and the self-joined Michael; Robin's Qualification Round entry
        self::assertStringContainsString('2 removed', $flash);
        self::assertStringContainsString('Full sync also removed 1 round entries and 0 pairs/teams left empty.', $flash);
    }

    public function testALargeRemovalNeedsTheTypedNumber(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->addParticipant(sprintf('Leaving Example %d', $i));
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token) . '?mode=sync');
        $label = $crawler->filter('label[for="participant-import-removed-count"]')->text();
        self::assertSame(1, preg_match('/\((\d+)\)/', $label, $match));
        // The twelve, Jane and the self-joined Michael
        self::assertSame('14', $match[1]);

        foreach (['13', '14'] as $typed) {
            $form = $this->confirmForm($crawler);
            /** @var \Symfony\Component\DomCrawler\Field\ChoiceFormField $checkbox */
            $checkbox = $form['confirm_removal'];
            $checkbox->tick();
            $form['removed_count'] = $typed;
            $this->browser->submit($form);

            if ($typed === '13') {
                self::assertStringStartsWith($this->previewUrl($token) . '?', (string) $this->browser->getResponse()->headers->get('Location'));
                self::assertSame(1, $this->participantsCalled('Leaving Example 1'), 'Nothing written');
                $crawler = $this->browser->followRedirect();
                self::assertStringContainsString('The number you typed is not the number of people', $crawler->filter('.alert-danger')->text());

                continue;
            }

            $this->assertResponseRedirects(self::PARTICIPANTS_URL, 303);
            self::assertSame(0, $this->participantsCalled('Leaving Example 1'));
        }
    }

    public function testNonMaintainerCannotConfirmNorDiscard(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['confirm', 'discard'] as $action) {
            $this->browser->request('POST', $this->previewUrl($token) . '/' . $action, ['_token' => 'whatever', 'mode' => 'update']);
            $this->assertResponseStatusCodeSame(403);
        }

        self::assertNotNull(self::getContainer()->get(ParticipantImportStash::class)->describe($token, self::COMPETITION));
        self::assertSame(0, $this->participantsCalled('Alex Example'));
    }

    public function testDiscardWithABadTokenThrowsNothingAway(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token));
        $form = $crawler->filter('form[action$="/' . $token . '/discard"]')->form();
        $form['_token'] = 'not-a-token';
        $this->browser->submit($form);

        $this->assertResponseRedirects(self::PARTICIPANTS_URL, 303);
        $this->browser->request('GET', $this->previewUrl($token));
        $this->assertResponseIsSuccessful();
    }

    public function testDiscardOfAnUnknownUploadClaimsNothing(): void
    {
        $token = $this->stash(self::COMPETITION);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token));
        $csrf = $crawler->filter('form[action$="/' . $token . '/discard"] input[name="_token"]')->attr('value');

        $this->browser->request('POST', $this->previewUrl(str_repeat('ab', 16)) . '/discard', ['_token' => $csrf]);

        $this->assertResponseRedirects(self::PARTICIPANTS_URL, 303);
        $crawler = $this->browser->followRedirect();
        self::assertStringNotContainsString('thrown away', $crawler->filter('main')->text());
        self::assertNotNull(self::getContainer()->get(ParticipantImportStash::class)->describe($token, self::COMPETITION));
    }

    public function testTheSheetWithTheNamesIsPreselected(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Info');
        $spreadsheet->getActiveSheet()->fromArray([['Event', 'Example Cup'], ['Venue', 'Sample Hall'], ['Contact', 'Organiser Example'], ['Fee', '10']]);
        $participants = $spreadsheet->createSheet();
        $participants->setTitle('Registrations');
        $participants->fromArray([['First name', 'Last name', 'Country'], ['Alex', 'Example', 'cz']]);

        $token = $this->stashXlsx($spreadsheet);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token));

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Registrations', $crawler->filter('.nav-pills .nav-link.active')->text());
        self::assertSame('first_name', $this->selected($crawler, 'map[0]'));

        // No sheet with a name column: the longest one
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Short');
        $spreadsheet->getActiveSheet()->fromArray([['Who', 'Where'], ['Alex Example', 'cz']]);
        $long = $spreadsheet->createSheet();
        $long->setTitle('Long');
        $long->fromArray([['Who', 'Where'], ['Alex Example', 'cz'], ['Bea Sample', 'de'], ['Cid Sample', 'at']]);

        $crawler = $this->browser->request('GET', $this->previewUrl($this->stashXlsx($spreadsheet)));
        self::assertStringContainsString('Long', $crawler->filter('.nav-pills .nav-link.active')->text());
    }

    public function testATeamTheImportGivesReadsAsAChange(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, self::COMPETITION);
        self::assertNotNull($competition);
        $entityManager->persist(new CompetitionRound(Uuid::uuid7(), $competition, 'Pair Round', 60, new \DateTimeImmutable('+40 days'), category: RoundCategory::Duo));
        $entityManager->flush();

        $token = $this->stash(self::COMPETITION, "Name,Rounds,Team: Pair Round\nAlex Example,Pair Round,Corner Crew\n");
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', $this->previewUrl($token));

        $this->assertResponseIsSuccessful();
        $team = $crawler->filter('#participant-import-rows tr[data-row="2"] [data-team-round="Pair Round"]');
        self::assertSame('Pair Round: → Corner Crew', preg_replace('/\s+/', ' ', trim($team->text())));
        self::assertCount(1, $team->filter('strong'));
    }

    private function stash(string $competitionId, string $content = self::CSV): string
    {
        $path = tempnam(sys_get_temp_dir(), 'participant_import_test_');
        assert(is_string($path));
        file_put_contents($path, $content);

        $stashed = self::getContainer()->get(ParticipantImportStash::class)->keep(
            new UploadedFile($path, 'participants.csv', 'text/csv', null, true),
            $competitionId,
            PlayerFixture::PLAYER_ADMIN,
        );
        unlink($path);

        self::assertNotNull($stashed);

        return $stashed->token;
    }

    private function stashXlsx(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'participant_import_test_');
        assert(is_string($path));
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $stashed = self::getContainer()->get(ParticipantImportStash::class)->keep(
            new UploadedFile($path, 'participants.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            self::COMPETITION,
            PlayerFixture::PLAYER_ADMIN,
        );
        unlink($path);

        self::assertNotNull($stashed);

        return $stashed->token;
    }

    private function addParticipant(string $name): CompetitionParticipant
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, self::COMPETITION);
        self::assertNotNull($competition);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, 'cz', $competition);
        $entityManager->persist($participant);

        return $participant;
    }

    private function addToRound(CompetitionParticipant $participant, string $roundId): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $round = $entityManager->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);

        $entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round));
    }

    private function previewUrl(string $token, string $competitionId = self::COMPETITION): string
    {
        return '/en/import-event-participants/' . $competitionId . '/' . $token;
    }

    private function confirmForm(Crawler $crawler): Form
    {
        return $crawler->filter('#participant-import-confirm form')->form();
    }

    private function selected(Crawler $crawler, string $name): null|string
    {
        return $crawler->filter('select[name="' . $name . '"] option[selected]')->attr('value');
    }

    private function participantsCalled(string $name): int
    {
        /** @var int $count */
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :competitionId AND name = :name AND deleted_at IS NULL',
            ['competitionId' => self::COMPETITION, 'name' => $name],
        );

        return $count;
    }
}
