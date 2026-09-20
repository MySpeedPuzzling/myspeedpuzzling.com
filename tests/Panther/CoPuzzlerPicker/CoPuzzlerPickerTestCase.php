<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\CoPuzzlerPicker;

use DateTimeImmutable;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use PDO;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Component\Panther\Client;

/**
 * The picker is driven as PLAYER_ADMIN: admins get it whatever PAIRS_TEAMS_PICKER_PUBLIC says
 * (docs/features/feature_flags.md), so these tests do not depend on the rollout flag.
 *
 * The admin has no pair/team history in the fixtures, so each test seeds the one it needs straight
 * into its own database - the one the browser talks to (var/panther_db_url.txt).
 */
abstract class CoPuzzlerPickerTestCase extends AbstractPantherTestCase
{
    protected const string ADMIN = PlayerFixture::PLAYER_ADMIN;
    protected const string JOHN = PlayerFixture::PLAYER_REGULAR;
    protected const string MICHAEL = PlayerFixture::PLAYER_WITH_FAVORITES;
    protected const string SARAH = PlayerFixture::PLAYER_WITH_STRIPE;

    protected static function openAddForm(int $width = 390, int $height = 844): Client
    {
        $client = self::createBrowserClient();
        $client->manage()->window()->setSize(new WebDriverDimension($width, $height));

        self::loginUser($client, 'auth0|admin003', 'admin@speedpuzzling.cz', 'Admin User');

        $client->request('GET', '/en/puzzle-add/' . PuzzleFixture::PUZZLE_1500_01);
        $client->waitFor('[data-controller="copuzzler-picker"]');

        return $client;
    }

    /**
     * A history that exercises every part of the picker: a frequent pair, a pair with a guest, a named
     * team, an unnamed team sharing people with it, and a one-off.
     */
    protected static function seedHistory(): void
    {
        self::seedTeam(null, [self::ADMIN, self::JOHN], times: 5, daysAgo: 2);
        self::seedTeam(null, [self::ADMIN, 'Grandma'], times: 2, daysAgo: 30);
        self::seedTeam('Family', [self::ADMIN, self::JOHN, self::MICHAEL], times: 4, daysAgo: 5);
        self::seedTeam(null, [self::ADMIN, self::JOHN, 'Grandma'], times: 2, daysAgo: 40);
        self::seedTeam(null, [self::ADMIN, self::SARAH, self::MICHAEL], times: 1, daysAgo: 300);
    }

    /**
     * @param non-empty-list<string> $people Player ids, anything else is a guest name; the first one tracks the times
     */
    protected static function seedTeam(null|string $name, array $people, int $times, int $daysAgo): string
    {
        $puzzlers = array_map(
            static fn(string $person): Puzzler => Uuid::isValid($person)
                ? new Puzzler($person, null, null, null, false)
                : new Puzzler(null, $person, null, null, false),
            $people,
        );
        $composition = TeamComposition::fromGroup(new PuzzlersGroup(null, $puzzlers));

        $database = self::testDatabase();
        $teamId = Uuid::uuid7()->toString();
        $at = (new DateTimeImmutable())->modify("-{$daysAgo} days")->format('Y-m-d H:i:s');

        $database->prepare('INSERT INTO puzzling_team (id, composition_key, size, created_at, name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$teamId, $composition->key, $composition->size(), $at, $name]);

        foreach ($composition->members as $member) {
            $database->prepare('INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([Uuid::uuid7()->toString(), $teamId, $member->memberKey, $member->playerId, $member->guestName, $member->position]);
        }

        $snapshot = json_encode([
            'team_id' => null,
            'puzzlers' => array_map(static fn(Puzzler $puzzler): array => ['player_id' => $puzzler->playerId, 'player_name' => $puzzler->playerName], $puzzlers),
        ], JSON_THROW_ON_ERROR);

        for ($i = 0; $i < $times; $i++) {
            $database->prepare(<<<SQL
INSERT INTO puzzle_solving_time (id, seconds_to_solve, player_id, puzzle_id, tracked_at, finished_at, verified, team, puzzlers_count, puzzling_type, puzzling_team_id)
VALUES (?, 7200, ?, ?, ?, ?, false, ?, ?, ?, ?)
SQL)->execute([
                Uuid::uuid7()->toString(),
                $people[0],
                PuzzleFixture::PUZZLE_2000,
                $at,
                $at,
                $snapshot,
                count($puzzlers),
                count($puzzlers) === 2 ? 'duo' : 'team',
                $teamId,
            ]);
        }

        return $teamId;
    }

    protected static function testDatabase(): PDO
    {
        $url = file_get_contents(__DIR__ . '/../../../var/panther_db_url.txt');
        self::assertIsString($url, 'The per-test Panther database is not set up');

        $parts = parse_url(trim($url));
        self::assertIsArray($parts);

        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'] ?? 'postgres', $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/')),
            $parts['user'] ?? 'postgres',
            $parts['pass'] ?? 'postgres',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    // --- driving the picker --------------------------------------------------------------------

    protected static function switchTo(Client $client, string $mode): void
    {
        self::click($client, sprintf('.copuzzler-switch [data-mode="%s"]', $mode));
    }

    protected static function click(Client $client, string $selector): void
    {
        $client->waitForVisibility($selector);
        // Through the DOM rather than WebDriver's pointer: the sticky header may cover an element the
        // browser scrolled to the very top - what is under test is the picker, not the scroll position
        $client->executeScript('document.querySelector(arguments[0]).click();', [$selector]);
    }

    protected static function pickPerson(Client $client, string $key): void
    {
        self::click($client, sprintf('.copuzzler-options [data-action="copuzzler-picker#pickPerson"][data-key="%s"]', $key));
    }

    protected static function waitForSuggestions(Client $client): void
    {
        $client->waitFor('.copuzzler-options [data-action="copuzzler-picker#pickPerson"]');
    }

    /**
     * @return list<string>
     */
    protected static function submittedGroup(Client $client): array
    {
        /** @var list<string> $values */
        $values = $client->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('input[name="group_players[]"]')).map(function (input) { return input.value; });
        JS);

        return $values;
    }

    /**
     * @return list<string>
     */
    protected static function chipLabels(Client $client): array
    {
        /** @var list<string> $labels */
        $labels = $client->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('.copuzzler-chips .copuzzler-chip__label')).map(function (chip) { return chip.textContent; });
        JS);

        return $labels;
    }

    protected static function activeMode(Client $client): string
    {
        return (string) $client->findElement(WebDriverBy::cssSelector('.copuzzler-switch [aria-checked="true"]'))->getAttribute('data-mode');
    }

    protected static function text(Client $client, string $selector): string
    {
        /** @var string $text */
        $text = $client->executeScript('var e = document.querySelector(arguments[0]); return e ? e.textContent.trim() : "";', [$selector]);

        return $text;
    }

    protected static function screenshot(Client $client, string $name): void
    {
        $directory = __DIR__ . '/../../../var/copuzzler-picker-screenshots';

        if (is_dir($directory) === false) {
            mkdir($directory, 0777, true);
        }

        $client->executeScript('document.querySelector(".copuzzler-picker").scrollIntoView({block: "center"});');
        $client->takeScreenshot($directory . '/' . $name . '.png');
    }
}
