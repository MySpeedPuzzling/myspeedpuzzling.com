<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\AdvancementStepNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Message\CreateCompetitionAdvancementStep;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DeleteCompetitionAdvancementStep;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Message\EditCompetitionAdvancementStep;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Results\AdvancementStepOverview;
use SpeedPuzzling\Web\Results\AdvancementStepsView;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Advancement steps (competition toolkit A8, A-round-formats.md §3.5) over the World-Series-like event (6 solo + 6 pair
 * rounds) used as a bracket: the write messages and their refusals, pruning on round delete/move, the duplicate's
 * remapped copies and the event delete cascade.
 */
final class AdvancementStepsTest extends KernelTestCase
{
    use WorldSeriesSetup;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testABracketIsSavedNormalisedAndHasItsStages(): void
    {
        $groups = $this->create(new AdvancementStepSettings(
            [strtoupper($this->soloRounds[0]), $this->soloRounds[1], $this->soloRounds[0]],
            [$this->soloRounds[2], $this->soloRounds[3]],
            AdvanceDistribution::Balanced,
            // Not by source: the map is dropped
            [$this->soloRounds[0] => $this->soloRounds[2]],
            qualifyCount: 8,
            bestOfEachCountry: 1,
        ), name: '  Groups → Semifinals ');
        $semis = $this->create(new AdvancementStepSettings([$this->soloRounds[2], $this->soloRounds[3]], [$this->soloRounds[4]], AdvanceDistribution::Single, qualifyCount: 4, qualifyScope: AdvancementQualifyScope::Overall));

        $steps = $this->steps();
        self::assertSame([$groups, $semis], array_map(static fn (AdvancementStepOverview $step): string => $step->id, $steps));
        self::assertSame('Groups → Semifinals', $steps[0]->name);
        self::assertNull($steps[1]->name);
        self::assertSame([$this->soloRounds[0], $this->soloRounds[1]], $steps[0]->settings->sourceRoundIds);
        self::assertSame([], $steps[0]->settings->targetBySource);
        self::assertSame(8, $steps[0]->settings->qualifyCount);
        self::assertSame(1, $steps[0]->settings->bestOfEachCountry);
        self::assertSame(AdvancementQualifyScope::PerRound, $steps[0]->settings->qualifyScope);
        self::assertSame(1, $steps[1]->position);
        self::assertTrue($steps[1]->isOverall());
        self::assertFalse($steps[0]->isOverall());

        $view = $this->view();
        self::assertSame([['Solo 1', 'Solo 2'], ['Solo 3', 'Solo 4'], ['Solo 5']], $view->stages);
        self::assertSame('Solo 3, Solo 4 → Solo 5', $view->items[1]['label']);
        self::assertCount(1, $view->forSourceRound($this->soloRounds[0]));
        self::assertCount(1, $view->forSourceRound($this->soloRounds[3]));
        self::assertSame([], $view->forSourceRound($this->soloRounds[4]));
    }

    public function testTheGraphRulesAreCheckedAcrossTheEventsSteps(): void
    {
        $first = $this->create(new AdvancementStepSettings([$this->soloRounds[0]], [$this->soloRounds[2]], AdvanceDistribution::Single));

        // A round fed by two steps
        $this->assertRefused('round_fed_twice', fn () => $this->create(new AdvancementStepSettings([$this->soloRounds[1]], [$this->soloRounds[2]], AdvanceDistribution::Single)));
        // Back into the round it came from
        $this->assertRefused('cycle', fn () => $this->create(new AdvancementStepSettings([$this->soloRounds[2]], [$this->soloRounds[0]], AdvanceDistribution::Single)));
        $this->assertRefused('mixed_categories', fn () => $this->create(new AdvancementStepSettings([$this->soloRounds[0]], [$this->pairRounds[1]], AdvanceDistribution::Single)));
        $this->assertRefused('unknown_round', fn () => $this->create(new AdvancementStepSettings([$this->soloRounds[0]], [OfficialResultsFixture::ROUND_FINAL], AdvanceDistribution::Single)));
        $this->assertRefused('by_source_incomplete', fn () => $this->create(new AdvancementStepSettings([$this->pairRounds[0], $this->pairRounds[1]], [$this->pairRounds[2]], AdvanceDistribution::BySource, [$this->pairRounds[0] => $this->pairRounds[2]])));

        // Editing a step never clashes with itself
        $this->messageBus->dispatch(new EditCompetitionAdvancementStep($first, self::WS_COMPETITION, 'Renamed', new AdvancementStepSettings([$this->soloRounds[0], $this->soloRounds[1]], [$this->soloRounds[2]], AdvanceDistribution::Single, qualifyCount: 3)));
        $edited = $this->steps()[0];
        self::assertSame('Renamed', $edited->name);
        self::assertSame([$this->soloRounds[0], $this->soloRounds[1]], $edited->settings->sourceRoundIds);
        self::assertSame(3, $edited->settings->qualifyCount);

        $this->assertRefused('source_is_target', fn () => $this->messageBus->dispatch(new EditCompetitionAdvancementStep($first, self::WS_COMPETITION, null, new AdvancementStepSettings([$this->soloRounds[0]], [$this->soloRounds[0]], AdvanceDistribution::Single))));
        self::assertCount(1, $this->steps());
    }

