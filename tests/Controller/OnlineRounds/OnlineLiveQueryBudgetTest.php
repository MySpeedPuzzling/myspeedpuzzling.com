<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use SpeedPuzzling\Web\Services\OnlineRounds\InMemoryOnlinePresence;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The control room polls `online_live` every few seconds and the review reads every participant at once - both cost
 * the same number of statements for 42 participants (taps, undos, photos, presence) as for 2 (docs/features/
 * online-competitions/implementation-plan.md WP 1.7 "live.json within its query budget").
 */
final class OnlineLiveQueryBudgetTest extends WebTestCase
{
    use OnlineReviewSeeding;
    use QueryCountAssertions;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string LIVE = '/en/online-rounds/' . self::ROUND . '/live.json';
    private const string REVIEW = '/en/online-rounds/' . self::ROUND . '/review';

    // Signed in (the account, the profile - an admin's rights need no statement of their own, an organiser's take one
    // more), the round, its online timing - 4 - and then: the participants, their latest photos (2). Measured 2026-10-13.
    private const int LIVE_BUDGET = 6;
    // The same 4, then the round's puzzle (its puzzles, the puzzle), the entries, their events, the event (the round's
    // time zone falls back to its country) - 5. Measured 2026-10-13. + the round's gap marks (stream RG, 2026-10-14: a
    // raise the reviewer marked as a gap is never suggested) - one statement for every participant at once.
    private const int REVIEW_BUDGET = 10;

    public function testTheLiveStateCostsTheSameForFortyTwoParticipantsAsForTwo(): void
    {
        $browser = $this->signedIn();
        $startMs = $this->startOnlineRound();
        $this->seedParticipant(OnlineRoundsFixture::ENTRY_CONNECTED, OnlineRoundsFixture::PARTICIPANT_CONNECTED, $startMs);

        $small = $this->statementsOf($browser, self::LIVE);

        for ($i = 0; $i < 40; $i++) {
            $entry = $this->enterParticipant(sprintf('Invented Puzzler %02d', $i), ['cz', 'de', 'us', 'pl'][$i % 4]);
            $this->seedParticipant($entry->id->toString(), $entry->participant->id->toString(), $startMs + $i * 1_000);
        }

        $large = $this->statementsOf($browser, self::LIVE);

        self::assertSame($small, $large, sprintf('42 participants must cost what 2 cost: %d vs %d statements.', $large, $small));
        self::assertLessThanOrEqual(self::LIVE_BUDGET, $large, implode("\n", $this->executedSql($browser)));

        $state = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['participants']);
        self::assertCount(42, $state['participants']);
        self::assertCount(41, array_filter($state['participants'], static fn (mixed $participant): bool => is_array($participant) && is_array($participant['photo'])));
    }

    public function testTheReviewListCostsTheSameForFortyTwoParticipantsAsForTwo(): void
    {
        $browser = $this->signedIn();
        $startMs = $this->startOnlineRound();
        $this->seedParticipant(OnlineRoundsFixture::ENTRY_CONNECTED, OnlineRoundsFixture::PARTICIPANT_CONNECTED, $startMs);

        $small = $this->statementsOf($browser, self::REVIEW);

        for ($i = 0; $i < 40; $i++) {
            $entry = $this->enterParticipant(sprintf('Invented Puzzler %02d', $i));
            $this->seedParticipant($entry->id->toString(), $entry->participant->id->toString(), $startMs + $i * 1_000);
        }

        $large = $this->statementsOf($browser, self::REVIEW);

        self::assertSame($small, $large, sprintf('42 participants must cost what 2 cost: %d vs %d statements.', $large, $small));
        self::assertLessThanOrEqual(self::REVIEW_BUDGET, $large, implode("\n", $this->executedSql($browser)));

        $list = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['entries']);
        self::assertCount(42, $list['entries']);
    }

    /**
     * Every request on a fresh kernel, like production's - nothing the seeding loaded saves the request a statement
     */
    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return $browser;
    }

    /**
     * Ready, a heartbeat, three periodic photos, a tap taken back and the tap that counts, a camera lost and back
     */
    private function seedParticipant(string $entryId, string $participantId, int $startMs): void
    {
        $onlineEntry = $this->onlineEntryOf($entryId);
        $onlineEntry->readyAt = new \DateTimeImmutable('@' . intdiv($startMs, 1000));
        $onlineEntry->activeDeviceId = OnlineRoundsFixture::DEVICE_CONNECTED;

        for ($photo = 1; $photo <= 3; $photo++) {
            $this->evidence($onlineEntry, OnlineMediaKind::Periodic, $startMs + $photo * 30_000);
        }

        $mistake = $this->finishTap($onlineEntry, $startMs + 600_000);
        $this->undoTap($onlineEntry, $mistake, $startMs + 603_000);
        $this->finishTap($onlineEntry, $startMs + 900_000);
        $this->phoneEvent($onlineEntry, OnlineEventType::CameraLost, $startMs + 100_000);
        $this->phoneEvent($onlineEntry, OnlineEventType::CameraBack, $startMs + 104_000);

        self::getContainer()->get(InMemoryOnlinePresence::class)->beat(self::ROUND, $participantId, ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'visible' => true], (int) floor(microtime(true) * 1000));
    }

    private function statementsOf(KernelBrowser $browser, string $url): int
    {
        // Warm-up: whatever the page caches, the counted request finds it cached
        $browser->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/json']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
