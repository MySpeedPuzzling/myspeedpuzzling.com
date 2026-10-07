<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The page editor and every write behind it: authorised on the page that owns the section, CSRF on every POST, 303 on
 * success and 422 when refused (Turbo never gets a 200 to a form), organiser pages never cached or indexed.
 */
final class PageSectionEditorTest extends WebTestCase
{
    // Maintained by PlayerFixture::PLAYER_REGULAR; in person
    private const string OWN_EVENT = CompetitionFixture::COMPETITION_UNAPPROVED;
    // Maintained by PlayerFixture::PLAYER_REGULAR; online
    private const string OWN_ONLINE_EVENT = CompetitionFixture::COMPETITION_RECURRING_ONLINE;
    // Not maintained by PlayerFixture::PLAYER_REGULAR
    private const string FOREIGN_EVENT = CompetitionFixture::COMPETITION_WJPC_2024;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheEditorIsForSignedInMaintainersOnly(): void
    {
        $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_EVENT);
        $this->assertResponseRedirects();

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', '/en/manage-event-page/' . self::FOREIGN_EVENT);
        $this->assertResponseStatusCodeSame(403);

        $this->browser->request('GET', '/en/manage-series-page/' . CompetitionSeriesFixture::SERIES_OFFLINE);
        $this->assertResponseStatusCodeSame(403);