    public function testAStepOfAnotherEventIsNotFound(): void
    {
        $step = $this->create(new AdvancementStepSettings([$this->soloRounds[0]], [$this->soloRounds[1]], AdvanceDistribution::Single));
        $settings = new AdvancementStepSettings([$this->soloRounds[0]], [$this->soloRounds[2]], AdvanceDistribution::Single);

        $this->assertNotFound(fn () => $this->messageBus->dispatch(new EditCompetitionAdvancementStep($step, OfficialResultsFixture::COMPETITION_RESULTS_CUP, null, $settings)));
        $this->assertNotFound(fn () => $this->messageBus->dispatch(new DeleteCompetitionAdvancementStep($step, OfficialResultsFixture::COMPETITION_RESULTS_CUP)));
        $this->assertNotFound(fn () => $this->messageBus->dispatch(new DeleteCompetitionAdvancementStep('not-a-uuid', self::WS_COMPETITION)));

        $this->messageBus->dispatch(new DeleteCompetitionAdvancementStep($step, self::WS_COMPETITION));
        self::assertSame([], $this->steps());
    }

    public function testDeletingARoundPrunesTheStepsAndRemovesAStepLeftEmpty(): void
    {
        $groups = $this->create(new AdvancementStepSettings([$this->soloRounds[0], $this->soloRounds[1]], [$this->soloRounds[2], $this->soloRounds[3]], AdvanceDistribution::Balanced, qualifyCount: 2));
        $final = $this->create(new AdvancementStepSettings([$this->soloRounds[2], $this->soloRounds[3]], [$this->soloRounds[4]], AdvanceDistribution::Single));
        $pairs = $this->create(new AdvancementStepSettings([$this->pairRounds[0], $this->pairRounds[1]], [$this->pairRounds[2], $this->pairRounds[3]], AdvanceDistribution::BySource, [$this->pairRounds[0] => $this->pairRounds[2], $this->pairRounds[1] => $this->pairRounds[3]]));

        $this->messageBus->dispatch(new DeleteCompetitionRound($this->soloRounds[3], self::WS_COMPETITION));
        $this->messageBus->dispatch(new DeleteCompetitionRound($this->pairRounds[3], self::WS_COMPETITION));

        $steps = $this->byId();
        // "Spread evenly" over one round left is "all into one round"
        self::assertSame([$this->soloRounds[2]], $steps[$groups]->settings->targetRoundIds);
        self::assertSame(AdvanceDistribution::Single, $steps[$groups]->settings->distribution);
        self::assertSame([$this->soloRounds[2]], $steps[$final]->settings->sourceRoundIds);
        // The map lost a target: kept, but broken until the organiser fixes it - and no desk banner for it
        self::assertSame([$this->pairRounds[0] => $this->pairRounds[2]], $steps[$pairs]->settings->targetBySource);
        $view = $this->view();
        self::assertSame('advancement_step.refused.by_source_incomplete', $view->items[2]['problem']);
        self::assertSame([], $view->forSourceRound($this->pairRounds[0]));
        self::assertSame([], $view->stages);

        // The final goes: its step has no target any more
        $this->messageBus->dispatch(new DeleteCompetitionRound($this->soloRounds[4], self::WS_COMPETITION));
        self::assertSame([$groups, $pairs], array_keys($this->byId()));
    }

    public function testMovingAnEmptyRoundAwayPrunesTheSteps(): void
    {
        $this->database->executeStatement('DELETE FROM competition_participant_round WHERE round_id = :round', ['round' => $this->soloRounds[5]]);
        $this->create(new AdvancementStepSettings([$this->soloRounds[0]], [$this->soloRounds[5]], AdvanceDistribution::Single));
        $kept = $this->create(new AdvancementStepSettings([$this->soloRounds[1], $this->soloRounds[5]], [$this->soloRounds[2]], AdvanceDistribution::Single));

        $this->messageBus->dispatch(new MoveRoundToCompetition($this->soloRounds[5], self::WS_COMPETITION, OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_ADMIN));

        $steps = $this->byId();
        self::assertSame([$kept], array_keys($steps));
        self::assertSame([$this->soloRounds[1]], $steps[$kept]->settings->sourceRoundIds);
    }

