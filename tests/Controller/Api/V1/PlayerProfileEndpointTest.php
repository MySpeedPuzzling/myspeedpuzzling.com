<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\OpenApiAssertions;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\ProfileInsightsSeeding;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/players/{playerId} - the profile page as the API: public profile
 * plus the MSP Rating, skill tier and badge blocks, gated exactly as the page
 * is (templates/player_profile.html.twig): a private profile is masked for
 * everyone but its owner, rating and skill vanish when the player opted out of
 * rankings, and skill is members-only for the *viewer* (the token owner).
 *
 * Fixtures (.claude/fixtures.md): PLAYER_WITH_STRIPE (Sarah Williams, London)
 * and PLAYER_ADMIN are members, PLAYER_REGULAR is not, PLAYER_PRIVATE (Jane
 * Smith) is private. No player_elo / player_skill / badge rows exist - the
 * ProfileInsightsSeeding trait seeds them per test.
 *
 * @phpstan-type Rating array{pieces_count: int, points: int, rank: int, total_players: int}
 * @phpstan-type Skill array{pieces_count: int, tier: string, percentile: float, confidence: string, qualifying_puzzles_count: int}
 * @phpstan-type Profile array{
 *     id: string,
 *     name: null|string,
 *     code: string,
 *     avatar: null|string,
 *     country: null|string,
 *     city: null|string,
 *     bio: null|string,
 *     facebook: null|string,
 *     instagram: null|string,
 *     is_private: bool,
 *     has_active_membership: bool,
 *     rating: null|list<Rating>,
 *     skill: null|list<Skill>,
 *     badges: list<string>,
 * }
 */
final class PlayerProfileEndpointTest extends WebTestCase
{
    use OpenApiAssertions;
    use ProfileInsightsSeeding;
    use QueryCountAssertions;

