<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DeleteCompetitionInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testDeletesACompetitionNobodyHasAResultIn(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'DELETE', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertResponseStatusCodeSame(204);

        self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRefusesACompetitionWithResults(): void
    {
        $browser = self::createClient();

        $error = self::callInternalApi($browser, 'DELETE', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('result(s) - it is not deleted', (string) json_encode($error));

        self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertResponseIsSuccessful();
    }

    public function testUnknownCompetitionIsNotFound(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'DELETE', '/internal-api/competitions/018d0004-0000-0000-0000-0000000000ff');
        self::assertResponseStatusCodeSame(404);
    }
}
