<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CompetitionListEndpointTest extends WebTestCase
{
    public function testWithValidTokenReturnsApprovedCompetitions(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/competitions');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{count: int, competitions: list<array{id: string}>} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        // The advertised count must match the number of returned competitions.
        $this->assertCount($response['count'], $response['competitions']);

        $ids = array_column($response['competitions'], 'id');

        // Approved, standalone competitions are listed.
        $this->assertContains(CompetitionFixture::COMPETITION_WJPC_2024, $ids);
        $this->assertContains(CompetitionApiFixture::COMPETITION_API, $ids);

        // Unapproved competitions must never appear.
        $this->assertNotContains(CompetitionFixture::COMPETITION_UNAPPROVED, $ids);
    }

    public function testOnlineFilterReturnsOnlyOnlineCompetitions(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/competitions?online=true');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{competitions: list<array{is_online: bool}>} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        foreach ($response['competitions'] as $competition) {
            $this->assertTrue($competition['is_online']);
        }
    }

    public function testWithoutTokenReturnsUnauthorized(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/api/v1/competitions');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
