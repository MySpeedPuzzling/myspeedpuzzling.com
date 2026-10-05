<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyKnown;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Message\SuggestPuzzleName;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SuggestPuzzleNameHandlerTest extends KernelTestCase
{
    use ChangesPuzzleRecords;

    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testAPlayersNameBecomesAChangeRequestOfTheNamesOnly(): void
    {
        $suggestionId = $this->suggest(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_REGULAR, 'Jardín mágico', 'es');

        $changeRequest = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($suggestionId);
        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $changeRequest->reporter->id->toString());
        self::assertSame([
            ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
            ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
            ['name' => 'Jardín mágico', 'language' => 'es'],
        ], $changeRequest->proposedAlternativeNames);
        self::assertSame([
            ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
            ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
        ], $changeRequest->originalAlternativeNames);
        // Nothing else is proposed
        self::assertNull($changeRequest->proposedName);
        self::assertNull($changeRequest->proposedEan);
        self::assertNull($changeRequest->proposedPiecesCount);
        self::assertNull($changeRequest->proposedManufacturer);
        self::assertSame('Puzzle 7', $changeRequest->originalName);

        // The puzzle waits for the review
        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_02);
        self::assertCount(2, $puzzle->alternativeNames);
    }

    public function testTheSameNameWithoutALanguageGetsTheSuggestedOne(): void
    {
        // PUZZLE_300 has "Kouzelna zahrada" without a language
        $suggestionId = $this->suggest(PuzzleFixture::PUZZLE_300, PlayerFixture::PLAYER_REGULAR, 'Kouzelná zahrada', 'cs');

        $changeRequest = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($suggestionId);
        self::assertSame([['name' => 'Kouzelná zahrada', 'language' => 'cs']], $changeRequest->proposedAlternativeNames);

        $diff = $changeRequest->proposedNamesDiff();
        self::assertSame([], $diff->added);
        self::assertCount(1, $diff->changed);
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function knownNames(): iterable
    {
        yield 'the main title, any spelling' => ['puzzle 7', null];
        yield 'another name with a language' => ['Kouzelna zahrada', 'sk'];
        yield 'another name, no language given' => ['Zauberhafter Garten', null];
    }

    #[DataProvider('knownNames')]
    public function testANameThePuzzleHasIsRefused(string $name, null|string $language): void
    {
        $this->expectException(PuzzleNameAlreadyKnown::class);

        $this->suggest(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_REGULAR, $name, $language);
    }

    public function testAModeratorsNameIsSavedAtOnceAndRecorded(): void
    {
        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));

        $suggestionId = $this->suggest(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_REGULAR, 'Jardín mágico', 'es');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_02);
        self::assertSame(['name' => 'Jardín mágico', 'language' => 'es'], $puzzle->alternativeNames[2] ?? null);
        self::assertStringContainsString("\njardin magico\n", (string) $puzzle->searchNames);
        self::assertNotNull($puzzle->namesChangedAt);

        // No change request in between
        self::assertNull($entityManager->find(PuzzleChangeRequest::class, $suggestionId));

        $decision = $entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'action' => PuzzleModerationAction::PuzzleEdited,
            'puzzleId' => Uuid::fromString(PuzzleFixture::PUZZLE_1000_02),
        ]);
        self::assertNotNull($decision);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $decision->decidedById->toString());
        $after = $decision->details['after'] ?? null;
        self::assertIsArray($after);
        self::assertSame($puzzle->alternativeNames, $after['alternativeNames'] ?? null);
    }

    public function testAnAdminMakesTheNameTheMainTitle(): void
    {
        $this->suggest(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_ADMIN, 'Magic Garden', 'en', makeMainTitle: true);

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_02);
        self::assertSame('Magic Garden', $puzzle->name);
        // English is no language of the main title
        self::assertNull($puzzle->nameLanguage);
        self::assertSame([
            ['name' => 'Puzzle 7', 'language' => null],
            ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
            ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
        ], $puzzle->alternativeNames);
    }

    public function testAPlayerCannotChangeTheMainTitle(): void
    {
        $suggestionId = $this->suggest(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_REGULAR, 'Magic Garden', null, makeMainTitle: true);

        $changeRequest = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($suggestionId);
        self::assertNull($changeRequest->proposedName);
        self::assertSame(['name' => 'Magic Garden', 'language' => null], $changeRequest->proposedAlternativeNames[2] ?? null);
    }

    public function testAFullListIsRefused(): void
    {
        $names = [];
        for ($i = 1; $i <= PuzzleNames::FORM_MAX_NAMES; $i++) {
            $names[] = new PuzzleName('Name ' . $i, null);
        }
        self::renamePuzzle(PuzzleFixture::PUZZLE_300, 'Puzzle 11', new PuzzleNames($names));

        $this->expectException(InvalidPuzzleValues::class);

        $this->suggest(PuzzleFixture::PUZZLE_300, PlayerFixture::PLAYER_REGULAR, 'One too many', 'de');
    }

    private function suggest(string $puzzleId, string $playerId, string $name, null|string $language, bool $makeMainTitle = false): string
    {
        $suggestionId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SuggestPuzzleName(
            suggestionId: $suggestionId,
            puzzleId: $puzzleId,
            playerId: $playerId,
            name: $name,
            language: $language,
            makeMainTitle: $makeMainTitle,
        ));

        return $suggestionId;
    }
}
