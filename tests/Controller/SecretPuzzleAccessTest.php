<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleApprovals;
use SpeedPuzzling\Web\Query\GetPuzzleMergeRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A puzzle a competition keeps secret has no page for anybody but its organisers, is out of every moderation queue,
 * and is approved, merged or edited by nobody until it is revealed.
 */
final class SecretPuzzleAccessTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testPuzzlePageIsOnlyThereForItsOrganisersBeforeTheReveal(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $page = '/en/puzzle/' . $puzzleId;

        // A maintainer of the event who did not add it
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, CompetitionApiFixture::COMPETITION_API);
        $maintainer = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertNotNull($competition);
        self::assertNotNull($maintainer);
        $competition->maintainers->add($maintainer);
        // A community moderator (no admin)
        $moderator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($moderator);
        $moderator->moderatorSince = new DateTimeImmutable('-1 year');
        $entityManager->flush();

        $this->browser->request('GET', $page);
        self::assertResponseStatusCodeSame(404, 'anonymous');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);
        $this->browser->request('GET', $page);
        self::assertResponseStatusCodeSame(404, 'another player');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('GET', $page);
        self::assertResponseStatusCodeSame(404, 'a moderator');
        $this->browser->request('GET', '/admin/puzzles/' . $puzzleId . '/edit');
        self::assertResponseStatusCodeSame(404, 'a moderator editing it');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->browser->request('GET', $page);
        self::assertResponseIsSuccessful('a maintainer of the event');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $page);
        self::assertResponseIsSuccessful('whoever added it');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', $page);
        self::assertResponseIsSuccessful('an admin');
    }

    public function testModerationQueueLeavesSecretPuzzlesOut(): void
    {
        $approvals = self::getContainer()->get(GetPuzzleApprovals::class);
        $countBefore = $approvals->countPending();

        $roundPuzzle = $this->addSecretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);
        $puzzleId = $roundPuzzle->puzzle->id->toString();

        self::assertFalse($roundPuzzle->puzzle->approved);
        self::assertSame($countBefore, $approvals->countPending());
        self::assertNull($approvals->byPuzzleId($puzzleId));

        foreach ($approvals->pending() as $pending) {
            self::assertNotSame($puzzleId, $pending->puzzleId);
        }

        foreach ($approvals->possibleDuplicates(PuzzleFixture::PUZZLE_1000_01) as $candidate) {
            self::assertNotSame($puzzleId, $candidate->puzzleId);
        }
    }

    public function testSecretPuzzleIsApprovedByNobody(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);

        $this->expectException(PuzzleIsStillSecret::class);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ApprovePuzzle(
            puzzleId: $roundPuzzle->puzzle->id->toString(),
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Anything',
            nameLanguage: null,
            alternativeNames: new PuzzleNames(),
            piecesCount: 1000,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
        ));
    }

    public function testSecretPuzzleIsMergedNeitherWay(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);
        $secretId = $roundPuzzle->puzzle->id->toString();
        $bus = self::getContainer()->get(MessageBusInterface::class);

        foreach ([[$secretId, PuzzleFixture::PUZZLE_500_05], [PuzzleFixture::PUZZLE_500_05, $secretId]] as [$survivor, $merged]) {
            $mergeRequestId = Uuid::uuid7()->toString();
            $bus->dispatch(new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: $survivor,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [$merged],
            ));

            // Out of the merge queue too
            self::assertNull(self::getContainer()->get(GetPuzzleMergeRequests::class)->byId($mergeRequestId));

            try {
                $bus->dispatch(new ApprovePuzzleMergeRequest(
                    mergeRequestId: $mergeRequestId,
                    reviewerId: PlayerFixture::PLAYER_ADMIN,
                    survivorPuzzleId: $survivor,
                    mergedName: 'Merged',
                    mergedEans: null,
                    mergedBrandCodes: null,
                    mergedPiecesCount: 1000,
                    mergedManufacturerId: null,
                    selectedImagePuzzleId: null,
                ));
                self::fail('A merge with a secret puzzle must be refused');
            } catch (PuzzleIsStillSecret $exception) {
                self::assertSame($secretId, $exception->puzzleId);
            }
        }
    }

    private function addSecretPuzzle(string $userId): CompetitionRoundPuzzle
    {
        $roundPuzzleId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: $userId,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Rolling Hills Secret',
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $roundPuzzle = $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle;
    }
}
