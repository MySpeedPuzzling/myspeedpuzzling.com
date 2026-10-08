<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md, "Time verification": mark / unmark a solving time without the queue's card.
 */
final class SolvingTimeVerificationInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string UNKNOWN_TIME = '018d0031-0000-0000-0000-0000000002ff';

    public function testReadsTheStateOfAMarkedTime(): void
    {
        $browser = self::createClient();

        $state = self::callInternalApi($browser, 'GET', $this->uri(SuspiciousTimesFixture::TIME_MARKED, 'verification'));
        self::assertResponseIsSuccessful();

        self::assertTrue($state['suspicious']);
        self::assertIsArray($state['case']);
        self::assertSame('marked', $state['case']['status']);
        self::assertSame(['faster_than_usual', 'hours_left_out'], $state['case']['reasonsShown']);
        self::assertIsArray($state['notices']);
        self::assertCount(1, $state['notices']);
    }

    public function testMarksATimeTheScanNeverRaised(): void
    {
        $browser = self::createClient();

        $state = self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_EDITION_FAST, 'mark-suspicious'), [
            'note' => 'Could you check the time once more?',
        ]);
        self::assertResponseIsSuccessful();

        self::assertTrue($state['suspicious']);
        self::assertIsArray($state['case']);
        self::assertSame('marked', $state['case']['status']);
        self::assertSame('moderator', $state['case']['origin']);
        self::assertSame([], $state['case']['reasonsShown']);
        self::assertSame('Could you check the time once more?', $state['case']['moderatorNote']);
        // The next notice run tells the player
        self::assertSame([], $state['notices']);

        self::assertSame(['marked'], $this->decisionsOf(SuspiciousTimesFixture::TIME_EDITION_FAST));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->database()->fetchOne(
            'SELECT decided_by_id FROM suspicious_time_decision WHERE time_id = :timeId',
            ['timeId' => SuspiciousTimesFixture::TIME_EDITION_FAST],
        ));
    }

    public function testMarksAPendingCaseWithTheChosenReasons(): void
    {
        $browser = self::createClient();

        $state = self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_STEADY_FAST, 'mark-suspicious'), [
            'reasonCodes' => ['hours_left_out'],
        ]);
        self::assertResponseIsSuccessful();

        self::assertIsArray($state['case']);
        self::assertSame(SuspiciousTimesFixture::CASE_PENDING_FAST, $state['case']['id']);
        self::assertSame('detector', $state['case']['origin']);
        self::assertSame(['hours_left_out'], $state['case']['reasonsShown']);
    }

    public function testATimeEMailedByHandIsRecordedAsToldForEveryMember(): void
    {
        $browser = self::createClient();

        $state = self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_PARTNERS_PAIR, 'mark-suspicious'), [
            'toldByHand' => true,
        ]);
        self::assertResponseIsSuccessful();

        self::assertIsArray($state['notices']);
        self::assertCount(2, $state['notices']);

        foreach ($state['notices'] as $notice) {
            self::assertIsArray($notice);
            self::assertSame('manual_email', $notice['via']);
        }
    }

    public function testMarkingAMarkedTimeIsAConflict(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_MARKED, 'mark-suspicious'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->decisionsOf(SuspiciousTimesFixture::TIME_MARKED));
    }

    public function testUnmarksAMarkedTimeAndAnswersThePlayer(): void
    {
        $browser = self::createClient();

        $state = self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_MARKED, 'unmark-suspicious'), [
            'note' => 'Thanks for checking!',
        ]);
        self::assertResponseIsSuccessful();

        self::assertFalse($state['suspicious']);
        self::assertIsArray($state['case']);
        self::assertSame('trusted', $state['case']['status']);
        self::assertSame(['unmarked'], $this->decisionsOf(SuspiciousTimesFixture::TIME_MARKED));
    }

    public function testUnmarksATimeFlaggedBySqlWithoutACase(): void
    {
        $browser = self::createClient();

        $state = self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_SQL_FLAGGED, 'unmark-suspicious'));
        self::assertResponseIsSuccessful();

        self::assertFalse($state['suspicious']);
        self::assertIsArray($state['case']);
        self::assertSame('manual', $state['case']['origin']);
        self::assertSame('trusted', $state['case']['status']);
        self::assertSame(['unmarked'], $this->decisionsOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED));
    }

    public function testTrustsAPendingCase(): void
    {
        $browser = self::createClient();

        $state = self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_STEADY_TYPO, 'unmark-suspicious'));
        self::assertResponseIsSuccessful();

        self::assertIsArray($state['case']);
        self::assertSame('trusted', $state['case']['status']);
        self::assertSame(['trusted'], $this->decisionsOf(SuspiciousTimesFixture::TIME_STEADY_TYPO));
    }

    public function testUnmarkingATimeNobodyFlaggedIsAConflict(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_EDITION_FAST, 'unmark-suspicious'));
        self::assertResponseStatusCodeSame(409);
    }

    public function testRefusesUnknownFieldsAndReasonCodes(): void
    {
        $browser = self::createClient();

        $error = self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_EDITION_FAST, 'mark-suspicious'), [
            'reasonCodes' => ['made_up'],
            'tellPlayer' => false,
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('reasonCodes', (string) json_encode($error));
        self::assertStringContainsString('tellPlayer', (string) json_encode($error));
        self::assertSame([], $this->decisionsOf(SuspiciousTimesFixture::TIME_EDITION_FAST));
    }

    public function testUnknownTimeIsNotFound(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'GET', $this->uri(self::UNKNOWN_TIME, 'verification'));
        self::assertResponseStatusCodeSame(404);

        self::callInternalApi($browser, 'POST', $this->uri(self::UNKNOWN_TIME, 'mark-suspicious'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testNeedsTheToken(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(SuspiciousTimesFixture::TIME_EDITION_FAST, 'mark-suspicious'), token: null);
        self::assertResponseStatusCodeSame(401);
    }

    private function uri(string $timeId, string $action): string
    {
        return '/internal-api/solving-times/' . $timeId . '/' . $action;
    }

    /**
     * @return list<string>
     */
    private function decisionsOf(string $timeId): array
    {
        /** @var list<string> $decisions */
        $decisions = $this->database()->fetchFirstColumn(
            'SELECT decision FROM suspicious_time_decision WHERE time_id = :timeId ORDER BY decided_at, id',
            ['timeId' => $timeId],
        );

        return $decisions;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
