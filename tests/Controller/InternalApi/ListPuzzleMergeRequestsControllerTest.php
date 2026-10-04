<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Controller\InternalApi\ListPuzzleMergeRequestsController;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

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
}
