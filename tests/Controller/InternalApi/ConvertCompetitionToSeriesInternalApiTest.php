<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Monolog\Handler\TestHandler;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md "Converting an event into a series" (docs/features/events-page/
 * high-frequency-series.md "The conversion tool").
 */
final class ConvertCompetitionToSeriesInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testTheEventBecomesTheSeriesAndItsOldAddressLeadsThere(): void
    {
        $browser = self::createClient();
        $auditLog = new TestHandler();
        self::getContainer()->get('monolog.logger.internal_api_audit')->pushHandler($auditLog);

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . EventsPageFixture::COMPETITION_MEADOW_TBA . '/convert-to-series', [
            'keepAsEdition' => false,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($answer['seriesId']);
        self::assertSame(EventsPageFixture::COMPETITION_MEADOW_TBA_NAME, $answer['name']);
        self::assertSame('meadow-puzzle-championship', $answer['slug']);
        self::assertSame([], $answer['editions']);

        $records = $auditLog->getRecords();
        self::assertCount(1, $records);
        self::assertSame($answer['seriesId'], $records[0]->context['createdId']);
        self::assertSame(201, $records[0]->context['status']);

        $browser->request('GET', '/en/events/meadow-puzzle-championship');
        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/en/series/meadow-puzzle-championship');
    }

    public function testKeepingTheEventAsTheFirstEditionIsTheDefault(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . EventsPageFixture::COMPETITION_MEADOW_TBA . '/convert-to-series');

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($answer['editions']);
        self::assertCount(1, $answer['editions']);
        self::assertIsArray($answer['editions'][0]);
        self::assertSame(EventsPageFixture::COMPETITION_MEADOW_TBA, $answer['editions'][0]['competitionId']);
    }

    public function testRefusalsAndMistakes(): void
    {
        $browser = self::createClient();

        // Rounds would be lost - 409, JSON, nothing changes
        $refusal = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '/convert-to-series', [
            'keepAsEdition' => false,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertIsString($refusal['error']);
        self::assertStringContainsString('rounds', $refusal['error']);

        // Participants: refused unless dropped
        $participants = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN . '/convert-to-series', [
            'keepAsEdition' => false,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertIsString($participants['error']);
        self::assertStringContainsString('dropParticipants', $participants['error']);

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN . '/convert-to-series', [
            'keepAsEdition' => false,
            'dropParticipants' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        // An unknown field, a wrong type
        $mistake = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . EventsPageFixture::COMPETITION_MEADOW_TBA . '/convert-to-series', [
            'keepAsEditon' => false,
            'dropParticipants' => 'yes',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($mistake['errors']);
        self::assertArrayHasKey('keepAsEditon', $mistake['errors']);
        self::assertArrayHasKey('dropParticipants', $mistake['errors']);

        // An unknown competition
        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . Uuid::uuid7()->toString() . '/convert-to-series');
        self::assertResponseStatusCodeSame(404);

        // Without the token
        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . EventsPageFixture::COMPETITION_MEADOW_TBA . '/convert-to-series', token: null);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnEditionIsAlreadyInASeries(): void
    {
        $browser = self::createClient();
        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . EventsPageFixture::COMPETITION_MEADOW_TBA . '/convert-to-series');
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($answer['seriesId']);

        // The event is an edition now
        $refusal = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . EventsPageFixture::COMPETITION_MEADOW_TBA . '/convert-to-series');

        self::assertResponseStatusCodeSame(409);
        self::assertIsString($refusal['error']);
        self::assertStringContainsString('edition', $refusal['error']);
    }
}
