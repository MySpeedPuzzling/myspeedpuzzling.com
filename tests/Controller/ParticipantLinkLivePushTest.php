<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Tests\Controller\Compete\CompeteApiRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A player's own link changes - "I'm going" picking a name, leaving, the online personal link - tell the organiser's
 * open participants sheets after the commit (docs/features/competitions-management/participant-linking.md §5.8): the
 * sheet's private topic with the version after the write.
 */
final class ParticipantLinkLivePushTest extends WebTestCase
{
    use CompeteApiRequests;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testPickingANameTellsTheSheet(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('POST', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024, [
            'participant_id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ]);

        self::assertResponseRedirects();
        $this->assertTheSheetWasTold(CompetitionFixture::COMPETITION_WJPC_2024);
    }

    public function testLeavingTellsTheSheet(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('POST', '/en/leave-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertResponseRedirects();
        $this->assertTheSheetWasTold(CompetitionFixture::COMPETITION_WJPC_2024);
    }

    public function testThePersonalLinkTellsTheSheet(): void
    {
        self::forgetCachedAccess($this->browser);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->browser->request('POST', '/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/connect', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_REFERER' => 'http://localhost/page',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: '{}');

        self::assertResponseIsSuccessful();
        $this->assertTheSheetWasTold(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
    }

    public function testThePersonalLinkOfSomebodyElseTellsNobody(): void
    {
        self::forgetCachedAccess($this->browser);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->browser->request('POST', '/en/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED . '/connect', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_REFERER' => 'http://localhost/page',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: '{}');

        self::assertResponseIsSuccessful();
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    private function assertTheSheetWasTold(string $competitionId): void
    {
        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame(['/competition-participants/' . $competitionId], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());

        $payload = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('participants_sheet.changed', $payload['type']);
        self::assertSame(self::getContainer()->get(GetParticipantsSheetVersion::class)->ofCompetition($competitionId), $payload['version']);
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
