<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PDO;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyPublic;
use SpeedPuzzling\Web\Exceptions\RevealMomentAlreadyPassed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyShown;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleCannotHideEverywhere;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Message\KeepRoundPuzzleHiddenEverywhere;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Round 2 of the secret-puzzle review: placeholders hidden by hand are no round's, concurrent changes wait for each
 * other, nothing public is hidden again, nothing is revealed early, admins may correct a secret puzzle.
 */
final class SecretPuzzleSafeguardsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPlaceholderHiddenByHandCannotBeAddedSecretToARound(): void
    {
        $this->hideByHand(PuzzleFixture::PUZZLE_1000_03);

        try {
            $this->addToRound(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_1000_03, hide: true);
            self::fail('A placeholder hidden by hand is no round\'s');
        } catch (PuzzleHiddenByHand) {
        }

        $this->assertStillHiddenByHand(PuzzleFixture::PUZZLE_1000_03);
    }

    public function testPlaceholderAddedAsNotSecretCannotBeRevealedThroughTheRound(): void
    {
        $this->hideByHand(PuzzleFixture::PUZZLE_1000_03);
        // Added as an ordinary puzzle (the organiser cannot even pick it, but a crafted request could)
        $this->entityManager->persist($row = new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION),
            puzzle: $this->puzzle(PuzzleFixture::PUZZLE_1000_03),
        ));
        $this->entityManager->flush();
        $roundPuzzleId = $row->id->toString();
        $this->entityManager->clear();

        try {
            $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null));
            self::fail('A placeholder hidden by hand is no round\'s to reveal');
        } catch (PuzzleHiddenByHand) {
        }

        try {
            $this->messageBus->dispatch(new RevealRoundPuzzleNow($roundPuzzleId));
            self::fail('Reveal now on a row that is not secret rewrites nothing');
        } catch (RoundPuzzleAlreadyRevealed) {
        }

        $this->entityManager->clear();
        $this->assertStillHiddenByHand(PuzzleFixture::PUZZLE_1000_03);
    }

    public function testRevealNowOnARevealedRowIsRefused(): void
    {
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $this->messageBus->dispatch(new RevealRoundPuzzleNow($roundPuzzleId));
        $this->entityManager->clear();
        $revealedAt = $this->roundPuzzle($roundPuzzleId)->revealsAt();

        try {
            $this->messageBus->dispatch(new RevealRoundPuzzleNow($roundPuzzleId));
            self::fail('A revealed row keeps the moment it came out');
        } catch (RoundPuzzleAlreadyRevealed) {
        }

        $this->entityManager->clear();
        self::assertEquals($revealedAt, $this->roundPuzzle($roundPuzzleId)->revealsAt());
    }

    public function testSwitchingToAutomaticWhenTheRoundHasStartedIsRefused(): void
    {
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null));
        $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)->startsAt = new DateTimeImmutable('-1 hour');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->expectException(RevealMomentAlreadyPassed::class);
        $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Automatic, null));
    }

    public function testAMovedRoundNeverHidesAPuzzleItAlreadyRevealed(): void
    {
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $puzzleId = $this->roundPuzzle($roundPuzzleId)->puzzle->id->toString();

        // The round started 2 hours ago: its automatic reveal is over, the puzzle is public
        $start = new DateTimeImmutable('-2 hours')->setTime((int) new DateTimeImmutable('-2 hours')->format('H'), 0);
        $this->editRound($start);
        $revealed = $this->puzzle($puzzleId)->hideUntil;
        self::assertNotNull($revealed);
        self::assertLessThan(new DateTimeImmutable(), $revealed);

        // Moved a week later: the puzzle stays out, its reveal pinned to the moment it came out
        $this->editRound($start->modify('+7 days'));

        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        self::assertSame(RoundPuzzleReveal::Scheduled, $roundPuzzle->revealMode);
        self::assertSame($revealed->getTimestamp(), $roundPuzzle->revealsAt()?->getTimestamp());
        self::assertSame($revealed->getTimestamp(), $this->puzzle($puzzleId)->hideUntil?->getTimestamp());
    }

    public function testKeepHiddenEverywhereTakesOverAnOlderSiteWideHide(): void
    {
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $revealsAt = $roundPuzzle->revealsAt();
        self::assertNotNull($revealsAt);

        // As before the reveal model: the row did not hide it everywhere, and the site-wide hide ends 8 hours earlier
        $roundPuzzle->hidesEverywhere = false;
        $roundPuzzle->puzzle->keepSecretUntil($revealsAt->modify('-8 hours'), $revealsAt->modify('-8 hours'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->messageBus->dispatch(new KeepRoundPuzzleHiddenEverywhere($roundPuzzleId));
        $this->entityManager->clear();

        self::assertTrue($this->roundPuzzle($roundPuzzleId)->hidesEverywhere);
        self::assertSame($revealsAt->getTimestamp(), $this->puzzle($puzzleId)->hideUntil?->getTimestamp());
    }

    public function testARowShownOnTheEventPageTurnsSecretOnlyBeforeTheRoundAndWhileNoOtherRoundShowsIt(): void
    {
        $final = $this->round(CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $final->startsAt = new DateTimeImmutable('+20 days');
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Before the round starts, shown nowhere else: the organiser may still make it secret (a box ticked late)
        $this->addToRound(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_1500_01, hide: false);
        $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($this->rowOf(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_1500_01), PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null));
        $this->entityManager->clear();
        self::assertTrue($this->roundPuzzle($this->rowOf(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_1500_01))->hideUntilRoundStarts);

        // Another round shows the puzzle (PUZZLE_500_01 is in the Czech final, not secret)
        try {
            $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($this->rowOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_01), PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null));
            self::fail('A puzzle another round shows is never hidden again');
        } catch (RoundPuzzleAlreadyShown) {
        }

        // The round has started
        $this->addToRound(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_1500_02, hide: false);
        $final = $this->round(CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $final->startsAt = new DateTimeImmutable('-1 hour');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->expectException(RoundPuzzleAlreadyShown::class);
        $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($this->rowOf(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_1500_02), PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null));
    }

    public function testANameAlreadyPublicIsNeverHiddenAgain(): void
    {
        // Kept secret on the whole site with its picture only - its name is out
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, hideMode: PuzzleHideMode::ImageOnly);
        $puzzleId = $this->roundPuzzle($roundPuzzleId)->puzzle->id->toString();
        self::assertFalse($this->puzzle($puzzleId)->isHiddenAt(new DateTimeImmutable()));
        $this->entityManager->clear();

        // Switching its round to "entirely"
        try {
            $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null));
            self::fail('A public name is never hidden again by a reveal change');
        } catch (PuzzleNameAlreadyPublic) {
        }

        // Adding it "entirely" to another round
        try {
            $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
                roundPuzzleId: Uuid::uuid7(),
                roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
                userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
                brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                puzzle: $puzzleId,
                piecesCount: null,
                puzzlePhoto: null,
                eans: EanList::fromStored(null),
                brandCodes: BrandCodeList::fromStored(null),
                hideUntilRoundStarts: true,
                hideMode: PuzzleHideMode::Entirely,
            ));
            self::fail('A public name is never hidden again by another round');
        } catch (PuzzleNameAlreadyPublic) {
        }

        $this->entityManager->clear();
        self::assertFalse($this->puzzle($puzzleId)->isHiddenAt(new DateTimeImmutable()));

        // Its picture may stay secret in another round
        $this->addToRound(CompetitionRoundFixture::ROUND_CZECH_FINAL, $puzzleId, hide: true, hideMode: PuzzleHideMode::ImageOnly);
        self::assertFalse($this->puzzle($puzzleId)->isHiddenAt(new DateTimeImmutable()));
    }

    public function testKeepHiddenEverywhereNeverStartsAHideItOnlyExtendsOne(): void
    {
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $revealsAt = $roundPuzzle->revealsAt();
        self::assertNotNull($revealsAt);

        // The site no longer hides the puzzle: its name and picture are out - nothing to extend
        $roundPuzzle->hidesEverywhere = false;
        $roundPuzzle->puzzle->keepSecretUntil(new DateTimeImmutable('-1 hour'), new DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        try {
            $this->messageBus->dispatch(new KeepRoundPuzzleHiddenEverywhere($roundPuzzleId));
            self::fail('A puzzle out in public is never hidden again');
        } catch (RoundPuzzleCannotHideEverywhere) {
        }

        // The site hides it longer than this round would - nothing to take over either
        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        $roundPuzzle->puzzle->keepSecretUntil($revealsAt->modify('+1 day'), $revealsAt->modify('+1 day'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        try {
            $this->messageBus->dispatch(new KeepRoundPuzzleHiddenEverywhere($roundPuzzleId));
            self::fail('Nothing ends before this round\'s reveal');
        } catch (RoundPuzzleCannotHideEverywhere) {
        }

        $this->entityManager->clear();
        self::assertFalse($this->roundPuzzle($roundPuzzleId)->hidesEverywhere);
        self::assertSame($revealsAt->modify('+1 day')->getTimestamp(), $this->puzzle($puzzleId)->hideUntil?->getTimestamp());
    }

    public function testAnAdminCorrectsASecretPuzzleAModeratorCannot(): void
    {
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, photo: true);
        $puzzleId = $this->roundPuzzle($roundPuzzleId)->puzzle->id->toString();

        // A new secret puzzle's picture has a random name - nothing guessable from its name or id
        $image = $this->puzzle($puzzleId)->image;
        self::assertNotNull($image);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.\w+$/', $image);
        self::assertStringNotContainsString(substr($puzzleId, 0, 8), $image);

        $moderator = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($moderator);
        $moderator->moderatorSince = new DateTimeImmutable('-1 year');
        $this->entityManager->flush();

        try {
            $this->editPuzzle($puzzleId, PlayerFixture::PLAYER_WITH_STRIPE, 'Moderator Fix');
            self::fail('A moderator never changes a secret puzzle');
        } catch (PuzzleIsStillSecret) {
        }

        $this->editPuzzle($puzzleId, PlayerFixture::PLAYER_ADMIN, 'Admin Fix');
        $this->entityManager->clear();
        self::assertSame('Admin Fix', $this->puzzle($puzzleId)->name);
    }

    /**
     * Every handler that changes a secret row or a round's start locks first: the round (FOR SHARE when it adds or
     * changes one row, FOR NO KEY UPDATE when the round itself changes), then the puzzles with secret rows (FOR NO
     * KEY UPDATE) - a change already holding one makes it wait (here: fail fast), never compute on a state being
     * changed.
     *
     * @return iterable<string, array{string}>
     */
    public static function lockingMessages(): iterable
    {
        yield 'change reveal' => ['change'];
        yield 'reveal now' => ['reveal'];
        yield 'remove from the round' => ['remove'];
        yield 'keep hidden everywhere' => ['keep'];
        yield 'edit the round' => ['edit'];
        yield 'delete the round' => ['delete'];
        yield 'set the round puzzles' => ['set'];
        yield 'add the puzzle to another round' => ['add'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lockingMessages')]
    public function testHandlersLockThePuzzleBeforeReadingIt(string $action): void
    {
        $message = $this->lockingMessage($action);

        // FOR SHARE: blocks the handler's FOR NO KEY UPDATE, but not the key-share lock the test's own new row holds
        $failedStatement = $this->failedStatementWhileLocked(
            sprintf("SELECT id FROM puzzle WHERE id = '%s' FOR SHARE", PuzzleFixture::PUZZLE_500_03),
            $message,
        );

        self::assertIsString($failedStatement, 'The handler must wait for the puzzle\'s row');
        self::assertStringStartsWith('SELECT id FROM puzzle', $failedStatement);
        // Never FOR UPDATE - that one would also wait for every time or collection item inserted for the puzzle
        self::assertStringContainsString('FOR NO KEY UPDATE', $failedStatement);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lockingMessages')]
    public function testHandlersLockTheRoundBeforeItsPuzzles(string $action): void
    {
        $message = $this->lockingMessage($action);
        $roundId = $action === 'add' ? CompetitionRoundFixture::ROUND_CZECH_FINAL : CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

        // FOR NO KEY UPDATE: blocks the handler's FOR SHARE and FOR NO KEY UPDATE, but not the key-share lock the
        // test's own new row holds
        $failedStatement = $this->failedStatementWhileLocked(
            sprintf("SELECT id FROM competition_round WHERE id = '%s' FOR NO KEY UPDATE", $roundId),
            $message,
        );

        self::assertIsString($failedStatement, 'The handler must wait for the round\'s row');
        self::assertStringStartsWith('SELECT id FROM competition_round', $failedStatement);
        // A row added or changed shares the round; a change of the round (its start, its puzzles) takes it
        self::assertStringContainsString(
            in_array($action, ['edit', 'delete', 'set'], true) ? 'FOR NO KEY UPDATE' : 'FOR SHARE',
            $failedStatement,
        );
    }

    /**
     * A secret row of a fixture puzzle in the Qualification round - committed fixtures, so the other connection can
     * lock their rows (the test's own rows are not).
     */
    private function lockingMessage(string $action): object
    {
        $puzzleId = PuzzleFixture::PUZZLE_500_03;
        $row = new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION),
            puzzle: $this->puzzle($puzzleId),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            hidesEverywhere: $action !== 'keep',
        );
        $this->entityManager->persist($row);
        $this->entityManager->flush();
        $roundPuzzleId = $row->id->toString();
        $this->entityManager->clear();

        return match ($action) {
            'change' => new ChangeRoundPuzzleReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null),
            'reveal' => new RevealRoundPuzzleNow($roundPuzzleId),
            'remove' => new RemovePuzzleFromCompetitionRound($roundPuzzleId),
            'keep' => new KeepRoundPuzzleHiddenEverywhere($roundPuzzleId),
            // The same values - the handler changes nothing, so only its own lock holds the round
            'edit' => $this->editRoundMessage($this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)->startsAt),
            'delete' => new DeleteCompetitionRound(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionFixture::COMPETITION_WJPC_2024),
            'set' => new SetCompetitionRoundPuzzles(
                CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
                array_values(array_map(
                    static fn (CompetitionRoundPuzzle $roundPuzzle): string => $roundPuzzle->puzzle->id->toString(),
                    $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)->roundPuzzles->toArray(),
                )),
            ),
            default => new AddPuzzleToCompetitionRound(
                roundPuzzleId: Uuid::uuid7(),
                roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
                userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
                brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                puzzle: $puzzleId,
                piecesCount: null,
                puzzlePhoto: null,
                eans: EanList::fromStored(null),
                brandCodes: BrandCodeList::fromStored(null),
                hideUntilRoundStarts: true,
            ),
        };
    }

    /**
     * Dispatches the message while another connection holds a lock - the statement that waited too long, or null.
     */
    private function failedStatementWhileLocked(string $lockingStatement, object $message): null|string
    {
        $otherRequest = $this->otherDatabaseConnection();
        $otherRequest->exec("SET lock_timeout = '2s'");
        $otherRequest->beginTransaction();
        $otherRequest->query($lockingStatement);
        $this->entityManager->getConnection()->executeStatement("SET LOCAL lock_timeout = '200ms'");

        $failedStatement = null;

        try {
            $this->messageBus->dispatch($message);
        } catch (Throwable $exception) {
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof DriverException && $cause->getSQLState() === '55P03') {
                    $failedStatement = $cause->getQuery()?->getSQL();
                }
            }
        } finally {
            $otherRequest->rollBack();
        }

        return $failedStatement;
    }

    public function testBackfillMovesAGuessableImageOfASecretPuzzle(): void
    {
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, photo: true);
        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        $puzzle = $roundPuzzle->puzzle;
        $puzzleId = $puzzle->id->toString();

        // As before random names: a guessable image name, and no row marked yet
        $filesystem = self::getContainer()->get(\League\Flysystem\Filesystem::class);
        $guessable = 'ravensburger-secret-500-' . substr($puzzleId, 0, 8) . '.jpg';
        self::assertNotNull($puzzle->image);
        $filesystem->copy($puzzle->image, $guessable);
        $puzzle->moveImageTo($guessable);
        $roundPuzzle->hidesEverywhere = false;
        $this->entityManager->flush();
        $this->entityManager->clear();

        // The dry run says what it would move and what to purge
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel ?? self::bootKernel());
        $command = new \Symfony\Component\Console\Tester\CommandTester($application->find('myspeedpuzzling:backfill-round-puzzle-reveals'));
        $command->execute([]);
        $command->assertCommandIsSuccessful();
        $display = $command->getDisplay();
        self::assertStringContainsString('would move to a random name: ' . $guessable, $display);
        self::assertStringContainsString('/preset:puzzle_small/plain/' . $guessable, $display);
        self::assertStringContainsString('/original/' . $guessable, $display);
        self::assertStringContainsString('docker compose exec images-cache rm -f /var/cache/nginx/imgproxy/', $display);
        self::assertStringContainsString('0 times, 0 collection items', $display);
        $this->entityManager->clear();
        self::assertSame($guessable, $this->puzzle($puzzleId)->image);

        // The write copies inside the transaction and never deletes there - a rollback must not lose the picture
        $command->execute(['--write' => true]);
        $command->assertCommandIsSuccessful();
        $this->entityManager->clear();

        $image = (string) $this->puzzle($puzzleId)->image;
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $image);
        self::assertTrue($filesystem->fileExists($image));
        self::assertTrue($filesystem->fileExists($guessable));

        // The old object goes once the change is committed: an async message, sent only after the bus finished
        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $async */
        $async = self::getContainer()->get('messenger.transport.async');
        $deletes = array_values(array_filter(
            array_map(static fn (\Symfony\Component\Messenger\Envelope $envelope): object => $envelope->getMessage(), $async->getSent()),
            static fn (object $message): bool => $message instanceof \SpeedPuzzling\Web\Message\DeleteObsoletePuzzleImage,
        ));
        self::assertCount(1, $deletes);
        self::assertSame($guessable, $deletes[0]->path);

        self::getContainer()->get(\SpeedPuzzling\Web\MessageHandler\DeleteObsoletePuzzleImageHandler::class)($deletes[0]);
        self::assertFalse($filesystem->fileExists($guessable));
        self::assertTrue($filesystem->fileExists($image));
    }

    public function testAPuzzleThatBecomesSecretEverywhereLosesItsGuessableImageName(): void
    {
        // Created secret "image only" - a random name - then its picture name made guessable, as before random names
        $roundPuzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, photo: true);
        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $filesystem = self::getContainer()->get(\League\Flysystem\Filesystem::class);
        $guessable = 'ravensburger-keep-500-' . substr($puzzleId, 0, 8) . '.jpg';
        self::assertNotNull($roundPuzzle->puzzle->image);
        $filesystem->copy($roundPuzzle->puzzle->image, $guessable);
        $roundPuzzle->puzzle->moveImageTo($guessable);
        // The site-wide hide ends before this round's reveal - "Keep it hidden everywhere" applies
        $revealsAt = $roundPuzzle->revealsAt();
        self::assertNotNull($revealsAt);
        $roundPuzzle->hidesEverywhere = false;
        $roundPuzzle->puzzle->keepSecretUntil($revealsAt->modify('-8 hours'), $revealsAt->modify('-8 hours'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->messageBus->dispatch(new KeepRoundPuzzleHiddenEverywhere($roundPuzzleId));
        $this->entityManager->clear();

        $image = $this->puzzle($puzzleId)->image;
        self::assertNotNull($image);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $image);
        self::assertTrue($filesystem->fileExists($image));
    }

    private function editPuzzle(string $puzzleId, string $editorId, string $name): void
    {
        $record = self::getContainer()->get(GetPuzzleRecord::class)->byId($puzzleId);
        self::assertNotNull($record);

        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: $puzzleId,
            editorId: $editorId,
            values: new PuzzleRecordValues(
                name: $name,
                nameLanguage: $record->nameLanguage,
                alternativeNames: $record->alternativeNames,
                manufacturerId: $record->manufacturerId,
                piecesCount: $record->piecesCount,
                eans: EanList::fromStored($record->ean),
                brandCodes: BrandCodeList::fromStored($record->identificationNumber),
                image: PuzzleImageChoice::Keep,
                recordVersion: $record->recordVersion(),
            ),
            note: null,
        ));
    }

    private function hideByHand(string $puzzleId): void
    {
        $puzzle = $this->puzzle($puzzleId);
        $puzzle->approved = true;
        $puzzle->keepSecretUntil(new DateTimeImmutable('2099-01-01 00:00:00'), new DateTimeImmutable('2099-01-01 00:00:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function assertStillHiddenByHand(string $puzzleId): void
    {
        $this->entityManager->clear();
        $puzzle = $this->puzzle($puzzleId);
        self::assertEquals(new DateTimeImmutable('2099-01-01 00:00:00'), $puzzle->hideUntil);
        self::assertEquals(new DateTimeImmutable('2099-01-01 00:00:00'), $puzzle->hideImageUntil);
    }

    private function newSecretPuzzle(string $roundId, bool $photo = false, PuzzleHideMode $hideMode = PuzzleHideMode::Entirely): string
    {
        $roundPuzzleId = Uuid::uuid7();
        $photoFile = null;

        if ($photo) {
            $imagePath = tempnam(sys_get_temp_dir(), 'secret_puzzle_') . '.jpg';
            $image = imagecreatetruecolor(10, 10);
            assert($image !== false);
            imagejpeg($image, $imagePath);
            $photoFile = new UploadedFile($imagePath, 'box.jpg', 'image/jpeg', null, true);
        }

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Secret ' . $roundPuzzleId->toString(),
            piecesCount: 500,
            puzzlePhoto: $photoFile,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
        ));
        $this->entityManager->clear();

        return $roundPuzzleId->toString();
    }

    private function addToRound(string $roundId, string $puzzleId, bool $hide, PuzzleHideMode $hideMode = PuzzleHideMode::Entirely): void
    {
        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: Uuid::uuid7(),
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzleId,
            piecesCount: null,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: $hide,
            hideMode: $hideMode,
        ));
        $this->entityManager->clear();
    }

    private function editRoundMessage(DateTimeImmutable $startsAt): EditCompetitionRound
    {
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        return new EditCompetitionRound(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            name: $round->name,
            minutesLimit: $round->minutesLimit,
            startsAt: $startsAt,
            timezone: 'Europe/Prague',
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $round->category,
        );
    }

    private function editRound(DateTimeImmutable $startsAt): void
    {
        $this->messageBus->dispatch($this->editRoundMessage($startsAt));
        $this->entityManager->clear();
    }

    private function otherDatabaseConnection(): PDO
    {
        $databaseUrl = $_ENV['DATABASE_URL'] ?? null;
        self::assertIsString($databaseUrl);
        $url = parse_url($databaseUrl);
        self::assertIsArray($url);

        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $url['host'] ?? 'postgres', $url['port'] ?? 5432, ltrim($url['path'] ?? '', '/')),
            $url['user'] ?? null,
            $url['pass'] ?? null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function rowOf(string $roundId, string $puzzleId): string
    {
        $roundPuzzleId = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM competition_round_puzzle WHERE round_id = :roundId AND puzzle_id = :puzzleId',
            ['roundId' => $roundId, 'puzzleId' => $puzzleId],
        );
        self::assertIsString($roundPuzzleId);

        return $roundPuzzleId;
    }

    private function round(string $roundId): CompetitionRound
    {
        $round = $this->entityManager->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);

        return $round;
    }

    private function roundPuzzle(string $roundPuzzleId): CompetitionRoundPuzzle
    {
        $roundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle;
    }

    private function puzzle(string $puzzleId): Puzzle
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        return $puzzle;
    }
}
