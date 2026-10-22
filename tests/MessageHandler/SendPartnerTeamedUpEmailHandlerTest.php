<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ClosePartnerListing;
use SpeedPuzzling\Web\Message\SendPartnerTeamedUpEmail;
use SpeedPuzzling\Web\MessageHandler\SendPartnerTeamedUpEmailHandler;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "<name> says you teamed up" (docs/features/partner-finder.md "Phase 2 as built"): to a named player still listed at the
 * event, with the way to update their own listing - never closing it for them.
 */
final class SendPartnerTeamedUpEmailHandlerTest extends KernelTestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testClosingAsFoundQueuesTheEMailForTheNamedPartner(): void
    {
        $this->requestFromMichaelToJohn();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ClosePartnerListing(
            PartnerFinderFixture::LISTING_REGULAR_PAIR,
            PlayerFixture::PLAYER_REGULAR,
            PartnerListingClosedReason::Found,
            teamedUpWith: [PlayerFixture::PLAYER_WITH_FAVORITES],
        ));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $partners = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SendPartnerTeamedUpEmail) {
                $partners[] = $message->partnerPlayerId;
            }
        }

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $partners);
    }

    public function testTheNamedPartnerGetsTheWayToUpdateTheirListing(): void
    {
        $mailer = new TestMailerSpy();
        $this->handler($mailer)(new SendPartnerTeamedUpEmail(PartnerFinderFixture::LISTING_REGULAR_PAIR, PlayerFixture::PLAYER_WITH_FAVORITES));

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('emails/partner_teamed_up.html.twig', $email->getHtmlTemplate());
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());

        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME . ' teamed up with you', $html);
        self::assertStringContainsString('Update my listing', $html);
        self::assertStringContainsString(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG, $html);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR_EMAIL, $html);
    }

    public function testNothingWhenTheirListingIsClosedTheySwitchedTheseEMailsOffOrABlockStandsBetween(): void
    {
        $mailer = new TestMailerSpy();
        $send = fn () => $this->handler($mailer)(new SendPartnerTeamedUpEmail(PartnerFinderFixture::LISTING_REGULAR_PAIR, PlayerFixture::PLAYER_WITH_FAVORITES));

        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );
        $send();
        $this->database->executeStatement('DELETE FROM user_block WHERE blocker_id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]);

        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_FAVORITES)->changePartnerFinderEmails(false);
        $send();
        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_FAVORITES)->changePartnerFinderEmails(true);

        $this->database->executeStatement('UPDATE partner_listing SET closed_at = NOW() WHERE id = :id', ['id' => PartnerFinderFixture::LISTING_FAVORITES_TEAM]);
        $send();

        self::assertCount(0, $mailer->sent);
    }

    private function handler(TestMailerSpy $mailer): SendPartnerTeamedUpEmailHandler
    {
        $container = self::getContainer();

        return new SendPartnerTeamedUpEmailHandler(
            partnerListingRepository: $container->get(PartnerListingRepository::class),
            playerRepository: $container->get(PlayerRepository::class),
            getUserBlocks: $container->get(GetUserBlocks::class),
            playerAccountEmail: $container->get(PlayerAccountEmail::class),
            emailPreferencesLinkGenerator: $container->get(EmailPreferencesLinkGenerator::class),
            unsubscribeUrl: $container->get(PartnerFinderEmailsUnsubscribeUrl::class),
            partnerFinderUrls: $container->get(PartnerFinderUrls::class),
            mailer: $mailer,
            translator: $container->get(TranslatorInterface::class),
        );
    }

    private function requestFromMichaelToJohn(): void
    {
        $this->database->executeStatement(
            "INSERT INTO partner_request (id, listing_id, from_player_id, kind, created_at) VALUES (:id, :listing, :from, 'pair', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'listing' => PartnerFinderFixture::LISTING_REGULAR_PAIR, 'from' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
    }
}
