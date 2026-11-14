<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CustomFields;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\EditCustomField;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

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

    public function testSavedAsAFullPageTheAnswerIsTheResultWithAFlash(): void
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

        $this->save($browser, $timeId, [$notes => '', $breaks => '4']);
        self::assertSame([$breaks => 4], $scenario->storedValues($timeId, self::PLAYER), 'An input left empty takes the value away');
    }

    /**
     * Opened from the full-page result detail the form is a modal over that page: saved, the modal closes and the
     * page under it refreshes - never the result detail rendered into the modal on top of itself.
     */
    public function testSavedInTheModalOverAPageTheModalClosesAndThePageRefreshes(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = $scenario->result(self::PLAYER_USER);

        // The link of the full page says where it is
        $page = $browser->request('GET', '/en/result/' . $timeId);
        $link = $page->filter('[data-testid="result-my-fields"] a');
        self::assertSame('/en/custom-fields/result/' . $timeId . '?context=page', $link->attr('href'));
        self::assertSame('modal-frame', $link->attr('data-turbo-frame'));

        $form = $browser->request('GET', (string) $link->attr('href'), server: self::FRAME)->filter('[data-testid="custom-values-form"]');
        self::assertSame('page', $form->filter('input[name="context"]')->attr('value'));
        // Cancelling only closes the modal - the result is on the page under it
        self::assertCount(1, $form->filter('button[data-bs-dismiss="modal"]'));
        self::assertCount(0, $form->filter('a[href="/en/result/' . $timeId . '"]'));

        // Turbo sends a frame's form with the frame header and asks for a stream
        $browser->request('POST', '/en/custom-fields/result/' . $timeId, [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'context' => 'page',
            'my_fields' => [$notes => 'edges last'],
        ], server: [...self::FRAME, 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $browser->getResponse()->headers->get('Content-Type'));
        $stream = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="modal-frame">', $stream, 'The modal closes');
        self::assertStringContainsString('<turbo-stream action="refresh">', $stream, 'The page under it refreshes');
        self::assertStringNotContainsString('<turbo-frame', $stream, 'Nothing is rendered into the modal');
        self::assertSame([$notes => 'edges last'], $scenario->storedValues($timeId, self::PLAYER));

        // The refreshed page shows the values and says so
        $refreshed = $browser->request('GET', '/en/result/' . $timeId);
        self::assertStringContainsString('Notes: edges last', $refreshed->filter('[data-testid="result-my-fields"]')->text());
        self::assertStringContainsString('Your fields were saved.', $refreshed->filter('body')->text());

        // A full-page form submission also asks for a stream - without the frame header it gets the redirect
        $this->save($browser, $timeId, [$notes => 'again'], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);
        self::assertResponseRedirects('/en/result/' . $timeId);
    }

    /**
     * Opened from a result detail that is itself in the modal (from a list): saved, the detail comes back into the
     * modal and its block shows the new values.
     */
    public function testSavedInsideTheResultDetailsOwnModalTheDetailComesBackWithTheNewValues(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $breaks = $scenario->field(self::PLAYER, 'Breaks', CustomFieldType::Number);
        $timeId = $scenario->result(self::PLAYER_USER);

        $detail = $browser->request('GET', '/en/result/' . $timeId, server: self::FRAME);
        $link = $detail->filter('[data-testid="result-my-fields"] a');
        self::assertSame('/en/custom-fields/result/' . $timeId . '?context=modal', $link->attr('href'));

        $form = $browser->request('GET', (string) $link->attr('href'), server: self::FRAME)->filter('[data-testid="custom-values-form"]');
        self::assertSame('modal', $form->filter('input[name="context"]')->attr('value'));
        // Cancelling goes back to the detail, inside the frame
        self::assertCount(1, $form->filter('a.btn-secondary[href="/en/result/' . $timeId . '"]'));
        self::assertCount(0, $form->filter('button[data-bs-dismiss="modal"]'));

        $browser->request('POST', '/en/custom-fields/result/' . $timeId, [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'context' => 'modal',
            'my_fields' => [$breaks => '4'],
        ], server: [...self::FRAME, 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        // A redirect Turbo follows inside the frame
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/en/result/' . $timeId);
        self::assertSame([$breaks => 4], $scenario->storedValues($timeId, self::PLAYER));

        $crawler = $browser->request('GET', '/en/result/' . $timeId, server: self::FRAME);
        self::assertStringStartsWith('<turbo-frame id="modal-frame">', trim((string) $browser->getResponse()->getContent()));
        self::assertStringContainsString('Breaks: 4', $crawler->filter('[data-testid="result-my-fields"]')->text());
        // No flash waits for the next full page
        self::assertStringNotContainsString('Your fields were saved.', $browser->request('GET', '/en/custom-fields')->filter('body')->text());
    }

    public function testReSavingTheFormAsItOpenedChangesNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $number = $scenario->field(self::PLAYER, 'Ratio', CustomFieldType::Number);
        $place = $scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $tags = $scenario->field(self::PLAYER, 'Labels', CustomFieldType::Tags);
        $sorted = $scenario->field(self::PLAYER, 'Sorted first', CustomFieldType::YesNo);
        $timeId = $scenario->result(self::PLAYER_USER);
        // A float whose own input form would be asked about today ("1.125"), and a choice the field no longer has
        $scenario->setValues($timeId, self::PLAYER, [$number => '1,1250', $place => 'floor', $notes => "one\ntwo", $tags => 'cats, sky', $sorted => 'no']);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new EditCustomField($place, self::PLAYER, 'Place', ['table'], true, false));
        $before = $this->valuesRow($timeId);

        $page = $browser->request('GET', '/en/custom-fields/result/' . $timeId);
        $form = $page->filter('[data-testid="custom-values-form"]');
        self::assertSame('1.125', $form->filter('input[name="my_fields[' . $number . ']"]')->attr('value'));
        // The removed choice is still this result's value - offered for it, selected
        self::assertSame('floor', $form->filter('select[name="my_fields[' . $place . ']"] option[selected]')->attr('value'));

        // Exactly what the form holds, sent back
        $browser->submit($form->form());

        self::assertResponseRedirects('/en/result/' . $timeId);
        self::assertSame($before, $this->valuesRow($timeId), 'Nothing changed - not even the row\'s time');
    }

    public function testChoicesThatLookAlikeAreNeverSelectedTogether(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $choice = $scenario->field(self::PLAYER, 'Level', CustomFieldType::Choice, ['1', '1.0', '01', '7', '07']);
        $timeId = $scenario->result(self::PLAYER_USER);

        foreach (['1', '1.0', '07'] as $stored) {
            $scenario->setValues($timeId, self::PLAYER, [$choice => $stored]);

            $selected = $browser->request('GET', '/en/custom-fields/result/' . $timeId)->filter('select[name="my_fields[' . $choice . ']"] option[selected]');

            self::assertCount(1, $selected, $stored);
            self::assertSame($stored, $selected->attr('value'));
        }

        // The same for yes / no
        $yesNo = $scenario->field(self::PLAYER, 'Sorted first', CustomFieldType::YesNo);
        $scenario->setValues($timeId, self::PLAYER, [$yesNo => 'no']);
        $selected = $browser->request('GET', '/en/custom-fields/result/' . $timeId)->filter('select[name="my_fields[' . $yesNo . ']"] option[selected]');
        self::assertCount(1, $selected);
        self::assertSame('no', $selected->attr('value'));
    }

    public function testAValueTheDatabaseCouldNotHoldIsAnsweredWith422LikeAnyWrongValue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $number = $scenario->field(self::PLAYER, 'Pieces left', CustomFieldType::Number);
        $timeId = $scenario->result(self::PLAYER_USER);

        foreach (["nul \0 byte", "broken \xC3\x28 utf-8"] as $value) {
            $crawler = $this->save($browser, $timeId, [$notes => $value]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('This value can not be saved.', $crawler->filter('[data-custom-field="' . $notes . '"] .invalid-feedback')->text());
            self::assertNull($scenario->storedValues($timeId, self::PLAYER));
        }

        // A number that can be read two ways is asked about, not guessed
        $crawler = $this->save($browser, $timeId, [$number => '1,250']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Write it without the separator (1250) or as a fraction (1.25)', $crawler->filter('[data-custom-field="' . $number . '"] .invalid-feedback')->text());

        // The page works on: the next save goes through
        $this->save($browser, $timeId, [$notes => 'fine', $number => '1250']);
        self::assertResponseRedirects('/en/result/' . $timeId);
        self::assertSame([$notes => 'fine', $number => 1250], $scenario->storedValues($timeId, self::PLAYER));
    }

    public function testNamesAndValuesAreShownAsTextNeverAsMarkup(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $nasty = '<script>alert("x")</script>\'';
        $field = $scenario->field(self::PLAYER, $nasty);
        $choice = $scenario->field(self::PLAYER, 'Choice ' . $nasty, CustomFieldType::Choice, [$nasty]);
        $tags = $scenario->field(self::PLAYER, 'Labels', CustomFieldType::Tags);
        $timeId = $scenario->result(self::PLAYER_USER);
        $scenario->setValues($timeId, self::PLAYER, [$field => $nasty, $choice => $nasty, $tags => $nasty]);

        foreach (['/en/result/' . $timeId, '/en/custom-fields/result/' . $timeId, '/en/custom-fields', '/en/puzzle-add'] as $url) {
            foreach ([[], self::FRAME] as $server) {
                $crawler = $browser->request('GET', $url, server: $server);

                self::assertResponseIsSuccessful($url);
                $content = (string) $browser->getResponse()->getContent();
                self::assertStringNotContainsString('<script>alert(', $content, $url);
                self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&#039;', $content, $url);
            }
        }

        // As text it is all there
        $block = $browser->request('GET', '/en/result/' . $timeId)->filter('[data-testid="result-my-fields"]')->text();
        self::assertStringContainsString($nasty . ': ' . $nasty, $block);
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
        $scenario->setValues($filled, self::PLAYER, [$notes => "<b>edges</b>\nlast", $sorted => 'no', $day => '2026-03-01', $tags => 'cats, sky']);

        foreach ([[], self::FRAME] as $server) {
            $crawler = $browser->request('GET', '/en/result/' . $filled, server: $server);

            self::assertResponseIsSuccessful();
            $blocks = $crawler->filter('[data-testid="result-my-fields"]');
            // Both attempts of the player on this puzzle: one with values, one waiting for them
            self::assertCount(2, $blocks);

            $block = $crawler->filter('a[href^="/en/custom-fields/result/' . $filled . '?"]')->closest('[data-testid="result-my-fields"]');
            self::assertNotNull($block);
            self::assertStringContainsString('Notes: <b>edges</b> last', $block->text(), 'Shown as text, never as markup');
            self::assertStringContainsString('Sorted first: No', $block->text());
            // In the page's language, like the app's other dates
            self::assertStringContainsString('Started on: 1 Mar 2026', $block->text());
            self::assertStringContainsString('My tags: cats, sky', $block->text());
            // The text keeps its line break
            $note = $block->filter('span[style*="white-space: pre-line"]');
            self::assertCount(1, $note);
            self::assertStringContainsString("&lt;b&gt;edges&lt;/b&gt;\nlast", $note->html());
            // A button with a 44 px target - opens in the modal, from the full page and from inside the modal alike
            $button = $block->filter('a');
            self::assertSame('Edit', trim($button->text()));
            self::assertStringContainsString('btn', (string) $button->attr('class'));
            self::assertStringContainsString('min-height: 44px', (string) $button->attr('style'));
            self::assertStringContainsString('min-width: 44px', (string) $button->attr('style'));
            self::assertSame('modal-frame', $button->attr('data-turbo-frame'));
            self::assertStringEndsWith($server === [] ? '?context=page' : '?context=modal', (string) $button->attr('href'));

            $waiting = $crawler->filter('a[href^="/en/custom-fields/result/' . $empty . '?"]')->closest('[data-testid="result-my-fields"]');
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

    public function testTheDateOfAFieldIsWrittenInThePagesLanguage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $day = $scenario->field(self::PLAYER, 'Started on', CustomFieldType::Date);
        $timeId = $scenario->result(self::PLAYER_USER);
        $scenario->setValues($timeId, self::PLAYER, [$day => '2026-03-01']);

        foreach (['/en/result/' => '1 Mar 2026', '/vysledek/' => '1. 3. 2026', '/de/ergebnis/' => '1. März 2026', '/ja/結果/' => '2026年3月1日'] as $path => $date) {
            $block = $browser->request('GET', $path . $timeId)->filter('[data-testid="result-my-fields"]');

            self::assertResponseIsSuccessful($path);
            self::assertStringContainsString($date, $block->text(), $path);
        }
    }

    public function testARatingIsRadiosInTheFormAndStarsOnTheResult(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $liked = $scenario->readyMade(self::PLAYER, StandardCustomField::HowILikedIt);
        $felt = $scenario->readyMade(self::PLAYER, StandardCustomField::HowHardItFelt);
        $timeId = $scenario->result(self::PLAYER_USER);
        $scenario->setValues($timeId, self::PLAYER, [$liked => '4']);

        // The result: stars for the eye, "4 of 5" for a screen reader; a rating not given is not there
        $block = $browser->request('GET', '/en/result/' . $timeId)->filter('[data-testid="result-my-fields"]');
        self::assertStringContainsString('How I liked it:', $block->text());
        self::assertSame('★★★★☆', $block->filter('[role="img"]')->text());
        self::assertSame('4 of 5', $block->filter('[role="img"]')->attr('aria-label'));
        self::assertStringNotContainsString('How hard it felt', $block->text());

        // The form: five radios and "No rating" per field, the stored one checked - nothing in it needs JavaScript
        $form = $browser->request('GET', '/en/custom-fields/result/' . $timeId)->filter('[data-testid="custom-values-form"]');
        $checked = static fn (string $fieldId): array => $form->filter('input[type="radio"][name="my_fields[' . $fieldId . ']"][checked]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value'));
        self::assertCount(6, $form->filter('input[type="radio"][name="my_fields[' . $liked . ']"]'));
        self::assertSame(['4'], $checked($liked));
        self::assertSame([''], $checked($felt));
        self::assertCount(0, $form->filter('[data-controller], [data-action]'), 'No JavaScript behaviour in the form');

        // Another star, and a rating for the other field
        $this->save($browser, $timeId, [$liked => '2', $felt => '5']);
        self::assertResponseRedirects('/en/result/' . $timeId);
        self::assertSame([$liked => 2, $felt => 5], $scenario->storedValues($timeId, self::PLAYER));

        // "No rating" takes one away; a group sent without any radio keeps what it has
        $this->save($browser, $timeId, [$liked => '']);
        self::assertResponseRedirects('/en/result/' . $timeId);
        self::assertSame([$felt => 5], $scenario->storedValues($timeId, self::PLAYER));

        // No rating at all is a refusal at the field
        $refused = $this->save($browser, $timeId, [$liked => '7', $felt => '5']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('1 to 5', $refused->filter('[data-custom-field="' . $liked . '"] .invalid-feedback')->text());
        self::assertSame([$felt => 5], $scenario->storedValues($timeId, self::PLAYER));
    }

    /**
     * @return array<string, mixed>
     */
    private function valuesRow(string $timeId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT id, custom_values::text AS stored, updated_at FROM solving_time_custom_values WHERE solving_time_id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row);

        return $row;
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
