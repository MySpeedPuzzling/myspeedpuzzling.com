<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\AddPuzzleToWishList;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\ScheduleRoundStart;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Every page and every write of one puzzle: a secret competition puzzle does not exist for a guest, another player
 * or a community moderator - only for the event's maintainers and admins.
 */
final class SecretPuzzleRoutesTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use ReadsRoundAutomaticReveal;

    private const string SECRET_NAME = 'Secret Puzzle Alpha';
    private const string SECRET_EAN = '4005556175512';

    /**
     * @return iterable<string, array{string, bool}> path (with {id}), whether its organisers reach it
     */
    public static function guardedRoutes(): iterable
    {
        yield 'puzzle page' => ['/en/puzzle/{id}', true];
        yield 'marketplace puzzle page' => ['/en/marketplace/puzzle/{id}', true];
        yield 'suggest a change' => ['/en/puzzle/{id}/suggest-change', true];
        yield 'pending proposals' => ['/en/puzzle/{id}/pending-proposals', true];
        yield 'QR code' => ['/en/puzzle/{id}/qr-code', true];
        yield 'suggest a name' => ['/en/puzzle/{id}/suggest-name', false];
        yield 'add to a collection' => ['/en/collections/{id}/add', true];
        yield 'add to the wishlist' => ['/en/wishlist/{id}/add', true];
        yield 'sell or swap' => ['/en/sell-swap/{id}/add', true];
        yield 'lend' => ['/en/lend/{id}', true];
        yield 'borrow' => ['/en/borrow/{id}', true];
        yield 'stopwatch' => ['/en/puzzle-stopwatch/{id}', true];
        yield 'start a stopwatch' => ['/en/start-stopwatch/{id}', true];
        yield 'add a time' => ['/en/puzzle-add/{id}', true];
        yield 'move in the collections' => ['/en/collections/{id}/move', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guardedRoutes')]
    public function testSecretPuzzleExistsOnlyForItsOrganisers(string $path, bool $organisersReachIt): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();
        $url = str_replace('{id}', $puzzleId, $path);

        // A guest: nothing (404, or the sign-in page - never the puzzle)
        $browser->request('GET', $url);
        self::assertNotSame(200, $browser->getResponse()->getStatusCode(), 'guest');
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());

        foreach ([PlayerFixture::PLAYER_PRIVATE => 'another player', PlayerFixture::PLAYER_WITH_STRIPE => 'a moderator'] as $playerId => $who) {
            TestingLogin::asPlayer($browser, $playerId);
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $who);
        }

        if ($organisersReachIt === false) {
            return;
        }

        foreach ([PlayerFixture::PLAYER_WITH_FAVORITES => 'a maintainer of the event', PlayerFixture::PLAYER_ADMIN => 'an admin'] as $playerId => $who) {
            TestingLogin::asPlayer($browser, $playerId);
            $browser->request('GET', $url);
            self::assertNotSame(404, $browser->getResponse()->getStatusCode(), $who);
            self::assertLessThan(500, $browser->getResponse()->getStatusCode(), $who);
        }
    }

    /**
     * "When the stopwatch starts" with a countdown started with "Reveal the puzzles" ticked (toolkit/R-review-and-plan.md
     * §2.1.2): one second before the countdown ends the puzzle's page does not exist for anybody but its organisers, at
     * the start it is public
     */
    public function testAStopwatchRevealOpensThePuzzleOnlyWhenTheCountdownEnds(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $puzzleId = $this->secretPuzzle();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $roundPuzzle = $entityManager->getRepository(CompetitionRoundPuzzle::class)->findOneBy(['puzzle' => $puzzleId]);
        self::assertNotNull($roundPuzzle);
        $bus = $container->get(MessageBusInterface::class);
        $bus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzle->id->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));
        $entityManager->clear();

        // The countdown ends one second after the frozen now - the test runs at scheduled - 1 s
        $startsAt = TestClock::now()->modify('+1 second');
        $round = $entityManager->find(CompetitionRound::class, CompetitionApiFixture::ROUND_FUTURE);
        self::assertNotNull($round);
        $hash = SecretRevealPreview::stopwatchStartHash($container->get(SecretRevealPreview::class)->byStopwatchStart($round), $round);
        $bus->dispatch(new ScheduleRoundStart(CompetitionApiFixture::ROUND_FUTURE, $startsAt, PlayerFixture::PLAYER_ADMIN, revealPuzzles: true, revealListHash: $hash));
        $entityManager->clear();

        $browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertNotSame(200, $browser->getResponse()->getStatusCode(), 'a guest at scheduled - 1 s');
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseStatusCodeSame(404, 'another player at scheduled - 1 s');

        // The test container's clock is a MockClock (config/services_test.php) - it moves on at once
        $container->get(ClockInterface::class)->sleep(1);

        $browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful('at the start');
        self::assertStringContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());
    }

    /**
     * A competition display's state (toolkit D3, the public rule of R-review-and-plan.md §1.5): the secret is not in it
     * for anybody - not for a maintainer of the event or an admin with the link either; the full reveal-mode matrix is
     * DisplaySecretPuzzleTest
     */
    public function testACompetitionDisplayNeverCarriesTheSecretBeforeTheReveal(): void
    {
        $browser = self::createClient();
        $this->secretPuzzle();
        $token = 'DispSecret111111111111';
        self::getContainer()->get(Connection::class)->insert('competition_display', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => CompetitionApiFixture::COMPETITION_API,
            'kind' => 'overlay',
            'name' => 'Stream',
            'token' => $token,
            'locale' => 'en',
            'design' => '{"schema": 1, "elements": [{"id": "puzzle01", "type": "puzzle", "hidden": false, "when": {"phases": ["idle"]}, "props": {}}]}',
            'created_at' => TestClock::FROZEN_NOW,
            'updated_at' => TestClock::FROZEN_NOW,
            'follow_mode' => 'pinned',
            'pinned_round_id' => CompetitionApiFixture::ROUND_FUTURE,
        ]);

        foreach ([[null, 'a guest'], [PlayerFixture::PLAYER_WITH_FAVORITES, 'a maintainer of the event'], [PlayerFixture::PLAYER_ADMIN, 'an admin']] as [$playerId, $who]) {
            if ($playerId !== null) {
                TestingLogin::asPlayer($browser, $playerId);
            }

            // The state answer and the display page with the same state inline (toolkit D4), read decoded
            foreach (['/display/' . $token . '/state', '/display/' . $token] as $url) {
                $browser->request('GET', $url);
                self::assertResponseIsSuccessful($who);
                $content = html_entity_decode((string) $browser->getResponse()->getContent(), ENT_QUOTES | ENT_HTML5);
                self::assertStringContainsString('API Visible', $content, 'No canary: the display shows no puzzle at all.');
                self::assertStringNotContainsString(self::SECRET_NAME, $content, "{$who} {$url}");
            }
        }
    }

    /**
     * The public competitor page and its state JSON (toolkit B4, the public rule of R-review-and-plan.md §1.5): the
     * secret is not on them for anybody - a maintainer of the event and an admin neither - not its name, puzzle id or
     * EAN; with "When the stopwatch starts" and a countdown it stays out at scheduled - 1 s and is there at the start.
     */
    public function testACompetitorPageNeverCarriesTheSecretBeforeTheReveal(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $puzzleId = $this->secretPuzzle();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $competitionId = CompetitionApiFixture::COMPETITION_API;
        $participantId = Uuid::uuid7()->toString();
        $connection->executeStatement('UPDATE competition SET competitor_pages = true WHERE id = :id', ['id' => $competitionId]);
        $connection->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source, registration_status) VALUES (:id, 'Sam Scanner', 'cz', :competition, 'imported', 'registered')",
            ['id' => $participantId, 'competition' => $competitionId],
        );
        $connection->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, table_number) VALUES (:id, :participant, :round, 7)',
            ['id' => Uuid::uuid7()->toString(), 'participant' => $participantId, 'round' => CompetitionApiFixture::ROUND_FUTURE],
        );
        $urls = [
            '/en/competitor/' . $competitionId . '/p/' . $participantId,
            '/en/competitor/' . $competitionId . '/t/7',
            '/competitor-state/' . $competitionId . '/p/' . $participantId,
        ];
        $assertSecret = function (bool $revealed, string $when) use ($browser, $urls, $puzzleId): void {
            foreach ([[null, 'a guest'], [PlayerFixture::PLAYER_WITH_FAVORITES, 'a maintainer of the event'], [PlayerFixture::PLAYER_ADMIN, 'an admin']] as [$playerId, $who]) {
                $browser->restart();
                if ($playerId !== null) {
                    TestingLogin::asPlayer($browser, $playerId);
                }

                foreach ($urls as $url) {
                    $browser->request('GET', $url);
                    self::assertResponseIsSuccessful("{$who} {$url}");
                    $content = html_entity_decode((string) $browser->getResponse()->getContent(), ENT_QUOTES | ENT_HTML5);
                    self::assertStringContainsString('API Visible', $content, 'No canary: the page shows no puzzle at all.');

                    if ($revealed) {
                        self::assertStringContainsString(self::SECRET_NAME, $content, "{$when}: {$who} {$url}");
                        continue;
                    }

                    foreach ([self::SECRET_NAME, $puzzleId, self::SECRET_EAN] as $secret) {
                        self::assertStringNotContainsString($secret, $content, "{$when}: {$who} {$url}");
                    }
                }
            }
        };

        $assertSecret(false, 'manual reveal not made');

        $entityManager = $container->get(EntityManagerInterface::class);
        $roundPuzzle = $entityManager->getRepository(CompetitionRoundPuzzle::class)->findOneBy(['puzzle' => $puzzleId]);
        self::assertNotNull($roundPuzzle);
        $bus = $container->get(MessageBusInterface::class);
        $bus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzle->id->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));
        $entityManager->clear();
        $round = $entityManager->find(CompetitionRound::class, CompetitionApiFixture::ROUND_FUTURE);
        self::assertNotNull($round);
        $hash = SecretRevealPreview::stopwatchStartHash($container->get(SecretRevealPreview::class)->byStopwatchStart($round), $round);
        $bus->dispatch(new ScheduleRoundStart(CompetitionApiFixture::ROUND_FUTURE, TestClock::now()->modify('+1 second'), PlayerFixture::PLAYER_ADMIN, revealPuzzles: true, revealListHash: $hash));
        $entityManager->clear();

        $assertSecret(false, 'scheduled - 1 s');

        $container->get(ClockInterface::class)->sleep(1);
        $assertSecret(true, 'at the start');
    }

    /**
     * The analytics studio's JSON (docs/features/competitions-management/analytics-studio.md §5, the public rule of
     * R-review-and-plan.md §1.5): before the reveal not one answer carries the secret - not its name, id or EAN, for
     * anybody (no viewer); the full swap test is AnalyticsStudioSecretPuzzleTest. After the reveal it is there.
     */
    public function testTheAnalyticsStudioNeverCarriesTheSecretBeforeTheReveal(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE competition_round SET slug = 'future-round' WHERE id = :id", ['id' => CompetitionApiFixture::ROUND_FUTURE]);
        $base = '/analytics-studio/' . CompetitionApiFixture::ROUND_FUTURE;
        $read = function (string $answer) use ($browser, $base): string {
            $cache = self::getContainer()->get('analytics_studio_cache');
                $cache->clear();
            $browser->request('GET', $base . '/' . $answer);
            self::assertResponseIsSuccessful($answer);

            return (string) $browser->getResponse()->getContent();
        };

        foreach ([[null, 'a guest'], [PlayerFixture::PLAYER_WITH_FAVORITES, 'a maintainer of the event'], [PlayerFixture::PLAYER_ADMIN, 'an admin']] as [$playerId, $who]) {
            $browser->restart();
            if ($playerId !== null) {
                TestingLogin::asPlayer($browser, $playerId);
            }

            self::assertStringContainsString('API Visible', $read('state'), 'No canary: the studio shows no puzzle at all.');
            foreach (['state', 'analytics', 'predictions'] as $answer) {
                $content = $read($answer);
                foreach ([self::SECRET_NAME, $puzzleId, self::SECRET_EAN] as $secret) {
                    self::assertStringNotContainsString($secret, $content, "{$who} {$answer}");
                }
            }
        }

        $connection->executeStatement('UPDATE competition_round_puzzle SET hide_until_round_starts = false WHERE puzzle_id = :puzzle', ['puzzle' => $puzzleId]);
        $connection->executeStatement('UPDATE puzzle SET hide_until = NULL, hide_image_until = NULL WHERE id = :puzzle', ['puzzle' => $puzzleId]);
        self::assertStringContainsString(self::SECRET_NAME, $read('state'), 'revealed');
    }

    public function testAdminPagesOfASecretPuzzleAreForAdminsOnly(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();

        foreach (['/admin/puzzles/{id}/edit', '/admin/puzzles/{id}/history'] as $path) {
            $url = str_replace('{id}', $puzzleId, $path);

            TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, 'a moderator: ' . $path);

            TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful('an admin: ' . $path);
        }
    }

    public function testAModeratorNeitherSeesNorDismissesABoxTextMismatchOfASecretPuzzle(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        foreach ([$puzzleId, PuzzleFixture::PUZZLE_500_01] as $mismatchPuzzleId) {
            $connection->executeStatement(
                "INSERT INTO box_text_mismatch (puzzle_id, field, value_key, box_values, catalogue_value, evidence, analysis_received_at, detected_at)
                 VALUES (?, 'pieces', '999', '[\"999\"]', '1000', '[]', '2026-10-01 10:00:00', '2026-10-01 10:00:00')",
                [$mismatchPuzzleId],
            );
        }

        // The list shows a moderator only the public puzzle's mismatch
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/admin/box-text-mismatches');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-testid="box-text-mismatch"]'));
        $token = (string) $crawler->filter('form[action$="/dismiss"] input[name="_token"]')->attr('value');

        // Dismissing the secret one with a valid token: no such puzzle for a moderator
        $browser->request('POST', str_replace('{id}', $puzzleId, '/admin/box-text-mismatches/{id}/dismiss'), ['_token' => $token, 'field' => 'pieces', 'value' => '999']);
        self::assertResponseStatusCodeSame(404, 'a moderator');
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM box_text_mismatch_decision WHERE puzzle_id = ?', [$puzzleId]));

        // An admin sees it, marked
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/admin/box-text-mismatches');
        self::assertCount(2, $crawler->filter('[data-testid="box-text-mismatch"]'));
        self::assertSelectorTextContains('body', 'Hidden now');
    }

    public function testAModeratorNeitherSeesNorDecidesNorRestoresASecretPuzzlesPicture(): void
    {
        $browser = self::createClient();
        $secretPuzzleId = $this->secretPuzzle();
        $secretProposal = $this->pictureProposal($secretPuzzleId);
        $publicProposal = $this->pictureProposal(PuzzleFixture::PUZZLE_1000_04);

        // The picture review lists only the public puzzle's proposal to a moderator (an admin sees both)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/admin/puzzle-change-requests/pictures');
        self::assertResponseIsSuccessful();
        self::assertSame(['picture-proposal-' . $publicProposal], $crawler->filter('[data-testid="picture-proposal"]')->extract(['id']));
        $token = $crawler->filter('#picture-proposal-' . $publicProposal . ' input[name="_token"]')->attr('value');

        // Deciding it with a valid token: no such request for a moderator
        $browser->request('POST', '/admin/puzzle-change-requests/' . $secretProposal . '/picture-decision', [
            '_token' => $token,
            'decision' => 'reject',
        ]);
        self::assertResponseStatusCodeSame(404);

        // "Restore this picture" on its history neither
        $browser->request('POST', str_replace(['{id}', '{decisionId}'], [$secretPuzzleId, Uuid::uuid7()->toString()], '/admin/puzzles/{id}/history/{decisionId}/restore-image'));
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/admin/puzzle-change-requests/pictures');
        self::assertSelectorExists('#picture-proposal-' . $secretProposal);
    }

    public function testWritesRefuseASecretPuzzleWhoeverSendsThem(): void
    {
        self::createClient();
        $puzzleId = $this->secretPuzzle();
        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch(new AddPuzzleToWishList(PlayerFixture::PLAYER_PRIVATE, $puzzleId));
            self::fail('Another player cannot use a secret puzzle (form, API or multiscan)');
        } catch (PuzzleNotFound) {
        }

        // Its adder neither - nothing personal before the reveal (SecretPuzzleWritesTest has every write)
        try {
            $bus->dispatch(new AddPuzzleToWishList(PlayerFixture::PLAYER_REGULAR, $puzzleId));
            self::fail('Nobody records anything on a secret puzzle before its reveal');
        } catch (PuzzleNotRevealedYet) {
        }

        self::assertFalse(self::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne(
            'SELECT 1 FROM wish_list_item WHERE puzzle_id = :puzzleId',
            ['puzzleId' => $puzzleId],
        ));
    }

    public function testAnOrganiserIsToldWhenTheSecretOpens(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // A modal (Turbo Frame): the message takes the frame's place
        $browser->request('POST', '/en/wishlist/' . $puzzleId . '/add', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseStatusCodeSame(422);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="modal-frame">', $content);
        self::assertStringContainsString('This puzzle is still secret until', $content);
        self::assertStringContainsString('you can add it after the reveal', $content);

        // A full page: back where it came from, with the message
        $browser->request('POST', '/en/wishlist/' . $puzzleId . '/add', server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertResponseStatusCodeSame(303);
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('This puzzle is still secret until', $crawler->text());
    }

    public function testReportingADuplicateOfASecretPuzzleIsRefusedUnseen(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::ImageOnly);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        // Image only - the name is public, but a report shows and proposes codes
        $browser->request('POST', str_replace('{id}', $puzzleId, '/en/puzzle/{id}/report-duplicate'), ['duplicate_puzzle_ids' => [PuzzleFixture::PUZZLE_500_05]]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheMarketplaceFilterIsNoWayToASecretPuzzle(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();

        // The puzzle filter is a writable prop - anybody can send any id in a Live request
        $render = function (string $playerId) use ($browser, $puzzleId): string {
            TestingLogin::asPlayer($browser, $playerId);
            $component = $this->createLiveComponent('MarketplaceListing', ['puzzleId' => $puzzleId], $browser);
            $component->setRouteLocale('en');

            return $component->render()->toString();
        };

        self::assertStringNotContainsString(self::SECRET_NAME, $render(PlayerFixture::PLAYER_PRIVATE));
        // Its organisers do get the filter
        self::assertStringContainsString(self::SECRET_NAME, $render(PlayerFixture::PLAYER_REGULAR));
    }

    public function testThePuzzlePickerListsACompetitionsSecretsOnlyForItsOrganisers(): void
    {
        $browser = self::createClient();
        $this->secretPuzzle();
        $picker = '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionApiFixture::COMPETITION_API;

        // Naming the competition opens nothing for somebody who does not organise it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', $picker);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());

        // A maintainer of it gets its secret puzzles
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', $picker);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());
    }

    public function testAnotherEventsOrganiserCannotPutAPictureSecretIntoTheirRound(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::ImageOnly);

        // PLAYER_PRIVATE organises WJPC 2024, nothing of the competition keeping the picture secret
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $wjpc = $entityManager->find(Competition::class, CompetitionFixture::COMPETITION_WJPC_2024);
        $organiser = $entityManager->find(Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($wjpc);
        self::assertNotNull($organiser);
        $wjpc->maintainers->add($organiser);
        $entityManager->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', '/en/add-puzzle-to-round/' . CompetitionRoundFixture::ROUND_WJPC_FINAL);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->last()->form();
        $prefix = (string) $crawler->filter('input[name$="[puzzle]"]')->attr('name');
        $prefix = substr($prefix, 0, (int) strpos($prefix, '['));

        $browser->submit($form, [
            $prefix . '[brand]' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            $prefix . '[puzzle]' => $puzzleId,
        ]);
        self::assertResponseStatusCodeSame(404);

        self::assertFalse(self::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne(
            'SELECT 1 FROM competition_round_puzzle WHERE puzzle_id = :puzzleId AND round_id = :roundId',
            ['puzzleId' => $puzzleId, 'roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        ));
    }

    public function testBarcodeSearchDoesNotFindAPicturelessSecret(): void
    {
        $browser = self::createClient();
        $this->secretPuzzle(PuzzleHideMode::ImageOnly);

        $browser->request('GET', '/en/puzzle-by-ean-search/' . self::SECRET_EAN);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());
    }

    /**
     * A pending proposal of a new picture (an artwork crop) of the puzzle - nothing is decided with it here.
     */
    private function pictureProposal(string $puzzleId): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $reporter = $entityManager->find(Player::class, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($puzzle);
        self::assertNotNull($reporter);
        $id = Uuid::uuid7();

        $entityManager->persist(new PuzzleChangeRequest(
            id: $id,
            puzzle: $puzzle,
            reporter: $reporter,
            submittedAt: TestClock::now(),
            proposedName: $puzzle->name,
            proposedManufacturer: $puzzle->manufacturer,
            proposedPiecesCount: $puzzle->piecesCount,
            proposedEan: $puzzle->ean,
            proposedIdentificationNumber: $puzzle->identificationNumber,
            proposedImage: 'proposal-' . $id->toString() . '.jpg',
            originalName: $puzzle->name,
            originalManufacturerId: $puzzle->manufacturer?->id,
            originalPiecesCount: $puzzle->piecesCount,
            originalEan: $puzzle->ean,
            originalIdentificationNumber: $puzzle->identificationNumber,
            originalImage: $puzzle->image,
            tags: ['artwork_crop'],
            confidence: 0.9,
        ));
        $entityManager->flush();

        return $id->toString();
    }

    private function secretPuzzle(PuzzleHideMode $hideMode = PuzzleHideMode::Entirely): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // A maintainer of the event who did not add the puzzle, and a community moderator
        $competition = $entityManager->find(Competition::class, CompetitionApiFixture::COMPETITION_API);
        $maintainer = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_FAVORITES);
        $moderator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($competition);
        self::assertNotNull($maintainer);
        self::assertNotNull($moderator);
        $competition->maintainers->add($maintainer);
        $moderator->moderatorSince = TestClock::now()->modify('-1 year');
        $entityManager->flush();

        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: self::SECRET_NAME,
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(self::SECRET_EAN),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionApiFixture::ROUND_FUTURE),
        ));
        $entityManager->clear();

        $roundPuzzle = $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle->puzzle->id->toString();
    }
}
