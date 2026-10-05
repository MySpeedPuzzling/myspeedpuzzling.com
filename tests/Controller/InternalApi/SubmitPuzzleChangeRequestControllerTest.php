<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\InternalApi\SubmitPuzzleChangeRequestController;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubmitPuzzleChangeRequestControllerTest extends KernelTestCase
{
    /** Its stored code 4005556789012 has a wrong check digit and it has no pending proposal */
    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_03;

    public function testFilesTheProposalAsTheReviewerKeepingEveryFieldNotGiven(): void
    {
        $response = $this->controller()($this->jsonRequest([
            'puzzleId' => self::PUZZLE,
            'ean' => '4005556789012, 4005555011897',
        ]));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['changeRequestId'] ?? null);
        // The record the proposal was filed against - sent back on approve
        self::assertSame(
            PuzzleRecordVersion::ofPuzzle(self::getContainer()->get(PuzzleRepository::class)->get(self::PUZZLE)),
            $body['recordVersion'] ?? null,
        );

        $changeRequest = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($body['changeRequestId']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $changeRequest->reporter->id->toString());
        self::assertSame('4005556789012, 4005555011897', $changeRequest->proposedEan);
        self::assertSame($changeRequest->originalName, $changeRequest->proposedName);
        self::assertSame($changeRequest->originalPiecesCount, $changeRequest->proposedPiecesCount);
        self::assertSame($changeRequest->originalIdentificationNumber, $changeRequest->proposedIdentificationNumber);
        self::assertNull($changeRequest->proposedImage);
    }

    public function testInvalidNewCodeIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('45555011897');

        $this->controller()($this->jsonRequest(['puzzleId' => self::PUZZLE, 'ean' => '45555011897']));
    }

    public function testNothingToChangeIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->controller()($this->jsonRequest(['puzzleId' => self::PUZZLE, 'ean' => '4005556789012']));
    }

    public function testPuzzleWithAPendingProposalAnswersConflict(): void
    {
        // PuzzleReportFixture holds a pending change request for this one
        $response = $this->controller()($this->jsonRequest([
            'puzzleId' => PuzzleFixture::PUZZLE_500_02,
            'ean' => '4005555011897',
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testFilesTheOtherNamesAsTheWholeListWithTheMainTitlesLanguage(): void
    {
        $response = $this->controller()($this->jsonRequest([
            'puzzleId' => PuzzleFixture::PUZZLE_1000_02,
            'alternativeNames' => [
                ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
                ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
                ['name' => ' Jardín  mágico ', 'language' => 'ES'],
            ],
            'nameLanguage' => null,
        ]));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['changeRequestId'] ?? null);
        self::assertNull($body['nameLanguage']);
        self::assertIsArray($body['alternativeNames'] ?? null);
        self::assertSame(['name' => 'Jardín mágico', 'language' => 'es'], $body['alternativeNames'][2] ?? null);

        $changeRequest = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($body['changeRequestId']);
        self::assertSame($body['alternativeNames'], $changeRequest->proposedAlternativeNames);
        self::assertCount(2, $changeRequest->originalAlternativeNames ?? []);
        self::assertSame('Puzzle 7', $changeRequest->proposedName);
    }

    public function testTheMainTitlesLanguageAloneIsAProposal(): void
    {
        $response = $this->controller()($this->jsonRequest(['puzzleId' => PuzzleFixture::PUZZLE_1000_02, 'nameLanguage' => 'cs']));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('cs', $body['nameLanguage']);
    }

    public function testTheSameNamesInAnotherOrderAreNothingToChange(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Nothing to change');

        $this->controller()($this->jsonRequest([
            'puzzleId' => PuzzleFixture::PUZZLE_1000_02,
            'alternativeNames' => [
                ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
                ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
            ],
        ]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidNames(): iterable
    {
        yield 'not a list' => [['alternativeNames' => ['name' => 'X']]];
        yield 'a blank name' => [['alternativeNames' => [['name' => '  ', 'language' => 'cs']]]];
        yield 'an unknown language' => [['alternativeNames' => [['name' => 'X', 'language' => 'xx-invalid-tag-long']]]];
        yield 'an unknown main title language' => [['nameLanguage' => 'qqq']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidNames')]
    public function testInvalidNamesAreRefused(array $body): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->controller()($this->jsonRequest(['puzzleId' => PuzzleFixture::PUZZLE_1000_02] + $body));
    }

    private function controller(): SubmitPuzzleChangeRequestController
    {
        $container = self::getContainer();

        return new SubmitPuzzleChangeRequestController(
            $container->get(MessageBusInterface::class),
            $container->get(GetPuzzleRecord::class),
            $container->get(GetPendingPuzzleProposals::class),
            PlayerFixture::PLAYER_ADMIN,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create('/internal-api/puzzle-change-requests', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    }
}
