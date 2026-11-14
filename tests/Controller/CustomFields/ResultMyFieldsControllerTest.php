<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CustomFields;

use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A player's own values on a result: the owner-only block of the result detail and the values form - its own route,
 * form and message, in the modal frame and as a full page (docs/features/custom-fields.md).
 */
final class ResultMyFieldsControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string PLAYER_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string OTHER_PLAYER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'modal-frame'];

    public function testTheFormOpensAsAFullPageWithTheStoredValues(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $breaks = $scenario->field(self::PLAYER, 'Breaks', CustomFieldType::Number);
        $place = $scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        $sorted = $scenario->field(self::PLAYER, 'Sorted first', CustomFieldType::YesNo);
        $day = $scenario->field(self::PLAYER, 'Started on', CustomFieldType::Date);
        $tags = $scenario->readyMade(self::PLAYER, StandardCustomField::MyTags);
        $timeId = $scenario->result(self::PLAYER_USER);
        $scenario->setValues($timeId, self::PLAYER, [$notes => 'edges last', $breaks => '2.5', $place => 'floor', $sorted => 'yes', $day => '2026-03-01', $tags => 'cats, sky']);

        $crawler = $browser->request('GET', '/en/custom-fields/result/' . $timeId);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<html', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));

        $form = $crawler->filter('[data-testid="custom-values-form"]');
        // An explicit action: in the modal frame a form without one posts to the page under the modal
        self::assertSame('/en/custom-fields/result/' . $timeId, $form->attr('action'));
        self::assertSame('edges last', $form->filter('textarea[name="my_fields[' . $notes . ']"]')->text());
        self::assertSame('2.5', $form->filter('input[name="my_fields[' . $breaks . ']"]')->attr('value'));
        self::assertSame('decimal', $form->filter('input[name="my_fields[' . $breaks . ']"]')->attr('inputmode'));
        self::assertSame('floor', $form->filter('select[name="my_fields[' . $place . ']"] option[selected]')->attr('value'));
        self::assertSame('yes', $form->filter('select[name="my_fields[' . $sorted . ']"] option[selected]')->attr('value'));
        self::assertSame('2026-03-01', $form->filter('input[type="date"][name="my_fields[' . $day . ']"]')->attr('value'));
        self::assertSame('cats, sky', $form->filter('input[name="my_fields[' . $tags . ']"]')->attr('value'));
        // The ready-made field is named by the page, the player's own as typed - in the player's order
        self::assertSame(['Notes', 'Breaks', 'Place', 'Sorted first', 'Started on', 'My tags'], $form->filter('label')->each(static fn (Crawler $label): string => trim($label->text())));
    }

    public function testInTheModalFrameTheFormIsTheFrame(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $scenario->field(self::PLAYER, 'Notes');
        $timeId = $scenario->result(self::PLAYER_USER);

        $crawler = $browser->request('GET', '/en/custom-fields/result/' . $timeId, server: self::FRAME);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="modal-frame">', trim($content));
        self::assertStringNotContainsString('<html', $content);
        self::assertSame('/en/custom-fields/result/' . $timeId, $crawler->filter('[data-testid="custom-values-form"]')->attr('action'));
        // The link out of the modal leaves the frame
        self::assertSame('_top', $crawler->filter('a[href="/en/custom-fields"]')->attr('data-turbo-frame'));
    }

    public function testSavedTheAnswerIsTheResultDetailAsAPageAndInTheFrame(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $breaks = $scenario->field(self::PLAYER, 'Breaks', CustomFieldType::Number);
        $timeId = $scenario->result(self::PLAYER_USER);

        $this->save($browser, $timeId, [$notes => 'edges last', $breaks => '3']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/en/result/' . $timeId);
        self::assertSame([$notes => 'edges last', $breaks => 3], $scenario->storedValues($timeId, self::PLAYER));
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Your fields were saved.', $crawler->filter('body')->text());

        // In the frame: the same redirect, which Turbo follows inside the frame - the detail replaces the form
        $this->save($browser, $timeId, [$notes => '', $breaks => '4'], self::FRAME);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/en/result/' . $timeId);
        self::assertSame([$breaks => 4], $scenario->storedValues($timeId, self::PLAYER), 'An input left empty takes the value away');
        $crawler = $browser->request('GET', '/en/result/' . $timeId, server: self::FRAME);
        self::assertStringStartsWith('<turbo-frame id="modal-frame">', trim((string) $browser->getResponse()->getContent()));
        self::assertStringContainsString('Breaks: 4', $crawler->filter('[data-testid="result-my-fields"]')->text());
    }

    public function testAValueThatCanNotBeStoredIsAnsweredWith422AndNothingIsSaved(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $breaks = $scenario->field(self::PLAYER, 'Breaks', CustomFieldType::Number);
        $timeId = $scenario->result(self::PLAYER_USER);
        $scenario->setValues($timeId, self::PLAYER, [$notes => 'kept']);

        foreach ([[], self::FRAME] as $server) {
            $crawler = $this->save($browser, $timeId, [$notes => 'changed', $breaks => 'a few'], $server);

            self::assertResponseStatusCodeSame(422);
            self::assertSame($server !== [], str_starts_with(trim((string) $browser->getResponse()->getContent()), '<turbo-frame id="modal-frame">'));
            $form = $crawler->filter('[data-testid="custom-values-form"]');
            self::assertStringContainsString('Enter a number', $form->filter('[data-custom-field="' . $breaks . '"] .invalid-feedback')->text());
            self::assertCount(0, $form->filter('[data-custom-field="' . $notes . '"] .invalid-feedback'));
            // What was typed is still in the form
            self::assertSame('a few', $form->filter('input[name="my_fields[' . $breaks . ']"]')->attr('value'));
            self::assertSame('changed', $form->filter('textarea[name="my_fields[' . $notes . ']"]')->text());
            self::assertSame([$notes => 'kept'], $scenario->storedValues($timeId, self::PLAYER));
        }
    }

    public function testOnlyWhoeverIsOnTheResultGetsToTheFormAndOnlyToTheirOwnValues(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notesOfPlayer = $scenario->field(self::PLAYER, 'Notes of the tracker');
        $notesOfPartner = $scenario->field(self::OTHER_PLAYER, 'Notes of the partner');
        $solo = $scenario->result(self::PLAYER_USER);
        $pair = $scenario->result(self::PLAYER_USER, ['#player3'], time: '04:00:00');
        $scenario->setValues($pair, self::PLAYER, [$notesOfPlayer => 'the tracker wrote this']);

        // A guest is sent to sign in
        $browser->request('GET', '/en/custom-fields/result/' . $solo);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        TestingLogin::asPlayer($browser, self::OTHER_PLAYER);

        // Somebody else's solo result: nothing there, neither to read nor to write
        $browser->request('GET', '/en/custom-fields/result/' . $solo);
        self::assertResponseStatusCodeSame(404);
        $browser->request('POST', '/en/custom-fields/result/' . $solo, ['my_fields' => [$notesOfPartner => 'x']]);
        self::assertResponseStatusCodeSame(404);

        // The pair partner gets their own form - their field, empty - and nothing of the tracker's
        $crawler = $browser->request('GET', '/en/custom-fields/result/' . $pair);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('the tracker wrote this', $content);
        self::assertStringNotContainsString('Notes of the tracker', $content);
        self::assertStringNotContainsString($notesOfPlayer, $content);
        self::assertSame('', $crawler->filter('textarea[name="my_fields[' . $notesOfPartner . ']"]')->text());

        // Sending the tracker's field id changes nothing of the tracker's: it is not one of the partner's fields
        $this->save($browser, $pair, [$notesOfPartner => 'the partner wrote this', $notesOfPlayer => 'overwritten']);
        self::assertResponseRedirects('/en/result/' . $pair);
        self::assertSame([$notesOfPlayer => 'the tracker wrote this'], $scenario->storedValues($pair, self::PLAYER));
        self::assertSame([$notesOfPartner => 'the partner wrote this'], $scenario->storedValues($pair, self::OTHER_PLAYER));
    }

    public function testTheFormNeedsItsToken(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = $scenario->result(self::PLAYER_USER);

        $browser->request('POST', '/en/custom-fields/result/' . $timeId, ['_token' => 'wrong', 'my_fields' => [$notes => 'x']]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($scenario->storedValues($timeId, self::PLAYER));
    }

    public function testAPlayerWithoutFieldsIsShownTheWayToMakeOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $timeId = (new CustomFieldsScenario(self::getContainer()))->result(self::PLAYER_USER);

        $crawler = $browser->request('GET', '/en/custom-fields/result/' . $timeId);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="custom-values-form"] button[type="submit"]'));
        self::assertCount(1, $crawler->filter('[data-testid="custom-values-form"] a[href="/en/custom-fields"]'));
    }

    public function testTheResultDetailShowsTheOwnerTheirValuesWithTheWayToTheForm(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $sorted = $scenario->field(self::PLAYER, 'Sorted first', CustomFieldType::YesNo);
        $day = $scenario->field(self::PLAYER, 'Started on', CustomFieldType::Date);
        $tags = $scenario->readyMade(self::PLAYER, StandardCustomField::MyTags);
        $filled = $scenario->result(self::PLAYER_USER);
        $empty = $scenario->result(self::PLAYER_USER, time: '04:30:00', daysAgo: 5);
        $scenario->setValues($filled, self::PLAYER, [$notes => '<b>edges</b> last', $sorted => 'no', $day => '2026-03-01', $tags => 'cats, sky']);

        foreach ([[], self::FRAME] as $server) {
            $crawler = $browser->request('GET', '/en/result/' . $filled, server: $server);

            self::assertResponseIsSuccessful();
            $blocks = $crawler->filter('[data-testid="result-my-fields"]');
            // Both attempts of the player on this puzzle: one with values, one waiting for them
            self::assertCount(2, $blocks);

            $block = $crawler->filter('a[href="/en/custom-fields/result/' . $filled . '"]')->closest('[data-testid="result-my-fields"]');
            self::assertNotNull($block);
            self::assertStringContainsString('Notes: <b>edges</b> last', $block->text(), 'Shown as text, never as markup');
            self::assertStringContainsString('Sorted first: No', $block->text());
            self::assertStringContainsString('Started on: 01.03.2026', $block->text());
            self::assertStringContainsString('My tags: cats, sky', $block->text());
            self::assertSame('Edit', trim($block->filter('a')->text()));
            // Opens in the modal - from the full page and from inside the modal alike
            self::assertSame('modal-frame', $block->filter('a')->attr('data-turbo-frame'));

            $waiting = $crawler->filter('a[href="/en/custom-fields/result/' . $empty . '"]')->closest('[data-testid="result-my-fields"]');
            self::assertNotNull($waiting);
            self::assertSame('Fill in', trim($waiting->filter('a')->text()));
        }
    }

    public function testTheResultDetailShowsNothingOfItToAGuestToAnotherPlayerAndToAPlayerWithoutFields(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = $scenario->result(self::PLAYER_USER);
        $scenario->setValues($timeId, self::PLAYER, [$notes => 'edges last']);
        // The other player has fields of their own - on somebody else's result they mean nothing
        $scenario->field(self::OTHER_PLAYER, 'Their notes');

        foreach ([null, self::OTHER_PLAYER] as $viewer) {
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            foreach ([[], self::FRAME] as $server) {
                $crawler = $browser->request('GET', '/en/result/' . $timeId, server: $server);

                self::assertResponseIsSuccessful();
                self::assertCount(0, $crawler->filter('[data-testid="result-my-fields"]'));
                self::assertStringNotContainsString('edges last', (string) $browser->getResponse()->getContent());
                self::assertStringNotContainsString('/custom-fields/result/', (string) $browser->getResponse()->getContent());
            }
        }
    }

    public function testTheResultDetailCostsAPlayerWithoutFieldsNoStatementOnceItIsKnown(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $timeId = (new CustomFieldsScenario(self::getContainer()))->result(self::PLAYER_USER);

        // The first request of the session asks once whether the player has fields...
        $browser->request('GET', '/en/result/' . $timeId);
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        $withoutFields = $this->queryCount($browser);
        self::assertStringNotContainsString('player_custom_field', implode("\n", $this->executedSql($browser)), '...and remembers the answer');

        // A player with fields pays two: their fields and their values on the attempts
        (new CustomFieldsScenario(self::getContainer()))->field(self::PLAYER, 'Notes');
        $browser->request('GET', '/en/custom-fields');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertSame($withoutFields + 2, $this->queryCount($browser));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $server
     */
    private function save(KernelBrowser $browser, string $timeId, array $values, array $server = []): Crawler
    {
        $page = $browser->request('GET', '/en/custom-fields/result/' . $timeId);
        $token = $page->filter('[data-testid="custom-values-form"] input[name="_token"]')->attr('value');

        return $browser->request('POST', '/en/custom-fields/result/' . $timeId, ['_token' => $token, 'my_fields' => $values], server: $server);
    }
}
