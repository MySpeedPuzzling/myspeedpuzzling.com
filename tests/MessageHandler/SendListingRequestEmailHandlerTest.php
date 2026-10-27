<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ChangeListingRequestEmails;
use SpeedPuzzling\Web\Message\SendListingRequestEmail;
use SpeedPuzzling\Web\Message\WithdrawListingRequest;
use SpeedPuzzling\Web\MessageHandler\SendListingRequestEmailHandler;
use SpeedPuzzling\Web\Repository\ListingRequestRepository;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\ListingRequest\ListingRequestEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ListingRequestSeeding;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail to the seller about a sell/swap request (docs/features/marketplace/12-listing-requests.md): read fresh, on
 * the notifications stream with a one-click unsubscribe; nothing when switched off or nothing waits any more.
 */
final class SendListingRequestEmailHandlerTest extends KernelTestCase
{
    use ListingRequestSeeding;

    private const string BUYER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string SELLER = PlayerFixture::PLAYER_REGULAR;

    private TestMailerSpy $mailer;
    private SendListingRequestEmailHandler $handler;
    private string $requestId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->mailer = new TestMailerSpy();
        $this->handler = new SendListingRequestEmailHandler(
            listingRequestRepository: $container->get(ListingRequestRepository::class),
            playerAccountEmail: $container->get(PlayerAccountEmail::class),
            emailPreferencesLinkGenerator: $container->get(EmailPreferencesLinkGenerator::class),
            unsubscribeUrl: $container->get(ListingRequestEmailsUnsubscribeUrl::class),
            urlGenerator: $container->get(UrlGeneratorInterface::class),
            mailer: $this->mailer,
            translator: $container->get(TranslatorInterface::class),
        );

        $listing = $this->seedListing(self::SELLER, $this->seedPuzzle(1000, 'Lighthouse at Dusk'));
        $offered = $this->seedListing(self::BUYER, $this->seedPuzzle(500, 'Swap Me'));
        $this->seedListingType($listing, 'both');

        $this->askFor(self::BUYER, [self::SELLER => [$listing]], offersBySeller: [self::SELLER => [$offered]], notes: [self::SELLER => 'Ship to Brno?']);
        $this->requestId = $this->requestIdOf(self::BUYER, self::SELLER);
    }

    public function testTheSellerGetsAnEmailNamingWhoAsksForWhatAndTheOffers(): void
    {
        ($this->handler)(new SendListingRequestEmail($this->requestId));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $email->getTo()[0]->getAddress());
        self::assertSame('emails/listing_request.html.twig', $email->getHtmlTemplate());
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertStringContainsString('/sell-swap-request-emails/unsubscribe/' . self::SELLER, (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, (string) $email->getSubject());

        $context = $email->getContext();
        self::assertSame('Ship to Brno?', $context['note']);
        self::assertSame([['name' => 'Lighthouse at Dusk', 'pieces' => 1000, 'brand' => null, 'type' => 'both', 'price' => 10.0, 'currency' => 'EUR']], $context['listings']);
        self::assertSame([['name' => 'Swap Me', 'pieces' => 500, 'brand' => null]], $context['offers']);
        self::assertIsString($context['answerUrl']);
        self::assertStringContainsString('/sell-swap-requests/' . $this->requestId, $context['answerUrl']);
    }

    public function testNothingWhenTheSellerSwitchedTheseEmailsOff(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeListingRequestEmails(self::SELLER, false));

        ($this->handler)(new SendListingRequestEmail($this->requestId));

        self::assertSame([], $this->mailer->sent);
    }

    public function testNothingWhenTheBuyerWithdrewMeanwhile(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new WithdrawListingRequest($this->requestId, self::BUYER));

        ($this->handler)(new SendListingRequestEmail($this->requestId));

        self::assertSame([], $this->mailer->sent);
    }

    public function testTheEmailRendersInEveryLocaleWithoutAnUntranslatedText(): void
    {
        $renderer = self::getContainer()->get('twig.mime_body_renderer');

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $i => $locale) {
            self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET locale = :locale WHERE id = :id', ['id' => self::SELLER, 'locale' => $locale]);
            self::getContainer()->get('doctrine.orm.entity_manager')->clear();

            ($this->handler)(new SendListingRequestEmail($this->requestId));

            $email = $this->mailer->sent[$i];
            self::assertInstanceOf(TemplatedEmail::class, $email);
            $renderer->render($email);
            $html = $email->getHtmlBody();
            self::assertIsString($html);

            self::assertStringContainsString('Lighthouse at Dusk', $html, $locale);
            self::assertStringContainsString('Swap Me', $html, $locale);
            self::assertStringNotContainsString('listing_request.', $html . (string) $email->getSubject(), $locale);
        }
    }
}