        $crawler = $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_EVENT);
        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSelectorExists('[data-page-sections-empty]');
        self::assertCount(7, $crawler->filter('.dropdown-menu a[href*="/add-page-section"]'));
    }

    public function testAnOnlineEventIsNotOfferedAVenue(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_ONLINE_EVENT);
        $this->assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('.dropdown-menu a[href*="/add-page-section"]'));
        self::assertCount(0, $crawler->filter('.dropdown-menu a[href*="type=venue"]'));

        $this->browser->request('GET', '/en/add-page-section?competition=' . self::OWN_ONLINE_EVENT . '&type=venue');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAddingASection(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/add-page-section?competition=' . self::OWN_EVENT . '&type=faq';

        $crawler = $this->browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        $form = $crawler->filter('form[method="post"]');
        self::assertSame($url, $form->attr('action'));

        // Without the page's token nothing is saved and the form says why
        $this->browser->request('POST', $url, ['title' => 'FAQ', 'items' => [['question' => 'Parking?', 'answer' => 'Yes']]]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-page-section-errors]', 'The page was open for too long');
        self::assertSame(0, $this->sectionCount(self::OWN_EVENT));

        // Nothing to show is refused, with the typed heading kept
        $this->browser->submit($form->form(), ['title' => 'FAQ', 'items' => [['question' => '', 'answer' => '']]]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-page-section-errors]', 'Add some content');
        self::assertSame('FAQ', $this->browser->getCrawler()->filter('input[name="title"]')->attr('value'));

        $this->browser->submit($form->form(), ['title' => 'FAQ', 'items' => [['question' => 'Parking?', 'answer' => 'Behind the hall']]]);
        $this->assertResponseStatusCodeSame(303);
        $this->assertResponseRedirects('/en/manage-event-page/' . self::OWN_EVENT);
        self::assertSame(1, $this->sectionCount(self::OWN_EVENT));

        $crawler = $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'The section was added to the page.');
        self::assertCount(1, $crawler->filter('[data-section-id]'));
    }

    public function testEveryTypesFormRendersAndKeepsItsPictures(): void
    {
        $owner = PageSectionOwner::competition(self::OWN_EVENT);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        foreach (PageSectionType::cases() as $type) {
            $crawler = $this->browser->request('GET', '/en/add-page-section?competition=' . self::OWN_EVENT . '&type=' . $type->value);
            $this->assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('form[method="post"] input[name="_token"]'), $type->value);
        }

        $photo = $owner->uploadDirectory() . '0199a1b2-0000-7000-8000-000000000001.jpg';
        $gallery = $this->section($owner, PageSectionType::Gallery, ['images' => [['path' => $photo, 'caption' => 'Finals']]]);
        $sponsors = $this->section($owner, PageSectionType::Sponsors, ['sponsors' => [['name' => 'Shop', 'url' => 'https://shop.example', 'logoPath' => $photo]]]);

        foreach ([$gallery => 'images[0][path]', $sponsors => 'sponsors[0][logoPath]'] as $sectionId => $field) {
            $crawler = $this->browser->request('GET', '/en/edit-page-section/' . $sectionId);
            $this->assertResponseIsSuccessful();
            self::assertSame($photo, $crawler->filter('input[name="' . $field . '"]')->attr('value'));
            self::assertStringEndsWith('/plain/' . $photo, (string) $crawler->filter('[data-section-image-upload-target="preview"]')->first()->attr('src'));
        }
    }

    public function testInvalidLinksAreExplainedNotDropped(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/add-page-section?competition=' . self::OWN_EVENT . '&type=links';
        $form = $this->browser->request('GET', $url)->filter('form[method="post"]')->form();

        $this->browser->submit($form, ['links' => [['label' => 'Group', 'url' => 'javascript:alert(1)']]]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-page-section-errors]', 'A web address must be complete');
        self::assertSame(0, $this->sectionCount(self::OWN_EVENT));
    }

    public function testNobodyAddsToAPageTheyDoNotMaintain(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', '/en/add-page-section?competition=' . self::FOREIGN_EVENT . '&type=rich_text');
        $this->assertResponseStatusCodeSame(403);

        $this->browser->request('POST', '/en/add-page-section?series=' . CompetitionSeriesFixture::SERIES_OFFLINE . '&type=rich_text', ['html' => '<p>x</p>']);
        $this->assertResponseStatusCodeSame(403);
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM competition_page_section'));
    }

    public function testEditingIsAuthorisedOnTheOwnerOfTheSection(): void
    {
        $foreignSection = $this->section(PageSectionOwner::competition(self::FOREIGN_EVENT), PageSectionType::RichText, ['html' => '<p>Theirs</p>']);
        $ownSection = $this->section(PageSectionOwner::competition(self::OWN_EVENT), PageSectionType::RichText, ['html' => '<p>Mine</p>']);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token(self::OWN_EVENT);

        // The token of my own page does not open another organiser's section
        $this->browser->request('GET', '/en/edit-page-section/' . $foreignSection);
        $this->assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/en/edit-page-section/' . $foreignSection, ['_token' => $token, 'html' => '<p>Hijacked</p>']);
        $this->assertResponseStatusCodeSame(403);
        self::assertStringContainsString('Theirs', $this->scalar('SELECT content::text FROM competition_page_section WHERE id = :id', ['id' => $foreignSection]));

        $crawler = $this->browser->request('GET', '/en/edit-page-section/' . $ownSection);
        $this->assertResponseIsSuccessful();
        $this->browser->submit($crawler->filter('form[method="post"]')->form(), ['title' => 'Rules', 'html' => '<p>Updated <script>x</script></p>']);
        $this->assertResponseStatusCodeSame(303);
        self::assertSame('Rules', $this->database->fetchOne('SELECT title FROM competition_page_section WHERE id = :id', ['id' => $ownSection]));
        self::assertSame('<p>Updated </p>', $this->scalar("SELECT content->>'html' FROM competition_page_section WHERE id = :id", ['id' => $ownSection]));
    }

    public function testAnEditionsEditorShowsButDoesNotChangeTheSeriesSections(): void
    {
        $seriesSection = $this->section(PageSectionOwner::series(CompetitionSeriesFixture::SERIES_OFFLINE), PageSectionType::RichText, ['html' => '<p>Series rules</p>'], 'Series rules');
        $this->makeEditionMaintainer(PlayerFixture::PLAYER_REGULAR, CompetitionSeriesFixture::EDITION_OFFLINE_1);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/manage-event-page/' . CompetitionSeriesFixture::EDITION_OFFLINE_1);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Series rules', $crawler->filter('[data-page-sections-inherited]')->text());
        self::assertCount(0, $crawler->filter('a[href$="/edit-page-section/' . $seriesSection . '"]'));
        // Not a series maintainer - no way to its editor
        self::assertCount(0, $crawler->filter('a[href*="/manage-series-page/"]'));

        $this->browser->request('GET', '/en/edit-page-section/' . $seriesSection);
        $this->assertResponseStatusCodeSame(403);

        // Nor can the edition's token reorder or hide the series' section
        $token = $this->token(CompetitionSeriesFixture::EDITION_OFFLINE_1);
        $this->browser->request('POST', '/en/reorder-page-sections', [
            '_token' => $token,
            'competitionId' => CompetitionSeriesFixture::EDITION_OFFLINE_1,
            'sections' => [$seriesSection],
        ]);
        $this->assertResponseStatusCodeSame(404);
        $this->browser->request('POST', '/en/page-section-visibility/' . $seriesSection, ['_token' => $token, 'visible' => '0']);
        $this->assertResponseStatusCodeSame(403);
        self::assertTrue((bool) $this->database->fetchOne('SELECT visible FROM competition_page_section WHERE id = :id', ['id' => $seriesSection]));
    }

    public function testDeletingAndHidingNeedThePagesToken(): void
    {
        $sectionId = $this->section(PageSectionOwner::competition(self::OWN_EVENT), PageSectionType::RichText, ['html' => '<p>Mine</p>']);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('POST', '/en/page-section-visibility/' . $sectionId, ['_token' => 'wrong', 'visible' => '0']);
        $this->assertResponseStatusCodeSame(303);
        $this->browser->request('POST', '/en/delete-page-section/' . $sectionId, ['_token' => 'wrong']);
        $this->assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->sectionCount(self::OWN_EVENT));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'The page was open for too long');

        $crawler = $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_EVENT);
        $this->browser->submit($crawler->filter('form[action$="/page-section-visibility/' . $sectionId . '"]')->form());
        $this->assertResponseRedirects('/en/manage-event-page/' . self::OWN_EVENT . '#page-section-' . $sectionId, 303);
        self::assertFalse((bool) $this->database->fetchOne('SELECT visible FROM competition_page_section WHERE id = :id', ['id' => $sectionId]));

        $crawler = $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_EVENT);
        self::assertSelectorTextContains('#page-section-' . $sectionId, 'Hidden');
        $this->browser->submit($crawler->filter('form[action$="/delete-page-section/' . $sectionId . '"]')->form());
        $this->assertResponseRedirects('/en/manage-event-page/' . self::OWN_EVENT, 303);
        self::assertSame(0, $this->sectionCount(self::OWN_EVENT));
    }

    public function testReorderingOwnSectionsAndRefusingOthers(): void
    {
        $owner = PageSectionOwner::competition(self::OWN_EVENT);
        $first = $this->section($owner, PageSectionType::RichText, ['html' => '<p>1</p>']);
        $second = $this->section($owner, PageSectionType::RichText, ['html' => '<p>2</p>']);
        $foreign = $this->section(PageSectionOwner::competition(self::FOREIGN_EVENT), PageSectionType::RichText, ['html' => '<p>x</p>']);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token(self::OWN_EVENT);

        $this->browser->request('POST', '/en/reorder-page-sections', ['_token' => 'wrong', 'competitionId' => self::OWN_EVENT, 'sections' => [$second, $first]]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('The page was open for too long. Please try again.', $this->json()['error']);

        $this->browser->request('POST', '/en/reorder-page-sections', ['_token' => $token, 'competitionId' => self::FOREIGN_EVENT, 'sections' => [$foreign]]);
        $this->assertResponseStatusCodeSame(403);

        $this->browser->request('POST', '/en/reorder-page-sections', ['_token' => $token, 'competitionId' => self::OWN_EVENT, 'sections' => [$second, $foreign, $first]]);
        $this->assertResponseStatusCodeSame(404);
        self::assertSame([$first, $second], $this->order(self::OWN_EVENT));

        $this->browser->request('POST', '/en/reorder-page-sections', ['_token' => $token, 'competitionId' => self::OWN_EVENT, 'sections' => [$second, $first]]);
        $this->assertResponseIsSuccessful();
        self::assertSame([$second, $first], $this->order(self::OWN_EVENT));

        // The editor lists them in that order, with Move up off for the first and Move down off for the last
        $crawler = $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_EVENT);
        self::assertSame([$second, $first], $crawler->filter('[data-section-id]')->each(static fn ($item): string => (string) $item->attr('data-section-id')));
        self::assertNotNull($crawler->filter('[data-section-id="' . $second . '"] [data-move="up"]')->attr('disabled'));
        self::assertNull($crawler->filter('[data-section-id="' . $second . '"] [data-move="down"]')->attr('disabled'));
        self::assertNotNull($crawler->filter('[data-section-id="' . $first . '"] [data-move="down"]')->attr('disabled'));
    }

    public function testUploadingAPicture(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token(self::OWN_EVENT);

        $this->browser->request('POST', '/en/upload-page-section-image', ['_token' => $token, 'competitionId' => self::FOREIGN_EVENT], ['file' => $this->png()]);
        $this->assertResponseStatusCodeSame(403);

        $this->browser->request('POST', '/en/upload-page-section-image', ['_token' => 'wrong', 'competitionId' => self::OWN_EVENT], ['file' => $this->png()]);
        $this->assertResponseStatusCodeSame(422);

        $this->browser->request('POST', '/en/upload-page-section-image', ['_token' => $token, 'competitionId' => self::OWN_EVENT]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('Choose a picture to upload.', $this->json()['error']);

        $text = tempnam(sys_get_temp_dir(), 'not_an_image_');
        file_put_contents($text, 'hello');
        $this->browser->request('POST', '/en/upload-page-section-image', ['_token' => $token, 'competitionId' => self::OWN_EVENT], ['file' => new UploadedFile($text, 'notes.txt', 'text/plain', null, true)]);
        $this->assertResponseStatusCodeSame(422);
        // The validator's own message, translated - shown under the picture, never in an alert()
        self::assertStringContainsString('The mime type of the file is invalid', $this->json()['error']);

        $this->browser->request('POST', '/en/upload-page-section-image', ['_token' => $token, 'competitionId' => self::OWN_EVENT], ['file' => $this->png()]);
        $this->assertResponseIsSuccessful();
        $path = $this->json()['path'];
        self::assertStringStartsWith('competition-pages/' . self::OWN_EVENT . '/', $path);
        self::assertStringEndsWith('.png', $path);
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($path));
    }

    private function token(string $competitionId): string
    {
        $crawler = $this->browser->request('GET', '/en/manage-event-page/' . $competitionId);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-page-sections-list-token-value]')->attr('data-page-sections-list-token-value');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function section(PageSectionOwner $owner, PageSectionType $type, array $content, null|string $title = null): string
    {
        $sectionId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPageSection(
            $sectionId,
            $owner->competitionId,
            $owner->seriesId,
            $type,
            $title,
            $content,
        ));

        return $sectionId->toString();
    }

    private function sectionCount(string $competitionId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM competition_page_section WHERE competition_id = :id', ['id' => $competitionId]);
    }

    /**
     * @return list<string>
     */
    private function order(string $competitionId): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn('SELECT id FROM competition_page_section WHERE competition_id = :id ORDER BY position', ['id' => $competitionId]);

        return $ids;
    }

    private function makeEditionMaintainer(string $playerId, string $competitionId): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, $competitionId);
        $player = $entityManager->find(Player::class, $playerId);
        self::assertNotNull($competition);
        self::assertNotNull($player);
        $competition->maintainers->add($player);
        $entityManager->flush();
    }

    private function png(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'section_png_') . '.png';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagepng($image, $path);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    /**
     * @return array<string, string>
     */
    private function json(): array
    {
        /** @var array<string, string> $data */
        $data = json_decode((string) $this->browser->getResponse()->getContent(), true);

        return $data;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function scalar(string $sql, array $parameters = []): string
    {
        $value = $this->database->fetchOne($sql, $parameters);
        self::assertTrue(is_scalar($value), $sql);

        return (string) $value;
    }
}
