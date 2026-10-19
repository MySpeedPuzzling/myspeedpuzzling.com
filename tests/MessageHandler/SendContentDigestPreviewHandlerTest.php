<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\SendContentDigestPreview;
use SpeedPuzzling\Web\MessageHandler\SendContentDigestPreviewHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Digest\ContentDigestEmailFactory;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
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

        $handler = new SendContentDigestPreviewHandler(
            $container->get(PlayerRepository::class),
            $container->get(ContentDigestEmailFactory::class),
            $transport,
        );

        $handler(new SendContentDigestPreview(PlayerFixture::PLAYER_WITH_STRIPE, 'preview@example.com', ContentDigestKind::Activity, '2030-W11-W12'));

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
}
