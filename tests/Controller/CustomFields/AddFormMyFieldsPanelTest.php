<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CustomFields;

use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Making and managing the player's own fields from the add-result form (docs/features/custom-fields.md "Making and
 * managing fields from the add form"): the invite line and the dialog in the form, the manage page answered as a
 * panel for the dialog's Turbo Frame, and the block the browser fetches when the dialog closes.
 */
final class AddFormMyFieldsPanelTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const array PANEL = ['HTTP_TURBO_FRAME' => 'my-fields-panel'];

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheDialogInTheFormIsEmptyAndHoldsNoForm(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        self::assertResponseIsSuccessful();

        $area = $crawler->filter('form[name="puzzle_add_form"] [data-testid="own-fields-area"]');
        self::assertCount(1, $area);
        self::assertSame('my-fields-panel', $area->attr('data-controller'));
        self::assertSame('/en/custom-fields', $area->attr('data-my-fields-panel-panel-url-value'));
        self::assertSame('/en/custom-fields/add-form-block', $area->attr('data-my-fields-panel-block-url-value'));

        // A form can not hold a form: the dialog is rendered empty, nothing with a name, nothing that submits
        $dialog = $area->filter('dialog');
        self::assertCount(1, $dialog);
        self::assertCount(0, $dialog->filter('form, input, select, textarea, button[type="submit"], [name]'));
        self::assertCount(1, $dialog->filter('turbo-frame#my-fields-panel'));
        self::assertNull($dialog->filter('turbo-frame')->attr('src'), 'Nothing is loaded until it is opened');

        // Without JavaScript the line is a link to the manage page in a new tab
        $invite = $area->filter('[data-testid="own-fields-invite"] a');
        self::assertSame('/en/custom-fields', $invite->attr('href'));
        self::assertSame('_blank', $invite->attr('target'));
        self::assertStringContainsString('Add your own field', $invite->text());
    }

    public function testThePlayerWithFieldsGetsTheBlockInsideTheAreaAndItsManageLinkOpensTheDialog(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        (new CustomFieldsScenario(self::getContainer()))->field(self::PLAYER, 'Quokka ledger');

        $crawler = $browser->request('GET', '/en/puzzle-add');

        $block = $crawler->filter('[data-testid="own-fields-area"] [data-my-fields-panel-target="block"] [data-testid="add-form-my-fields"]');
        self::assertCount(1, $block);
        self::assertSame('my-fields-panel#open', $block->filter('a[href="/en/custom-fields"]')->attr('data-action'));
        self::assertCount(1, $crawler->filter('[data-testid="own-fields-invite"]'));
    }

    public function testTheEditFormHasNoAreaAsAPageAndInTheModal(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $timeId = (new CustomFieldsScenario(self::getContainer()))->result(PlayerFixture::PLAYER_REGULAR_USER_ID);

        foreach ([[], ['HTTP_TURBO_FRAME' => 'modal-frame']] as $server) {
            $browser->request('GET', '/en/edit-time/' . $timeId, server: $server);

            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('own-fields', (string) $browser->getResponse()->getContent());
        }
    }

    public function testAskedForByTheDialogsFrameTheManagePageAnswersWithItsBodyAlone(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $panel = $browser->request('GET', '/en/custom-fields', server: self::PANEL);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="my-fields-panel">', ltrim($content));
        self::assertStringNotContainsString('<html', $content);
        self::assertStringNotContainsString('<nav', $content);
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));
        self::assertCount(1, $panel->filter('turbo-frame#my-fields-panel form[name="custom_field_form"]'));
        self::assertGreaterThan(0, $panel->filter('[data-testid="ready-made-field"]')->count());

        // Nothing in use yet: no empty list in the panel. The ready-made fields for results come first, the ones for
        // collection puzzles wait behind "More"
        self::assertStringNotContainsString('id="my-fields"', $content);
        self::assertGreaterThan(0, $panel->filter('[data-testid="ready-made-more"] [data-testid="ready-made-field"]')->count());
        self::assertStringNotContainsString('Collection puzzles', $panel->filter('[data-testid="ready-made-more"]')->previousAll()->text());

        // With a field in use, adding still comes before the list
        (new CustomFieldsScenario(self::getContainer()))->field(self::PLAYER, 'Quokka ledger');
        $browser->request('GET', '/en/custom-fields', server: self::PANEL);
        $content = (string) $browser->getResponse()->getContent();
        self::assertLessThan(strpos($content, 'id="my-fields"'), strpos($content, 'data-testid="ready-made-field"'));

        // The page itself is the page it always was
        $page = $browser->request('GET', '/en/custom-fields');
        self::assertCount(1, $page->filter('html [data-testid="custom-fields-page"]'));
        self::assertCount(0, $page->filter('turbo-frame#my-fields-panel'));
        self::assertCount(0, $page->filter('[data-testid="ready-made-more"]'));
    }

    public function testAFieldIsMadeRenamedMovedAndArchivedWithoutLeavingThePanel(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        // Made: the answer is a redirect the frame follows, and the panel says what happened
        $panel = $browser->request('GET', '/en/custom-fields', server: self::PANEL);
        $browser->request('POST', '/en/custom-fields', ['custom_field_form' => [
            '_token' => $panel->filter('input[name="custom_field_form[_token]"]')->attr('value'),
            'name' => 'Quokka ledger',
            'type' => 'text',
            'forResults' => '1',
        ]], server: self::PANEL);
        self::assertResponseRedirects('/en/custom-fields');

        $panel = $this->followInPanel($browser);
        self::assertStringContainsString('added', strtolower($panel->filter('[data-testid="my-fields-panel-flash"]')->text()));
        self::assertStringContainsString('Quokka ledger', $panel->filter('[data-testid="custom-field"]')->text());

        // A ready-made one with one tap
        $browser->submit($panel->filter('[data-testid="ready-made-field"]')->first()->form(), serverParameters: self::PANEL);
        self::assertResponseRedirects('/en/custom-fields');
        $panel = $this->followInPanel($browser);
        self::assertCount(2, $panel->filter('[data-testid="custom-field"]'));

        // Renamed: the edit form opens in the panel and comes back to it
        $edit = $browser->request('GET', $panel->filter('[data-testid="custom-field"] a[href$="/edit"]')->attr('href') ?? '', server: self::PANEL);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<turbo-frame id="my-fields-panel">', ltrim((string) $browser->getResponse()->getContent()));
        self::assertStringNotContainsString('<html', (string) $browser->getResponse()->getContent());

        $form = $edit->filter('form[name="custom_field_form"]')->form();
        $form['custom_field_form[name]'] = 'Wombat ledger';
        $browser->submit($form, serverParameters: self::PANEL);
        self::assertResponseRedirects('/en/custom-fields');
        $panel = $this->followInPanel($browser);
        self::assertStringContainsString('Wombat ledger', $panel->filter('[data-testid="custom-field"]')->text());

        // A refused rename stays in the panel with its error
        $edit = $browser->request('GET', $panel->filter('[data-testid="custom-field"] a[href$="/edit"]')->attr('href') ?? '', server: self::PANEL);
        $form = $edit->filter('form[name="custom_field_form"]')->form();
        $form['custom_field_form[name]'] = '';
        $browser->submit($form, serverParameters: self::PANEL);
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('<turbo-frame id="my-fields-panel">', ltrim((string) $browser->getResponse()->getContent()));

        // Moved and archived
        $browser->submit($panel->filter('[data-testid="custom-field"]')->first()->filter('form[action$="/move"]')->last()->form(), serverParameters: self::PANEL);
        self::assertResponseRedirects();
        $panel = $this->followInPanel($browser);
        self::assertStringContainsString('Wombat ledger', $panel->filter('[data-testid="custom-field"]')->last()->text());

        $browser->submit($panel->filter('[data-testid="custom-field"]')->last()->filter('form[action$="/archive"]')->form(), serverParameters: self::PANEL);
        self::assertResponseRedirects('/en/custom-fields');
        $panel = $this->followInPanel($browser);
        self::assertCount(1, $panel->filter('[data-testid="custom-field"]'));
        self::assertStringContainsString('Wombat ledger', $panel->filter('[data-testid="archived-custom-field"]')->text());
    }

    public function testTheBlockTheBrowserFetchesHoldsTheFieldsAndNeverAValue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        // No fields: an empty answer, and the session knows nothing of fields
        $browser->request('GET', '/en/puzzle-add');
        $browser->request('GET', '/en/custom-fields/add-form-block');
        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Quokka ledger');
        $scenario->field(self::PLAYER, 'Mood', CustomFieldType::Rating);
        $scenario->field(self::PLAYER, 'Shelf only', forResults: false, forCollectionItems: true);
        $scenario->setValues($scenario->result(PlayerFixture::PLAYER_REGULAR_USER_ID), self::PLAYER, [$notes => 'axolotl-whispers']);

        $block = $browser->request('GET', '/en/custom-fields/add-form-block');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringNotContainsString('<html', $content);
        self::assertStringNotContainsString('<form', $content);
        self::assertStringNotContainsString('axolotl-whispers', $content);
        self::assertStringNotContainsString('Shelf only', $content);
        self::assertCount(1, $block->filter('[data-testid="add-form-my-fields"] textarea[name="my_fields[' . $notes . ']"]'));
        self::assertCount(6, $block->filter('[data-testid="add-form-my-fields"] input[type="radio"]'));
        // Shown, not behind the toggle: the player just came from managing them
        self::assertStringNotContainsString('hidden', (string) $block->filter('[data-toggle-target="myFields"]')->attr('class'));

        // ...and the add form agrees at once, although this session remembered "no fields"
        $form = $browser->request('GET', '/en/puzzle-add');
        self::assertCount(1, $form->filter('[data-testid="add-form-my-fields"] textarea'));
    }

    public function testTheBlockIsNotThereForAGuestNorWhileTheFormsFieldsAreSwitchedOff(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/custom-fields/add-form-block');
        self::assertResponseRedirects();

        TestingLogin::asPlayer($browser, self::PLAYER);
        $this->overrideFeatureFlagEnv('CUSTOM_FIELDS_IN_ADD_FORM', false);

        $browser->request('GET', '/en/custom-fields/add-form-block');
        self::assertResponseStatusCodeSame(404);
    }

    private function followInPanel(KernelBrowser $browser): Crawler
    {
        // Like the browser's fetch: the redirect is followed with the frame's header
        $crawler = $browser->request('GET', (string) $browser->getResponse()->headers->get('Location'), server: self::PANEL);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<turbo-frame id="my-fields-panel">', ltrim((string) $browser->getResponse()->getContent()));

        return $crawler;
    }
}
