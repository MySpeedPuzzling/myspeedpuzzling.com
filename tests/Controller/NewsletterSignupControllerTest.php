<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Services\NewsletterSignupTicket;
use SpeedPuzzling\Web\Tests\TestDouble\TurnstileHttpMock;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The footer signup opens a "Send the confirmation e-mail?" step and mails nothing; only that step's form sends -
 * after the honeypot, the step's minimum time and Turnstile (docs/features/newsletter/README.md "Bots").
 * A guest subscriber row is what the send creates (SubscribeToNewsletterHandler), so its absence = nothing sent.
 */
final class NewsletterSignupControllerTest extends WebTestCase
{
    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    protected function setUp(): void
    {
        TurnstileHttpMock::reset();
    }

    public function testFooterFormOpensTheStepInTheModalAndSendsNothing(): void
    {
        $browser = self::createClient();
        $email = $this->email();

        $browser->request('POST', '/en/newsletter/subscribe', [
            '_token' => 'csrf-token',
            'email' => $email,
        ], [], self::ORIGIN + ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal-frame form[action="/en/newsletter/subscribe/send"]');
        self::assertSelectorExists('form[data-controller="turnstile"][data-turnstile-site-key-value="test-turnstile-site-key"]');
        self::assertInputValueSame('email', $email);
        self::assertNull($this->subscriber($email));
    }

    public function testWithoutJavaScriptTheStepIsAPage(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/newsletter/subscribe', [
            '_token' => 'csrf-token',
            'email' => $this->email(),
        ], [], self::ORIGIN);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Almost there');
        self::assertSelectorNotExists('turbo-frame#modal-frame form');
    }

    public function testFooterHoneypotIsAnsweredLikeASuccess(): void
    {
        $browser = self::createClient();
        $email = $this->email();

        $browser->request('POST', '/en/newsletter/subscribe', [
            '_token' => 'csrf-token',
            'email' => $email,
            'homepage' => 'https://spam.example',
        ], [], self::ORIGIN);

        self::assertResponseRedirects('/en/newsletter/check-your-inbox');
        self::assertNull($this->subscriber($email));
    }

    public function testInvalidAddressShowsTheStepWithTheReason(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/newsletter/subscribe', [
            '_token' => 'csrf-token',
            'email' => 'not-an-address',
        ], [], self::ORIGIN + ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#modal-frame .alert', 'Please enter a valid e-mail address.');
    }

    public function testSendingAfterTheChecksSendsTheConfirmation(): void
    {
        $browser = self::createClient();
        $email = $this->email();

        $this->send($browser, $email, TurnstileHttpMock::PASSING_TOKEN);

        self::assertResponseRedirects('/en/newsletter/check-your-inbox');
        self::assertNotNull($this->subscriber($email));
        self::assertCount(1, TurnstileHttpMock::requestBodies());
    }

    public function testSendingAtOnceShowsTheStepAgain(): void
    {
        $browser = self::createClient();
        $email = $this->email();

        $this->send($browser, $email, TurnstileHttpMock::PASSING_TOKEN, secondsAgo: 0);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'Please check the address and send it once more.');
        self::assertNull($this->subscriber($email));
        // Refused before Turnstile: the person's token is not used up on a ticket problem
        self::assertSame([], TurnstileHttpMock::requestBodies());
    }

    public function testFailedTurnstileShowsTheStepAgain(): void
    {
        $browser = self::createClient();
        $email = $this->email();

        $this->send($browser, $email, 'forged-token');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', "The quick check didn't go through.");

        $this->send($browser, $email, TurnstileHttpMock::WRONG_ACTION_TOKEN);
        self::assertResponseStatusCodeSame(422);

        $this->send($browser, $email, null);
        self::assertResponseStatusCodeSame(422);

        self::assertNull($this->subscriber($email));
    }

    public function testTurnstileNotAnsweringSendsNothing(): void
    {
        $browser = self::createClient();
        $email = $this->email();

        $this->send($browser, $email, TurnstileHttpMock::DOWN_TOKEN);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', "The quick check isn't answering right now.");
        self::assertNull($this->subscriber($email));
    }

    public function testStepHoneypotIsAnsweredLikeASuccess(): void
    {
        $browser = self::createClient();
        $email = $this->email();

        $this->send($browser, $email, TurnstileHttpMock::PASSING_TOKEN, honeypot: 'filled');

        self::assertResponseRedirects('/en/newsletter/check-your-inbox');
        self::assertNull($this->subscriber($email));
        self::assertSame([], TurnstileHttpMock::requestBodies());
    }

    public function testOneAddressGetsAtMostTwoConfirmationsADay(): void
    {
        $browser = self::createClient();
        $email = $this->email();

        $this->send($browser, $email, TurnstileHttpMock::PASSING_TOKEN);
        self::assertResponseRedirects('/en/newsletter/check-your-inbox');
        $this->send($browser, $email, TurnstileHttpMock::PASSING_TOKEN);
        self::assertResponseRedirects('/en/newsletter/check-your-inbox');

        $this->send($browser, $email, TurnstileHttpMock::PASSING_TOKEN);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'Too many attempts');
    }

    private function send(KernelBrowser $browser, string $email, null|string $turnstileToken, int $secondsAgo = 10, string $honeypot = ''): void
    {
        $secret = self::getContainer()->getParameter('kernel.secret');
        $ticket = (new NewsletterSignupTicket($secret, new MockClock(TestClock::now()->modify(sprintf('-%d seconds', $secondsAgo)))))->issue();

        $fields = [
            '_token' => 'csrf-token',
            'email' => $email,
            'ticket' => $ticket,
            'homepage' => $honeypot,
        ];

        if ($turnstileToken !== null) {
            $fields['cf-turnstile-response'] = $turnstileToken;
        }

        $browser->request('POST', '/en/newsletter/subscribe/send', $fields, [], self::ORIGIN);
    }

    private function email(): string
    {
        return 'signup-' . Uuid::uuid7()->toString() . '@example.com';
    }

    private function subscriber(string $email): mixed
    {
        return self::getContainer()->get(NewsletterSubscriberRepository::class)->findByEmail($email);
    }
}
