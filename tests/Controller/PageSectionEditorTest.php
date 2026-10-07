<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PageSectionLimitReached;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Services\PageSectionContentSanitizer;
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
use Symfony\Contracts\Translation\TranslatorInterface;

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

    /**
     * Review 2, A-F8: the rich text editor speaks the page's language - its toolbar is the template's, with a translated
     * title and accessible name on every control, and the link tooltip's texts (CSS content in Quill's theme) come as
     * translated values the stylesheet reads.
     */
    public function testTheRichTextEditorHasNoUntranslatedText(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $crawler = $this->browser->request('GET', '/en/add-page-section?competition=' . self::OWN_EVENT . '&type=rich_text');
        $this->assertResponseIsSuccessful();

        $editor = $crawler->filter('[data-controller="wysiwyg"]');
        foreach (['visit-url' => 'visit_url', 'enter-link' => 'enter_link', 'edit-link' => 'edit_link', 'remove-link' => 'remove_link', 'save-link' => 'save_link'] as $value => $key) {
            self::assertSame($translator->trans('page_sections.editor.' . $key), $editor->attr('data-wysiwyg-' . $value . '-value'));
        }

        $buttons = $crawler->filter('[data-wysiwyg-target="toolbar"] button');
        self::assertCount(9, $buttons);
        foreach ($buttons as $button) {
            self::assertInstanceOf(\DOMElement::class, $button);
            self::assertNotSame('', $button->getAttribute('title'), $button->getAttribute('class'));
            self::assertSame($button->getAttribute('title'), $button->getAttribute('aria-label'));
        }

        // The header picker's options are its labels (Quill reads them as data-label)
        self::assertSame(
            [$translator->trans('page_sections.editor.heading_2'), $translator->trans('page_sections.editor.heading_3'), $translator->trans('page_sections.editor.heading_4'), $translator->trans('page_sections.editor.normal')],
            $crawler->filter('select.ql-header option')->each(static fn ($option): string => $option->text()),
        );
        self::assertNotSame('', $crawler->filter('select.ql-header')->attr('aria-label'));

        // Every English text of the snow theme the editor can show is replaced by the stylesheet
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/styles/_page-sections.scss');
        foreach (['--ql-visit-url', '--ql-enter-link', '--ql-edit-link', '--ql-remove-link', '--ql-save-link'] as $property) {
            self::assertStringContainsString('content: var(' . $property . ')', $styles);
        }
    }

    /**
     * Review 2, A-F9: a page holds at most CompetitionPageSection::MAX_PER_PAGE sections, a section at most
     * PageSectionContentSanitizer::MAX_IMAGES pictures.
     */
    public function testQuotasOfSectionsAndPictures(): void
    {
        $owner = PageSectionOwner::competition(self::OWN_EVENT);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // Pictures: one too many is explained, not stored
        $photos = [];
        for ($i = 0; $i <= PageSectionContentSanitizer::MAX_IMAGES; $i++) {
            $photos[] = ['path' => $owner->uploadDirectory() . Uuid::uuid7()->toString() . '.jpg', 'caption' => ''];
        }
        $url = '/en/add-page-section?competition=' . self::OWN_EVENT . '&type=gallery';
        $crawler = $this->browser->request('GET', $url);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->browser->request('POST', $url, ['_token' => $token, 'title' => 'Photos', 'images' => $photos]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-page-section-errors]', 'at most ' . PageSectionContentSanitizer::MAX_IMAGES . ' pictures');
        self::assertSame(0, $this->sectionCount(self::OWN_EVENT));

        // The sanitizer keeps no more either, whatever reaches the handler
        $galleryId = $this->section($owner, PageSectionType::Gallery, ['images' => $photos]);
        $content = json_decode((string) $this->scalar('SELECT content FROM competition_page_section WHERE id = :id', ['id' => $galleryId]), true);
        self::assertIsArray($content);
        self::assertIsArray($content['images']);
        self::assertCount(PageSectionContentSanitizer::MAX_IMAGES, $content['images']);

        // Sections: up to the cap, then the editor offers no more and the add page refuses
        for ($i = 1; $i < CompetitionPageSection::MAX_PER_PAGE; $i++) {
            $this->section($owner, PageSectionType::RichText, ['html' => '<p>Section ' . $i . '</p>']);
        }
        self::assertSame(CompetitionPageSection::MAX_PER_PAGE, $this->sectionCount(self::OWN_EVENT));

        $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_EVENT);
        self::assertSelectorExists('[data-page-sections-limit-reached]');
        self::assertSelectorNotExists('.dropdown-menu a[href*="/add-page-section"]');

        $this->browser->request('GET', '/en/add-page-section?competition=' . self::OWN_EVENT . '&type=faq');
        $this->assertResponseRedirects('/en/manage-event-page/' . self::OWN_EVENT);

        try {
            $this->section($owner, PageSectionType::RichText, ['html' => '<p>One too many</p>']);
            self::fail('A page holds at most ' . CompetitionPageSection::MAX_PER_PAGE . ' sections');
        } catch (PageSectionLimitReached) {
        }
        self::assertSame(CompetitionPageSection::MAX_PER_PAGE, $this->sectionCount(self::OWN_EVENT));
    }

    /**
     * Review 2, A-F7: an event that is not approved yet publishes none of its sections; its maintainers see why.
     */
    public function testSectionsOfAnEventThatIsNotApprovedAreNotPublished(): void
    {
        $owner = PageSectionOwner::competition(self::OWN_EVENT);
        $this->section($owner, PageSectionType::RichText, ['html' => '<p>Visit our shop</p>'], 'Shop');

        $this->browser->request('GET', '/en/events/unapproved-puzzle-event');
        $this->assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-page-sections]');
        self::assertStringNotContainsString('Visit our shop', (string) $this->browser->getResponse()->getContent());

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_EVENT);
        self::assertSelectorExists('[data-page-sections-not-public]');

        // An approved event's editor says nothing of the kind
        $this->database->executeStatement('UPDATE competition SET approved_at = NOW() WHERE id = :id', ['id' => self::OWN_EVENT]);
        $this->browser->request('GET', '/en/manage-event-page/' . self::OWN_EVENT);
        self::assertSelectorNotExists('[data-page-sections-not-public]');
        $this->browser->request('GET', '/en/events/unapproved-puzzle-event');
        self::assertSelectorTextContains('[data-page-sections]', 'Visit our shop');
    }

    public function testSectionsOfASeriesThatIsNotApprovedAreNotPublished(): void
    {
        $this->section(PageSectionOwner::series(CompetitionSeriesFixture::SERIES_UNAPPROVED), PageSectionType::RichText, ['html' => '<p>League rules</p>'], 'Rules');

        $this->browser->request('GET', '/en/series/pending-puzzle-league');
        $this->assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-page-sections]');

        $this->browser->request('GET', '/en/series/pending-puzzle-league/pending-puzzle-league-1');
        $this->assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-page-sections]');
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