    private const string OPENAPI_PATH = '/api/v1/players/{playerId}';

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $browser = self::createClient();

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTokenWithoutProfileReadScopeIsForbidden(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['results:read']);

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Personal access tokens are for the owner's own data only (/me/*); other
     * players' profiles are OAuth2 territory, as for every /players/{id} route.
     */
    public function testPersonalAccessTokenIsForbidden(): void
    {
        $browser = self::createClient();
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUnknownPlayerIsNotFound(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);

        $browser->request('GET', $this->endpoint('00000000-0000-0000-0000-000000000000'));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testMalformedPlayerIdIsNotFound(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);

        $browser->request('GET', $this->endpoint('not-a-uuid'));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * A public profile through an authorization-code token: the GET /me shape
     * without email, membership_ends_at and the opt-out flags, plus the three
     * blocks. The viewer (PLAYER_REGULAR) is not a member, so skill is null
     * even though the target has a tier - exactly the locked "Player insights"
     * button a non-member sees on the website.
     */
    public function testPublicProfileShapeForNonMemberViewer(): void
    {
        $browser = self::createClient();
        $this->seedRating($browser, PlayerFixture::PLAYER_REGULAR, 500, 1.601);
        $this->seedRating($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, 1.5324);
        $this->seedSkill($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, SkillTier::Proficient, 62.5, MetricConfidence::Medium, 14);
        $this->seedBadge($browser, PlayerFixture::PLAYER_WITH_STRIPE, BadgeType::Supporter);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['id', 'name', 'code', 'avatar', 'country', 'city', 'bio', 'facebook', 'instagram', 'is_private', 'has_active_membership', 'rating', 'skill', 'badges'],
            array_keys($this->decodeRaw($browser)),
            'the GET /me shape without email, membership_ends_at and the opt-out flags, the three blocks appended',
        );
        $response = $this->decode($browser);

        $this->assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $response['id']);
        $this->assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $response['name']);
        $this->assertSame('player4', $response['code']);
        $this->assertSame('gb', $response['country']);
        $this->assertSame('London', $response['city']);
        $this->assertFalse($response['is_private']);
        $this->assertTrue($response['has_active_membership']);
        $this->assertSame([['pieces_count' => 500, 'points' => 1532, 'rank' => 2, 'total_players' => 2]], $response['rating']);
        $this->assertNull($response['skill']);
        $this->assertSame(['supporter'], $response['badges']);
    }

    /**
     * A member viewer sees the target's skill tiers - puzzle-intelligence data
     * about the target that the profile page shows to any member visitor.
     */
    public function testMemberViewerSeesSkill(): void
    {
        $browser = self::createClient();
        $this->seedRating($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, 1.5324);
        $this->seedSkill($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, SkillTier::Proficient, 62.5, MetricConfidence::Medium, 14);
        $this->seedSkill($browser, PlayerFixture::PLAYER_WITH_STRIPE, 1000, SkillTier::Legend, 99.2, MetricConfidence::High, 30);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['profile:read']);

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame([['pieces_count' => 500, 'points' => 1532, 'rank' => 1, 'total_players' => 1]], $response['rating']);
        $this->assertSame([
            ['pieces_count' => 500, 'tier' => 'proficient', 'percentile' => 62.5, 'confidence' => 'medium', 'qualifying_puzzles_count' => 14],
            ['pieces_count' => 1000, 'tier' => 'legend', 'percentile' => 99.2, 'confidence' => 'high', 'qualifying_puzzles_count' => 30],
        ], $response['skill']);
    }

    /**
     * A member viewer looking at a public player who has no rows yet: the
     * lists are present and empty - null means "not available to this token".
     */
    public function testMemberViewerGetsEmptyListsWhenTargetHasNoRows(): void
    {
        $browser = self::createClient();
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['profile:read']);

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_REGULAR));

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $response['name']);
        $this->assertFalse($response['has_active_membership']);
        $this->assertSame([], $response['rating']);
        $this->assertSame([], $response['skill']);
        $this->assertSame([], $response['badges']);
    }

    /**
     * A client_credentials token has no player behind it: public profile data
     * and the public rating, never a membership - so skill is null.
     */
    public function testClientCredentialsTokenSeesPublicDataWithoutSkill(): void
    {
        $browser = self::createClient();
        $this->seedRating($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, 1.5324);
        $this->seedSkill($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, SkillTier::Proficient, 62.5, MetricConfidence::Medium, 14);
        $this->seedBadge($browser, PlayerFixture::PLAYER_WITH_STRIPE, BadgeType::Supporter);
        $this->authenticateClientCredentials($browser);

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $response['name']);
        $this->assertTrue($response['has_active_membership']);
        $this->assertSame([['pieces_count' => 500, 'points' => 1532, 'rank' => 1, 'total_players' => 1]], $response['rating']);
        $this->assertNull($response['skill']);
        $this->assertSame(['supporter'], $response['badges']);
    }

    /**
     * Opted out of rankings: the profile page shows neither block to anyone,
     * the API says null for both (a member viewer included); badges stay.
     */
    public function testRankingOptOutHidesRatingAndSkill(): void
    {
        $browser = self::createClient();
        $this->seedRating($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, 1.5324);
        $this->seedSkill($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, SkillTier::Proficient, 62.5, MetricConfidence::Medium, 14);
        $this->seedBadge($browser, PlayerFixture::PLAYER_WITH_STRIPE, BadgeType::Supporter);
        $this->optOutOfRankings($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['profile:read']);

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->assertResponseIsSuccessful();
        $raw = $this->decodeRaw($browser);
        $this->assertArrayHasKey('rating', $raw);
        $this->assertArrayHasKey('skill', $raw);
        $response = $this->decode($browser);

        $this->assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $response['name']);
        $this->assertNull($response['rating']);
        $this->assertNull($response['skill']);
        $this->assertSame(['supporter'], $response['badges']);
    }

    /**
     * A private profile seen by anyone else - a member, a machine token - is the
     * website's "Secret puzzler #CODE": id, code and the membership badge stay,
     * every other field is null, the insight blocks are null / empty, and no
     * insight query runs.
     */
    public function testPrivateProfileIsMaskedForAStranger(): void
    {
        $browser = self::createClient();
        $this->seedRating($browser, PlayerFixture::PLAYER_PRIVATE, 500, 1.9);
        $this->seedSkill($browser, PlayerFixture::PLAYER_PRIVATE, 500, SkillTier::Master, 96.0, MetricConfidence::High, 20);
        $this->seedBadge($browser, PlayerFixture::PLAYER_PRIVATE, BadgeType::Supporter);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['profile:read']);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_PRIVATE));

        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 5, 'masked private profile, authorization-code token (auth 3 + target + owner, no insight query)');
        $response = $this->decode($browser);

        $this->assertSame([
            'id' => PlayerFixture::PLAYER_PRIVATE,
            'name' => null,
            'code' => 'player2',
            'avatar' => null,
            'country' => null,
            'city' => null,
            'bio' => null,
            'facebook' => null,
            'instagram' => null,
            'is_private' => true,
            'has_active_membership' => false,
            'rating' => null,
            'skill' => null,
            'badges' => [],
        ], $response);

        $this->authenticateClientCredentials($browser);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_PRIVATE));

        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 2, 'masked private profile, client_credentials token (access token + target)');
        $response = $this->decode($browser);
        $this->assertNull($response['name']);
        $this->assertTrue($response['is_private']);
        $this->assertSame('player2', $response['code']);
        $this->assertNull($response['rating']);
        $this->assertNull($response['skill']);
        $this->assertSame([], $response['badges']);
    }

    /**
     * The player behind the token asking for their own (private) profile gets
     * the full response - the same as GET /me minus the private fields.
     */
    public function testPrivateProfileIsFullForItsOwner(): void
    {
        $browser = self::createClient();
        $this->seedRating($browser, PlayerFixture::PLAYER_REGULAR, 500, 1.601);
        $this->seedRating($browser, PlayerFixture::PLAYER_PRIVATE, 500, 1.9);
        $this->seedBadge($browser, PlayerFixture::PLAYER_PRIVATE, BadgeType::Supporter);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_PRIVATE, ['profile:read']);

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_PRIVATE));

        $this->assertResponseIsSuccessful();
        $response = $this->decode($browser);

        $this->assertSame(PlayerFixture::PLAYER_PRIVATE, $response['id']);
        $this->assertSame('Jane Smith', $response['name']);
        $this->assertSame('us', $response['country']);
        $this->assertSame('New York', $response['city']);
        $this->assertTrue($response['is_private']);
        // the private player counts in their own pool: #1 of 2 (PLAYER_REGULAR is the other)
        $this->assertSame([['pieces_count' => 500, 'points' => 1900, 'rank' => 1, 'total_players' => 2]], $response['rating']);
        $this->assertNull($response['skill'], 'PLAYER_PRIVATE is not a member');
        $this->assertSame(['supporter'], $response['badges']);
    }

    /**
     * points is exactly the number the profile page prints - round(elo x 1000)
     * (PlayerRatingEntry::displayRating()), never the raw elo.
     */
    public function testRatingPointsAreTheDisplayedRating(): void
    {
        $browser = self::createClient();
        $this->seedRating($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, 1.2346);
        $this->seedRating($browser, PlayerFixture::PLAYER_WITH_STRIPE, 1000, 0.9994);
        $this->seedRating($browser, PlayerFixture::PLAYER_WITH_STRIPE, 1500, 1.0004);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);

        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));

        $this->assertResponseIsSuccessful();
        $rating = $this->decode($browser)['rating'];
        $this->assertNotNull($rating);

        $this->assertSame([500, 1000, 1500], array_column($rating, 'pieces_count'), 'ascending by piece count');
        $this->assertSame([1235, 999, 1000], array_column($rating, 'points'), 'round(elo x 1000), not the raw elo and not truncated');
    }

    /**
     * Fixed cost per request (plan §8): authentication + target profile + owner
     * profile + one query per block. Measured: OAuth2 authentication is 3
     * queries (access token, player, consent usage), client_credentials 1.
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();
        $this->seedRating($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, 1.5324);
        $this->seedSkill($browser, PlayerFixture::PLAYER_WITH_STRIPE, 500, SkillTier::Proficient, 62.5, MetricConfidence::Medium, 14);
        $this->seedBadge($browser, PlayerFixture::PLAYER_WITH_STRIPE, BadgeType::Supporter);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['profile:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 8, 'member viewer: auth 3 + target + owner + rating + skill + badges');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 7, 'non-member viewer: no skill query');

        $this->authenticateClientCredentials($browser);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 4, 'client_credentials: access token + target + rating + badges, no owner profile');

        $this->optOutOfRankings($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_ADMIN, ['profile:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->endpoint(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 6, 'target opted out of rankings: badges only');
    }

    public function testOpenApiDocumentsTheEndpoint(): void
    {
        $browser = self::createClient();

        $document = $this->openApiDocument($browser);

        /** @var array<string, array{get: array{tags: list<string>, summary: string, parameters: list<array{name: string, in: string}>, responses: array<int|string, mixed>}}> $paths */
        $paths = $document['paths'];
        $this->assertArrayHasKey(self::OPENAPI_PATH, $paths);
        $operation = $paths[self::OPENAPI_PATH]['get'];
        $this->assertSame(['Players'], $operation['tags']);
        $this->assertNotSame('', $operation['summary']);
        $this->assertSame([['playerId', 'path']], array_map(static fn (array $p): array => [$p['name'], $p['in']], $operation['parameters']));
        $this->assertArrayHasKey('404', $operation['responses']);

        /** @var array{schemas: array<string, array{properties: array<string, mixed>}>} $components */
        $components = $document['components'];
        $schemas = $components['schemas'];
        $this->assertSame(
            ['id', 'name', 'code', 'avatar', 'country', 'city', 'bio', 'facebook', 'instagram', 'is_private', 'has_active_membership', 'rating', 'skill', 'badges'],
            array_keys($schemas['PlayerProfile']['properties']),
        );
        $this->assertSame(['pieces_count', 'points', 'rank', 'total_players'], array_keys($schemas['PlayerRatingResponse']['properties']));
        $this->assertSame(['pieces_count', 'tier', 'percentile', 'confidence', 'qualifying_puzzles_count'], array_keys($schemas['PlayerSkillResponse']['properties']));
        // the same three blocks on GET /me, appended after the PR 0 flags
        $this->assertSame(['rating', 'skill', 'badges'], array_slice(array_keys($schemas['CurrentUser']['properties']), -3));
    }

    private function endpoint(string $playerId): string
    {
        return '/api/v1/players/' . $playerId;
    }

    /**
     * @param array<non-empty-string> $scopes
     */
    private function authenticateOAuth2(KernelBrowser $browser, string $playerId, array $scopes): void
    {
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            $playerId,
            $scopes,
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    private function authenticateClientCredentials(KernelBrowser $browser): void
    {
        // sub = aud = client id is exactly what the client_credentials grant issues
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            ['profile:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    /**
     * @return Profile
     */
    private function decode(KernelBrowser $browser): array
    {
        /** @var Profile $decoded */
        $decoded = $this->decodeRaw($browser);

        return $decoded;
    }

    /**
     * Untyped, for assertions about the wire shape itself (key order, presence).
     *
     * @return array<string, mixed>
     */
    private function decodeRaw(KernelBrowser $browser): array
    {
        $content = $browser->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
