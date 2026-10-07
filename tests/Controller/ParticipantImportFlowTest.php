<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
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

    private function stash(string $competitionId): string
    {
        $path = tempnam(sys_get_temp_dir(), 'participant_import_test_');
        assert(is_string($path));
        file_put_contents($path, self::CSV);

        $stashed = self::getContainer()->get(ParticipantImportStash::class)->keep(
            new UploadedFile($path, 'participants.csv', 'text/csv', null, true),
            $competitionId,
            PlayerFixture::PLAYER_ADMIN,
        );
        unlink($path);

        self::assertNotNull($stashed);

        return $stashed->token;
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
