<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * An organiser (2026-10-05) opened the inline edit form of ~200 participants to assign
 * their rounds: every form came up empty or with the previous participant's values, and saving
 * it wrote those values over the participant - clearing the country, unlinking the MSP player,
 * renaming one participant after another. Every action goes through a real Live request here,
 * so the component is hydrated exactly like in the browser.
 */
final class ManageCompetitionParticipantsEditTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testEditFormIsPrefilledWithEveryCurrentValueOfTheParticipant(): void
    {
        $component = $this->componentAsAdmin();
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);

        $state = $this->state($component);
        self::assertSame(CompetitionParticipantFixture::PARTICIPANT_CONNECTED, $state->editingParticipantId);
        self::assertSame('John Regular', $state->editName);
        self::assertSame('cz', $state->editCountry);
        self::assertSame('EXT-001', $state->editExternalId);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $state->editPlayerId);
        self::assertNotNull($state->editPlayerName);
        self::assertEqualsCanonicalizing(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $state->editRoundIds,
        );

        $html = $component->render()->toString();
        $editRow = $this->editRowHtml($html, CompetitionParticipantFixture::PARTICIPANT_CONNECTED);
        self::assertStringContainsString('value="John Regular"', $editRow);
        self::assertStringContainsString('value="EXT-001"', $editRow);
        self::assertMatchesRegularExpression('/<option value="cz"[^>]*selected/', $editRow);
        self::assertSame(2, substr_count($editRow, 'aria-pressed="true"'));
    }

    public function testSwitchingToAnotherParticipantNeverCarriesValuesOver(): void
    {
        $component = $this->componentAsAdmin();
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
        $component->set('editName', 'Typed but never saved');
        $component->set('editCountry', 'fr');
        $component->call('toggleEditRound', ['roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL]);

        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]);

        $state = $this->state($component);
        self::assertSame(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED, $state->editingParticipantId);
        self::assertSame('Jane Unconnected', $state->editName);
        self::assertSame('us', $state->editCountry);
        self::assertSame('', $state->editExternalId);
        self::assertNull($state->editPlayerId);
        self::assertNull($state->editPlayerName);
        self::assertSame([CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION], $state->editRoundIds);

        $html = $component->render()->toString();
        self::assertSame(1, substr_count($html, 'data-model="editName"'), 'Only one edit row may exist at a time');
        self::assertStringNotContainsString('Typed but never saved', $html);
    }

    public function testSavingAnUntouchedFormKeepsTheParticipantExactlyAsItWas(): void
    {
        $component = $this->componentAsAdmin();
        $before = $this->participantRow(CompetitionParticipantFixture::PARTICIPANT_CONNECTED);
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
        $component->call('saveEdit');

        self::assertSame($before, $this->participantRow(CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
        self::assertNull($this->state($component)->editingParticipantId);
    }

    public function testSavingPersistsExactlyWhatTheFormShows(): void
    {
        $component = $this->componentAsAdmin();

        // An edit of another participant first - none of it may leak into the next one
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
        $component->call('cancelEdit');

        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]);
        $component->set('editName', 'Jane Renamed');
        $component->call('toggleEditRound', ['roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL]);
        $component->call('saveEdit');

        $row = $this->participantRow(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);
        self::assertSame('Jane Renamed', $row['name']);
        self::assertSame('us', $row['country']);
        self::assertNull($row['external_id']);
        self::assertNull($row['player_id']);
        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $row['rounds'],
        );
    }

    public function testCancelRestoresTheRow(): void
    {
        $component = $this->componentAsAdmin();
        $before = $this->participantRow(CompetitionParticipantFixture::PARTICIPANT_CONNECTED);
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
        $component->set('editName', 'Never saved');
        $component->call('cancelEdit');

        $html = $component->render()->toString();
        self::assertStringNotContainsString('data-model="editName"', $html);
        self::assertStringNotContainsString('Never saved', $html);
        self::assertStringContainsString('John Regular', $html);
        self::assertSame($before, $this->participantRow(CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
    }

    public function testBlankNameIsRefusedAndNothingIsWritten(): void
    {
        $component = $this->componentAsAdmin();
        $before = $this->participantRow(CompetitionParticipantFixture::PARTICIPANT_CONNECTED);
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
        $component->set('editName', '   ');
        $component->call('saveEdit');

        self::assertSame($before, $this->participantRow(CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
        self::assertSame(CompetitionParticipantFixture::PARTICIPANT_CONNECTED, $this->state($component)->editingParticipantId);
        self::assertStringContainsString('is-invalid', $component->render()->toString());
    }

    public function testDeletingTheParticipantBeingEditedClosesTheForm(): void
    {
        $component = $this->componentAsAdmin();
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
        $component->call('deleteParticipant', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);

        $state = $this->state($component);
        self::assertNull($state->editingParticipantId);
        self::assertSame('', $state->editName);
        self::assertSame([], $state->editRoundIds);
        self::assertNotNull($this->participantRow(CompetitionParticipantFixture::PARTICIPANT_CONNECTED)['deleted_at']);
    }

    public function testParticipantOfAnotherEventCannotBeEdited(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);
        $component = $this->createLiveComponent('ManageCompetitionParticipants', [
            'competitionId' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
        ], $client);
        $component->setRouteLocale('en');

        $this->expectException(NotFoundHttpException::class);
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
    }

    public function testParticipantOfAnotherEventCannotBeDeleted(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);
        $component = $this->createLiveComponent('ManageCompetitionParticipants', [
            'competitionId' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
        ], $client);
        $component->setRouteLocale('en');

        try {
            $component->call('deleteParticipant', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
            self::fail('Deleting a participant of another event must be refused');
        } catch (NotFoundHttpException) {
        }

        self::assertNull($this->participantRow(CompetitionParticipantFixture::PARTICIPANT_CONNECTED)['deleted_at']);
    }

    public function testOnlyEventMaintainersCanUseTheComponent(): void
    {
        $client = self::createClient();
        // PLAYER_REGULAR maintains other events, not WJPC 2024
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $this->expectException(AccessDeniedHttpException::class);

        $component = $this->createLiveComponent('ManageCompetitionParticipants', [
            'competitionId' => CompetitionFixture::COMPETITION_WJPC_2024,
        ], $client);
        $component->setRouteLocale('en');
        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
    }

    private function componentAsAdmin(): TestLiveComponent
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);

        $component = $this->createLiveComponent('ManageCompetitionParticipants', [
            'competitionId' => CompetitionFixture::COMPETITION_WJPC_2024,
        ], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * The state the browser holds after the last response - the props it sends with the next action.
     * (TestLiveComponent::component() hydrates outside of a request, where nobody is signed in.)
     */
    private function state(TestLiveComponent $component): \stdClass
    {
        $crawler = new Crawler($component->render()->toString());
        $props = $crawler->filter('[data-live-props-value]')->attr('data-live-props-value');
        self::assertNotNull($props);

        $state = json_decode($props, false, flags: JSON_THROW_ON_ERROR);
        assert($state instanceof \stdClass);

        return $state;
    }

    private function editRowHtml(string $html, string $participantId): string
    {
        $start = strpos($html, 'id="participant-' . $participantId . '-edit"');
        self::assertNotFalse($start, 'The edit row of the participant is rendered');
        $end = strpos($html, '</tr>', $start);
        self::assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * @return array{name: string, country: null|string, external_id: null|string, player_id: null|string, connected_at: null|string, deleted_at: null|string, rounds: list<string>}
     */
    private function participantRow(string $participantId): array
    {
        $connection = self::getContainer()->get(Connection::class);

        /** @var array{name: string, country: null|string, external_id: null|string, player_id: null|string, connected_at: null|string, deleted_at: null|string} $row */
        $row = $connection->fetchAssociative(
            'SELECT name, country, external_id, player_id, connected_at, deleted_at FROM competition_participant WHERE id = :id',
            ['id' => $participantId],
        );

        /** @var list<string> $rounds */
        $rounds = $connection->fetchFirstColumn(
            'SELECT round_id FROM competition_participant_round WHERE participant_id = :id ORDER BY round_id',
            ['id' => $participantId],
        );

        return $row + ['rounds' => $rounds];
    }
}
