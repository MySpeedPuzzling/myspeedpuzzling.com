<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\InternalApi\ListPuzzleMergeRequestsController;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

final class ListPuzzleMergeRequestsControllerTest extends KernelTestCase
{
    /**
     * A candidate carries every other name (`alternativeNames`, in order, with languages) next to `alternativeName`,
     * which keeps its meaning for existing callers: the Czech name when there is one, else the first.
     */
    public function testCandidatesCarryEveryOtherNameAndTheSingleOneOfOld(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_500_02);
        self::assertNotNull($puzzle);
        $puzzle->changeNames($puzzle->name, $puzzle->nameLanguage, PuzzleNames::fromArray([
            ['name' => 'Rätsel zwei', 'language' => 'de'],
            ['name' => 'Hádanka dvě', 'language' => 'cs'],
        ]), new DateTimeImmutable());
        $entityManager->flush();
        $entityManager->clear();

        $controller = $container->get(ListPuzzleMergeRequestsController::class);
        $response = $controller(Request::create('/internal-api/puzzle-merge-requests', 'GET', ['limit' => 100]));

        $content = $response->getContent();
        self::assertIsString($content);
        /** @var array{mergeRequests: list<array{mergeRequestId: string, candidates: list<array{puzzleId: string, alternativeName: null|string, alternativeNames: list<array{name: string, language: null|string}>}>}>} $body */
        $body = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        $candidates = [];

        foreach ($body['mergeRequests'] as $mergeRequest) {
            if ($mergeRequest['mergeRequestId'] === PuzzleReportFixture::MERGE_REQUEST_PENDING) {
                foreach ($mergeRequest['candidates'] as $candidate) {
                    $candidates[$candidate['puzzleId']] = $candidate;
                }
            }
        }

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_02, $candidates);
        self::assertSame('Hádanka dvě', $candidates[PuzzleFixture::PUZZLE_500_02]['alternativeName']);
        self::assertSame([
            ['name' => 'Rätsel zwei', 'language' => 'de'],
            ['name' => 'Hádanka dvě', 'language' => 'cs'],
        ], $candidates[PuzzleFixture::PUZZLE_500_02]['alternativeNames']);
    }

    public function testItemsCarryTheReportedLanguagesAndCandidatesTheirMainTitlesLanguage(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_1000_02);
        self::assertNotNull($puzzle);
        $puzzle->changeNames('Kouzelné ráno', 'cs', $puzzle->alternativeNames(), new DateTimeImmutable());
        $entityManager->flush();

        $mergeRequestId = Uuid::uuid7()->toString();
        $container->get(MessageBusInterface::class)->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_1000_01,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_1000_02],
            reportedNameLanguages: [PuzzleFixture::PUZZLE_1000_02 => 'cs'],
        ));

        $controller = $container->get(ListPuzzleMergeRequestsController::class);
        $response = $controller(Request::create('/internal-api/puzzle-merge-requests', 'GET', ['limit' => 100]));

        $content = $response->getContent();
        self::assertIsString($content);
        // An object even when empty - a map, never a list
        self::assertStringContainsString('"reportedNameLanguages":{}', $content);

        /** @var array{mergeRequests: list<array{mergeRequestId: string, reportedNameLanguages: array<string, string>, candidates: list<array{puzzleId: string, nameLanguage: null|string}>}>} $body */
        $body = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $items = array_values(array_filter($body['mergeRequests'], static fn (array $item): bool => $item['mergeRequestId'] === $mergeRequestId));
        self::assertCount(1, $items);

        self::assertSame([PuzzleFixture::PUZZLE_1000_02 => 'cs'], $items[0]['reportedNameLanguages']);
        self::assertSame(
            [PuzzleFixture::PUZZLE_1000_01 => null, PuzzleFixture::PUZZLE_1000_02 => 'cs'],
            array_column($items[0]['candidates'], 'nameLanguage', 'puzzleId'),
        );
    }

    public function testCandidatesCarryTheirRecordVersion(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $controller = $container->get(ListPuzzleMergeRequestsController::class);
        $response = $controller(Request::create('/internal-api/puzzle-merge-requests', 'GET', ['limit' => 100]));

        /** @var array{mergeRequests: list<array{mergeRequestId: string, candidates: list<array{puzzleId: string, recordVersion: string}>}>} $body */
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $items = array_values(array_filter($body['mergeRequests'], static fn (array $item): bool => $item['mergeRequestId'] === PuzzleReportFixture::MERGE_REQUEST_PENDING));
        self::assertCount(1, $items);

        $puzzleRepository = $container->get(PuzzleRepository::class);

        foreach ($items[0]['candidates'] as $candidate) {
            // The version the approval compares with - the moderators' forms compute the same
            self::assertSame(PuzzleRecordVersion::ofPuzzle($puzzleRepository->get($candidate['puzzleId'])), $candidate['recordVersion']);
        }

        self::assertCount(2, $items[0]['candidates']);
    }
}
