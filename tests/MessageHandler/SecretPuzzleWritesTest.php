<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzlesToCollection;
use SpeedPuzzling\Web\Message\AddPuzzlesToWishList;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\AddPuzzleToSellSwapList;
use SpeedPuzzling\Web\Message\AddPuzzleToWishList;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Message\BorrowPuzzleFromPlayer;
use SpeedPuzzling\Web\Message\BorrowPuzzlesFromPlayer;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\LendPuzzlesToPlayer;
use SpeedPuzzling\Web\Message\LendPuzzleToPlayer;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Message\StartStopwatch;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A secret competition puzzle takes nothing personal before its reveal - from anybody: whoever may not see it does not
 * learn it exists (PuzzleNotFound), its organisers are told when it opens (PuzzleNotRevealedYet). Every write path
 * refuses it in its handler, so the web forms, API v1 and multiscan all do. Each case fails when its guard is removed.
 */
final class SecretPuzzleWritesTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function personalRecords(): iterable
    {
        yield 'wishlist' => ['wishlist'];
        yield 'wishlist (multiscan)' => ['wishlist_bulk'];
        yield 'collection' => ['collection'];
        yield 'collection (multiscan)' => ['collection_bulk'];
        yield 'sell / swap' => ['sell_swap'];
        yield 'lend' => ['lend'];
        yield 'lend (multiscan)' => ['lend_bulk'];
        yield 'borrow' => ['borrow'];
        yield 'borrow (multiscan)' => ['borrow_bulk'];
        yield 'time (form, saved stopwatch, API v1)' => ['time'];
        yield 'relax / tracking' => ['tracking'];
        yield 'a time moved onto it' => ['edit_time'];
    }

    #[DataProvider('personalRecords')]
    public function testNobodyRecordsAnythingOnASecretPuzzleBeforeItsReveal(string $write): void
    {
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::Entirely);

        // Another player: it does not exist
        try {
            $this->messageBus->dispatch($this->write($write, $puzzleId, organiser: false));
            self::fail('Another player must not learn a secret puzzle exists: ' . $write);
        } catch (PuzzleNotFound) {
        }

        // Its organiser (here: whoever added it) sees it - and is told when it opens
        try {
            $this->messageBus->dispatch($this->write($write, $puzzleId, organiser: true));
            self::fail('Not even its organiser records anything before the reveal: ' . $write);
        } catch (PuzzleNotRevealedYet $refusal) {
            self::assertSame($puzzleId, $refusal->puzzleId);
            self::assertNotNull($refusal->revealsAt);
            self::assertSame('Europe/Prague', $refusal->timezone);
        }
    }

    public function testAnAdminRecordsNothingBeforeTheRevealEither(): void
    {
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::Entirely);

        $this->expectException(PuzzleNotRevealedYet::class);

        $this->messageBus->dispatch(new AddPuzzleToWishList(PlayerFixture::PLAYER_ADMIN, $puzzleId));
    }

    public function testImageOnlyKeepsTheNamePublicSoItCanBeUsed(): void
    {
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::ImageOnly);

        $this->messageBus->dispatch(new AddPuzzleToWishList(PlayerFixture::PLAYER_PRIVATE, $puzzleId));

        self::assertNotFalse($this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM wish_list_item WHERE puzzle_id = :puzzleId',
            ['puzzleId' => $puzzleId],
        ));
    }

    public function testAStopwatchOnASecretPuzzleIsItsOrganisersOnly(): void
    {
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::Entirely);

        try {
            $this->messageBus->dispatch(new StartStopwatch(Uuid::uuid7(), PlayerFixture::PLAYER_PRIVATE_USER_ID, $puzzleId));
            self::fail('A stopwatch shows its puzzle - not on somebody else\'s secret one');
        } catch (PuzzleNotFound) {
        }

        // Its organiser may time it (nothing is recorded until the time is saved)
        $stopwatchId = Uuid::uuid7();
        $this->messageBus->dispatch(new StartStopwatch($stopwatchId, PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzleId));
        self::assertNotNull($this->entityManager->find(Stopwatch::class, $stopwatchId->toString()));
    }

    public function testAnEanIsNotLinkedToAPuzzleWhosePictureIsSecret(): void
    {
        // Image only: the name is public, but the codes would give the box away
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::ImageOnly);

        $this->expectException(PuzzleNotFound::class);

        $this->messageBus->dispatch(new LinkEanToPuzzle($puzzleId, PlayerFixture::PLAYER_PRIVATE, '4005556175512'));
    }

    public function testAnotherCompetitionsPictureSecretIsNoOtherRoundsPuzzle(): void
    {
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::ImageOnly);

        $this->expectException(PuzzleNotFound::class);

        // PLAYER_PRIVATE organises nothing of the competition keeping its picture secret
        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: Uuid::uuid7(),
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_PRIVATE_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzleId,
            piecesCount: null,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: false,
        ));
    }

    public function testANewSecretPuzzleGetsARandomImageName(): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'secret_box_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $imagePath);

        $roundPuzzleId = Uuid::uuid7();
        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Guessable Name Secret',
            piecesCount: 1000,
            puzzlePhoto: new UploadedFile($imagePath, 'box.jpg', 'image/jpeg', null, true),
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::ImageOnly,
        ));
        $this->entityManager->clear();

        $roundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId->toString());
        self::assertNotNull($roundPuzzle);
        $image = $roundPuzzle->puzzle->image;
        self::assertNotNull($image);
        // Never derived from the brand, name or id - all public with "image only"
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $image);
        self::assertStringNotContainsStringIgnoringCase('guessable', $image);
    }

    private function write(string $write, string $puzzleId, bool $organiser): object
    {
        $playerId = $organiser ? PlayerFixture::PLAYER_REGULAR : PlayerFixture::PLAYER_PRIVATE;
        $userId = $organiser ? PlayerFixture::PLAYER_REGULAR_USER_ID : PlayerFixture::PLAYER_PRIVATE_USER_ID;
        $otherPlayerId = $organiser ? PlayerFixture::PLAYER_PRIVATE : PlayerFixture::PLAYER_REGULAR;

        return match ($write) {
            'wishlist' => new AddPuzzleToWishList($playerId, $puzzleId),
            'wishlist_bulk' => new AddPuzzlesToWishList($playerId, [$puzzleId]),
            'collection' => new AddPuzzleToCollection($playerId, $puzzleId, null, null),
            'collection_bulk' => new AddPuzzlesToCollection($playerId, [$puzzleId], null),
            'sell_swap' => new AddPuzzleToSellSwapList($playerId, $puzzleId, ListingType::Sell, 10.0, PuzzleCondition::New, null),
            'lend' => new LendPuzzleToPlayer($playerId, $puzzleId, $otherPlayerId),
            'lend_bulk' => new LendPuzzlesToPlayer($playerId, [$puzzleId], $otherPlayerId),
            'borrow' => new BorrowPuzzleFromPlayer($playerId, $puzzleId, $otherPlayerId),
            'borrow_bulk' => new BorrowPuzzlesFromPlayer($playerId, [$puzzleId], $otherPlayerId),
            'time' => new AddPuzzleSolvingTime(
                timeId: Uuid::uuid7(),
                userId: $userId,
                puzzleId: $puzzleId,
                competitionId: null,
                time: '01:00:00',
                comment: null,
                finishedPuzzlesPhoto: null,
                groupPlayers: [],
                finishedAt: null,
                firstAttempt: false,
                unboxed: false,
                createdVia: SolvingTimeSource::Api,
            ),
            'tracking' => new AddPuzzleTracking(
                trackingId: Uuid::uuid7(),
                userId: $userId,
                puzzleId: $puzzleId,
                comment: null,
                finishedPuzzlesPhoto: null,
                groupPlayers: [],
                finishedAt: null,
            ),
            // TIME_01 is PLAYER_REGULAR's, TIME_02 PLAYER_PRIVATE's - each moves their own result onto the puzzle
            default => new EditPuzzleSolvingTime(
                currentUserId: $userId,
                puzzleSolvingTimeId: $organiser ? PuzzleSolvingTimeFixture::TIME_01 : PuzzleSolvingTimeFixture::TIME_02,
                competitionId: null,
                time: '00:30:00',
                comment: null,
                groupPlayers: [],
                finishedAt: null,
                finishedPuzzlesPhoto: null,
                firstAttempt: false,
                unboxed: false,
                puzzleId: $puzzleId,
            ),
        };
    }

    /**
     * Created secret for the API competition's future round by PLAYER_REGULAR (its adder - an organiser of it).
     */
    private function secretPuzzle(PuzzleHideMode $hideMode): string
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Writes Secret ' . $roundPuzzleId->toString(),
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
        ));
        $this->entityManager->clear();

        $roundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId->toString());
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle->puzzle->id->toString();
    }
}
