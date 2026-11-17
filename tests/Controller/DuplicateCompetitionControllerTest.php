<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use IntlDateFormatter;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Duplicate" (docs/features/competitions-management/README.md "Duplicating an event"): signed in, COMPETITION_EDIT,
 * the stateless CSRF token on POST. GET asks for the new date (+ name, proposed for a series' next edition), POST makes
 * the copy on that date - a 303 to the new draft's edit page with a flash naming the date, 422 without a date; offered
 * in the ⋯ menu and on the edit page as a link.
 */
final class DuplicateCompetitionControllerTest extends WebTestCase
{
    private const string PATH = '/en/duplicate-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAPlayerWhoCannotEditTheEventIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $before = $this->copies();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->copies());
    }

    public function testARequestFromAnotherSiteIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $before = $this->copies();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->copies());
    }

    public function testTheOrganiserIsAskedForTheNewDateAndTheName(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-duplicate-form][action^="' . self::PATH . '"] input[type="date"][name="dateFrom"][required]');
        // A one-time event: no date proposed, the same name
        self::assertInputValueSame('dateFrom', '');
        self::assertInputValueSame('name', 'Results Cup');
        self::assertSelectorTextContains('[data-source-date]', 'Copy of');
        self::assertSelectorExists('[data-what-moves]');
    }

    public function testTheNextEditionOfASeriesIsProposedWithTheNextNumberAndTheSameGap(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $connection = self::getContainer()->get(Connection::class);
        $session2 = new DateTimeImmutable(self::text($connection->fetchOne('SELECT date_from FROM competition WHERE id = :id', ['id' => EventsPageFixture::EDITION_HARBOR_2])));
        $session1 = new DateTimeImmutable(self::text($connection->fetchOne('SELECT date_from FROM competition WHERE id = :id', ['id' => EventsPageFixture::EDITION_HARBOR_1])));
        $gap = (int) $session1->diff($session2)->format('%a');

        $browser->request('GET', '/en/duplicate-event/' . EventsPageFixture::EDITION_HARBOR_2);

        self::assertResponseIsSuccessful();
        // "Session 2" → "Session 3", one gap after it
        self::assertInputValueSame('name', 'Session 3');
        self::assertInputValueSame('dateFrom', $session2->modify(sprintf('+%d days', $gap))->format('Y-m-d'));
    }

    public function testTheCopyIsMadeOnTheNewDateWithItsRoundsAndRegistrationMoved(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE competition SET registration_opens_at = '2030-01-01 08:00:00', registration_closes_at = '2030-01-20 20:00:00' WHERE id = :id",
            ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        );
        $sourceFrom = new DateTimeImmutable(self::text($connection->fetchOne('SELECT date_from FROM competition WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP])));
        $newDay = $sourceFrom->modify('+21 days');
        $before = $this->copies();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token', 'dateFrom' => $newDay->format('Y-m-d'), 'name' => 'Results Cup II'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame($before, $this->copies());

        /** @var array{id: string, date_from: string, date_to: string, registration_opens_at: string, registration_closes_at: string, is_draft: bool} $copy */
        $copy = $connection->fetchAssociative("SELECT id, date_from, date_to, registration_opens_at, registration_closes_at, is_draft FROM competition WHERE name = 'Results Cup II'");
        self::assertResponseRedirects('/en/edit-event/' . $copy['id']);
        self::assertTrue($copy['is_draft']);
        self::assertSame($newDay->format('Y-m-d'), substr($copy['date_from'], 0, 10));
        self::assertSame($newDay->format('Y-m-d'), substr($copy['date_to'], 0, 10));
        self::assertSame('2030-01-22 08:00:00', $copy['registration_opens_at']);
        self::assertSame('2030-02-10 20:00:00', $copy['registration_closes_at']);

        $sourceStarts = $connection->fetchFirstColumn('SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        $copyStarts = $connection->fetchFirstColumn('SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => $copy['id']]);
        self::assertCount(5, $copyStarts);

        // Three weeks later at the same time of day in the rounds' zone (Brno)
        $prague = new DateTimeZone('Europe/Prague');

        foreach ($sourceStarts as $index => $start) {
            self::assertSame(
                (new DateTimeImmutable(self::text($start), new DateTimeZone('UTC')))->setTimezone($prague)->modify('+21 days')->format('Y-m-d H:i'),
                (new DateTimeImmutable(self::text($copyStarts[$index]), new DateTimeZone('UTC')))->setTimezone($prague)->format('Y-m-d H:i'),
            );
        }

        $formatted = (new IntlDateFormatter('en', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC'))->format($newDay);
        self::assertIsString($formatted);
        self::assertSame([$this->trans('event_duplicate.flash_dated', ['%date%' => $formatted])], $this->flashes($browser));

        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-duplicate-link][href^="/en/duplicate-event/' . $copy['id'] . '"]');
    }

    public function testWithoutADateTheFormComesBackWith422(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $before = $this->copies();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token', 'dateFrom' => '', 'name' => 'Results Cup'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form[data-duplicate-form]');
        self::assertSame($before, $this->copies());
    }

    public function testAPlayerWhoCannotEditTheEventCannotOpenTheForm(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::PATH);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheManageMenuOffersIt(): void
    {
        $browser = self::createClient();
        // On the team of the organization the series is under
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/event-actions/competition/' . OrganizationFixture::EDITION_LANTERN_1);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-duplicate][href^="/en/duplicate-event/' . OrganizationFixture::EDITION_LANTERN_1 . '"]');
    }

    private function copies(): int
    {
        return self::number(self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Results Cup'"));
    }

    /**
     * @return list<mixed>
     */
    private function flashes(KernelBrowser $browser): array
    {
        /** @var Session $session */
        $session = $browser->getRequest()->getSession();

        return array_values($session->getFlashBag()->peek('success'));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, $parameters);
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function number(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
