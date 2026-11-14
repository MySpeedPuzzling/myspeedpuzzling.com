<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CustomFields;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "My fields" - the manage page of a player's own fields (docs/features/custom-fields.md).
 */
final class CustomFieldsPageTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string OTHER_PLAYER = PlayerFixture::PLAYER_WITH_FAVORITES;

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/custom-fields');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocales(): iterable
    {
        yield 'en' => ['en', 'My fields'];
        yield 'cs' => ['cs', 'Moje pole'];
        yield 'de' => ['de', 'Meine Felder'];
        yield 'es' => ['es', 'Mis campos'];
        yield 'fr' => ['fr', 'Mes champs'];
        yield 'ja' => ['ja', 'マイ項目'];
    }

    #[DataProvider('provideLocales')]
    public function testThePageOpensInEveryLanguageAndAReadyMadeFieldIsNamedInIt(string $locale, string $title): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        (new CustomFieldsScenario(self::getContainer()))->readyMade(self::PLAYER, StandardCustomField::WhatToWorkOn);

        $crawler = $browser->request('GET', '/' . $locale . '/custom-fields');

        self::assertResponseIsSuccessful();
        self::assertSame($title, trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));

        $label = self::getContainer()->get('translator')->trans(StandardCustomField::WhatToWorkOn->labelKey(), locale: $locale);
        self::assertSame($label, trim($crawler->filter('[data-testid="custom-field"] .fw-semibold')->text()));
        // No text left as a translation key
        self::assertStringNotContainsString('my_fields.', $crawler->filter('main')->count() > 0 ? $crawler->filter('main')->text() : $crawler->filter('body')->text());
    }

    public function testAnUnknownLanguageIsNoPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $browser->request('GET', '/xx/custom-fields');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnEmptyPageOffersTheReadyMadeFieldsAndTheFormOfAnOwnOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $browser->request('GET', '/en/custom-fields');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="custom-field"]'));
        self::assertCount(count(StandardCustomField::cases()), $crawler->filter('[data-testid="ready-made-field"]'));
        // Each says where it is filled in - a field for collection puzzles is as usable as one for results
        self::assertStringContainsString('Collection puzzles', $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/condition"]')->text());
        self::assertStringNotContainsString('Collection puzzles', $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/what_to_work_on"]')->text());
        self::assertStringContainsString('Results', $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/how_i_liked_it"]')->text());
        self::assertStringContainsString('Collection puzzles', $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/how_i_liked_it"]')->text());
        self::assertStringContainsString('Rating (1-5)', $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/quality"]')->text());
        // Nothing says "soon" any more
        self::assertStringNotContainsString('soon', $crawler->filter('[data-testid="custom-fields-page"]')->text());
        // A rating is a type of an own field too
        self::assertCount(1, $crawler->filter('select[name="custom_field_form[type]"] option[value="rating"]'));
        // The form posts to its own address, whatever page shows it
        self::assertSame('/en/custom-fields', $crawler->filter('form[name="custom_field_form"]')->attr('action'));
        // Phone first: a list of cards, never a table
        self::assertCount(0, $crawler->filter('[data-testid="custom-fields-page"] table'));
    }

    public function testAnOwnFieldIsAddedAndListed(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $this->addOwnField($browser, ['name' => 'Where I puzzled', 'type' => 'choice', 'choices' => "table\nfloor\n", 'forResults' => '1']);

        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();
        $card = $crawler->filter('[data-testid="custom-field"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Where I puzzled', $card->text());
        self::assertStringContainsString('One of a list', $card->text());
        self::assertStringContainsString('table, floor', $card->text());
        self::assertStringContainsString('The field was added.', $crawler->filter('body')->text());
        self::assertStringContainsString('Fields in use (1 of 20)', $crawler->filter('#my-fields')->text());
    }

    public function testAFieldThatCanNotBeAddedIsAnsweredWith422AndTheReason(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $scenario->field(self::PLAYER, 'Mood');

        $crawler = $this->addOwnField($browser, ['name' => '', 'type' => 'text', 'forResults' => '1']);
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->addOwnField($browser, ['name' => 'mood', 'type' => 'text', 'forResults' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You already have a field with this name.', $crawler->filter('form[name="custom_field_form"]')->text());
        // What was typed is still there
        self::assertSame('mood', $crawler->filter('input[name="custom_field_form[name]"]')->attr('value'));

        $crawler = $this->addOwnField($browser, ['name' => 'Place', 'type' => 'choice', 'choices' => '', 'forResults' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Write at least one choice', $crawler->filter('form[name="custom_field_form"]')->text());

        $crawler = $this->addOwnField($browser, ['name' => 'Nowhere', 'type' => 'text']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose where you fill the field in.', $crawler->filter('form[name="custom_field_form"]')->text());

        self::assertSame(1, $scenario->fieldsOf(self::PLAYER));
    }

    public function testANameAndChoicesLikeMarkupAreShownAsText(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $nasty = '<script>alert("x")</script>\'';

        $this->addOwnField($browser, ['name' => $nasty, 'type' => 'choice', 'choices' => $nasty . "\n<b>bold</b>", 'forResults' => '1']);
        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(', $content);
        self::assertStringNotContainsString('<b>bold</b>', $content);
        $card = $crawler->filter('[data-testid="custom-field"]');
        self::assertSame($nasty, trim($card->filter('.fw-semibold')->text()));
        self::assertStringContainsString($nasty . ', <b>bold</b>', $card->text());

        // The edit page holds them as text as well
        $edit = $browser->click($card->filter('a[href$="/edit"]')->link());
        self::assertStringNotContainsString('<script>alert(', (string) $browser->getResponse()->getContent());
        self::assertSame($nasty, $edit->filter('input[name="custom_field_form[name]"]')->attr('value'));
    }

    public function testANameTheDatabaseCouldNotHoldIsRefusedWith422(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        foreach (["Pla\0ce", "Place \xC3\x28"] as $name) {
            $crawler = $this->addOwnField($browser, ['name' => $name, 'type' => 'text', 'forResults' => '1']);

            // Refused with words - by the form itself where it sees it first, by the handler otherwise (the handler's
            // own refusal: CustomFieldHandlersTest)
            self::assertResponseStatusCodeSame(422);
            self::assertMatchesRegularExpression(
                '/characters that can not be saved|does not match the expected UTF-8 charset|is not valid/',
                $crawler->filter('form[name="custom_field_form"]')->text(),
            );
        }

        self::assertSame(0, (new CustomFieldsScenario(self::getContainer()))->fieldsOf(self::PLAYER));
    }

    public function testTheHardCapSaysWhatItIsAndWhatToDo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $database = self::getContainer()->get(Connection::class);

        // Sixty archived fields - written straight into the table, the handlers' own test makes them one by one
        for ($i = 1; $i <= CustomField::MAX_PER_PLAYER; $i++) {
            $database->insert('player_custom_field', [
                'id' => Uuid::uuid7()->toString(),
                'player_id' => self::PLAYER,
                'name' => 'Field ' . $i,
                'type' => 'text',
                'choices' => '[]',
                'for_results' => 'true',
                'for_collection_items' => 'false',
                'position' => $i,
                'created_at' => TestClock::now()->format('Y-m-d H:i:s'),
                'archived_at' => TestClock::now()->format('Y-m-d H:i:s'),
            ]);
        }

        $crawler = $browser->request('GET', '/en/custom-fields');
        self::assertResponseIsSuccessful();
        // Told before trying: not the twenty in use, and what helps
        $notice = $crawler->filter('[data-testid="total-limit"]')->text();
        self::assertStringContainsString('You have 60 fields, the archived ones counted', $notice);
        self::assertStringContainsString('Restore an archived field instead of making a new one.', $notice);
        self::assertNotNull($crawler->filter('form[name="custom_field_form"] button[type="submit"]')->attr('disabled'));
        // Restoring is what still works
        self::assertNull($crawler->filter('[data-testid="archived-custom-field"] button')->first()->attr('disabled'));

        // Tried anyway (a form opened before): refused with the same words
        $crawler = $this->addOwnField($browser, ['name' => 'One too many', 'type' => 'text', 'forResults' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Restore an archived field instead of making a new one.', $crawler->filter('form[name="custom_field_form"]')->text());
    }

    public function testAReadyMadeFieldIsAddedWithOneTapAndIsNoLongerOffered(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $crawler = $browser->request('GET', '/en/custom-fields');

        $browser->submit($crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/my_tags"]')->form());

        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('My tags', $crawler->filter('[data-testid="custom-field"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/my_tags"]'));
        // Its name is the page's, so there is nothing to edit
        self::assertCount(0, $crawler->filter('[data-testid="custom-field"] a[href*="/edit"]'));

        $browser->request('POST', '/en/custom-fields/ready-made/no_such_field', ['_token' => $this->token($crawler)]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFieldsMoveUpAndDown(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $scenario->field(self::PLAYER, 'First');
        $second = $scenario->field(self::PLAYER, 'Second');
        $scenario->field(self::PLAYER, 'Third');

        $crawler = $browser->request('GET', '/en/custom-fields');
        self::assertSame(['First', 'Second', 'Third'], $this->listedNames($crawler));
        // The ends have nowhere to go
        self::assertNotNull($crawler->filter('[data-testid="custom-field"]')->first()->filter('button[title="Move up"]')->attr('disabled'));
        self::assertNotNull($crawler->filter('[data-testid="custom-field"]')->last()->filter('button[title="Move down"]')->attr('disabled'));

        $browser->request('POST', '/en/custom-fields/' . $second . '/move', ['_token' => $this->token($crawler), 'direction' => 'up']);
        self::assertResponseRedirects('/en/custom-fields#my-fields');
        self::assertSame(['Second', 'First', 'Third'], $this->listedNames($browser->request('GET', '/en/custom-fields')));

        $browser->request('POST', '/en/custom-fields/' . $second . '/move', ['_token' => $this->token($crawler), 'direction' => 'down']);
        $browser->request('POST', '/en/custom-fields/' . $second . '/move', ['_token' => $this->token($crawler), 'direction' => 'down']);
        // Past the end: nothing moves
        $browser->request('POST', '/en/custom-fields/' . $second . '/move', ['_token' => $this->token($crawler), 'direction' => 'down']);
        self::assertResponseRedirects();
        self::assertSame(['First', 'Third', 'Second'], $this->listedNames($browser->request('GET', '/en/custom-fields')));
    }

    public function testAFieldIsArchivedAndRestored(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $fieldId = (new CustomFieldsScenario(self::getContainer()))->field(self::PLAYER, 'Mood');
        $crawler = $browser->request('GET', '/en/custom-fields');

        $browser->request('POST', '/en/custom-fields/' . $fieldId . '/archive', ['_token' => $this->token($crawler)]);

        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid="custom-field"]'));
        self::assertStringContainsString('Mood', $crawler->filter('[data-testid="archived-custom-field"]')->text());
        self::assertStringContainsString('What you wrote in it is kept', $crawler->filter('body')->text());

        $browser->submit($crawler->filter('[data-testid="archived-custom-field"] form')->form());

        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();
        self::assertSame(['Mood'], $this->listedNames($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="archived-custom-field"]'));
    }

    public function testAnOwnFieldIsRenamedOnItsEditPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $fieldId = $scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        $scenario->field(self::PLAYER, 'Mood');

        $crawler = $browser->request('GET', '/en/custom-fields/' . $fieldId . '/edit');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="custom_field_form"]');
        self::assertSame('/en/custom-fields/' . $fieldId . '/edit', $form->attr('action'));
        self::assertSame('Place', $form->filter('input[name="custom_field_form[name]"]')->attr('value'));
        self::assertSame("table\nfloor", $form->filter('textarea[name="custom_field_form[choices]"]')->text(normalizeWhitespace: false));
        // The type is told, never asked
        self::assertCount(0, $form->filter('[name="custom_field_form[type]"]'));
        self::assertStringContainsString('One of a list', $form->text());

        // Another field's name: refused, with what was typed
        $crawler = $browser->submit($form->form(['custom_field_form[name]' => 'MOOD']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You already have a field with this name.', $crawler->filter('form[name="custom_field_form"]')->text());

        $browser->submit($crawler->filter('form[name="custom_field_form"]')->form([
            'custom_field_form[name]' => 'Where',
            'custom_field_form[choices]' => "table\ndesk",
        ]));
        self::assertResponseRedirects('/en/custom-fields');
        $card = $browser->followRedirect()->filter('[data-testid="custom-field"]')->first();
        self::assertStringContainsString('Where', $card->text());
        self::assertStringContainsString('table, desk', $card->text());
    }

    public function testSomebodyElsesFieldAndAReadyMadeFieldHaveNoEditPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $theirs = $scenario->field(self::OTHER_PLAYER, 'Theirs');
        $readyMade = $scenario->readyMade(self::PLAYER, StandardCustomField::MyTags);
        $crawler = $browser->request('GET', '/en/custom-fields');

        // Nothing of the other player's is on the page
        self::assertStringNotContainsString('Theirs', (string) $browser->getResponse()->getContent());

        foreach ([$theirs, $readyMade] as $fieldId) {
            $browser->request('GET', '/en/custom-fields/' . $fieldId . '/edit');
            self::assertResponseStatusCodeSame(404);
        }

        $browser->request('POST', '/en/custom-fields/' . $theirs . '/archive', ['_token' => $this->token($crawler)]);
        self::assertResponseStatusCodeSame(404);
        $browser->request('POST', '/en/custom-fields/' . $theirs . '/move', ['_token' => $this->token($crawler), 'direction' => 'up']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(1, $scenario->fieldsOf(self::OTHER_PLAYER));
    }

    public function testEveryButtonOfThePageNeedsItsToken(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $fieldId = $scenario->field(self::PLAYER, 'Mood');

        foreach (['/en/custom-fields/ready-made/my_tags', '/en/custom-fields/' . $fieldId . '/archive', '/en/custom-fields/' . $fieldId . '/move'] as $url) {
            $browser->request('POST', $url, ['_token' => 'wrong']);
            self::assertResponseStatusCodeSame(403, $url);
        }

        self::assertSame(1, $scenario->fieldsOf(self::PLAYER));
    }

    public function testTheProfileSettingsLinkToThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        self::assertSame('/en/custom-fields', $crawler->filter('#custom-fields a.btn')->attr('href'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function addOwnField(KernelBrowser $browser, array $fields): Crawler
    {
        $crawler = $browser->request('GET', '/en/custom-fields');
        $token = $crawler->filter('input[name="custom_field_form[_token]"]')->attr('value');

        return $browser->request('POST', '/en/custom-fields', ['custom_field_form' => [...$fields, '_token' => $token]]);
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('form[action*="/custom-fields/"] input[name="_token"]')->first()->attr('value');
    }

    /**
     * @return list<string>
     */
    private function listedNames(Crawler $page): array
    {
        return $page->filter('[data-testid="custom-field"] .fw-semibold')->each(static fn (Crawler $name): string => trim($name->text()));
    }
}
