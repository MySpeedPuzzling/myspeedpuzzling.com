<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SetCompetitionDivisions;
use SpeedPuzzling\Web\Message\SetRoundEntryDivisions;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Value\DivisionInput;
use SpeedPuzzling\Web\Value\EntryDivisionInput;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * St. Paul's divisions on the Results Cup (OfficialResultsFixture): Regular, Senior 55+ (S) and Junior U16 (J); Group A
 * (solo) uses all three, Pairs Regular + Senior, the Final Senior only. Group A's entries: Anna Regular (3600 s), Ben and
 * Cara Senior (4200 s each - a tie), Dan Senior (850 pieces), Eva Junior (did not start), Filip none (no result).
 */
trait DivisionsSetup
{
    private string $regular = '';
    private string $senior = '';
    private string $junior = '';

    private function setUpStPaulDivisions(MessageBusInterface $messageBus, bool $withEntries = true): void
    {
        $this->regular = Uuid::uuid7()->toString();
        $this->senior = Uuid::uuid7()->toString();
        $this->junior = Uuid::uuid7()->toString();

        $messageBus->dispatch(new SetCompetitionDivisions(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            divisions: [
                new DivisionInput($this->regular, 'Regular'),
                new DivisionInput($this->senior, 'Senior 55+', 'S'),
                new DivisionInput($this->junior, 'Junior U16', 'J'),
            ],
            rounds: [
                OfficialResultsFixture::ROUND_GROUP_A => [$this->regular, $this->senior, $this->junior],
                OfficialResultsFixture::ROUND_PAIRS => [$this->regular, $this->senior],
                OfficialResultsFixture::ROUND_FINAL => [$this->senior],
            ],
        ));

        if ($withEntries === false) {
            return;
        }

        $messageBus->dispatch(new SetRoundEntryDivisions(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: OfficialResultsFixture::ROUND_GROUP_A,
            entries: [
                new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_ANNA, null, 'Regular'),
                new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_BEN, null, 's'),
                new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_CARA, null, $this->senior),
                new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_DAN, null, 'senior 55+'),
                new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_EVA, null, 'Junior U16'),
            ],
        ));
    }

    /**
     * The one number a COUNT(*) statement answers.
     *
     * @param list<mixed> $params
     */
    private static function divisionRowCount(Connection $database, string $sql, array $params = []): int
    {
        $count = $database->fetchOne($sql, $params);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * The exception the write throws - it must throw one.
     */
    private static function refusalOf(callable $write): \Throwable
    {
        try {
            $write();
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        self::fail('A refusal was expected');
    }

    private static function handled(MessageBusInterface $messageBus, object $message): mixed
    {
        return $messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
