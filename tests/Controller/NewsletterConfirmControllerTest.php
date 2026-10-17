<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Message\SubscribeToNewsletter;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SpeedPuzzling\Web\Value\NewsletterSubscriberStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Mail security scanners open every link of an incoming e-mail - the confirmation link only shows a button,
 * the button subscribes.
 */
final class NewsletterConfirmControllerTest extends WebTestCase
{
    private const string EMAIL = 'confirm-by-button@example.com';

    public function testOpeningTheLinkConfirmsNothing(): void
    {
        $browser = self::createClient();
        $token = $this->confirmToken();

        $browser->request('GET', '/en/newsletter/confirm/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Confirm your subscription');
        self::assertSelectorTextContains('main', self::EMAIL);
        self::assertSame(NewsletterSubscriberStatus::Pending, $this->subscriberStatus());
    }

    public function testTheButtonConfirmsAndShowsTheResult(): void
    {
        $browser = self::createClient();
        $token = $this->confirmToken();

        $crawler = $browser->request('GET', '/en/newsletter/confirm/' . $token);
        $browser->submit($crawler->selectButton('Yes, subscribe me')->form(), [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        self::assertResponseRedirects('/en/newsletter/confirm/' . $token . '/done');
        self::assertSame(NewsletterSubscriberStatus::Confirmed, $this->subscriberStatus());

        $browser->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "You're in!");
    }

    public function testTheButtonWithoutValidCsrfConfirmsNothing(): void
    {
        $browser = self::createClient();
        $token = $this->confirmToken();

        $browser->request('POST', '/en/newsletter/confirm/' . $token . '/confirm', ['_token' => 'forged']);

        self::assertResponseRedirects('/en/newsletter/confirm/' . $token);
        self::assertSame(NewsletterSubscriberStatus::Pending, $this->subscriberStatus());
    }

    public function testGarbageTokenShowsTheInvalidPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/newsletter/confirm/not-a-token');

        self::assertResponseStatusCodeSame(404);
    }

    private function confirmToken(): string
    {
        $container = self::getContainer();
        $container->get(MessageBusInterface::class)->dispatch(new SubscribeToNewsletter(self::EMAIL, 'en', null));
        $subscriber = $container->get(NewsletterSubscriberRepository::class)->findByEmail(self::EMAIL);
        self::assertNotNull($subscriber);

        return $container->get(NewsletterTokenSigner::class)
            ->generateConfirmToken(NewsletterAudience::Guest, $subscriber->id->toString(), $subscriber->email);
    }

    private function subscriberStatus(): null|NewsletterSubscriberStatus
    {
        return self::getContainer()->get(NewsletterSubscriberRepository::class)->findByEmail(self::EMAIL)?->status;
    }
}
