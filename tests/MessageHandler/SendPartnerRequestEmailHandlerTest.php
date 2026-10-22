<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\SendPartnerRequestEmail;
use SpeedPuzzling\Web\MessageHandler\SendPartnerRequestEmailHandler;
use SpeedPuzzling\Web\Query\GetPlayerCard;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PartnerRequestRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail to the listed puzzler (docs/features/partner-finder.md D6): read fresh, on the notifications stream, with
 * a one-click unsubscribe, naming who asks - never anybody's e-mail address.
 */
final class SendPartnerRequestEmailHandlerTest extends KernelTestCase
{
    private TestMailerSpy $mailer;
    private SendPartnerRequestEmailHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->mailer = new TestMailerSpy();
        $this->handler = new SendPartnerRequestEmailHandler(
            partnerRequestRepository: $container->get(PartnerRequestRepository::class),
            partnerListingRepository: $container->get(PartnerListingRepository::class),
            getPlayerCard: $container->get(GetPlayerCard::class),
            playerAccountEmail: $container->get(PlayerAccountEmail::class),
            emailPreferencesLinkGenerator: $container->get(EmailPreferencesLinkGenerator::class),
            unsubscribeUrl: $container->get(PartnerFinderEmailsUnsubscribeUrl::class),
            partnerFinderUrls: $container->get(PartnerFinderUrls::class),
            urlGenerator: $container->get(UrlGeneratorInterface::class),
            mailer: $this->mailer,
            translator: $container->get(TranslatorInterface::class),
        );
    }

    public function testTheListedPuzzlerGetsAnEMailNamingWhoAsks(): void
    {
        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR)->changeLocale('cs');

        ($this->handler)(new SendPartnerRequestEmail(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);

        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $email->getTo()[0]->getAddress());
        self::assertSame('notify@notify.myspeedpuzzling.com', $email->getFrom()[0]->getAddress());
        self::assertSame('cs', $email->getLocale());
        self::assertSame('emails/partner_request.html.twig', $email->getHtmlTemplate());

        $headers = $email->getHeaders();
        self::assertSame('notifications', $headers->get('X-Transport')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());
        $listUnsubscribe = (string) $headers->get('List-Unsubscribe')?->getBodyAsString();
        self::assertStringStartsWith('<http', $listUnsubscribe);
        self::assertStringContainsString('/cs/partner-finder-emails/unsubscribe/' . PlayerFixture::PLAYER_REGULAR, $listUnsubscribe);
        self::assertStringContainsString('_hash=', $listUnsubscribe);

        $context = $email->getContext();
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $context['senderName']);
        self::assertIsString($context['replyUrl']);
        self::assertStringContainsString('/partners/request/' . PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR . '/reply', $context['replyUrl']);
        self::assertIsString($context['closeListingUrl']);
        self::assertStringContainsString(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG, $context['closeListingUrl']);

        // The template renders, names the sender and the event, and carries nobody's e-mail address
        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $html);
        self::assertStringContainsString('Partner Finder Open', $html);
        self::assertStringContainsString('Hi John, I would love to pair up!', $html);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE_EMAIL, $html);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR_EMAIL, $html);
    }

    public function testAPrivateSenderIsNamedButShowsNoNumbers(): void
    {
        // Canary: as a public player the sender has numbers the e-mail would show
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "INSERT INTO community_player_stats (player_id, solved_total, pieces_total, solves7d, pieces7d, solves30d, solves_prev30d, solves_this_month, pieces_this_month, solves_last_month, pieces_last_month, best500_seconds, best1000_seconds, monthly_solves, favorites_count, computed_at)
             VALUES (:player, 50, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2400, 5400, '[]', 0, " . TestClock::SQL_NOW . ")
             ON CONFLICT (player_id) DO UPDATE SET solved_total = 50, best500_seconds = 2400, best1000_seconds = 5400",
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        $publicCard = self::getContainer()->get(GetPlayerCard::class)->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE, false);
        self::assertGreaterThan(0, $publicCard->solvedTotal, 'No canary: the sender has no numbers to hide.');

        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE player SET is_private = true WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        ($this->handler)(new SendPartnerRequestEmail(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);

        // Sending is consent to be named - but a private profile's numbers stay private
        $context = $email->getContext();
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $context['senderName']);
        self::assertNull($context['senderBest500Seconds']);
        self::assertNull($context['senderBest1000Seconds']);
        self::assertNull($context['senderSolvedTotal']);

        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $html);
        self::assertDoesNotMatchRegularExpression('/puzzles? solved|Best (500|1000) pieces/', $html);
    }

    public function testNothingIsSentWhenTheListedPuzzlerSwitchedTheseEMailsOff(): void
    {
        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR)->changePartnerFinderEmails(false);

        ($this->handler)(new SendPartnerRequestEmail(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testNothingIsSentForAListingClosedMeanwhile(): void
    {
        self::getContainer()->get(PartnerListingRepository::class)
            ->get(PartnerFinderFixture::LISTING_REGULAR_PAIR)
            ->close(PartnerListingClosedReason::Found, TestClock::now());

        ($this->handler)(new SendPartnerRequestEmail(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testNothingIsSentForARequestThatIsGone(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement('DELETE FROM partner_request WHERE id = :id', ['id' => PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR]);
        $entityManager->clear();

        ($this->handler)(new SendPartnerRequestEmail(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR));

        self::assertCount(0, $this->mailer->sent);
    }
}