    public function testDuplicatingTheEventCopiesTheStepsOntoTheCopiedRounds(): void
    {
        $this->create(new AdvancementStepSettings([$this->soloRounds[0], $this->soloRounds[1]], [$this->soloRounds[2], $this->soloRounds[3]], AdvanceDistribution::Balanced, qualifyCount: 8, qualifyScope: AdvancementQualifyScope::Overall, bestOfEachCountry: 2), name: 'Groups');
        $this->create(new AdvancementStepSettings([$this->pairRounds[0], $this->pairRounds[1]], [$this->pairRounds[2], $this->pairRounds[3]], AdvanceDistribution::BySource, [$this->pairRounds[0] => $this->pairRounds[3], $this->pairRounds[1] => $this->pairRounds[2]]));

        $copyId = Uuid::uuid7();
        $this->messageBus->dispatch(new DuplicateCompetition(
            sourceCompetitionId: self::WS_COMPETITION,
            newCompetitionId: $copyId,
            actingPlayerId: self::WS_ORGANISER,
        ));

        $copies = self::getContainer()->get(GetCompetitionAdvancementSteps::class)->ofCompetition($copyId->toString());
        self::assertCount(2, $copies);
        /** @var array<string, string> $slugs */
        $slugs = $this->database->fetchAllKeyValue('SELECT id, slug FROM competition_round WHERE competition_id = :id', ['id' => $copyId->toString()]);
        $slug = static fn (string $id): string => $slugs[$id] ?? 'not a copied round';

        self::assertSame('Groups', $copies[0]->name);
        self::assertSame(['solo-1', 'solo-2'], array_map($slug, $copies[0]->settings->sourceRoundIds));
        self::assertSame(['solo-3', 'solo-4'], array_map($slug, $copies[0]->settings->targetRoundIds));
        self::assertSame(AdvanceDistribution::Balanced, $copies[0]->settings->distribution);
        self::assertSame(8, $copies[0]->settings->qualifyCount);
        self::assertSame(AdvancementQualifyScope::Overall, $copies[0]->settings->qualifyScope);
        self::assertSame(2, $copies[0]->settings->bestOfEachCountry);

        $map = [];
        foreach ($copies[1]->settings->targetBySource as $source => $target) {
            $map[$slug($source)] = $slug($target);
        }
        self::assertSame(['pairs-1' => 'pairs-4', 'pairs-2' => 'pairs-3'], $map);

        // The copies work as declared on the copy, the source keeps its own
        $rounds = self::getContainer()->get(GetCompetitionRoundsForManagement::class)->ofCompetition($copyId->toString());
        self::assertSame([null, null], array_map(static fn (array $item): null|string => $item['problem'], AdvancementStepsView::of($copies, $rounds)->items));
        self::assertCount(2, $this->steps());
    }

    public function testDeletingTheEventDeletesItsSteps(): void
    {
        $this->create(new AdvancementStepSettings([$this->soloRounds[0]], [$this->soloRounds[1]], AdvanceDistribution::Single));

        $this->messageBus->dispatch(new DeleteCompetition(self::WS_COMPETITION));

        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM competition_advancement_step'));
    }

    private function create(AdvancementStepSettings $settings, null|string $name = null): string
    {
        $id = Uuid::uuid7();
        $this->messageBus->dispatch(new CreateCompetitionAdvancementStep($id, self::WS_COMPETITION, $name, $settings));

        return $id->toString();
    }

    /**
     * @return list<AdvancementStepOverview>
     */
    private function steps(): array
    {
        return self::getContainer()->get(GetCompetitionAdvancementSteps::class)->ofCompetition(self::WS_COMPETITION);
    }

    /**
     * @return array<string, AdvancementStepOverview>
     */
    private function byId(): array
    {
        $steps = [];
        foreach ($this->steps() as $step) {
            $steps[$step->id] = $step;
        }

        return $steps;
    }

    private function view(): AdvancementStepsView
    {
        return AdvancementStepsView::of($this->steps(), self::getContainer()->get(GetCompetitionRoundsForManagement::class)->ofCompetition(self::WS_COMPETITION));
    }

    private function assertRefused(string $reason, Closure $write): void
    {
        try {
            $write();
            self::fail('Expected the step to be refused: ' . $reason);
        } catch (HandlerFailedException $failed) {
            $previous = $failed->getPrevious();
            self::assertInstanceOf(InvalidAdvancementStep::class, $previous);
            self::assertSame($reason, $previous->reason);
        } catch (InvalidAdvancementStep $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function assertNotFound(Closure $write): void
    {
        try {
            $write();
            self::fail('Expected AdvancementStepNotFound');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf(AdvancementStepNotFound::class, $failed->getPrevious());
        } catch (AdvancementStepNotFound) {
            self::addToAssertionCount(1);
        }
    }
}
