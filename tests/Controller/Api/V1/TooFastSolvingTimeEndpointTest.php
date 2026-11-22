<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A time too fast to be real (SuspiciousPpm, 100+ pieces per minute per puzzler) is the API's 422 on `time`, nothing
 * saved - it used to answer 500 (WEB-GC).
 */
final class TooFastSolvingTimeEndpointTest extends WebTestCase
{
    public function testATooFastTimeIsA422OnTime(): void
    {
        $browser = self::createClient();
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));

        // 500 pieces in 2 minutes = 250 ppm
        $browser->request('POST', '/api/v1/me/solving-times', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'puzzle_id' => PuzzleFixture::PUZZLE_500_02,
            'time' => '02:00',
            'comment' => 'too-fast-api-check',
        ]));

        self::assertResponseStatusCodeSame(422);
        $answer = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($answer);
        self::assertIsArray($answer['violations'] ?? null);
        self::assertIsArray($answer['violations'][0] ?? null);
        self::assertSame('time', $answer['violations'][0]['propertyPath'] ?? null);
        $message = $answer['violations'][0]['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('too fast to be real', $message);

        $saved = self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM puzzle_solving_time WHERE comment = 'too-fast-api-check'");
        self::assertSame(0, is_numeric($saved) ? (int) $saved : -1);
    }
}
