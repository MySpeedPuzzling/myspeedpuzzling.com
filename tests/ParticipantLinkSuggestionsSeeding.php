<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;

/**
 * An event of PLAYER_WITH_STRIPE's whose list has people without a profile who may have one - every tier and every
 * exclusion of the link suggestions (docs/features/competitions-management/participant-linking.md §8.4). Invented
 * names only. The event "Lantern Linking Open" is the second edition of the series "Lantern Linking"; its first
 * edition linked Quirin.
 *
 * - Zoë (tier 1): imported "zoe o'brien–novak" + her own self-joined row "Zoë O’Brien-Novák" (curly apostrophe, a
 *   hyphen - the name key makes them one);
 * - Quirin (tier 2): imported "QUIRIN  vexley" (cz), linked at the first edition, profile country cz;
 * - Ottilie (tier 3): imported "Ottilie Brandvold" (no) = the player of that name and country;
 * - Ansel (none - a common name): four players "Ansel Common" (de);
 * - Bertram, Cosima, Dorian, Elodie (none unless...): Bertram is a player the organiser may block, Cosima a private
 *   profile, Dorian is linked to another person of the list, Elodie's pair was refused ("Not this person").
 *
 * @phpstan-type Seeded array{event: string, series: string, firstEdition: string, round: string, players: array<string, string>, participants: array<string, string>}
 */
trait ParticipantLinkSuggestionsSeeding
{
    /**
     * @return Seeded
     */
    private function seedLinkSuggestions(Connection $database): array
    {
        $now = TestClock::SQL_NOW;
        $suffix = substr(str_replace('-', '', Uuid::uuid7()->toString()), -10);
        $series = Uuid::uuid7()->toString();
        $first = Uuid::uuid7()->toString();
        $event = Uuid::uuid7()->toString();
        $round = Uuid::uuid7()->toString();

        $database->executeStatement(
            "INSERT INTO competition_series (id, name, slug, is_online, created_at, approved_at) VALUES (:id, 'Lantern Linking', :slug, false, {$now}, {$now})",
            ['id' => $series, 'slug' => 'lantern-linking-' . $suffix],
        );

        foreach ([[$first, 'Lantern Linking 2025', '-300 days'], [$event, 'Lantern Linking Open', '+20 days']] as [$id, $name, $shift]) {
            $database->executeStatement(
                "INSERT INTO competition (id, name, slug, series_id, is_online, created_at, approved_at, date_from, date_to, added_by_player_id, location, location_country_code)
                 VALUES (:id, :name, :slug, :series, false, {$now}, {$now}, {$now} + CAST(:shift AS INTERVAL), {$now} + CAST(:shift AS INTERVAL), :organiser, 'Harbor Town', 'cz')",
                ['id' => $id, 'name' => $name, 'slug' => 'll-' . substr($id, -8) . '-' . $suffix, 'series' => $series, 'shift' => $shift, 'organiser' => PlayerFixture::PLAYER_WITH_STRIPE],
            );
        }

        $database->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, category, slug) VALUES (:id, :event, 'Main', 60, {$now} + INTERVAL '20 days', 'solo', 'main')",
            ['id' => $round, 'event' => $event],
        );

        $players = [];

        foreach ([
            'zoe' => ['Zoë O’Brien-Novák', 'cz', false],
            'quirin' => ['Quirin Vexley', 'cz', false],
            'ottilie' => ['Ottilie Brandvold', 'no', false],
            'ansel1' => ['Ansel Common', 'de', false],
            'ansel2' => ['Ansel Common', 'de', false],
            'ansel3' => ['Ansel Common', 'de', false],
            'ansel4' => ['Ansel Common', 'de', false],
            'bertram' => ['Bertram Hidely', 'cz', false],
            'cosima' => ['Cosima Privet', 'cz', true],
            'dorian' => ['Dorian Twice', 'cz', false],
            'elodie' => ['Elodie Refused', 'cz', false],
        ] as $key => [$name, $country, $private]) {
            $players[$key] = Uuid::uuid7()->toString();
            $database->executeStatement(
                "INSERT INTO player (id, code, name, country, is_private, registered_at) VALUES (:id, :code, :name, :country, :private, {$now})",
                ['id' => $players[$key], 'code' => substr(md5($key . $suffix), 0, 8), 'name' => $name, 'country' => $country, 'private' => $private ? 'true' : 'false'],
            );
        }

        $participants = [];
        $participant = function (string $key, string $competition, string $name, null|string $country, null|string $player = null, string $source = 'imported') use ($database, $now, &$participants): void {
            $participants[$key] = Uuid::uuid7()->toString();
            $database->executeStatement(
                "INSERT INTO competition_participant (id, name, country, competition_id, source, player_id, connected_at, connected_via)
                 VALUES (:id, :name, :country, :competition, :source, :player, CASE WHEN CAST(:player AS UUID) IS NULL THEN NULL ELSE {$now} - INTERVAL '3 days' END, CASE WHEN CAST(:player AS UUID) IS NULL THEN NULL ELSE 'organiser' END)",
                ['id' => $participants[$key], 'name' => $name, 'country' => $country, 'competition' => $competition, 'source' => $source, 'player' => $player],
            );
        };

        $participant('quirinBefore', $first, 'Quirin Vexley', 'cz', $players['quirin']);
        $participant('zoe', $event, "zoe o'brien–novak", 'cz');
        $participant('zoeSelf', $event, 'Zoë O’Brien-Novák', null, $players['zoe'], 'self_joined');
        $participant('quirin', $event, 'QUIRIN  vexley', 'cz');
        $participant('ottilie', $event, 'Ottilie Brandvold', 'no');
        $participant('ansel', $event, 'Ansel Common', 'de');
        $participant('bertram', $event, 'Bertram Hidely', 'cz');
        $participant('cosima', $event, 'Cosima Privet', 'cz');
        $participant('dorian', $event, 'Dorian Twice', 'cz');
        $participant('dorianLinked', $event, 'Dorian T.', 'cz', $players['dorian']);
        $participant('elodie', $event, 'Elodie Refused', 'cz');

        $database->executeStatement(
            "INSERT INTO participant_link_log (id, competition_id, participant_id, participant_name, player_id, player_name, action, method, by_player_id, at)
             VALUES (:id, :competition, :participant, 'Elodie Refused', :player, 'Elodie Refused', 'not_this_person', NULL, NULL, {$now})",
            ['id' => Uuid::uuid7()->toString(), 'competition' => $event, 'participant' => $participants['elodie'], 'player' => $players['elodie']],
        );

        return ['event' => $event, 'series' => $series, 'firstEdition' => $first, 'round' => $round, 'players' => $players, 'participants' => $participants];
    }

    private function blockPlayer(Connection $database, string $blocker, string $blocked): void
    {
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blocker, 'blocked' => $blocked],
        );
    }
}
