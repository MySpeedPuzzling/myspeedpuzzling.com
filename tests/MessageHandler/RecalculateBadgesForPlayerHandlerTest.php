<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\MessageHandler\RecalculateBadgesForPlayerHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FakeBadgeEvaluator;
use SpeedPuzzling\Web\Tests\TestDouble\FakePlayerRepository;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RecalculateBadgesForPlayerHandlerTest extends KernelTestCase
{
    private TestMailerSpy $mailer;
    private Connection $connection;
    private PlayerRepository $playerRepository;
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->mailer = new TestMailerSpy();
        $this->connection = $container->get(Connection::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->translator = $container->get(TranslatorInterface::class);

        // Clear any badges a previous test may have inserted for the fixtures we touch.
        $this->connection->executeStatement('DELETE FROM badge WHERE player_id IN (:players)', [
            'players' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE],
        ], ['players' => ArrayParameterType::STRING]);
    }

    public function testDoesNothingWhenEvaluatorReturnsNoNewBadges(): void
    {
        $handler = $this->buildHandler(new FakeBadgeEvaluator([]));

        $handler(new RecalculateBadgesForPlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testSendsSingleEmailListingHighestTierPerType(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $now = new DateTimeImmutable('2026-04-16 12:00:00');

        $badges = [
            Badge::earn($player, BadgeType::PuzzlesSolved, $now, BadgeTier::Bronze),
            Badge::earn($player, BadgeType::PuzzlesSolved, $now, BadgeTier::Silver),
            Badge::earn($player, BadgeType::PuzzlesSolved, $now, BadgeTier::Gold),
            Badge::earn($player, BadgeType::Streak, $now, BadgeTier::Bronze),
        ];

        $handler = $this->buildHandler(new FakeBadgeEvaluator($badges));

        $handler(new RecalculateBadgesForPlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertCount(1, $this->mailer->sent);

        $message = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $message);
        self::assertSame('emails/badges_earned.html.twig', $message->getHtmlTemplate());

        $context = $message->getContext();
        /** @var list<array{type: BadgeType, tier: null|BadgeTier}> $emailedBadges */
        $emailedBadges = $context['badges'];
        self::assertCount(2, $emailedBadges);

        $byType = [];
        foreach ($emailedBadges as $entry) {
            $byType[$entry['type']->value] = $entry['tier']?->value;
        }
        self::assertSame(3, $byType['puzzles_solved']);
        self::assertSame(1, $byType['streak']);
    }

    public function testSkipsEmailWhenPlayerHasNoEmailAddress(): void
    {
        $player = $this->buildEmaillessPlayer();
        $stubRepository = new FakePlayerRepository($player);

        $handler = new RecalculateBadgesForPlayerHandler(
            badgeEvaluator: new FakeBadgeEvaluator([
                Badge::earn($player, BadgeType::Streak, new DateTimeImmutable(), BadgeTier::Bronze),
            ]),
            playerRepository: $stubRepository,
            mailer: $this->mailer,
            translator: $this->translator,
        );

        $handler(new RecalculateBadgesForPlayer($player->id->toString()));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testUsesTransactionalTransportHeader(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        $handler = $this->buildHandler(new FakeBadgeEvaluator([
            Badge::earn($player, BadgeType::Streak, new DateTimeImmutable(), BadgeTier::Bronze),
        ]));

        $handler(new RecalculateBadgesForPlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertCount(1, $this->mailer->sent);
        $message = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $message);
        self::assertSame('transactional', $message->getHeaders()->get('X-Transport')?->getBodyAsString());
    }

    private function buildHandler(BadgeEvaluator $evaluator): RecalculateBadgesForPlayerHandler
    {
        return new RecalculateBadgesForPlayerHandler(
            badgeEvaluator: $evaluator,
            playerRepository: $this->playerRepositoryForTest(),
            mailer: $this->mailer,
            translator: $this->translator,
        );
    }

    private function playerRepositoryForTest(): PlayerRepository
    {
        return $this->playerRepository;
    }

    private function buildEmaillessPlayer(): Player
    {
        // We don't persist; handler only reads the player's id/email/locale.
        return new Player(
            id: \Ramsey\Uuid\Uuid::uuid7(),
            code: 'noemail-test',
            userId: null,
            email: null,
            name: 'No Email Tester',
            registeredAt: new DateTimeImmutable(),
        );
    }
}
