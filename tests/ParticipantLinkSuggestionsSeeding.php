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
 *   profile, Dorian is linked to another person of the list, Elodie's pair was refused ("Not this person");
 * - Wendelin (tier 2, never pre-ticked - R2): a cheater renamed their profile "Wendelin Sorrow" and joined the first
 *   edition by themselves; the real Wendelin (at) is on the organiser's list of this one.
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

        // Days from the frozen now (an integer, never a relative moment string - SystemClockCoverageTest)
        $edition = static function (string $id, string $name, int $days) use ($database, $now, $series, $suffix): void {
            $database->executeStatement(
                "INSERT INTO competition (id, name, slug, series_id, is_online, created_at, approved_at, date_from, date_to, added_by_player_id, location, location_country_code)
                 VALUES (:id, :name, :slug, :series, false, {$now}, {$now}, {$now} + make_interval(days => :days), {$now} + make_interval(days => :days), :organiser, 'Harbor Town', 'cz')",
                ['id' => $id, 'name' => $name, 'slug' => 'll-' . substr($id, -8) . '-' . $suffix, 'series' => $series, 'days' => $days, 'organiser' => PlayerFixture::PLAYER_WITH_STRIPE],
            );
        };
        $edition($first, 'Lantern Linking 2025', -300);
        $edition($event, 'Lantern Linking Open', 20);

        $database->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, category, slug) VALUES (:id, :event, 'Main', 60, {$now} + INTERVAL '20 days', 'solo', 'main')",
            ['id' => $round, 'event' => $event],
        );

        $player = static function (string $key, string $name, string $country, bool $private = false) use ($database, $now, $suffix): string {
            $id = Uuid::uuid7()->toString();
            $database->executeStatement(
                "INSERT INTO player (id, code, name, country, is_private, registered_at) VALUES (:id, :code, :name, :country, :private, {$now})",
                ['id' => $id, 'code' => substr(md5($key . $suffix), 0, 8), 'name' => $name, 'country' => $country, 'private' => $private ? 'true' : 'false'],
            );

            return $id;
        };
        $players = [
            'zoe' => $player('zoe', 'Zoë O’Brien-Novák', 'cz'),
            'quirin' => $player('quirin', 'Quirin Vexley', 'cz'),
            'ottilie' => $player('ottilie', 'Ottilie Brandvold', 'no'),
            'ansel1' => $player('ansel1', 'Ansel Common', 'de'),
            'ansel2' => $player('ansel2', 'Ansel Common', 'de'),
            'ansel3' => $player('ansel3', 'Ansel Common', 'de'),
            'ansel4' => $player('ansel4', 'Ansel Common', 'de'),
            'bertram' => $player('bertram', 'Bertram Hidely', 'cz'),
            'cosima' => $player('cosima', 'Cosima Privet', 'cz', private: true),
            'dorian' => $player('dorian', 'Dorian Twice', 'cz'),
            'elodie' => $player('elodie', 'Elodie Refused', 'cz'),
            // A cheater (R2): renamed their profile to "Wendelin Sorrow" and joined the first edition by themselves
            'wendelin' => $player('wendelin', 'Wendelin Sorrow', 'at'),
        ];

        $participant = static function (string $competition, string $name, null|string $country, null|string $player = null, string $source = 'imported') use ($database, $now): string {
            $id = Uuid::uuid7()->toString();
            $database->executeStatement(
                "INSERT INTO competition_participant (id, name, country, competition_id, source, player_id, connected_at, connected_via)
                 VALUES (:id, :name, :country, :competition, :source, :player, CASE WHEN CAST(:player AS UUID) IS NULL THEN NULL ELSE {$now} - INTERVAL '3 days' END, CASE WHEN CAST(:player AS UUID) IS NULL THEN NULL ELSE 'organiser' END)",
                ['id' => $id, 'name' => $name, 'country' => $country, 'competition' => $competition, 'source' => $source, 'player' => $player],
            );

            return $id;
        };
        $participants = [
            'quirinBefore' => $participant($first, 'Quirin Vexley', 'cz', $players['quirin']),
            // The cheater's own self-joined row at the first edition - its name copied from their profile
            'wendelinBefore' => $participant($first, 'Wendelin Sorrow', null, $players['wendelin'], 'self_joined'),
            'zoe' => $participant($event, "zoe o'brien–novak", 'cz'),
            'zoeSelf' => $participant($event, 'Zoë O’Brien-Novák', null, $players['zoe'], 'self_joined'),
            'quirin' => $participant($event, 'QUIRIN  vexley', 'cz'),
            'ottilie' => $participant($event, 'Ottilie Brandvold', 'no'),
            'ansel' => $participant($event, 'Ansel Common', 'de'),
            'bertram' => $participant($event, 'Bertram Hidely', 'cz'),
            'cosima' => $participant($event, 'Cosima Privet', 'cz'),
            'dorian' => $participant($event, 'Dorian Twice', 'cz'),
            'dorianLinked' => $participant($event, 'Dorian T.', 'cz', $players['dorian']),
            'elodie' => $participant($event, 'Elodie Refused', 'cz'),
            // The real Wendelin, on the organiser's list
            'wendelin' => $participant($event, 'Wendelin Sorrow', 'at'),
        ];

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
