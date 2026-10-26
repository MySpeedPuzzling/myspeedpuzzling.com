<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AskToBorrowPuzzles;
use SpeedPuzzling\Web\Message\SendBorrowRequestEmail;
use SpeedPuzzling\Web\Message\WithdrawBorrowRequest;
use SpeedPuzzling\Web\MessageHandler\SendBorrowRequestEmailHandler;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use SpeedPuzzling\Web\Services\BorrowRequest\BorrowRequestEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail to the owner about a borrow request (docs/features/wishlist-availability.md): read fresh, on the
 * notifications stream with a one-click unsubscribe; nothing when switched off or nothing waits any more.
 */
final class SendBorrowRequestEmailHandlerTest extends KernelTestCase
{
    use BorrowSeeding;

    private const string ASKER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string OWNER = PlayerFixture::PLAYER_REGULAR;

    private TestMailerSpy $mailer;
    private SendBorrowRequestEmailHandler $handler;
    private string $requestId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->mailer = new TestMailerSpy();
        $this->handler = new SendBorrowRequestEmailHandler(
            borrowRequestRepository: $container->get(BorrowRequestRepository::class),
            playerAccountEmail: $container->get(PlayerAccountEmail::class),
            emailPreferencesLinkGenerator: $container->get(EmailPreferencesLinkGenerator::class),
            unsubscribeUrl: $container->get(BorrowRequestEmailsUnsubscribeUrl::class),
            urlGenerator: $container->get(UrlGeneratorInterface::class),
            mailer: $this->mailer,
            translator: $container->get(TranslatorInterface::class),
        );

        // PLAYER_WITH_FAVORITES follows PLAYER_REGULAR
        $puzzle = $this->seedPuzzle(1000, 'Lighthouse at Dusk');
        $this->seedCollectionVisibility(self::OWNER, true);
        $this->seedOwned(self::OWNER, $puzzle);
        $this->seedWished(self::ASKER, $puzzle);

        $container->get(MessageBusInterface::class)->dispatch(new AskToBorrowPuzzles(Uuid::uuid7()->toString(), self::ASKER, [self::OWNER => [$puzzle]], [self::OWNER => 'Saturday?']));

        $requestId = $container->get(Connection::class)->fetchOne('SELECT id FROM borrow_request WHERE asker_id = :asker', ['asker' => self::ASKER]);
        self::assertIsString($requestId);
        $this->requestId = $requestId;
    }

    public function testTheOwnerGetsAnEmailNamingWhoAsksAndWhichPuzzles(): void
    {
        ($this->handler)(new SendBorrowRequestEmail($this->requestId));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $email->getTo()[0]->getAddress());
        self::assertSame('emails/borrow_request.html.twig', $email->getHtmlTemplate());
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertStringContainsString('/borrow-request-emails/unsubscribe/' . self::OWNER, (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, (string) $email->getSubject());

        $context = $email->getContext();
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $context['askerName']);
        self::assertSame('Saturday?', $context['note']);
        self::assertSame([['name' => 'Lighthouse at Dusk', 'pieces' => 1000, 'brand' => null]], $context['puzzles']);
        self::assertIsString($context['answerUrl']);
        self::assertStringContainsString('/borrow-requests/' . $this->requestId, $context['answerUrl']);
    }

    public function testNothingWhenTheOwnerSwitchedTheseEmailsOff(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET borrow_request_emails = false WHERE id = :id', ['id' => self::OWNER]);
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        ($this->handler)(new SendBorrowRequestEmail($this->requestId));

        self::assertSame([], $this->mailer->sent);
    }

    public function testNothingWhenTheAskerWithdrewMeanwhile(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new WithdrawBorrowRequest($this->requestId, self::ASKER));

        ($this->handler)(new SendBorrowRequestEmail($this->requestId));

        self::assertSame([], $this->mailer->sent);
    }
}
