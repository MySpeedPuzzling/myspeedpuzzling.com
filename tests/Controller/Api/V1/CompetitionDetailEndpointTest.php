<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CompetitionDetailEndpointTest extends WebTestCase
{
    public function testWithValidTokenReturnsCompetitionWithRounds(): void
    {
        $response = $this->requestDetail(CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $response['id']);
        $this->assertArrayHasKey('name', $response);
        $this->assertArrayHasKey('rounds', $response);

        /** @var array<int, array<string, mixed>> $rounds */
        $rounds = $response['rounds'];

        // Each round exposes its identity and puzzles, never participants.
        foreach ($rounds as $round) {
            $this->assertArrayHasKey('id', $round);
            $this->assertArrayHasKey('name', $round);
            $this->assertArrayHasKey('puzzles', $round);
        }
    }

    public function testResponseDoesNotExposeParticipants(): void
    {
        $response = $this->requestDetail(CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertArrayNotHasKey('participants', $response);

        /** @var array<int, array<string, mixed>> $rounds */
        $rounds = $response['rounds'];

        foreach ($rounds as $round) {
            $this->assertArrayNotHasKey('participants', $round);
        }
    }

    public function testWithoutTokenReturnsUnauthorized(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/api/v1/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testNonExistentCompetitionReturnsNotFound(): void
    {
        $browser = $this->authenticatedClient();
        $browser->request('GET', '/api/v1/competitions/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testInvalidUuidReturnsNotFound(): void
    {
        $browser = $this->authenticatedClient();
        $browser->request('GET', '/api/v1/competitions/not-a-uuid');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUnapprovedCompetitionReturnsNotFound(): void
    {
        // Privacy: unapproved competitions (and their potentially secret puzzles) must not leak.
        $browser = $this->authenticatedClient();
        $browser->request('GET', '/api/v1/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testEntirelyHiddenPuzzleIsOmittedBeforeReveal(): void
    {
        $puzzles = $this->puzzlesForRound(CompetitionApiFixture::ROUND_FUTURE);

        $puzzleIds = array_column($puzzles, 'id');

        // hideMode = Entirely + before reveal → the puzzle row must be absent completely.
        $this->assertNotContains(CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY, $puzzleIds);

        // The fixture image must not leak through any other puzzle either.
        $images = array_column($puzzles, 'image');
        $this->assertNotContains(CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY, $images);
    }

    public function testImageOnlyHiddenPuzzleHasNullImageBeforeReveal(): void
    {
        $puzzles = $this->puzzlesForRound(CompetitionApiFixture::ROUND_FUTURE);

        $hiddenImagePuzzle = $this->findPuzzle($puzzles, CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE);

        // hideMode = ImageOnly + before reveal → puzzle is present, but image is stripped.
        $this->assertNotNull($hiddenImagePuzzle, 'Image-only hidden puzzle must still be returned.');
        $this->assertNull($hiddenImagePuzzle['image']);

        // Non-sensitive metadata is still exposed.
        $this->assertSame('API Hidden Image', $hiddenImagePuzzle['name']);
        $this->assertSame(500, $hiddenImagePuzzle['pieces_count']);
        $this->assertSame('Ravensburger', $hiddenImagePuzzle['manufacturer_name']);
    }

    public function testNonHiddenPuzzleIsFullyVisible(): void
    {
        $puzzles = $this->puzzlesForRound(CompetitionApiFixture::ROUND_FUTURE);

        $visiblePuzzle = $this->findPuzzle($puzzles, CompetitionApiFixture::PUZZLE_VISIBLE);

        $this->assertNotNull($visiblePuzzle);
        $this->assertSame(CompetitionApiFixture::IMAGE_VISIBLE, $visiblePuzzle['image']);
    }

    public function testRevealedPuzzleIsFullyVisibleAfterReveal(): void
    {
        $puzzles = $this->puzzlesForRound(CompetitionApiFixture::ROUND_PAST);

        $revealedPuzzle = $this->findPuzzle($puzzles, CompetitionApiFixture::PUZZLE_PAST);

        // The round started in the past, so a hide-until-round-starts puzzle is now revealed,
        // image included.
        $this->assertNotNull($revealedPuzzle);
        $this->assertSame(CompetitionApiFixture::IMAGE_PAST, $revealedPuzzle['image']);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestDetail(string $competitionId): array
    {
        $browser = $this->authenticatedClient();
        $browser->request('GET', '/api/v1/competitions/' . $competitionId);

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array<string, mixed> $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        return $response;
    }

    /**
     * @return array<array{id: string, name: string, pieces_count: int, image: null|string, manufacturer_name: null|string}>
     */
    private function puzzlesForRound(string $roundId): array
    {
        $response = $this->requestDetail(CompetitionApiFixture::COMPETITION_API);

        /** @var array<array{id: string, name: string, starts_at: null|string, minutes_limit: int, category: string, puzzles: array<array{id: string, name: string, pieces_count: int, image: null|string, manufacturer_name: null|string}>}> $rounds */
        $rounds = $response['rounds'];

        foreach ($rounds as $round) {
            if ($round['id'] === $roundId) {
                return $round['puzzles'];
            }
        }

        self::fail(sprintf('Round "%s" not found in competition detail response.', $roundId));
    }

    /**
     * @param array<array{id: string, name: string, pieces_count: int, image: null|string, manufacturer_name: null|string}> $puzzles
     * @return null|array{id: string, name: string, pieces_count: int, image: null|string, manufacturer_name: null|string}
     */
    private function findPuzzle(array $puzzles, string $puzzleId): null|array
    {
        foreach ($puzzles as $puzzle) {
            if ($puzzle['id'] === $puzzleId) {
                return $puzzle;
            }
        }

        return null;
    }

    private function authenticatedClient(): KernelBrowser
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);

        return $browser;
    }
}
