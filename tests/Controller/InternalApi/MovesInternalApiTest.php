<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md "Organizations, series and drafts": moving an edition, moving a round and turning a
 * series into an organization through the API - answers and refusals (the handlers' own tests cover the rules).
 */
final class MovesInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testMovesAnEdition(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/competitions/' . OrganizationFixture::EDITION_LANTERN_2 . '/move';

        $answer = self::callInternalApi($browser, 'POST', $uri, ['seriesId' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL]);
        self::assertResponseIsSuccessful();
        self::assertSame(OrganizationFixture::EDITION_LANTERN_2, $answer['competitionId']);
        self::assertIsArray($answer['series']);
        self::assertSame(OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, $answer['series']['seriesId']);
        self::assertSame(OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG, $answer['series']['slug']);
        self::assertSame('lantern-night-two', $answer['slug']);
        self::assertTrue($answer['isOnline']);

        $browser->request('GET', '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-two');
        self::assertResponseRedirects('/en/series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG . '/lantern-night-two', 301);
    }

    public function testATakenSlugIsAConflictUntilANewOneIsSent(): void
    {
        $browser = self::createClient();
        // "virtual-contest-1" is an edition of the target series already
        self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . OrganizationFixture::EDITION_LANTERN_1, ['slug' => 'virtual-contest-1']);
        self::assertResponseIsSuccessful();

        $uri = '/internal-api/competitions/' . OrganizationFixture::EDITION_LANTERN_1 . '/move';
        $refused = self::callInternalApi($browser, 'POST', $uri, ['seriesId' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('virtual-contest-1', self::string($refused['error']));

        $answer = self::callInternalApi($browser, 'POST', $uri, ['seriesId' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, 'slug' => 'lantern-night-online']);
        self::assertResponseIsSuccessful();
        self::assertSame('lantern-night-online', $answer['slug']);
    }

    public function testMovingAnEditionIsRefusedWhenItCannotBe(): void
    {
        $browser = self::createClient();

        // A one-time event
        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/move', ['seriesId' => OrganizationFixture::SERIES_LANTERN_NIGHTS]);
        self::assertResponseStatusCodeSame(409);

        // The series it is in
        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . OrganizationFixture::EDITION_LANTERN_1 . '/move', ['seriesId' => OrganizationFixture::SERIES_LANTERN_NIGHTS]);
        self::assertResponseStatusCodeSame(409);

        $invalid = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . OrganizationFixture::EDITION_LANTERN_1 . '/move', ['slug' => 'Bad Slug']);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($invalid['errors']);
        self::assertArrayHasKey('seriesId', $invalid['errors']);
        self::assertArrayHasKey('slug', $invalid['errors']);

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . OrganizationFixture::EDITION_LANTERN_1 . '/move', ['seriesId' => '018d0042-0000-0000-0000-00000000ffff']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testMovesARoundAndRefusesARoundWithEntries(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/rounds/' . CompetitionSeriesFixture::ROUND_EJJ_69 . '/move', [
            'competitionId' => OrganizationFixture::EDITION_VIRTUAL_NEXT,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(CompetitionSeriesFixture::ROUND_EJJ_69, $answer['roundId']);
        self::assertSame(OrganizationFixture::EDITION_VIRTUAL_NEXT, $answer['competitionId']);
        self::assertSame(1, self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . OrganizationFixture::EDITION_VIRTUAL_NEXT)['roundsCount']);

        $refused = self::callInternalApi($browser, 'POST', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION . '/move', [
            'competitionId' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Participants', self::string($refused['error']));

        self::callInternalApi($browser, 'POST', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL . '/move', [
            'competitionId' => '018d0004-0000-0000-0000-00000000ffff',
        ]);
        self::assertResponseStatusCodeSame(404);

        self::callInternalApi($browser, 'POST', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL . '/move', []);
        self::assertResponseStatusCodeSame(400);
    }

    public function testTurnsASeriesIntoAnOrganization(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/series/' . CompetitionSeriesFixture::SERIES_OFFLINE . '/create-organization', [
            'name' => 'Prague Puzzle Meetups',
            'kind' => 'community',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('prague-puzzle-meetups', $answer['slug']);
        self::assertSame('approved', $answer['status']);
        self::assertSame('community', $answer['kind']);
        self::assertSame('cz', $answer['countryCode']);
        self::assertSame('Prague', $answer['region']);
        self::assertSame([CompetitionSeriesFixture::SERIES_OFFLINE], array_column(self::list($answer['series']), 'seriesId'));
        // Nobody is e-mailed: an admin did it
        self::assertQueuedEmailCount(0);

        self::callInternalApi($browser, 'POST', '/internal-api/series/' . CompetitionSeriesFixture::SERIES_OFFLINE . '/create-organization', ['name' => 'Twice']);
        self::assertResponseStatusCodeSame(409);

        // A location too long for a region is left out, never cut
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET location = :location WHERE id = :id',
            ['location' => str_repeat('Long Valley ', 15), 'id' => CompetitionSeriesFixture::SERIES_UNAPPROVED],
        );
        $longPlace = self::callInternalApi($browser, 'POST', '/internal-api/series/' . CompetitionSeriesFixture::SERIES_UNAPPROVED . '/create-organization', [
            'name' => 'Long Valley Puzzlers',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($longPlace['region']);

        // Sent as null = none (left out = the series' own, above)
        $without = self::callInternalApi($browser, 'POST', '/internal-api/series/' . CompetitionSeriesFixture::SERIES_EJJ . '/create-organization', [
            'name' => 'Euro Jigsaw Jam Association',
            'countryCode' => null,
            'region' => null,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($without['countryCode']);
        self::assertNull($without['region']);

        $invalid = self::callInternalApi($browser, 'POST', '/internal-api/series/' . CompetitionSeriesFixture::SERIES_EJJ . '/create-organization', [
            'kind' => 'guild',
            'countryCode' => 'xx',
            'newSeriesSlug' => 'Bad Slug',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($invalid['errors']);
        foreach (['name', 'kind', 'countryCode', 'newSeriesSlug'] as $field) {
            self::assertArrayHasKey($field, $invalid['errors'], $field);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
