<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionAdvancementStep;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Advancement steps in the organiser's pages (competition toolkit A8): the steps page and its forms behind
 * COMPETITION_TOOLKIT_MANAGE, the hub card, and on the results desk the step banner with "Mark top N" (the existing Top N
 * review dialog, preset) and "Advance" (the Advance dialog prefilled) - only when the round feeds a step; the overall
 * preset endpoint.
 *
 * The Results Cup: Group A (Anna 3600 s, Ben + Cara 4200 s, Dan unfinished, Eva DNS, Filip no result) and Group B
 * (Gina 3900, Hugo 4500, Ivan 5000) → Final.
 */
final class AdvancementStepsPagesTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';
    private const string COMPETITION = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string STEPS = '/en/manage-event-advancement/' . self::COMPETITION;
    private const string DESK_GROUP_A = '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A;

    private KernelBrowser $browser;

    /** @var list<string> the last preset answer's ties at the cut */
    private array $lastTied = [];

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $this->browser = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testThePagesAreBehindTheToolkitVoter(): void
    {
        $this->browser->request('GET', self::STEPS);
        self::assertResponseRedirects();

        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->request('GET', self::STEPS);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', self::STEPS . '/new');
        self::assertResponseStatusCodeSame(403);

        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $this->browser->request('GET', self::STEPS);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-advancement-steps-empty]');
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', self::STEPS);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAStepIsAddedEditedAndDeletedThroughTheForms(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::STEPS . '/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-advancement-step-form] form')->form();
        self::tick($crawler, $form, 'sourceRoundIds', OfficialResultsFixture::ROUND_GROUP_A);
        self::tick($crawler, $form, 'sourceRoundIds', OfficialResultsFixture::ROUND_GROUP_B);
        self::tick($crawler, $form, 'targetRoundIds', OfficialResultsFixture::ROUND_FINAL);
        $form['advancement_step_form[qualifyCount]'] = '2';
        $form['advancement_step_form[distribution]'] = 'single';
        $this->browser->submit($form);

        self::assertResponseRedirects(self::STEPS, 303);
        $crawler = $this->browser->followRedirect();
        $step = $crawler->filter('[data-advancement-step]');
        self::assertCount(1, $step);
        self::assertStringContainsString('Group A, Group B → Final', $step->text());
        self::assertStringContainsString('Top 2 of each round go to Final.', $step->text());
        // The stages are the bracket's columns since A9 (the organiser's bracket replaced A8's stage strip)
        self::assertSame([['Group A', 'Group B'], ['Final']], $crawler->filter('[data-advancement-stages] [data-bracket-stage]')->each(static fn ($stage): array => $stage->filter('.bracket-round-name')->each(static fn ($round): string => trim($round->text()))));

        // A second step into the Final is refused with the reason - the form again, 422
        $crawler = $this->browser->request('GET', self::STEPS . '/new');
        $form = $crawler->filter('[data-advancement-step-form] form')->form();
        self::tick($crawler, $form, 'sourceRoundIds', OfficialResultsFixture::ROUND_GROUP_B);
        self::tick($crawler, $form, 'targetRoundIds', OfficialResultsFixture::ROUND_FINAL);
        $form['advancement_step_form[distribution]'] = 'single';
        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-advancement-step-form]', 'fed by another step already');

        $stepId = (string) $step->attr('data-advancement-step');
        $crawler = $this->browser->request('GET', self::STEPS . '/' . $stepId . '/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-advancement-step-form] form')->form();
        $form['advancement_step_form[name]'] = 'Groups → Final';
        $form['advancement_step_form[qualifyScope]'] = 'overall';
        $this->browser->submit($form);
        self::assertResponseRedirects(self::STEPS, 303);
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Groups → Final', $crawler->filter('[data-advancement-step]')->text());
        self::assertStringContainsString('Top 2 of Group A, Group B together go to Final.', $crawler->filter('[data-advancement-step]')->text());

        // Delete needs its token
        $this->browser->request('POST', self::STEPS . '/' . $stepId . '/delete', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        $deleteForm = $crawler->filter('[data-advancement-step-delete]')->form();
        $this->browser->submit($deleteForm);
        self::assertResponseRedirects(self::STEPS, 303);
        $this->browser->followRedirect();
        self::assertSelectorExists('[data-advancement-steps-empty]');
    }

    public function testTheHubCardLinksTheSteps(): void
    {
        $this->createStep(new AdvancementStepSettings([OfficialResultsFixture::ROUND_GROUP_A], [OfficialResultsFixture::ROUND_FINAL], AdvanceDistribution::Single));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', '/en/manage-event-toolkit/' . self::COMPETITION);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-toolkit-advancement-steps]', '1 advancement step declared.');
        self::assertSelectorExists('[data-toolkit-card="standings"] a[data-toolkit-advancement-steps-link][href="' . self::STEPS . '"]');
    }

    public function testTheDeskWithoutStepsShowsNoBannerAndNoNewHooks(): void
    {
        // A step that Group A does not feed changes nothing on Group A's desk either
        $this->createStep(new AdvancementStepSettings([OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL], AdvanceDistribution::Single, qualifyCount: 2));

        foreach ([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_FINAL] as $roundId) {
            TestingLogin::asPlayer($this->browser, self::ORGANISER);
            $crawler = $this->browser->request('GET', '/en/manage-round-results/' . $roundId);

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('[data-advancement-step-banner]');
            self::assertNull($crawler->filter('[data-controller="advance-qualified"]')->attr('data-action'));
            self::assertStringNotContainsString('step_overall', (string) $crawler->filter('[data-controller="results-desk"]')->attr('data-results-desk-texts-value'));
        }
    }

    public function testTheDeskBannerOffersTheStepsPresets(): void
    {
        $stepId = $this->createStep(new AdvancementStepSettings(
            [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B],
            [OfficialResultsFixture::ROUND_FINAL],
            AdvanceDistribution::Single,
            qualifyCount: 2,
            bestOfEachCountry: 1,
        ), 'Groups → Final');
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $crawler = $this->browser->request('GET', self::DESK_GROUP_A);

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-advancement-step-banner="' . $stepId . '"]');
        self::assertCount(1, $banner);
        self::assertStringContainsString('Step: Groups → Final', $banner->text());
        self::assertStringContainsString('Top 2 of each round go to Final. Also the best 1 of each country.', $banner->text());

        // "Mark top 2" = the desk's own Top N helper with the step's number
        $mark = $banner->filter('[data-advancement-step-mark]');
        self::assertSame('results-desk#openHelper', $mark->attr('data-action'));
        self::assertSame('top', $mark->attr('data-helper'));
        self::assertSame('2', $mark->attr('data-helper-count'));
        self::assertNull($mark->attr('data-helper-preset-url'));
        self::assertStringContainsString('Mark top 2', $mark->text());

        // "Advance" hands the step to the Advance dialog, which listens for it on this page
        $advance = $banner->filter('[data-advancement-step-advance]');
        self::assertSame([
            'id' => $stepId,
            'name' => 'Groups → Final',
            'sourceRoundIds' => [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B],
            'targetRoundIds' => [OfficialResultsFixture::ROUND_FINAL],
            'distribution' => 'single',
            'targetBySource' => [],
            'qualifyCount' => 2,
            'qualifyScope' => 'per_round',
            'bestOfEachCountry' => 1,
        ], json_decode((string) $advance->attr('data-step'), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('advance-qualified:open-step@window->advance-qualified#openStep', $crawler->filter('[data-controller="advance-qualified"]')->attr('data-action'));
        self::assertStringContainsString('step_overall_loading', (string) $crawler->filter('[data-controller="results-desk"]')->attr('data-results-desk-texts-value'));

        // The Final's desk: not a source of the step
        $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_FINAL);
        self::assertSelectorNotExists('[data-advancement-step-banner]');
    }

    public function testTheOverallPresetRanksAllSourceRoundsTogether(): void
    {
        $stepId = $this->createStep(new AdvancementStepSettings(
            [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B],
            [OfficialResultsFixture::ROUND_FINAL],
            AdvanceDistribution::Single,
            qualifyCount: 3,
            qualifyScope: AdvancementQualifyScope::Overall,
        ));
        $url = '/en/official-results/advancement-steps/' . $stepId . '/preset';

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $crawler = $this->browser->request('GET', self::DESK_GROUP_A);
        $mark = $crawler->filter('[data-advancement-step-mark]');
        self::assertSame($url, $mark->attr('data-helper-preset-url'));
        self::assertStringContainsString('Mark top 3 overall', $mark->text());

        // Both winners, then Hugo (4500 ÷ 3900 = 1.154 × his winner) before Ben and Cara (4200 ÷ 3600 = 1.167)
        self::assertSame([[self::anna(), 1], [self::gina(), 2], [self::hugo(), 3]], $this->preset($url));
        self::assertSame([], $this->lastTied);
        // One more place: Ben and Cara share it - both proposed, both highlighted
        self::assertSame([[self::anna(), 1], [self::gina(), 2], [self::hugo(), 3], [self::ben(), 4], [self::cara(), 5]], $this->preset($url . '?count=4'));
        self::assertSame([self::ben(), self::cara()], $this->lastTied);
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * Ticks the round's checkbox in a round list of the step form.
     */
    private static function tick(Crawler $crawler, Form $form, string $field, string $roundId): void
    {
        $values = $crawler->filter('input[name="advancement_step_form[' . $field . '][]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
        $index = array_search($roundId, $values, true);
        self::assertIsInt($index, 'No such round in ' . $field);

        $choices = $form['advancement_step_form[' . $field . ']'];
        self::assertIsArray($choices);
        $choice = $choices[$index];
        self::assertInstanceOf(ChoiceFormField::class, $choice);
        $choice->tick();
    }

    /**
     * @return list<array{string, int}> ref + seed
     */
    private function preset(string $url): array
    {
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        /** @var array{selected: list<array{ref: string, roundId: string, seed: int}>, tiedAtCut: list<string>} $answer */
        $answer = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->lastTied = $answer['tiedAtCut'];

        return array_map(static fn (array $item): array => [$item['ref'], $item['seed']], $answer['selected']);
    }

    private function createStep(AdvancementStepSettings $settings, null|string $name = null): string
    {
        $id = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CreateCompetitionAdvancementStep($id, self::COMPETITION, $name, $settings));

        return $id->toString();
    }

    private static function anna(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA;
    }

    private static function ben(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_BEN;
    }

    private static function cara(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_CARA;
    }

    private static function gina(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_B_GINA;
    }

    private static function hugo(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO;
    }
}
