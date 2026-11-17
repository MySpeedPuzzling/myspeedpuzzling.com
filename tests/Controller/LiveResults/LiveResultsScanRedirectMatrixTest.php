<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\GoldenFile;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Baseline pin of the competition toolkit (R-review-and-plan.md §2.9, package F0): where a printed name tag's QR
 * (`live_results_scan`, `/{_locale}/live/{cid}/p/{participantId}` - frozen, R §1.3) leads every kind of viewer, for
 * every kind of tag - the whole matrix in one golden table. The toolkit's new tag kinds and competitor pages (B2) add
 * branches for competitions that switched them on; for an untouched competition every row must stay as it is.
 */
final class LiveResultsScanRedirectMatrixTest extends WebTestCase
{
    use GoldenFile;

    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;

    public function testEveryViewerAndEveryTagLeadWhereTheyLedBefore(): void
    {
        self::assertMatchesGoldenFile(__DIR__ . '/golden/live-results-scan-matrix.txt', $this->matrix(competitorPages: false), 'The name tag scan redirect matrix');
    }

    /**
     * Competitor pages on (toolkit B2, B-qr-competitor-page.md §3.3): only the public branch may change - organisers
     * and referees land where they did; signed out and puzzlers open a known participant's competitor page
     * (`/en/competitor/{cid}/p/{id}`, CompetitorPageController), anything else the event page as before.
     */
    public function testWithCompetitorPagesOnOnlyThePublicBranchChanges(): void
    {
        self::assertMatchesGoldenFile(__DIR__ . '/golden/live-results-scan-matrix-competitor-pages.txt', $this->matrix(competitorPages: true), 'The name tag scan redirect matrix with competitor pages on');
    }

    private function matrix(bool $competitorPages): string
    {
        $browser = self::createClient();
        $browser->disableReboot();

        if ($competitorPages) {
            self::getContainer()->get(Connection::class)->executeStatement(
                'UPDATE competition SET competitor_pages = true WHERE id IN (:ids)',
                ['ids' => [OfficialResultsFixture::COMPETITION_RESULTS_CUP, CompetitionFixture::COMPETITION_UNAPPROVED, OrganizationFixture::EDITION_LANTERN_DRAFT]],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        self::getContainer()->get(Connection::class)->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'player_id' => self::REFEREE,
            'added_by_id' => PlayerFixture::PLAYER_WITH_STRIPE,
            'added_at' => '2026-10-01 10:00:00',
        ]);

        $viewers = [
            'signed out' => null,
            'puzzler' => PlayerFixture::PLAYER_REGULAR,
            'organiser' => PlayerFixture::PLAYER_WITH_STRIPE,
            'referee' => self::REFEREE,
            'admin' => PlayerFixture::PLAYER_ADMIN,
        ];

        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $tags = [
            'participant in several rounds' => [$cup, OfficialResultsFixture::PARTICIPANT_ANNA],
            'participant in one round' => [$cup, OfficialResultsFixture::PARTICIPANT_IVAN],
            'participant of another event' => [$cup, CompetitionParticipantFixture::PARTICIPANT_CONNECTED],
            'unknown participant' => [$cup, '018d0020-0000-0000-0000-00000000ffff'],
            'event without rounds' => [CompetitionFixture::COMPETITION_UNAPPROVED, '018d0020-0000-0000-0000-00000000ffff'],
            'draft event' => [OrganizationFixture::EDITION_LANTERN_DRAFT, '018d0020-0000-0000-0000-00000000ffff'],
            'unknown event' => ['018d0020-0000-0000-0000-00000000ffff', OfficialResultsFixture::PARTICIPANT_ANNA],
        ];

        $lines = [];
        foreach ($viewers as $viewer => $playerId) {
            foreach ($tags as $tag => [$competitionId, $participantId]) {
                $browser->restart();
                if ($playerId !== null) {
                    TestingLogin::asPlayer($browser, $playerId);
                }

                $browser->request('GET', '/en/live/' . $competitionId . '/p/' . $participantId);
                $response = $browser->getResponse();
                $session = $browser->getRequest()->getSession();
                assert($session instanceof FlashBagAwareSessionInterface);
                $flashes = $session->getFlashBag()->peekAll();

                $lines[] = sprintf(
                    '%s | %s | %d %s | %s | %s',
                    $viewer,
                    $tag,
                    $response->getStatusCode(),
                    (string) $response->headers->get('Location'),
                    $flashes === [] ? 'no flash' : 'flash ' . implode(',', array_keys($flashes)),
                    (string) $response->headers->get('X-Robots-Tag'),
                );
                self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
