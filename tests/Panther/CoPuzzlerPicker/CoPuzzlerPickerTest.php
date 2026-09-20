<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\CoPuzzlerPicker;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Component\Panther\Client;

/**
 * The promises of docs/features/pairs-and-teams/README.md that only a real browser can keep:
 * choosing co-puzzlers never disturbs the form around it, the mode is always switchable and
 * nothing is ever lost by switching, and a mis-tap is one tap away from being undone.
 */
final class CoPuzzlerPickerTest extends CoPuzzlerPickerTestCase
{
    public function testChoosingCoPuzzlersNeverDisturbsTheForm(): void
    {
        $client = self::openAddForm();
        self::seedHistory();

        $client->executeScript(<<<'JS'
            document.querySelector('[name="puzzle_add_form[comment]"]').value = 'Rainy Sunday';
            document.querySelector('[name="puzzle_add_form[timeHours]"]').value = '2';
            document.querySelector('[name="puzzle_add_form[timeMinutes]"]').value = '30';
        JS);
        $urlBefore = $client->getCurrentURL();

        self::switchTo($client, 'pair');
        self::waitForSuggestions($client);
        self::pickPerson($client, self::JOHN);
        self::assertSame(['#PLAYER1'], self::submittedGroup($client));
        self::assertStringContainsString('Pair with John Doe', self::text($client, '.copuzzler-summary__text'));
        self::assertStringContainsString('5×', self::text($client, '.copuzzler-summary__text'));

        self::switchTo($client, 'team');
        self::assertSame(['John Doe'], self::chipLabels($client), 'The pair partner is carried into the team');

        // Somebody the player never puzzled with: found by the remote search
        self::search($client, 'Sarah');
        $client->waitFor('.copuzzler-search .ts-dropdown .option:not(.create)');
        self::searchInput($client)->sendKeys(WebDriverKeys::ENTER);
        $client->waitFor('.copuzzler-chip[data-key="' . self::SARAH . '"]');

        // A guest without an account, confirmed with Enter - the key that must never submit the form
        self::search($client, 'Aunt Zoe');
        $client->waitFor('.copuzzler-search .ts-dropdown .create');
        self::searchInput($client)->sendKeys(WebDriverKeys::ENTER);
        $client->waitFor('.copuzzler-chip[data-key="g:aunt zoe"]');

        // …not even on an empty search box
        self::searchInput($client)->sendKeys(WebDriverKeys::ENTER);
        usleep(300000);

        self::assertSame($urlBefore, $client->getCurrentURL(), 'The form was submitted or the page navigated');
        self::assertSame(['John Doe', 'Sarah Williams', 'Aunt Zoe'], self::chipLabels($client));
        self::assertSame(['#PLAYER1', '#PLAYER4', 'Aunt Zoe'], self::submittedGroup($client));
        self::assertStringContainsString('Team of 4', self::text($client, '.copuzzler-identity'));
        self::assertStringContainsString('first time together', self::text($client, '.copuzzler-identity'));

        self::assertSame(
            ['Rainy Sunday', '2', '30'],
            $client->executeScript(<<<'JS'
                return ['comment', 'timeHours', 'timeMinutes'].map(function (field) {
                    return document.querySelector('[name="puzzle_add_form[' + field + ']"]').value;
                });
            JS),
            'Fields of the form around the picker lost their values',
        );
    }

