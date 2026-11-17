<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Seating;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Use these tables in later rounds of this category" (competition toolkit W3, seating.md): the confirm's targets and
 * the copy - OfficialResultsApi's rules.
 */
final class CopyTableNumbersControllerTest extends WebTestCase
{
    private const string URL = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_PAIRS . '/copy-table-numbers';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testOnlyTheEventsOrganisersSeeTheTargets(): void
    {
        $this->browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(401);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(403);

        $this->post(['targets' => []]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheConfirmListsTheLaterRoundsAndTheCopyWritesThem(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $sharks = $this->pair('Sharks again', [OfficialResultsFixture::PARTICIPANT_ANNA, OfficialResultsFixture::PARTICIPANT_BEN]);

        $this->browser->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        $answer = $this->json();
        self::assertSame(3, $answer['sourceSeated']);
        self::assertIsArray($answer['targets']);
        self::assertCount(1, $answer['targets']);
        $target = $answer['targets'][0];
        self::assertIsArray($target);
        self::assertSame(OfficialResultsFixture::ROUND_PAIRS_FINAL, $target['roundId']);
        self::assertSame(['entries' => 1, 'matched' => 1, 'changes' => 1], array_intersect_key($target, ['entries' => 0, 'matched' => 0, 'changes' => 0]));
        self::assertIsString($target['hash']);

        // A stale confirm: refused with the reason, nothing written
        $this->post(['targets' => [['round' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'hash' => 'stale']]]);
        self::assertResponseStatusCodeSame(422);
        $refused = $this->json();
        self::assertSame('copy_refused', $refused['error']);
        self::assertIsArray($refused['problems']);
        $problem = $refused['problems'][0];
        self::assertIsArray($problem);
        self::assertSame('changed_meanwhile', $problem['reason']);
        self::assertIsString($problem['message']);
        self::assertNotSame('', $problem['message']);

        $this->post(['targets' => [['round' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'hash' => $target['hash']]]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['changed' => [OfficialResultsFixture::ROUND_PAIRS_FINAL => 1]], $this->json());
        self::assertSame(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT table_number FROM competition_team WHERE id = :id', ['id' => $sharks]));
    }

    public function testUnreadableTargetsAreRefused(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ([['targets' => 'all'], ['targets' => [['round' => 'pairs-final', 'hash' => 'x']]], ['targets' => [['round' => OfficialResultsFixture::ROUND_PAIRS_FINAL]]]] as $body) {
            $this->post($body);

            self::assertResponseStatusCodeSame(400);
            self::assertSame(['error' => 'invalid_targets'], $this->json());
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): void
    {
        $this->browser->request('POST', self::URL, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $participantIds
     */
    private function pair(string $name, array $participantIds): string
    {
        $database = self::getContainer()->get(Connection::class);
        $teamId = Uuid::uuid7()->toString();
        $database->insert('competition_team', ['id' => $teamId, 'round_id' => OfficialResultsFixture::ROUND_PAIRS_FINAL, 'name' => $name]);

        foreach ($participantIds as $participantId) {
            $database->insert('competition_participant_round', [
                'id' => Uuid::uuid7()->toString(),
                'participant_id' => $participantId,
                'round_id' => OfficialResultsFixture::ROUND_PAIRS_FINAL,
                'team_id' => $teamId,
            ]);
        }

        return $teamId;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
