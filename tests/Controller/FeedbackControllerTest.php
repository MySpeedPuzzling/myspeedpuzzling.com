<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\FormData\FeedbackFormData;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Mime\Email;

/**
 * The Help sheet's feedback box (docs/features/navigation README §4.5, implementation plan §7, HELP-01b): the sheet is
 * the dynamic modal's content and the form posts into it - the errors (422) and the sent state (200) come back in the
 * frame, so the sheet never leaves the page. The `help` page (no JS, the app) posts the same form: Post/Redirect/Get.
 * GET `feedback` (old links) answers the sheet for a frame request and redirects to `help` otherwise. The e-mail names
 * the page by its absolute URL, rebuilt from the validated context.
 */
final class FeedbackControllerTest extends WebTestCase
{
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'modal-frame'];

    public function testTheSheetCarriesTheFeedbackBox(): void
    {
        $browser = $this->signedIn();

        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode('/en/faq'), server: self::FRAME);

        self::assertResponseIsSuccessful();
        $root = $crawler->filter('turbo-frame#modal-frame > [data-help-sheet]');
        self::assertCount(1, $root);

        // The content root: a phone sheet with Back (GEST-01), the swipe (GEST-02) and the help sheet's own controller
        self::assertSame('', $root->attr('data-modal-size'));
        self::assertNotNull($root->attr('data-modal-sheet'));
        self::assertNotNull($root->attr('data-modal-scrollable'));
        self::assertNotNull($root->attr('data-modal-history'));
        self::assertSame('bottom-sheet help-sheet', $root->attr('data-controller'));
        self::assertNull($root->attr('data-help-sheet-sent'));
        // A send answered by something else than this sheet (the session ended: the firewall sends the POST to sign in)
        // leads to the help page with the same page attached, as a full visit (dynamic_modal_controller.js, HELP-05 (d))
        self::assertSame('/en/help?url=/en/faq', $root->attr('data-modal-fallback-url'));
        self::assertCount(1, $root->filter('[data-bottom-sheet-target="handle"] .bottom-sheet-handle'));
        self::assertCount(1, $root->filter('.modal-body[data-bottom-sheet-target="body"]'));
        self::assertCount(1, $root->filter('.modal-title[data-bottom-sheet-target="heading"]'));

        // The form posts into the sheet itself, with an explicit action (CLAUDE.md, Turbo)
        $form = $root->filter('form[name="feedback_form"]');
        self::assertCount(1, $form);
        self::assertSame('/en/feedback', $form->attr('action'));
        self::assertSame('modal-frame', $form->attr('data-turbo-frame'));
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame('/en/faq', $form->filter('input[name="feedback_form[url]"]')->attr('value'));

        $message = $form->filter('textarea[name="feedback_form[message]"]');
        self::assertSame('message', $message->attr('data-help-sheet-target'));
        self::assertSame((string) FeedbackFormData::MESSAGE_MAX_LENGTH, $message->attr('maxlength'));
        self::assertSame('An idea, a bug, something you like or don\'t like - anything goes.', $message->attr('placeholder'));
        self::assertSame('What would you like to tell us?', trim($form->filter('label[for="feedback_form_message"]')->text()));
        self::assertStringContainsString('We\'ll attach a link to the page you are writing from.', $form->text());
        // The attached page is shown by its path, no guessed title (HELP-02)
        self::assertSame('/en/faq', trim($form->filter('.help-feedback-page')->text()));
        self::assertSame('Send', trim($form->filter('button[type="submit"]')->text()));
        self::assertSame('help/help/send_feedback', $form->filter('button[type="submit"]')->attr('data-nav'));
    }

    public function testWithoutAPageNothingIsSaidToBeAttached(): void
    {
        $browser = $this->signedIn();

        $crawler = $browser->request('GET', '/en/help', server: self::FRAME);

        $form = $crawler->filter('form[name="feedback_form"]');
        self::assertSame('', (string) $form->filter('input[name="feedback_form[url]"]')->attr('value'));
        self::assertStringNotContainsString('We\'ll attach a link', $form->text());
        self::assertCount(0, $form->filter('.help-feedback-page'));
    }

    /**
     * No page attached (the bare help page): the e-mail says so (HELP-02)
     */
    public function testWithoutAPageTheMailSaysNoPage(): void
    {
        $browser = $this->signedIn();
        $form = $this->sheetForm($browser, '');

        $browser->submit($form, ['feedback_form[message]' => 'Sent from nowhere in particular.'], self::FRAME);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('<b>URL:</b> no page<br>', (string) $this->onlyFeedbackMail()->getHtmlBody());
    }

    public function testASendFromTheSheetAnswersTheSentStateInPlace(): void
    {
        $browser = $this->signedIn();
        $page = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '?tab=times';
        $form = $this->sheetForm($browser, $page);

        $crawler = $browser->submit($form, ['feedback_form[message]' => 'The chart is upside down.'], self::FRAME);

        // A frame answer: 200 is right here (frame answers are exempt from the full-page rule)
        self::assertResponseStatusCodeSame(200);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="modal-frame">', trim($content));
        self::assertStringNotContainsString('<html', $content);

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);

        $root = $crawler->filter('turbo-frame#modal-frame > [data-help-sheet]');
        self::assertNotNull($root->attr('data-help-sheet-sent'), 'help_sheet_controller.js clears the draft and releases the modal on it');
        self::assertNotNull($root->attr('data-modal-history'));
        self::assertSame('bottom-sheet help-sheet', $root->attr('data-controller'));

        // The confirmation, Close and "Send another" - an empty form behind it, the page still attached
        $sent = $root->filter('[data-help-sheet-target="sent"]');
        self::assertSame('status', $sent->attr('role'));
        self::assertStringContainsString('Thank you!', $sent->text());
        self::assertStringContainsString('We have received your message and will read it soon.', $sent->text());
        self::assertSame(['help/help/sent_close', 'help/help/send_another'], $sent->filter('button')->each(static fn (Crawler $button): string => (string) $button->attr('data-nav')));
        self::assertSame('modal', $sent->filter('button[data-nav="help/help/sent_close"]')->attr('data-bs-dismiss'));
        self::assertSame('help-sheet#sendAnother', $sent->filter('button[data-nav="help/help/send_another"]')->attr('data-action'));

        $box = $root->filter('[data-help-sheet-target="box"]');
        self::assertNotNull($box->attr('hidden'));
        self::assertSame('', $box->filter('textarea[name="feedback_form[message]"]')->text());
        self::assertSame($page, $box->filter('input[name="feedback_form[url]"]')->attr('value'));

        // The other items are unchanged (HELP-02: a puzzle page puts its correction first)
        self::assertSame(
            ['feedback', 'suggest_correction', 'feature_requests', 'faq', 'getting_started', 'progression', 'where_did_it_go', 'language', 'reload'],
            $root->filter('[data-help-item]')->each(static fn (Crawler $item): string => (string) $item->attr('data-help-item')),
        );

        // The e-mail names the page by its absolute URL
        $email = $this->onlyFeedbackMail();
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('http://localhost' . htmlspecialchars($page), $html);
        self::assertStringContainsString('The chart is upside down.', $html);
        self::assertSame('MySpeedPuzzling Feedback', $email->getSubject());
    }

    /**
     * The page is matched as a GET from the POST: a GET-only page (the `menu` page) is still named in the e-mail
     */
    public function testAGetOnlyPageIsStillAttached(): void
    {
        $browser = $this->signedIn();
        $form = $this->sheetForm($browser, '/en/menu');

        $browser->submit($form, ['feedback_form[message]' => 'From the menu page.'], self::FRAME);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('<b>URL:</b> http://localhost/en/menu<br>', (string) $this->onlyFeedbackMail()->getHtmlBody());
    }

    public function testAnEmptyMessageComesBackInTheSheet(): void
    {
        $browser = $this->signedIn();
        $form = $this->sheetForm($browser, '/en/faq');

        $crawler = $browser->submit($form, ['feedback_form[message]' => '   '], self::FRAME);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('<turbo-frame id="modal-frame">', trim((string) $browser->getResponse()->getContent()));
        $root = $crawler->filter('turbo-frame#modal-frame > [data-help-sheet]');
        self::assertNull($root->attr('data-help-sheet-sent'));
        self::assertStringContainsString('Please write a message.', $root->filter('form[name="feedback_form"]')->text());
        // The page stays attached for the next try
        self::assertSame('/en/faq', $root->filter('input[name="feedback_form[url]"]')->attr('value'));
        self::assertCount(0, self::getMailerMessages());
    }

    public function testATooLongMessageComesBackInTheSheet(): void
    {
        $browser = $this->signedIn();
        $form = $this->sheetForm($browser, '/en/faq');
        $message = str_repeat('a', FeedbackFormData::MESSAGE_MAX_LENGTH + 1);

        $crawler = $browser->submit($form, ['feedback_form[message]' => $message], self::FRAME);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Your message can be at most 5000 characters long.', $crawler->filter('form[name="feedback_form"]')->text());
        // What was typed is kept
        self::assertSame($message, $crawler->filter('textarea[name="feedback_form[message]"]')->text());
        self::assertCount(0, self::getMailerMessages());
    }

    public function testASendFromThePageRedirectsBackToThePageItIsAbout(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode('/en/faq'));
        $form = $crawler->filter('main form[name="feedback_form"]');

        // A plain form on the page (no frame target)
        self::assertNull($form->attr('data-turbo-frame'));
        self::assertSame('/en/feedback', $form->attr('action'));

        $browser->submit($form->form(), ['feedback_form[message]' => 'Sent without JavaScript.']);

        self::assertResponseRedirects('/en/faq');
        // Read before the next request (the mailer log belongs to one request)
        $html = (string) $this->onlyFeedbackMail()->getHtmlBody();
        self::assertStringContainsString('http://localhost/en/faq', $html);
        self::assertStringContainsString('Sent without JavaScript.', $html);

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('We have received your feedback and highly appreciate it!', $crawler->filter('body')->text());
    }

    public function testASendFromThePageWithoutAPageRedirectsToHelp(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', '/en/help');

        $browser->submit($crawler->filter('main form[name="feedback_form"]')->form(), [
            'feedback_form[message]' => 'Hello',
            // Tampered: never a redirect target
            'feedback_form[url]' => 'https://evil.example/phish',
        ]);

        self::assertResponseRedirects('/en/help');
        $html = (string) $this->onlyFeedbackMail()->getHtmlBody();
        self::assertStringContainsString('unverified: https://evil.example/phish', $html);
    }

    public function testAnInvalidSendFromThePageAnswers422(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode('/en/faq'));

        $crawler = $browser->submit($crawler->filter('main form[name="feedback_form"]')->form(), ['feedback_form[message]' => '']);

        // A full-page POST never answers 200 (CLAUDE.md, Turbo)
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('html header'));
        self::assertStringContainsString('Please write a message.', $crawler->filter('main form[name="feedback_form"]')->text());
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * Old links (today's top bar / footer Feedback, bookmarks): the sheet for a frame request, else `help`, with the page
     */
    public function testGetFeedbackLeadsToHelp(): void
    {
        $browser = $this->signedIn();

        $browser->request('GET', '/en/feedback');
        self::assertResponseRedirects('/en/help');

        $browser->request('GET', '/en/feedback?url=' . rawurlencode('/en/faq'));
        self::assertResponseRedirects('/en/help?url=/en/faq');

        // Today's links carry the absolute URL of the page
        $browser->request('GET', '/en/feedback?url=' . rawurlencode('http://localhost/en/faq'));
        self::assertResponseRedirects('/en/help?url=/en/faq');

        $browser->request('GET', '/en/feedback?url=' . rawurlencode('https://evil.example/phish'));
        self::assertResponseRedirects('/en/help');

        $crawler = $browser->request('GET', '/en/feedback?url=' . rawurlencode('http://localhost/en/faq'), server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<turbo-frame id="modal-frame">', trim((string) $browser->getResponse()->getContent()));
        self::assertSame('/en/faq', $crawler->filter('turbo-frame#modal-frame > [data-help-sheet] input[name="feedback_form[url]"]')->attr('value'));
        self::assertContains('Turbo-Frame', $browser->getResponse()->getVary());
    }

    public function testGuestsAreAskedToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/feedback');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    /**
     * The feedback form of the sheet opened on $page
     */
    private function sheetForm(KernelBrowser $browser, string $page): Form
    {
        $crawler = $browser->request('GET', $page === '' ? '/en/help' : '/en/help?url=' . rawurlencode($page), server: self::FRAME);
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[name="feedback_form"]')->form();
    }

    private function onlyFeedbackMail(): Email
    {
        $messages = [];

        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && $message->getSubject() === 'MySpeedPuzzling Feedback') {
                $messages[] = $message;
            }
        }

        self::assertCount(1, $messages);

        return $messages[0];
    }
}
