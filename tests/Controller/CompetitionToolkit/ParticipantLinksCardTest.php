<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetParticipantClaimPolicy;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ParticipantLinkSuggestionsSeeding;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\ParticipantClaimPolicy;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The "Participants & profiles" card of the toolkit hub (docs/features/competitions-management/participant-linking.md
 * §7.5): the counters linking to the sheet's People filters and the claim policy radios - saved = redirect back,
 * refused = 422, only for the event's organisers.
 */
final class ParticipantLinksCardTest extends WebTestCase
{
    use ParticipantLinkSuggestionsSeeding;
    use QueryCountAssertions;

    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string HUB = '/en/manage-event-toolkit/' . self::EVENT;
    private const string FORM = 'participant_claim_policy_form';

    public function testTheCardIsFirstWithTheCountersAndAutomaticChecked(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);

        $page = $browser->request('GET', self::HUB);

        self::assertResponseIsSuccessful();
        self::assertSame('participant-links', $page->filter('[data-toolkit-card]')->first()->attr('data-toolkit-card'));
        $card = $page->filter('[data-toolkit-card="participant-links"]');
        self::assertStringContainsString('/en/participants-sheet/' . self::EVENT . '?tab=people&filter=not_linked', (string) $card->filter('[data-counter="linked"]')->attr('href'));
        self::assertCount(0, $card->filter('[data-counter="requests"]'));
        self::assertSame(['automatic', 'open', 'approval'], $card->filter('input[name="' . self::FORM . '[policy]"]')->each(static fn ($radio): string => (string) $radio->attr('value')));
        self::assertSame('automatic', $card->filter('input[name="' . self::FORM . '[policy]"][checked]')->attr('value'));
        self::assertStringContainsString('Now: Link at once', $card->text());
    }

    public function testWaitingRequestsAreCountedAndAutomaticSaysWhy(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE competition SET competitor_pages = true, time_proposals = true WHERE id = :id', ['id' => self::EVENT]);
        $database->executeStatement('UPDATE competition_participant SET player_id = NULL WHERE id = :id', ['id' => OfficialResultsFixture::PARTICIPANT_IVAN]);
        $database->executeStatement(
            "INSERT INTO participant_link_request (id, competition_id, participant_id, player_id, status, kind, player_name, player_code, releases_own_registration, created_at)
             VALUES (:id, :competition, :participant, :player, 'pending', 'claim', 'Admin User', 'admin', false, " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'competition' => self::EVENT, 'participant' => OfficialResultsFixture::PARTICIPANT_IVAN, 'player' => PlayerFixture::PLAYER_ADMIN],
        );

        $page = $browser->request('GET', self::HUB);

        $card = $page->filter('[data-toolkit-card="participant-links"]');
        self::assertStringContainsString('1 waiting for you to confirm', $card->filter('[data-counter="requests"]')->text());
        self::assertStringContainsString('filter=asks', (string) $card->filter('[data-counter="requests"]')->attr('href'));
        self::assertStringContainsString('Now: Ask me first', $card->text());
    }

    /**
     * Link suggestions (§7.5, package L5): "N people may already have a profile" to the sheet's filter - the same number
     * as the sheet's list, counted inside the card's one statement (no statement of its own, whatever the list's size).
     */
    public function testTheSuggestionsAreCountedInTheCardsStatement(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $database = self::getContainer()->get(Connection::class);
        $seeded = $this->seedLinkSuggestions($database);
        $hub = '/en/manage-event-toolkit/' . $seeded['event'];

        $page = $browser->request('GET', $hub);
        $counter = $page->filter('[data-toolkit-card="participant-links"] [data-counter="suggestions"]');
        self::assertCount(1, $counter);
        self::assertStringContainsString('4 people may already have a profile', $counter->text());
        self::assertStringContainsString('/en/participants-sheet/' . $seeded['event'] . '?tab=people&filter=suggestions', (string) $counter->attr('href'));

        $before = $this->hubStatements($browser, $hub);
        // 300 more people, every one of them with a suggestion (a player of their name and country)
        $database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source)
             SELECT gen_uuid_v7(), 'Ottilie Brandvold', 'no', :event, 'imported' FROM generate_series(1, 300)",
            ['event' => $seeded['event']],
        );
        $after = $this->hubStatements($browser, $hub);

        self::assertSame($before, $after);
        $summary = array_values(array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'FROM participant_link_request r')));
        self::assertCount(1, $summary, 'the summary is one statement');
        self::assertStringContainsString('suggestion', $summary[0]);
        self::assertStringContainsString('304 people may already have a profile', (string) $browser->getResponse()->getContent());
    }

    public function testNoSuggestionsNoCounter(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);

        $page = $browser->request('GET', self::HUB);

        self::assertCount(0, $page->filter('[data-counter="suggestions"]'));
    }

    public function testTheOrganiserSwitchesThePolicyAndBackToAutomatic(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $page = $browser->request('GET', self::HUB);

        $form = $page->filter('[data-claim-policy-save]')->form();
        $this->field($form, 'policy')->select('approval');
        $browser->submit($form);

        self::assertResponseRedirects(self::HUB);
        self::assertSame(ParticipantClaimPolicy::Approval, self::getContainer()->get(GetParticipantClaimPolicy::class)->of(self::EVENT)['stored']);

        $page = $browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Saved - picks from your list follow the new setting.');
        self::assertSame('approval', $page->filter('input[name="' . self::FORM . '[policy]"][checked]')->attr('value'));

        $form = $page->filter('[data-claim-policy-save]')->form();
        $this->field($form, 'policy')->select('automatic');
        $browser->submit($form);

        self::assertResponseRedirects(self::HUB);
        self::assertNull(self::getContainer()->get(GetParticipantClaimPolicy::class)->of(self::EVENT)['stored']);
    }

    public function testAnUnknownPolicyIsRefusedWith422(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $page = $browser->request('GET', self::HUB);

        $form = $page->filter('[data-claim-policy-save]')->form();
        $form->disableValidation();
        $this->field($form, 'policy')->setValue('anybody');
        $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getContainer()->get(GetParticipantClaimPolicy::class)->of(self::EVENT)['stored']);
    }

    public function testNobodyElseCanPost(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', self::HUB, [self::FORM => ['policy' => 'open']]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull(self::getContainer()->get(GetParticipantClaimPolicy::class)->of(self::EVENT)['stored']);
    }

    private function field(\Symfony\Component\DomCrawler\Form $form, string $name): ChoiceFormField
    {
        $field = $form[self::FORM . '[' . $name . ']'];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function hubStatements(KernelBrowser $browser, string $hub): int
    {
        $this->startCountingQueries($browser);
        $browser->request('GET', $hub);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private function browser(string $player): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
