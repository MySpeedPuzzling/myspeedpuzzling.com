<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetParticipantClaimPolicy;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
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

    private function browser(string $player): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
