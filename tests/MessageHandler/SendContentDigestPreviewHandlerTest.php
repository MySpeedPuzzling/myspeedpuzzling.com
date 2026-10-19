<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\NoActivityInDigestPeriod;
use SpeedPuzzling\Web\Message\SendContentDigestPreview;
use SpeedPuzzling\Web\MessageHandler\SendContentDigestPreviewHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Digest\ContentDigestEmailFactory;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\TransportSpy;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;

final class SendContentDigestPreviewHandlerTest extends KernelTestCase
{
    public function testThePlayersRealDigestGoesToTheGivenAddressAndNothingIsWritten(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $transport = new TransportSpy();
        // A solve inside 2030-W11-W12 (Mon 11 - Sun 24 March 2030)
        $container->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at, comment, finished_puzzle_photo,
                 first_attempt, unboxed, puzzlers_count, puzzling_type, suspicious, pieces_count_snapshot)
             VALUES (:id, 3600, :playerId, :puzzleId, '2030-03-13 18:00:00', true, NULL, '2030-03-13 18:00:00', NULL, NULL,
                 false, false, 1, 'solo', false, 500)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzleId' => PuzzleFixture::PUZZLE_500_04],
        );

        $this->handler($transport)(new SendContentDigestPreview(PlayerFixture::PLAYER_WITH_STRIPE, 'preview@example.com', ContentDigestKind::Activity, '2030-W11-W12'));

        self::assertCount(1, $transport->sent);
        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(['preview@example.com'], array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('emails/content_digest_activity.html.twig', $email->getHtmlTemplate());
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertSame(ContentDigestFrequency::EveryTwoWeeks, $email->getContext()['frequency'], 'The footer follows the period shown');

        self::assertSame(0, $container->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM content_digest_log WHERE player_id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE],
        ), 'No log row: a preview is not a send');
    }

    public function testAPeriodWithoutActivityIsRefusedNothingIsSent(): void
    {
        self::bootKernel();
        $transport = new TransportSpy();

        try {
            $this->handler($transport)(new SendContentDigestPreview(PlayerFixture::PLAYER_WITH_STRIPE, 'preview@example.com', ContentDigestKind::Activity, '2030-W11-W12'));
            self::fail('A real digest is never sent for an empty period, so neither is a preview');
        } catch (NoActivityInDigestPeriod) {
            self::assertCount(0, $transport->sent);
        }
    }

    private function handler(TransportSpy $transport): SendContentDigestPreviewHandler
    {
        $container = self::getContainer();

        return new SendContentDigestPreviewHandler(
            $container->get(PlayerRepository::class),
            $container->get(ContentDigestEmailFactory::class),
            $transport,
        );
    }
}
