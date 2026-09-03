<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Message\SendXpRevealEmail;
use SpeedPuzzling\Web\MessageHandler\SendXpRevealEmailHandler;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Repository\ContentDigestLogRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendXpRevealEmailHandlerTest extends KernelTestCase
{
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testSendsOncePerPlayerForever(): void
    {
        $mailer = new TestMailerSpy();
        $handler = $this->handler($mailer, flagActive: false);

        $handler(new SendXpRevealEmail(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->entityManager->flush();

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('emails/xp_reveal.html.twig', $email->getHtmlTemplate());
        self::assertSame('transactional', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertNotNull($email->getHeaders()->get('List-Unsubscribe'));

        // Second run: the idempotency log blocks a duplicate.
        $handler(new SendXpRevealEmail(PlayerFixture::PLAYER_WITH_STRIPE));

        self::assertSame(1, $mailer->sentCount());

        $logged = $this->database->fetchOne(
            "SELECT COUNT(*) FROM content_digest_log WHERE player_id = :playerId AND digest_type = 'xp_reveal'",
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        self::assertSame(1, (int) (is_numeric($logged) ? $logged : 0));
    }

    public function testRefusesWhileFlagActive(): void
    {
        $mailer = new TestMailerSpy();
        $handler = $this->handler($mailer, flagActive: true);

        $handler(new SendXpRevealEmail(PlayerFixture::PLAYER_WITH_STRIPE));

        self::assertCount(0, $mailer->sent);
    }

    public function testOptedOutPlayersAreSkipped(): void
    {
        $this->database->executeStatement(
            'UPDATE player SET experience_system_opted_out = true WHERE id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $mailer = new TestMailerSpy();
        $handler = $this->handler($mailer, flagActive: false);

        $handler(new SendXpRevealEmail(PlayerFixture::PLAYER_WITH_STRIPE));

        self::assertCount(0, $mailer->sent);
    }

    private function handler(TestMailerSpy $mailer, bool $flagActive): SendXpRevealEmailHandler
    {
        $container = self::getContainer();

        return new SendXpRevealEmailHandler(
            playerRepository: $container->get(PlayerRepository::class),
            getPlayerProfile: $container->get(GetPlayerProfile::class),
            getXpProfile: $container->get(GetXpProfile::class),
            getBadges: $container->get(GetBadges::class),
            contentDigestLogRepository: $container->get(ContentDigestLogRepository::class),
            mailer: $mailer,
            translator: $container->get(TranslatorInterface::class),
            uriSigner: $container->get(UriSigner::class),
            urlGenerator: $container->get(UrlGeneratorInterface::class),
            database: $this->database,
            clock: $container->get(ClockInterface::class),
            xpFeatureGate: new XpFeatureGate(adminOnly: $flagActive),
            logger: new NullLogger(),
        );
    }
}
