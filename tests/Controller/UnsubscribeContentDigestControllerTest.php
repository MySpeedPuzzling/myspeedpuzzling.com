<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class UnsubscribeContentDigestControllerTest extends WebTestCase
{
    public function testSignedGetShowsConfirmationAndPostUnsubscribes(): void
    {
        $browser = self::createClient();
        $container = self::getContainer();

        $url = $container->get(UrlGeneratorInterface::class)->generate(
            'unsubscribe_content_digest',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $signedUrl = $container->get(UriSigner::class)->sign($url, new \DateInterval('P30D'));

        // GET never unsubscribes (link prefetchers!) — it shows the confirm button.
        $browser->request('GET', $signedUrl);
        self::assertResponseIsSuccessful();

        $frequency = $container->get(Connection::class)->fetchOne(
            'SELECT content_digest_frequency FROM player WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        self::assertSame('weekly', $frequency);

        // POST (one-click / confirm button) flips the preference.
        $browser->request('POST', $signedUrl);
        self::assertResponseIsSuccessful();

        $frequency = $container->get(Connection::class)->fetchOne(
            'SELECT content_digest_frequency FROM player WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        self::assertSame('none', $frequency);
    }

    public function testTamperedSignatureIs404(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/unsubscribe/content-digest/' . PlayerFixture::PLAYER_REGULAR . '?_hash=forged');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnsignedRequestIs404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/unsubscribe/content-digest/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseStatusCodeSame(404);
    }
}