    public function testModeIsAlwaysSwitchableAndNothingIsLost(): void
    {
        $client = self::openAddForm();
        self::seedHistory();

        self::switchTo($client, 'team');
        self::waitForSuggestions($client);
        self::pickPerson($client, self::JOHN);
        self::pickPerson($client, self::MICHAEL);
        self::pickPerson($client, 'g:grandma');
        self::click($client, '[data-action="copuzzler-picker#showNameInput"]');
        $client->findElement(WebDriverBy::cssSelector('input[name="team_name"]'))->sendKeys('Sunday crew');

        self::assertSame(['#PLAYER1', '#PLAYER3', 'Grandma'], self::submittedGroup($client));

        // Team -> Pair: the team is put aside, its people come first ("Which one?")
        self::switchTo($client, 'pair');
        self::assertSame([], self::submittedGroup($client));
        self::assertSame('Which one?', self::text($client, '[data-copuzzler-picker-target="peopleLabel"]'));
        // …the most frequent pair partner among them first
        self::assertSame(
            [self::JOHN, 'g:grandma', self::MICHAEL],
            array_slice(self::offeredPeople($client), 0, 3),
        );

        self::pickPerson($client, self::MICHAEL);
        self::assertSame(['#PLAYER3'], self::submittedGroup($client));
        self::assertSame('pair', self::activeMode($client));

        // Pair -> Team: the team is back exactly as it was, name included
        self::switchTo($client, 'team');
        self::assertSame(['John Doe', 'Michael Johnson', 'Grandma'], self::chipLabels($client));
        self::assertSame(['#PLAYER1', '#PLAYER3', 'Grandma'], self::submittedGroup($client));
        self::assertSame('Sunday crew', self::value($client, 'input[name="team_name"]'));

        // -> Solo: nothing is submitted, nothing is thrown away
        self::switchTo($client, 'solo');
        self::assertSame([], self::submittedGroup($client));
        self::assertFalse($client->findElement(WebDriverBy::cssSelector('.copuzzler-card'))->isDisplayed());

        self::switchTo($client, 'team');
        self::assertSame(['#PLAYER1', '#PLAYER3', 'Grandma'], self::submittedGroup($client));
        self::assertSame('Sunday crew', self::value($client, 'input[name="team_name"]'));

        // …and the pair kept its own choice all along
        self::switchTo($client, 'pair');
        self::assertSame(['#PLAYER3'], self::submittedGroup($client));
        self::assertStringContainsString('Pair with Michael Johnson', self::text($client, '.copuzzler-summary__text'));

        // The switch works from the collapsed summary too
        self::switchTo($client, 'solo');
        self::assertSame('solo', self::activeMode($client));
    }

    public function testMisTapsAreOneTapAwayFromUndone(): void
    {
        $client = self::openAddForm();
        self::seedHistory();

        self::switchTo($client, 'team');
        self::waitForSuggestions($client);

        // A whole team by mistake: Undo takes all of them back
        self::click($client, '.copuzzler-option--team');
        self::assertSame(['John Doe', 'Michael Johnson'], self::chipLabels($client));
        self::assertStringContainsString('Family', self::text($client, '.copuzzler-identity'));
        self::click($client, '[data-action="copuzzler-picker#undoMultiAdd"]');
        self::assertSame([], self::chipLabels($client));
        self::assertSame([], self::submittedGroup($client));

        // Somebody removed by mistake is the first one offered again
        self::pickPerson($client, 'g:grandma');
        self::pickPerson($client, self::MICHAEL);
        self::click($client, '.copuzzler-chip[data-key="g:grandma"] .copuzzler-chip__remove');
        self::assertSame(['#PLAYER3'], self::submittedGroup($client));
        self::assertSame('g:grandma', self::offeredPeople($client)[0]);

        self::pickPerson($client, 'g:grandma');
        self::assertSame(['#PLAYER3', 'Grandma'], self::submittedGroup($client));
    }

    public function testSuggestionsNarrowToTeamsThatStillFit(): void
    {
        $client = self::openAddForm();
        self::seedHistory();

        self::switchTo($client, 'team');
        self::waitForSuggestions($client);

        // Nothing picked: the regular teams, never the one-off
        self::assertSame('Your teams', self::text($client, '[data-copuzzler-picker-target="teamsLabel"]'));
        self::assertCount(2, self::offeredTeams($client));

        // Grandma is in one team only - and tapping it can only ever add people
        self::pickPerson($client, 'g:grandma');
        $teams = self::offeredTeams($client);
        self::assertCount(1, $teams);
        self::assertStringContainsString('+ John Doe', $teams[0]);

        self::click($client, '.copuzzler-option--team');
        self::assertSame(['Grandma', 'John Doe'], self::chipLabels($client));
        self::assertStringContainsString('Team of 3', self::text($client, '.copuzzler-identity'));
        self::assertStringContainsString('2×', self::text($client, '.copuzzler-identity'));
    }

