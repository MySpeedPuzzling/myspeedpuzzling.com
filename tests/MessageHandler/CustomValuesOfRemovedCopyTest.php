<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\KeepDuplicateCopy;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A copy of a result is removed and another stays: what the player wrote into their own fields on the copy moves
 * onto the kept result (docs/features/custom-fields.md "When a copy of a result is removed") - "Keep this one" and
 * the automatic removal of the same form sent twice (docs/features/duplicate-results.md).
 */
final class CustomValuesOfRemovedCopyTest extends KernelTestCase
{
    private const string PLAYER = DuplicateResultsFixture::PLAYER_TWINS;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private CustomFieldsScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->scenario = new CustomFieldsScenario(self::getContainer());
    }

    public function testKeepThisOneMovesTheValuesOfTheDeletedCopyOntoTheKeptOne(): void
    {
        $notes = $this->scenario->field(self::PLAYER, 'Notes');
        $this->scenario->setValues(DuplicateResultsFixture::TIME_STRONG_B, self::PLAYER, [$notes => 'written on the copy']);

        $this->keep(DuplicateResultsFixture::TIME_STRONG_A);

        self::assertFalse($this->exists(DuplicateResultsFixture::TIME_STRONG_B));
        self::assertSame([$notes => 'written on the copy'], $this->scenario->storedValues(DuplicateResultsFixture::TIME_STRONG_A, self::PLAYER));
        self::assertSame(0, $this->scenario->valueRows(DuplicateResultsFixture::TIME_STRONG_B));
    }

    public function testWhenBothHaveValuesTheKeptOnesStayAndOnlyTheMissingFieldsAreFilled(): void
    {
        $notes = $this->scenario->field(self::PLAYER, 'Notes');
        $breaks = $this->scenario->field(self::PLAYER, 'Breaks', CustomFieldType::Number);
        $place = $this->scenario->field(self::PLAYER, 'Place');
        $this->scenario->setValues(DuplicateResultsFixture::TIME_STRONG_A, self::PLAYER, [$notes => 'of the kept one', $place => 'table']);
        $this->scenario->setValues(DuplicateResultsFixture::TIME_STRONG_B, self::PLAYER, [$notes => 'of the copy', $breaks => '2']);

        $this->keep(DuplicateResultsFixture::TIME_STRONG_A);

        self::assertEquals(
            [$notes => 'of the kept one', $place => 'table', $breaks => 2],
            $this->scenario->storedValues(DuplicateResultsFixture::TIME_STRONG_A, self::PLAYER),
        );
        self::assertSame(1, $this->scenario->valueRows(DuplicateResultsFixture::TIME_STRONG_A));
        self::assertSame(0, $this->scenario->valueRows(DuplicateResultsFixture::TIME_STRONG_B));
    }

    public function testKeepingTheOtherCopyWorksTheSameWay(): void
    {
        $notes = $this->scenario->field(self::PLAYER, 'Notes');
        $this->scenario->setValues(DuplicateResultsFixture::TIME_STRONG_A, self::PLAYER, [$notes => 'written on the first']);

        $this->keep(DuplicateResultsFixture::TIME_STRONG_B);

        self::assertFalse($this->exists(DuplicateResultsFixture::TIME_STRONG_A));
        self::assertSame([$notes => 'written on the first'], $this->scenario->storedValues(DuplicateResultsFixture::TIME_STRONG_B, self::PLAYER));
    }

    public function testACopyWithoutValuesChangesNothing(): void
    {
        $notes = $this->scenario->field(self::PLAYER, 'Notes');
        $this->scenario->setValues(DuplicateResultsFixture::TIME_STRONG_A, self::PLAYER, [$notes => 'of the kept one']);
        $before = $this->database->fetchAssociative('SELECT id, updated_at FROM solving_time_custom_values WHERE solving_time_id = :id', ['id' => DuplicateResultsFixture::TIME_STRONG_A]);

        $this->keep(DuplicateResultsFixture::TIME_STRONG_A);

        self::assertSame($before, $this->database->fetchAssociative('SELECT id, updated_at FROM solving_time_custom_values WHERE solving_time_id = :id', ['id' => DuplicateResultsFixture::TIME_STRONG_A]));
    }

    public function testTheAutomaticRemovalMovesTheValuesAndAnUndoBringsTheCopyBackWithoutThem(): void
    {
        $notes = $this->scenario->field(self::PLAYER, 'Notes');
        $breaks = $this->scenario->field(self::PLAYER, 'Breaks', CustomFieldType::Number);
        $this->scenario->setValues(DuplicateResultsFixture::TIME_CERTAIN_A, self::PLAYER, [$notes => 'of the kept one']);
        $this->scenario->setValues(DuplicateResultsFixture::TIME_CERTAIN_B, self::PLAYER, [$notes => 'of the copy', $breaks => '3']);

        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        $this->scenario->forgetLoadedEntities();

        self::assertFalse($this->exists(DuplicateResultsFixture::TIME_CERTAIN_B), 'The newer copy is removed');
        self::assertEquals(
            [$notes => 'of the kept one', $breaks => 3],
            $this->scenario->storedValues(DuplicateResultsFixture::TIME_CERTAIN_A, self::PLAYER),
        );
        self::assertSame(0, $this->scenario->valueRows(DuplicateResultsFixture::TIME_CERTAIN_B));

        $removalId = $this->database->fetchOne('SELECT id FROM result_auto_removal WHERE removed_time_id = :id', ['id' => DuplicateResultsFixture::TIME_CERTAIN_B]);
        self::assertIsString($removalId);
        $this->messageBus->dispatch(new UndoAutoRemoval($removalId, self::PLAYER));

        // The copy is back under its id - its values stay where they were moved to
        self::assertTrue($this->exists(DuplicateResultsFixture::TIME_CERTAIN_B));
        self::assertNull($this->scenario->storedValues(DuplicateResultsFixture::TIME_CERTAIN_B, self::PLAYER));
        self::assertEquals(
            [$notes => 'of the kept one', $breaks => 3],
            $this->scenario->storedValues(DuplicateResultsFixture::TIME_CERTAIN_A, self::PLAYER),
        );
    }

    private function keep(string $keepTimeId): void
    {
        $caseId = $this->database->fetchOne(
            'SELECT id FROM result_duplicate_case WHERE player_id = :playerId AND time_a_id = :timeAId',
            ['playerId' => self::PLAYER, 'timeAId' => DuplicateResultsFixture::TIME_STRONG_A],
        );
        assert(is_string($caseId));

        $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, $keepTimeId, self::PLAYER));
        $this->scenario->forgetLoadedEntities();
    }

    private function exists(string $timeId): bool
    {
        return $this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]) !== false;
    }
}
