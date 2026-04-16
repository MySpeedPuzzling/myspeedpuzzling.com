<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\SendBadgeNotificationEmail;
use SpeedPuzzling\Web\MessageHandler\SendBadgeNotificationEmailHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FakePlayerRepository;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendBadgeNotificationEmailHandlerTest extends KernelTestCase
{
    private TestMailerSpy $mailer;
    private PlayerRepository $playerRepository;
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->mailer = new TestMailerSpy();
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->translator = $container->get(TranslatorInterface::class);
    }

    public function testSendsEmailWithCorrectTemplate(): void
    {
        $handler = new SendBadgeNotificationEmailHandler(
            playerRepository: $this->playerRepository,
            mailer: $this->mailer,
            translator: $this->translator,
        );

        $handler(new SendBadgeNotificationEmail(
            playerId: PlayerFixture::PLAYER_REGULAR,
            badgeSummary: [
                ['type' => BadgeType::PuzzlesSolved, 'tier' => BadgeTier::Gold],
                ['type' => BadgeType::Streak, 'tier' => BadgeTier::Bronze],
            ],
        ));

        self::assertCount(1, $this->mailer->sent);
        $message = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $message);
        self::assertSame('emails/badges_earned.html.twig', $message->getHtmlTemplate());
        self::assertSame('transactional', $message->getHeaders()->get('X-Transport')?->getBodyAsString());
    }

    public function testSkipsEmailWhenPlayerHasNoEmail(): void
    {
        $player = new \SpeedPuzzling\Web\Entity\Player(
            id: \Ramsey\Uuid\Uuid::uuid7(),
            code: 'noemail-test',
            userId: null,
            email: null,
            name: 'No Email',
            registeredAt: new \DateTimeImmutable(),
        );

        $handler = new SendBadgeNotificationEmailHandler(
            playerRepository: new FakePlayerRepository($player),
            mailer: $this->mailer,
            translator: $this->translator,
        );

        $handler(new SendBadgeNotificationEmail(
            playerId: $player->id->toString(),
            badgeSummary: [['type' => BadgeType::Streak, 'tier' => BadgeTier::Bronze]],
        ));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testSkipsEmailWhenPlayerNotFound(): void
    {
        $handler = new SendBadgeNotificationEmailHandler(
            playerRepository: $this->playerRepository,
            mailer: $this->mailer,
            translator: $this->translator,
        );

        $handler(new SendBadgeNotificationEmail(
            playerId: '00000000-0000-0000-0000-000000000099',
            badgeSummary: [['type' => BadgeType::Streak, 'tier' => BadgeTier::Bronze]],
        ));

        self::assertCount(0, $this->mailer->sent);
    }
}
