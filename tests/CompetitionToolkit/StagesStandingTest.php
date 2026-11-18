<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Closure;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Message\EditCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMode;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The `stages` standing - the final classification across the bracket (competition toolkit v2 S5,
 * displays-standings.md §4.3, A-round-formats.md §3.5) on the Results Cup: Group A (published) + Group B → Final.
 * The write rules (a bracket of the category needed, no round list), the public page (a stage classified only once all
 * its rounds are published, "Qualified for" meanwhile, the number across the stages, hidden rows after numbering), the
 * organiser's preview and JSON, the public JSON, the form, duplicate, the statement cost.
 */
final class StagesStandingTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string CUP = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAStagesStandingNeedsABracketOfItsCategoryAndKeepsNoRounds(): void
    {
        $this->assertRefused('no_steps', fn () => $this->create(RoundCategory::Solo));

        $this->declareSteps();
        // The pairs have no step
        $this->assertRefused('no_steps', fn () => $this->create(RoundCategory::Duo));

        $standingId = $this->create(RoundCategory::Solo, numbered: true, roundIds: [OfficialResultsFixture::ROUND_GROUP_A]);
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($standingId);
        self::assertSame(StandingMode::Stages, $standing->mode);
        self::assertTrue($standing->numberedAcrossStages);
        self::assertSame([], $standing->roundIds);

        // An edit keeps the mode unless it says otherwise (the wizard edits rounds only)
        $this->bus()->dispatch(new EditCompetitionStanding($standingId, self::CUP, 'Renamed', RoundCategory::Solo, [], new StandingRules()));
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($standingId);
        self::assertSame(StandingMode::Stages, $standing->mode);
        self::assertTrue($standing->numberedAcrossStages);
        self::assertSame('Renamed', $standing->name);

        // Back to a sum - its rounds are needed again
        $this->assertRefused('no_rounds', fn () => $this->bus()->dispatch(new EditCompetitionStanding($standingId, self::CUP, 'Renamed', RoundCategory::Solo, [], new StandingRules(), StandingMode::Sum)));
        $this->bus()->dispatch(new EditCompetitionStanding($standingId, self::CUP, 'Renamed', RoundCategory::Solo, [OfficialResultsFixture::ROUND_GROUP_A], new StandingRules(), StandingMode::Sum, false));
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($standingId);
        self::assertSame(StandingMode::Sum, $standing->mode);
        self::assertFalse($standing->numberedAcrossStages);
    }

    public function testThePublicPageClassifiesAStageOnlyOnceAllItsRoundsArePublished(): void
    {
        $this->declareSteps();
        $standingId = $this->create(RoundCategory::Solo, publish: true);
        $url = '/en/events/results-cup/standings/' . $this->slug($standingId);

        // Group B is not published: the groups are not classified - nobody yet
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-provisional]');
        self::assertSelectorExists('[data-standing-empty]');
        self::assertCount(0, $crawler->filter('[data-standing-row]'));
        self::assertStringNotContainsString('Anna Fast', (string) $this->browser->getResponse()->getContent());

        // Groups published, the final not: the qualified are one group without a place, the others keep their group's
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $crawler = $this->browser->request('GET', $url);
        self::assertSelectorExists('[data-standing-provisional]');
        self::assertSame('after 1 of 2 stages', trim($crawler->filter('[data-standing-state] .text-muted')->text()));
        self::assertSame(
            [['Anna Fast', '', ''], ['Ben Steady', '', ''], ['Gina Quick', '', ''], ['Hugo Slow', '', ''], ['Cara Tied', '', '2'], ['Dan Unfinished', '', '4'], ['Ivan Last', '', '3']],
            self::rows($crawler),
        );
        self::assertSame(['2', '1'], $crawler->filter('[data-standing-stage]')->each(static fn (Crawler $header): string => (string) $header->attr('data-standing-stage')));
        self::assertCount(1, $crawler->filter('[data-standing-stage-qualified]'));
        self::assertStringContainsString('Qualified for Final', $crawler->filter('[data-standing-stage-qualified]')->text());
        self::assertStringContainsString('Stage 1: Group A / Group B', $crawler->filter('[data-standing-stage="1"]')->text());
        // Parallel groups: a sub-header per round, linking its published results
        self::assertSame(
            ['/en/events/results-cup/results/group-a', '/en/events/results-cup/results/group-b'],
            $crawler->filter('[data-standing-stage-round] a')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        // A "did not start" or a missing result is never shown (Eva, Filip)
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('Eva Noshow', $content);
        self::assertStringNotContainsString('Filip Pending', $content);

        // The final played and published, numbered across the stages
        $this->playTheFinal();
        $this->database->executeStatement('UPDATE competition_standing SET numbered_across_stages = true WHERE id = :id', ['id' => $standingId]);
        $crawler = $this->browser->request('GET', $url);
        self::assertSelectorExists('[data-standing-final]');
        // Ben did not start the final - the public never sees that, so he is out in the groups (tied with Cara there)
        self::assertSame(
            [['Anna Fast', '1', '1'], ['Gina Quick', '2', '2'], ['Hugo Slow', '3', '3'], ['Ben Steady', '4', '2'], ['Cara Tied', '4', '2'], ['Ivan Last', '6', '3'], ['Dan Unfinished', '7', '4']],
            self::rows($crawler),
        );
        self::assertCount(0, $crawler->filter('[data-standing-stage-qualified]'));
        self::assertCount(0, $crawler->filter('[data-standing-stage-round]'), 'Numbered: no sub-header per round');
        self::assertSame('numbered', $crawler->filter('[data-standing-stages]')->attr('data-standing-stages'));

        // The blocker does not see the player he blocked - everybody else keeps the number (hidden after numbering)
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', $url);
        self::assertSame(['1', '3', '4', '4', '6', '7'], array_map(static fn (array $row): string => $row[1], self::rows($crawler)));
        self::assertStringNotContainsString('Gina Quick', (string) $this->browser->getResponse()->getContent());

        // The table fragment an open page reloads (v2 S4) shows the same classification
        $this->browser->restart();
        $crawler = $this->browser->request('GET', '/en/standing-table/' . $standingId);
        self::assertResponseIsSuccessful();
        self::assertSame(['1', '2', '3', '4', '4', '6', '7'], array_map(static fn (array $row): string => $row[1], self::rows($crawler)));
    }

    public function testTheOrganiserSeesEveryRoundAndTheJsonCarriesTheStage(): void
    {
        $this->declareSteps();
        $standingId = $this->create(RoundCategory::Solo, publish: true, numbered: true);
        $this->playTheFinal();

        // The organiser's view: Ben's "did not start" in the final keeps him in the final, without a number
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $state = $this->json('/en/official-results/standings/' . $standingId);
        self::assertSame('stages', $state['standing']['mode']);
        self::assertTrue($state['standing']['numberedAcrossStages']);
        self::assertSame(['Anna Fast', 'Gina Quick', 'Hugo Slow', 'Ben Steady', 'Cara Tied', 'Ivan Last', 'Dan Unfinished'], array_column($state['rows'], 'name'));
        self::assertSame([1, 2, 3, null, 5, 6, 7], array_column($state['rows'], 'rank'));
        self::assertSame([2, 2, 2, 2, 1, 1, 1], array_column($state['rows'], 'stage'));
        self::assertSame(['Final', 'Final', 'Final', 'Final', 'Group A / Group B', 'Group A / Group B', 'Group A / Group B'], array_column($state['rows'], 'stageName'));
        self::assertSame([1, 2, 3, null, 2, 3, 4], array_column($state['rows'], 'stageRank'));
        self::assertSame('did_not_start', $state['rows'][3]['perRound'][0]['status']);
        self::assertSame([[1, 'Group A / Group B', true], [2, 'Final', true]], array_map(static fn (array $stage): array => [$stage['number'], $stage['name'], $stage['classified']], $state['stages']));

        // The organiser's pages render it
        $this->browser->request('GET', '/en/manage-event-standings/' . self::CUP);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-preview="' . $standingId . '"] [data-standing-stages="numbered"]');
        $this->browser->request('GET', '/en/manage-event-standings/' . self::CUP . '/' . $standingId . '/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-preview="' . $standingId . '"] [data-standing-stages]');

        // The public JSON: the stage of every row, no key, no player id
        $this->browser->restart();
        $public = $this->json('/api/competition-standings/' . $standingId);
        self::assertTrue($public['standing']['numberedAcrossStages']);
        self::assertSame([1, 2, 3, 4, 4, 6, 7], array_column($public['rows'], 'rank'));
        self::assertSame([2, 2, 2, 1, 1, 1, 1], array_column($public['rows'], 'stage'));
        self::assertSame([strtolower(OfficialResultsFixture::ROUND_FINAL), strtolower(OfficialResultsFixture::ROUND_GROUP_A)], array_slice(array_values(array_unique(array_column($public['rows'], 'stageRoundId'))), 0, 2));
        self::assertSame([false], array_values(array_unique(array_column($public['rows'], 'qualifiedForStage'))));
        $body = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('"key"', $body);
        self::assertStringNotContainsString('playerId', $body);
        self::assertStringNotContainsString(strtolower(OfficialResultsFixture::PARTICIPANT_ANNA), $body);
    }

    public function testNoBracketMeansNobodyIsClassified(): void
    {
        $this->declareSteps();
        $standingId = $this->create(RoundCategory::Solo, publish: true);
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        // The steps removed later - no bracket of the solo rounds any more
        $this->database->executeStatement('DELETE FROM competition_advancement_step WHERE competition_id = :id', ['id' => self::CUP]);

        $this->browser->request('GET', '/en/events/results-cup/standings/' . $this->slug($standingId));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-stages-clash]');
        self::assertSelectorExists('[data-standing-provisional]');
        self::assertSelectorNotExists('[data-standing-row]');

        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $state = $this->json('/en/official-results/standings/' . $standingId);
        self::assertSame([], $state['rows']);
        self::assertSame([], $state['stages']);
    }

    public function testTheFormOffersTheClassificationOnceTheEventHasABracket(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $newUrl = '/en/manage-event-standings/' . self::CUP . '/new';

        $crawler = $this->browser->request('GET', $newUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-standing-stages-no-steps]');
        self::assertSame('disabled', $crawler->filter('[data-standing-mode] input[value="stages"]')->attr('disabled'));

        $this->declareSteps();
        $crawler = $this->browser->request('GET', $newUrl);
        self::assertNull($crawler->filter('[data-standing-mode] input[value="stages"]')->attr('disabled'));
        self::assertStringContainsString('Possible for: Solo', $crawler->filter('[data-standing-stages-categories]')->text());

        $form = $crawler->filter('[data-standing-form]')->form();
        $values = $form->getPhpValues();
        /** @var array<string, mixed> $data */
        $data = $values['competition_standing_form'];
        // No rounds chosen - not needed for the classification
        unset($data['roundIds']);
        $values['competition_standing_form'] = [...$data, 'name' => 'Final classification', 'category' => 'solo', 'mode' => 'stages', 'numberedAcrossStages' => '1'];
        $this->browser->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/en/manage-event-standings/' . self::CUP, 303);

        /** @var array{mode: string, round_ids: string, numbered_across_stages: bool} $row */
        $row = $this->database->fetchAssociative('SELECT mode, round_ids, numbered_across_stages FROM competition_standing WHERE competition_id = :id', ['id' => self::CUP]);
        self::assertSame('stages', $row['mode']);
        self::assertSame('[]', $row['round_ids']);
        self::assertTrue($row['numbered_across_stages']);

        // A sum without rounds is still refused
        $values['competition_standing_form'] = [...$data, 'name' => 'Sum', 'category' => 'solo', 'mode' => 'sum'];
        $this->browser->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDuplicatingTheEventCopiesTheClassification(): void
    {
        $this->declareSteps();
        $this->create(RoundCategory::Solo, numbered: true);

        $copyId = Uuid::uuid7();
        $this->bus()->dispatch(new DuplicateCompetition(sourceCompetitionId: self::CUP, newCompetitionId: $copyId, actingPlayerId: self::ORGANISER));

        $copies = self::getContainer()->get(GetCompetitionStandings::class)->ofCompetition($copyId->toString());
        self::assertCount(1, $copies);
        self::assertSame(StandingMode::Stages, $copies[0]->mode);
        self::assertTrue($copies[0]->numberedAcrossStages);
        self::assertSame([], $copies[0]->roundIds);
        self::assertNull($copies[0]->publishedAt);
    }

    /**
     * The classification costs the bracket's two statements (the steps, the rounds in schedule order) on top of what
     * a sum over the same rounds costs - for the organiser and on the public page.
     */
    public function testTheStatementCostIsTheSumsPlusTheBracket(): void
    {
        $this->declareSteps();
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $stages = $this->create(RoundCategory::Solo, publish: true);
        $sum = PublishedStanding::insert($this->database, self::CUP, 'solo-sum', 'Solo sum', 'solo', [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B, OfficialResultsFixture::ROUND_FINAL]);

        self::assertSame($this->measure('/en/events/results-cup/standings/solo-sum') + 2, $this->measure('/en/events/results-cup/standings/' . $this->slug($stages)));

        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        self::assertSame($this->measure('/en/official-results/standings/' . $sum) + 2, $this->measure('/en/official-results/standings/' . $stages));
    }

    private function declareSteps(): void
    {
        DeclaredAdvancementStep::insert($this->database, self::CUP, [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], [OfficialResultsFixture::ROUND_FINAL], qualifyCount: 2);
    }

    /**
     * The final: Anna 50:00, Gina 51:40, Hugo 55:00, Ben did not start - published.
     */
    private function playTheFinal(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $this->database->executeStatement('UPDATE competition_participant_round SET result_seconds = 3000 WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_FINAL_ANNA]);

        foreach ([[OfficialResultsFixture::PARTICIPANT_GINA, 3100, false], [OfficialResultsFixture::PARTICIPANT_HUGO, 3300, false], [OfficialResultsFixture::PARTICIPANT_BEN, null, true]] as [$participantId, $seconds, $didNotStart]) {
            $this->database->executeStatement(
                'INSERT INTO competition_participant_round (id, participant_id, round_id, result_seconds, result_did_not_start) VALUES (:id, :participant, :round, :seconds, :dns)',
                ['id' => Uuid::uuid7()->toString(), 'participant' => $participantId, 'round' => OfficialResultsFixture::ROUND_FINAL, 'seconds' => $seconds, 'dns' => $didNotStart ? 'true' : 'false'],
            );
        }

        $this->publish(OfficialResultsFixture::ROUND_FINAL);
    }

    /**
     * @param list<string> $roundIds ignored by the handler for `stages`
     */
    private function create(RoundCategory $category, bool $publish = false, bool $numbered = false, array $roundIds = []): string
    {
        $standingId = Uuid::uuid7();
        $this->bus()->dispatch(new CreateCompetitionStanding($standingId, self::CUP, 'Final classification', $category, $roundIds, new StandingRules(), StandingMode::Stages, $numbered));

        if ($publish) {
            $this->database->executeStatement('UPDATE competition_standing SET published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $standingId->toString()]);
        }

        return $standingId->toString();
    }

    private function publish(string $roundId): void
    {
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $roundId]);
    }

    private function slug(string $standingId): string
    {
        $slug = $this->database->fetchOne('SELECT slug FROM competition_standing WHERE id = :id', ['id' => $standingId]);
        assert(is_string($slug));

        return $slug;
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $url): array
    {
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    private function measure(string $url): int
    {
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($this->browser);
    }

    /**
     * @return list<array{string, string, string}> name, data-standing-rank, data-standing-stage-rank of each row
     */
    private static function rows(Crawler $crawler): array
    {
        return $crawler->filter('[data-standing-row]')->each(static fn (Crawler $row): array => [
            trim($row->filter('.lb-name')->first()->text()),
            (string) $row->attr('data-standing-rank'),
            (string) $row->attr('data-standing-stage-rank'),
        ]);
    }

    private function assertRefused(string $reason, Closure $write): void
    {
        try {
            $write();
            self::fail('Expected the standing to be refused: ' . $reason);
        } catch (HandlerFailedException $failed) {
            $previous = $failed->getPrevious();
            self::assertInstanceOf(InvalidCompetitionStanding::class, $previous);
            self::assertSame($reason, $previous->reason);
        } catch (InvalidCompetitionStanding $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
