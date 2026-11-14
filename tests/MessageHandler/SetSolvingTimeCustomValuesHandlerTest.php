<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
use SpeedPuzzling\Web\Message\ArchiveCustomField;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EditCustomField;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ReleaseEventResultSlot;
use SpeedPuzzling\Web\Message\ReplaceGroupMemberWithGuest;
use SpeedPuzzling\Web\MessageHandler\RemoveCustomValuesOfFormerMembers;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TrackerlessTimeSeeding;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\CustomValueProblem;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A player's own values on a result (docs/features/custom-fields.md): their own row whoever else is on the result,
 * nothing about the result itself changes, and the row goes when the player is no longer on the result.
 */
final class SetSolvingTimeCustomValuesHandlerTest extends KernelTestCase
{
    use TrackerlessTimeSeeding;

    // A tracks, B is their pair partner, C is nobody of the result
    private const string A = PlayerFixture::PLAYER_REGULAR;
    private const string A_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string B = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string B_USER = PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID;
    private const string B_CODE = '#player3';
    private const string C = PlayerFixture::PLAYER_WITH_STRIPE;

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

    public function testEveryTypeIsStoredInItsShape(): void
    {
        $text = $this->scenario->field(self::A, 'Notes');
        $number = $this->scenario->field(self::A, 'Breaks', CustomFieldType::Number);
        $date = $this->scenario->field(self::A, 'Started on', CustomFieldType::Date);
        $yesNo = $this->scenario->field(self::A, 'Sorted first', CustomFieldType::YesNo);
        $choice = $this->scenario->field(self::A, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        $tags = $this->scenario->readyMade(self::A, StandardCustomField::MyTags);
        $timeId = $this->scenario->result(self::A_USER);

        $this->scenario->setValues($timeId, self::A, [
            $text => '  edges last ',
            $number => '2,5',
            $date => '2026-03-01',
            $yesNo => 'no',
            $choice => 'floor',
            $tags => 'gradient, Cats , gradient',
        ]);

        self::assertSame([
            $text => 'edges last',
            $number => 2.5,
            $date => '2026-03-01',
            $yesNo => false,
            $choice => 'floor',
            $tags => ['gradient', 'Cats'],
        ], $this->scenario->storedValues($timeId, self::A));
    }

    public function testOnlyTheFieldsGivenChangeAndAnEmptyValueIsTakenAway(): void
    {
        $notes = $this->scenario->field(self::A, 'Notes');
        $breaks = $this->scenario->field(self::A, 'Breaks', CustomFieldType::Number);
        $timeId = $this->scenario->result(self::A_USER);
        $this->scenario->setValues($timeId, self::A, [$notes => 'first', $breaks => '2']);

        $this->scenario->setValues($timeId, self::A, [$breaks => '3']);
        self::assertSame([$notes => 'first', $breaks => 3], $this->scenario->storedValues($timeId, self::A));

        // The same again stores the same
        $this->scenario->setValues($timeId, self::A, [$breaks => '3']);
        self::assertSame(1, $this->scenario->valueRows($timeId));

        $this->scenario->setValues($timeId, self::A, [$notes => '  ']);
        self::assertSame([$breaks => 3], $this->scenario->storedValues($timeId, self::A));

        // Nothing left: no row
        $this->scenario->setValues($timeId, self::A, [$breaks => null]);
        self::assertNull($this->scenario->storedValues($timeId, self::A));
        self::assertSame(0, $this->scenario->valueRows($timeId));

        // Nothing to store on a result without a row: still no row
        $this->scenario->setValues($timeId, self::A, [$notes => '']);
        self::assertSame(0, $this->scenario->valueRows($timeId));
    }

    public function testEveryMemberOfAPairsResultHasTheirOwnValues(): void
    {
        $notesOfA = $this->scenario->field(self::A, 'Notes');
        $notesOfB = $this->scenario->field(self::B, 'Notes');
        $timeId = $this->scenario->result(self::A_USER, [self::B_CODE]);

        $this->scenario->setValues($timeId, self::A, [$notesOfA => 'A thinks B was slow']);
        $this->scenario->setValues($timeId, self::B, [$notesOfB => 'B thinks A talked too much']);

        self::assertSame([$notesOfA => 'A thinks B was slow'], $this->scenario->storedValues($timeId, self::A));
        self::assertSame([$notesOfB => 'B thinks A talked too much'], $this->scenario->storedValues($timeId, self::B));
        self::assertSame(2, $this->scenario->valueRows($timeId));

        // B can not write into A's field - it is not B's field, so for B it does not exist
        $refusal = $this->refusalOf(fn () => $this->scenario->setValues($timeId, self::B, [$notesOfA => 'overwritten']));
        self::assertInstanceOf(InvalidCustomValues::class, $refusal);
        self::assertSame([$notesOfA => CustomValueProblem::UnknownField], $refusal->problems);
        self::assertSame([$notesOfA => 'A thinks B was slow'], $this->scenario->storedValues($timeId, self::A));
        self::assertSame([$notesOfB => 'B thinks A talked too much'], $this->scenario->storedValues($timeId, self::B));
    }

    public function testOnlyWhoeverIsOnTheResultSetsValuesOnIt(): void
    {
        $notesOfC = $this->scenario->field(self::C, 'Notes');
        $soloOfA = $this->scenario->result(self::A_USER);
        $pair = $this->scenario->result(self::A_USER, [self::B_CODE, 'A guest'], time: '04:00:00');

        foreach ([$soloOfA, $pair] as $timeId) {
            try {
                $this->scenario->setValues($timeId, self::C, [$notesOfC => 'not my result']);
                self::fail('Somebody who is not on the result set values on it.');
            } catch (CanNotModifyOtherPlayersTime) {
                self::assertSame(0, $this->scenario->valueRows($timeId));
            }
        }
    }

    public function testTheResultItselfIsNotTouchedAndNobodyIsTold(): void
    {
        $notes = $this->scenario->field(self::B, 'Notes');
        $timeId = $this->scenario->result(self::A_USER, [self::B_CODE]);
        $resultBefore = $this->database->fetchAssociative('SELECT * FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        $notificationsBefore = $this->database->fetchOne('SELECT COUNT(*) FROM notification');

        // B, a member who did not track the result, writes their own values
        $this->scenario->setValues($timeId, self::B, [$notes => 'mine alone']);

        self::assertSame($resultBefore, $this->database->fetchAssociative('SELECT * FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        self::assertSame($notificationsBefore, $this->database->fetchOne('SELECT COUNT(*) FROM notification'), 'An edit of the result tells the team - the values tell nobody');
    }

    public function testAValueThatIsNotOfItsFieldsTypeRefusesTheWholeSave(): void
    {
        $notes = $this->scenario->field(self::A, 'Notes');
        $breaks = $this->scenario->field(self::A, 'Breaks', CustomFieldType::Number);
        $timeId = $this->scenario->result(self::A_USER);
        $this->scenario->setValues($timeId, self::A, [$notes => 'kept']);

        $refusal = $this->refusalOf(fn () => $this->scenario->setValues($timeId, self::A, [$notes => 'changed', $breaks => 'a few']));

        self::assertInstanceOf(InvalidCustomValues::class, $refusal);
        self::assertSame([$breaks => CustomValueProblem::NotANumber], $refusal->problems);
        self::assertSame([$notes => 'kept'], $this->scenario->storedValues($timeId, self::A));
    }

    public function testAnArchivedFieldAFieldNotForResultsAndAnUnknownIdAreRefused(): void
    {
        $archived = $this->scenario->field(self::A, 'Old');
        $this->messageBus->dispatch(new ArchiveCustomField($archived, self::A));
        $collectionOnly = $this->scenario->readyMade(self::A, StandardCustomField::Condition);
        $timeId = $this->scenario->result(self::A_USER);

        foreach ([$archived => 'x', $collectionOnly => 'new', '018f0000-0000-7000-8000-000000000000' => 'x', 'not-an-id' => 'x'] as $fieldId => $value) {
            $refusal = $this->refusalOf(fn () => $this->scenario->setValues($timeId, self::A, [$fieldId => $value]));

            self::assertInstanceOf(InvalidCustomValues::class, $refusal, (string) $fieldId);
            self::assertSame([$fieldId => CustomValueProblem::UnknownField], $refusal->problems);
        }

        self::assertSame(0, $this->scenario->valueRows($timeId));
    }

    public function testAChoiceTheFieldNoLongerHasStaysOnTheResultsThatHaveIt(): void
    {
        $place = $this->scenario->field(self::A, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        $notes = $this->scenario->field(self::A, 'Notes');
        $timeId = $this->scenario->result(self::A_USER);
        $this->scenario->setValues($timeId, self::A, [$place => 'floor']);

        $this->messageBus->dispatch(new EditCustomField($place, self::A, 'Place', ['table', 'desk'], true, false));

        // The form sends every field back: the old choice is still this result's value...
        $this->scenario->setValues($timeId, self::A, [$place => 'floor', $notes => 'added later']);
        self::assertSame([$place => 'floor', $notes => 'added later'], $this->scenario->storedValues($timeId, self::A));

        // ...but no other result can take it
        $other = $this->scenario->result(self::A_USER, time: '04:30:00', daysAgo: 5);
        $refusal = $this->refusalOf(fn () => $this->scenario->setValues($other, self::A, [$place => 'floor']));
        self::assertInstanceOf(InvalidCustomValues::class, $refusal);
        self::assertSame([$place => CustomValueProblem::UnknownChoice], $refusal->problems);
    }

    public function testAnImportedEventResultTakesValuesOnlyFromItsClaimersAndAReleasedSpotLosesThem(): void
    {
        $notes = $this->scenario->field(self::A, 'Notes');
        $round = $this->seedTrackerlessEvent(PuzzleFixture::PUZZLE_1000_04);
        $timeId = $this->seedTrackerlessTime($round, PuzzleFixture::PUZZLE_1000_04, 'Puzzle Pals', 3600)->id->toString();

        // Nobody's yet: nobody sets anything on it
        try {
            $this->scenario->setValues($timeId, self::A, [$notes => 'not mine yet']);
            self::fail('Values were set on a result nobody has claimed.');
        } catch (CanNotModifyOtherPlayersTime) {
            self::assertSame(0, $this->scenario->valueRows($timeId));
        }

        $this->messageBus->dispatch(new ClaimEventResultSlot($timeId, self::A, false, null));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->scenario->setValues($timeId, self::A, [$notes => 'we were third']);
        self::assertSame([$notes => 'we were third'], $this->scenario->storedValues($timeId, self::A));

        $this->messageBus->dispatch(new ReleaseEventResultSlot($timeId, self::A));

        self::assertSame(0, $this->scenario->valueRows($timeId), 'No longer on the result - their values of it are gone');
    }

    public function testAMemberTakenOffAResultByAnEditLosesTheirValuesOfItTheOthersKeepTheirs(): void
    {
        $notesOfA = $this->scenario->field(self::A, 'Notes');
        $notesOfB = $this->scenario->field(self::B, 'Notes');
        $timeId = $this->scenario->result(self::A_USER, [self::B_CODE, 'A guest']);
        $this->scenario->setValues($timeId, self::A, [$notesOfA => 'of A']);
        $this->scenario->setValues($timeId, self::B, [$notesOfB => 'of B']);

        // An edit that keeps everybody keeps everything
        $this->edit($timeId, self::A_USER, [self::B_CODE, 'A guest'], comment: 'Fun');
        $this->handleGroupEdit($timeId, [self::A, self::B]);
        self::assertSame(2, $this->scenario->valueRows($timeId));

        // B is no longer on the result
        $this->edit($timeId, self::A_USER, ['A guest']);
        self::assertSame([$notesOfB => 'of B'], $this->scenario->storedValues($timeId, self::B), 'The edit itself runs none of this - the event is handled after it');
        $this->handleGroupEdit($timeId, [self::A, self::B]);

        self::assertNull($this->scenario->storedValues($timeId, self::B));
        self::assertSame([$notesOfA => 'of A'], $this->scenario->storedValues($timeId, self::A));
    }

    public function testAMemberReplacedByAGuestLosesTheirValuesOfTheResult(): void
    {
        $notesOfA = $this->scenario->field(self::A, 'Notes');
        $notesOfB = $this->scenario->field(self::B, 'Notes');
        $timeId = $this->scenario->result(self::A_USER, [self::B_CODE]);
        $this->scenario->setValues($timeId, self::A, [$notesOfA => 'of A']);
        $this->scenario->setValues($timeId, self::B, [$notesOfB => 'of B']);

        $this->messageBus->dispatch(new ReplaceGroupMemberWithGuest($timeId, self::B));

        self::assertNull($this->scenario->storedValues($timeId, self::B));
        self::assertSame([$notesOfA => 'of A'], $this->scenario->storedValues($timeId, self::A));
    }

    public function testADeletedResultTakesEverybodysValuesAlong(): void
    {
        $notesOfA = $this->scenario->field(self::A, 'Notes');
        $notesOfB = $this->scenario->field(self::B, 'Notes');
        $timeId = $this->scenario->result(self::A_USER, [self::B_CODE]);
        $this->scenario->setValues($timeId, self::A, [$notesOfA => 'of A']);
        $this->scenario->setValues($timeId, self::B, [$notesOfB => 'of B']);

        $this->messageBus->dispatch(new DeletePuzzleSolvingTime(self::A_USER, $timeId));

        self::assertSame(0, $this->scenario->valueRows($timeId));
        // The fields are the players' - they stay
        self::assertSame(1, $this->scenario->fieldsOf(self::A));
        self::assertSame(1, $this->scenario->fieldsOf(self::B));
    }

    public function testADeletedAccountTakesItsFieldsAndItsValuesOnEveryResultAlong(): void
    {
        $notesOfA = $this->scenario->field(self::A, 'Notes');
        $notesOfB = $this->scenario->field(self::B, 'Notes');
        $this->scenario->readyMade(self::B, StandardCustomField::MyTags);
        // B's own result, B on A's result, A on B's result
        $soloOfB = $this->scenario->result(self::B_USER);
        $trackedByA = $this->scenario->result(self::A_USER, [self::B_CODE], time: '04:00:00');
        $trackedByB = $this->scenario->result(self::B_USER, ['#player1'], time: '03:00:00', daysAgo: 6);
        $this->scenario->setValues($soloOfB, self::B, [$notesOfB => 'solo']);
        $this->scenario->setValues($trackedByA, self::B, [$notesOfB => 'as a member']);
        $this->scenario->setValues($trackedByA, self::A, [$notesOfA => 'A on their own result']);
        $this->scenario->setValues($trackedByB, self::B, [$notesOfB => 'as the tracker']);
        $this->scenario->setValues($trackedByB, self::A, [$notesOfA => 'A on the result B tracked']);

        $this->messageBus->dispatch(new DeletePlayer(self::B));

        self::assertSame(0, $this->scenario->fieldsOf(self::B));
        self::assertEquals(0, $this->database->fetchOne('SELECT COUNT(*) FROM solving_time_custom_values WHERE player_id = :id', ['id' => self::B]));
        // A's values stay - on A's result and on the result that is A's now
        self::assertSame([$notesOfA => 'A on their own result'], $this->scenario->storedValues($trackedByA, self::A));
        self::assertSame([$notesOfA => 'A on the result B tracked'], $this->scenario->storedValues($trackedByB, self::A));
        self::assertSame(1, $this->scenario->fieldsOf(self::A));
        self::assertSame(2, $this->scenario->valueRows());
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function edit(string $timeId, string $userId, array $groupPlayers, null|string $comment = null): void
    {
        $finishedAt = $this->database->fetchOne('SELECT finished_at FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        assert(is_string($finishedAt));

        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: $userId,
            puzzleSolvingTimeId: $timeId,
            competitionId: null,
            time: '05:00:00',
            comment: $comment,
            groupPlayers: $groupPlayers,
            finishedAt: new \DateTimeImmutable($finishedAt),
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
        ));

        $this->scenario->forgetLoadedEntities();
    }

    /**
     * GroupSolvingTimeEdited is handled asynchronously (the worker) - here the handler is called as the worker would.
     *
     * @param list<string> $membersBeforeEdit
     */
    private function handleGroupEdit(string $timeId, array $membersBeforeEdit): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        self::getContainer()->get(RemoveCustomValuesOfFormerMembers::class)(
            new GroupSolvingTimeEdited(Uuid::fromString($timeId), Uuid::fromString(self::A), $membersBeforeEdit),
        );

        $entityManager->flush();
        $entityManager->clear();
    }

    private function refusalOf(callable $action): null|\Throwable
    {
        try {
            $action();
        } catch (HandlerFailedException $exception) {
            return $exception->getPrevious();
        }

        return null;
    }
}