    public function testWhoeverYouPuzzledWithLatelyComesFirstThenTheMostFrequent(): void
    {
        $client = self::openAddForm();
        self::seedTeam(null, [self::ADMIN, self::JOHN], times: 6, daysAgo: 20);
        self::seedTeam(null, [self::ADMIN, self::MICHAEL], times: 3, daysAgo: 90);
        self::seedTeam(null, [self::ADMIN, 'Grandma'], times: 1, daysAgo: 1);
        self::seedTeam(null, [self::ADMIN, self::SARAH], times: 2, daysAgo: 0);

        self::switchTo($client, 'pair');
        self::waitForSuggestions($client);

        // Today, yesterday - and only then by how often
        self::assertSame([self::SARAH, 'g:grandma', self::JOHN, self::MICHAEL], array_slice(self::offeredPeople($client), 0, 4));
    }

    public function testNoMorePeopleThanTheMaximum(): void
    {
        $client = self::openAddForm();
        self::seedHistory();
        $client->executeScript('document.querySelector(".copuzzler-picker").setAttribute("data-copuzzler-picker-max-value", "2");');

        self::switchTo($client, 'team');
        self::waitForSuggestions($client);
        self::pickPerson($client, self::JOHN);
        self::pickPerson($client, self::MICHAEL);

        self::assertTrue($client->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('[data-action="copuzzler-picker#pickPerson"]')).every(function (button) { return button.disabled; });
        JS));

        $client->executeScript('document.querySelector(\'[data-action="copuzzler-picker#pickPerson"]\').click();');
        self::assertSame(['#PLAYER1', '#PLAYER3'], self::submittedGroup($client));
    }

    public function testGroupAndNameSurviveAValidationErrorAndAreSaved(): void
    {
        $client = self::openAddForm();
        self::seedHistory();

        self::switchTo($client, 'team');
        self::waitForSuggestions($client);
        self::pickPerson($client, self::SARAH);
        self::pickPerson($client, 'g:grandma');
        self::click($client, '[data-action="copuzzler-picker#showNameInput"]');
        $client->findElement(WebDriverBy::cssSelector('input[name="team_name"]'))->sendKeys('Knitting circle');

        // No time entered: the server refuses the form (422) and renders it again
        $client->executeScript(<<<'JS'
            var form = document.querySelector('form[name="puzzle_add_form"]');
            form.noValidate = true;
            form.requestSubmit();
        JS);
        $client->waitFor('.invalid-feedback, .alert-danger, .is-invalid');
        $client->waitFor('.copuzzler-summary__text');

        self::assertSame('team', self::activeMode($client));
        self::assertSame(['#PLAYER4', 'Grandma'], self::submittedGroup($client));
        self::assertSame('Knitting circle', self::value($client, 'input[name="team_name"]'));
        self::assertStringContainsString('Team of 3', self::text($client, '.copuzzler-summary__text'));

        $client->executeScript(<<<'JS'
            document.querySelector('[name="puzzle_add_form[timeHours]"]').value = '4';
            document.querySelector('[name="puzzle_add_form[timeMinutes]"]').value = '10';
            document.querySelector('[name="puzzle_add_form[timeSeconds]"]').value = '0';
            var form = document.querySelector('form[name="puzzle_add_form"]');
            form.noValidate = true;
            form.requestSubmit();
        JS);
        $client->wait(30)->until(static fn(): bool => str_contains((string) $client->getCurrentURL(), 'puzzle-add') === false);

        $team = self::testDatabase()->query(<<<SQL
SELECT team.name, team.size
FROM puzzle_solving_time time
INNER JOIN puzzling_team team ON team.id = time.puzzling_team_id
WHERE time.puzzle_id = '{$this->puzzleId()}' AND time.player_id = '{$this->adminId()}'
SQL)->fetch(\PDO::FETCH_ASSOC);

        self::assertSame(['name' => 'Knitting circle', 'size' => 3], $team);
    }

    public function testSwitchFitsOneLineOnTheSmallestPhonesInEveryLanguage(): void
    {
        $client = self::openAddForm(320, 640);

        $paths = [
            'en' => '/en/puzzle-add/',
            'cs' => '/pridat-puzzle/',
            'de' => '/de/puzzle-hinzufuegen/',
            'es' => '/es/agregar-puzzle/',
            'fr' => '/fr/ajouter-puzzle/',
            'ja' => '/ja/パズル追加/',
        ];

        foreach ($paths as $locale => $path) {
            $client->request('GET', $path . PuzzleFixture::PUZZLE_1500_01);
            $client->waitFor('[data-controller="copuzzler-picker"]');

            $geometry = $client->executeScript(<<<'JS'
                var options = Array.from(document.querySelectorAll('.copuzzler-switch__option'));
                var switcher = document.querySelector('.copuzzler-switch');
                return {
                    labels: options.map(function (option) { return option.querySelector('.copuzzler-switch__label').textContent; }),
                    tops: options.map(function (option) { return Math.round(option.getBoundingClientRect().top); }),
                    widths: options.map(function (option) { return Math.round(option.getBoundingClientRect().width); }),
                    labelsClipped: options.some(function (option) { var label = option.querySelector('.copuzzler-switch__label'); return label.scrollWidth > label.clientWidth; }),
                    switchOverflows: switcher.scrollWidth > switcher.clientWidth,
                    pageOverflows: document.documentElement.scrollWidth > document.documentElement.clientWidth
                };
            JS);

            self::assertIsArray($geometry);
            $message = $locale . ': ' . json_encode($geometry, JSON_UNESCAPED_UNICODE);

            self::assertCount(3, $geometry['tops'], $message);
            self::assertCount(1, array_unique($geometry['tops']), 'The three options are not on one line - ' . $message);
            self::assertLessThanOrEqual(1, max($geometry['widths']) - min($geometry['widths']), $message);
            self::assertFalse($geometry['labelsClipped'], $message);
            self::assertFalse($geometry['switchOverflows'], $message);
            self::assertFalse($geometry['pageOverflows'], $message);
        }
    }

    public function testMemberEditingSomebodyElsesTimeCannotRemoveTheTracker(): void
    {
        $client = self::openAddForm();
        // John tracked it, the admin took part
        self::seedTeam(null, [self::JOHN, self::ADMIN], times: 1, daysAgo: 3);
        $timeId = self::testDatabase()
            ->query("SELECT id FROM puzzle_solving_time WHERE player_id = '" . self::JOHN . "' AND puzzlers_count = 2 AND puzzle_id = '" . PuzzleFixture::PUZZLE_2000 . "'")
            ->fetchColumn();
        self::assertIsString($timeId);

        $client->request('GET', '/en/edit-time/' . $timeId);
        $client->waitFor('.copuzzler-summary__text');
        self::click($client, '[data-action="copuzzler-picker#expand"]');

        self::assertSame('pair', self::activeMode($client));
        self::assertSame(['John Doe', 'Admin User'], self::chipLabels($client));
        self::assertCount(0, $client->findElements(WebDriverBy::cssSelector('.copuzzler-chip--locked .copuzzler-chip__remove')));
        self::assertSame(['#ADMIN'], self::submittedGroup($client));

        // Leaving the time is allowed - and said out loud, in solo mode too
        self::switchTo($client, 'solo');
        self::assertSame('You will no longer be part of this time.', self::text($client, '[data-copuzzler-picker-target="notice"]'));

        self::switchTo($client, 'pair');
        self::assertSame(['#ADMIN'], self::submittedGroup($client));
        self::assertSame('', self::text($client, '[data-copuzzler-picker-target="notice"]'));
    }

    private function puzzleId(): string
    {
        return PuzzleFixture::PUZZLE_1500_01;
    }

    private function adminId(): string
    {
        return self::ADMIN;
    }

    private static function search(Client $client, string $text): void
    {
        $client->waitFor('.copuzzler-search .ts-control input');
        $input = self::searchInput($client);
        $input->click();
        $input->sendKeys($text);
    }

    private static function searchInput(Client $client): \Facebook\WebDriver\WebDriverElement
    {
        return $client->findElement(WebDriverBy::cssSelector('.copuzzler-search .ts-control input'));
    }

    private static function value(Client $client, string $selector): string
    {
        /** @var string $value */
        $value = $client->executeScript('return document.querySelector(arguments[0]).value;', [$selector]);

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function offeredPeople(Client $client): array
    {
        /** @var list<string> $keys */
        $keys = $client->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('[data-action="copuzzler-picker#pickPerson"]')).map(function (button) { return button.dataset.key; });
        JS);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function offeredTeams(Client $client): array
    {
        /** @var list<string> $titles */
        $titles = $client->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('.copuzzler-option--team .copuzzler-option__title')).map(function (title) { return title.textContent; });
        JS);

        return $titles;
    }
}
