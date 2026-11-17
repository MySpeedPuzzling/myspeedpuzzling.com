<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Move the rounds too" on the edit event form (docs/features/competitions-management/README.md "Moving an event's
 * date"): offered (ticked) only while the event has rounds and none has begun; ticked + a new start date moves them.
 */
final class EditCompetitionMoveRoundsFieldTest extends WebTestCase
{
    private const string FIELD = 'input[type="checkbox"][name="competition_form[moveRoundsWithDate]"]';

    public function testAnEventWithoutRoundsIsNotAsked(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/edit-event/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::FIELD);
        self::assertSelectorNotExists('[data-controller~="date-move-rounds"]');
    }

    public function testAnEventWithResultsIsNotAsked(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/edit-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::FIELD);
    }

    public function testTickedByDefaultItMovesTheRoundsWithTheNewStartDate(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $copyId = $this->copy($browser);
        $connection = self::getContainer()->get(Connection::class);
        $dateFrom = new DateTimeImmutable(self::text($connection->fetchOne('SELECT date_from FROM competition WHERE id = :id', ['id' => $copyId])));
        $before = $connection->fetchFirstColumn('SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => $copyId]);

        $browser->request('GET', '/en/edit-event/' . $copyId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(self::FIELD . '[checked]');
        self::assertSelectorExists('[data-controller~="date-move-rounds"][data-date-move-rounds-original-value="' . $dateFrom->format('d.m.Y') . '"]');
        self::assertSelectorExists('[data-date-move-rounds-original-end-value="' . $dateFrom->format('d.m.Y') . '"] input[name="competition_form[dateTo]"][data-date-move-rounds-target="end"]');

        $newDay = $dateFrom->modify('+10 days')->format('d.m.Y');
        $browser->submitForm('Save Changes', [
            'competition_form[dateFrom]' => $newDay,
            'competition_form[dateTo]' => $newDay,
        ]);

        self::assertResponseRedirects();
        $after = $connection->fetchFirstColumn('SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => $copyId]);
        self::assertSame(
            array_map(static fn (mixed $start): string => (new DateTimeImmutable(self::text($start)))->modify('+10 days')->format('Y-m-d H:i'), $before),
            array_map(static fn (mixed $start): string => (new DateTimeImmutable(self::text($start)))->format('Y-m-d H:i'), $after),
        );
    }

    public function testUntickedTheRoundsStay(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $copyId = $this->copy($browser);
        $connection = self::getContainer()->get(Connection::class);
        $dateFrom = new DateTimeImmutable(self::text($connection->fetchOne('SELECT date_from FROM competition WHERE id = :id', ['id' => $copyId])));
        $before = $connection->fetchFirstColumn('SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => $copyId]);

        $browser->request('GET', '/en/edit-event/' . $copyId);
        $form = $browser->getCrawler()->selectButton('Save Changes')->form();
        $newDay = $dateFrom->modify('+10 days')->format('d.m.Y');
        $form['competition_form[dateFrom]'] = $newDay;
        $form['competition_form[dateTo]'] = $newDay;
        /** @var \Symfony\Component\DomCrawler\Field\ChoiceFormField $checkbox */
        $checkbox = $form['competition_form[moveRoundsWithDate]'];
        $checkbox->untick();
        $browser->submit($form);

        self::assertResponseRedirects();
        self::assertSame($before, $connection->fetchFirstColumn('SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => $copyId]));
    }

    /**
     * A fresh draft copy of the Results Cup (5 rounds, nothing people did), made through "Duplicate" on its own date
     */
    private function copy(KernelBrowser $browser): string
    {
        $sourceFrom = new DateTimeImmutable(self::text(self::getContainer()->get(Connection::class)->fetchOne('SELECT date_from FROM competition WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP])));
        $browser->request('POST', '/en/duplicate-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, ['_token' => 'csrf-token', 'dateFrom' => $sourceFrom->format('Y-m-d'), 'name' => 'Results Cup'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(303);
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~^/en/edit-event/[0-9a-f-]{36}$~', $location);

        return substr($location, strlen('/en/edit-event/'));
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
