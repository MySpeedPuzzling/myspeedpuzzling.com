<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\CreateCompetitionAdvancementStep;
use SpeedPuzzling\Web\Message\SetUpEventWizardStructure;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The creation wizard's Structure step writes in one transaction (SetUpEventWizardStructure, W5): an advancement step
 * refused by AdvancementStepGraph rolls back the rounds created before it - never half a structure. The same rounds
 * with a valid step are written together with it.
 */
final class SetUpEventWizardStructureHandlerTest extends KernelTestCase
{
    public function testARefusedStepRollsBackTheRoundsAndAValidOneIsWrittenWithThem(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $competitionId = Uuid::uuid7()->toString();
        $bus->dispatch(self::competition($competitionId));

        $group = self::round($competitionId, 'Group A', RoundCategory::Solo, '08:00');
        $final = self::round($competitionId, 'Final', RoundCategory::Duo, '10:00');

        try {
            $bus->dispatch(new SetUpEventWizardStructure(
                competitionId: $competitionId,
                adds: [$group, $final],
                advancementSteps: [self::step($competitionId, $group, $final)],
            ));
            self::fail('A step between two categories must be refused');
        } catch (\Throwable $failure) {
            self::assertNotNull(EventWizardRefusal::find($failure, InvalidAdvancementStep::class), (string) $failure);
        }

        self::assertSame(0, self::rows('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id', $competitionId));
        self::assertSame(0, self::rows('SELECT COUNT(*) FROM competition_advancement_step WHERE competition_id = :id', $competitionId));

        $group = self::round($competitionId, 'Group A', RoundCategory::Solo, '08:00');
        $final = self::round($competitionId, 'Final', RoundCategory::Solo, '10:00');
        $bus->dispatch(new SetUpEventWizardStructure(
            competitionId: $competitionId,
            adds: [$group, $final],
            advancementSteps: [self::step($competitionId, $group, $final)],
        ));

        self::assertSame(2, self::rows('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id', $competitionId));
        self::assertSame(1, self::rows('SELECT COUNT(*) FROM competition_advancement_step WHERE competition_id = :id', $competitionId));
    }

    public function testEveryChangeMustBelongToTheEventLocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SetUpEventWizardStructure(
            competitionId: Uuid::uuid7()->toString(),
            adds: [self::round(Uuid::uuid7()->toString(), 'Group A', RoundCategory::Solo, '08:00')],
        );
    }

    private static function rows(string $query, string $competitionId): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne($query, ['id' => $competitionId]);

        return is_numeric($count) ? (int) $count : 0;
    }

    private static function step(string $competitionId, AddCompetitionRound $source, AddCompetitionRound $target): CreateCompetitionAdvancementStep
    {
        return new CreateCompetitionAdvancementStep(
            stepId: Uuid::uuid7(),
            competitionId: $competitionId,
            name: 'Group → Final',
            settings: new AdvancementStepSettings(
                sourceRoundIds: [$source->roundId->toString()],
                targetRoundIds: [$target->roundId->toString()],
                distribution: AdvanceDistribution::Single,
                qualifyCount: 10,
            ),
        );
    }

    private static function round(string $competitionId, string $name, RoundCategory $category, string $time): AddCompetitionRound
    {
        return new AddCompetitionRound(
            roundId: Uuid::uuid7(),
            competitionId: $competitionId,
            name: $name,
            minutesLimit: 90,
            startsAt: new DateTimeImmutable('2027-06-15 ' . $time . ':00'),
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $category,
        );
    }

    private static function competition(string $competitionId): AddCompetition
    {
        return new AddCompetition(
            competitionId: Uuid::fromString($competitionId),
            playerId: PlayerFixture::PLAYER_REGULAR,
            name: 'Structure Cup',
            shortcut: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Brno',
            locationCountryCode: 'cz',
            dateFrom: new DateTimeImmutable('2027-06-15'),
            dateTo: new DateTimeImmutable('2027-06-15'),
            isOnline: false,
            logo: null,
            maintainerIds: [],
            isDraft: true,
        );
    }
}
