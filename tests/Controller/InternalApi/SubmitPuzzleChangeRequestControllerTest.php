<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\InternalApi\SubmitPuzzleChangeRequestController;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
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
        // A list equal to the puzzle's proposes nothing for the field
        self::assertNull($changeRequest->proposedIdentificationNumber);
        self::assertNull($changeRequest->proposedImage);
    }

    public function testTheCodesMayComeAsAListOneCodePerEntry(): void
    {
        $response = $this->controller()($this->jsonRequest([
            'puzzleId' => self::PUZZLE,
            'ean' => ['4005556789012', '04005555011897', ''],
            'identificationNumber' => ['rb-8', ' 12000199 '],
        ]));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $changeRequest = $this->filed($response);
        self::assertSame('4005556789012, 4005555011897', $changeRequest->proposedEan);
        self::assertSame('RB-8, 12000199', $changeRequest->proposedIdentificationNumber);
    }

    public function testAnEmptyListRemovesEveryCode(): void
    {
        $response = $this->controller()($this->jsonRequest(['puzzleId' => self::PUZZLE, 'ean' => []]));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $changeRequest = $this->filed($response);
        self::assertSame('', $changeRequest->proposedEan, 'every code removed');
        self::assertSame('4005556789012', $changeRequest->originalEan);
    }

    public function testCodesInTheirStoredFormAgainAreNothingToChange(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->controller()($this->jsonRequest(['puzzleId' => self::PUZZLE, 'ean' => [' 4005556789012 ']]));
    }

    public function testAListOfBlankEntriesIsRefusedNeverARemoval(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"ean" holds no code - only an empty list [] removes every code.');

        $this->controller()($this->jsonRequest(['puzzleId' => self::PUZZLE, 'ean' => ['', ' ']]));
    }

    public function testACodeLongerThanTheColumnIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"identificationNumber" holds a value longer than 255 characters.');

        $this->controller()($this->jsonRequest(['puzzleId' => self::PUZZLE, 'identificationNumber' => [str_repeat('A', 256)]]));
    }

    public function testCodesTogetherLongerThanTheColumnAreRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('at most 255 characters');

        $this->controller()($this->jsonRequest([
            'puzzleId' => self::PUZZLE,
            'identificationNumber' => array_map(static fn (int $i): string => 'CODE-' . $i, range(1, 40)),
        ]));
    }

    public function testACodeListOfAnythingButStringsIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"ean" must be a string or a list of strings.');

        $this->controller()($this->jsonRequest(['puzzleId' => self::PUZZLE, 'ean' => [4005555011897]]));
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

    public function testNamesOnlyAreFiledWhileAnotherProposalWaits(): void
    {
        // PuzzleReportFixture holds a pending change request of the EAN for this one
        $response = $this->controller()($this->jsonRequest([
            'puzzleId' => PuzzleFixture::PUZZLE_500_02,
            'alternativeNames' => [['name' => 'Puzzle zwei', 'language' => 'de']],
        ]));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        // ... and a proposal of more than the names is not held up by the names-only one
        $response = $this->controller()($this->jsonRequest([
            'puzzleId' => PuzzleFixture::PUZZLE_1000_05,
            'nameLanguage' => 'cs',
        ]));
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $response = $this->controller()($this->jsonRequest([
            'puzzleId' => PuzzleFixture::PUZZLE_1000_05,
            'piecesCount' => 1500,
        ]));
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
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
        yield 'an unknown key in an entry' => [['alternativeNames' => [['name' => 'Jardín mágico', 'lang' => 'es']]]];
        yield 'an entry as a list' => [['alternativeNames' => [['Jardín mágico', 'es']]]];
    }

    public function testAnUnknownKeyInAnEntryIsNamed(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('An entry of "alternativeNames" holds only "name" and "language" - not: lang.');

        $this->controller()($this->jsonRequest([
            'puzzleId' => PuzzleFixture::PUZZLE_1000_02,
            'alternativeNames' => [['name' => 'Jardín mágico', 'lang' => 'es']],
        ]));
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

    private function filed(Response $response): PuzzleChangeRequest
    {
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['changeRequestId'] ?? null);

        return self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($body['changeRequestId']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create('/internal-api/puzzle-change-requests', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    }
}
