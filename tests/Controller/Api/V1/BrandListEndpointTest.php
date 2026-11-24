<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\OpenApiAssertions;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * GET /api/v1/brands (docs/features/api/README.md "Brands"): every brand with a puzzle the catalog search returns - the
 * id GET /api/v1/puzzles filters by, and puzzles_count equal to that search's total.
 *
 * @phpstan-type BrandItem array{id: string, name: string, slug: null|string, logo_url: null|string, puzzles_count: int}
 */
final class BrandListEndpointTest extends WebTestCase
{
    use OpenApiAssertions;
    use QueryCountAssertions;

    private const string ENDPOINT = '/api/v1/brands';

    public function testListsBrandsWithPuzzlesByName(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser);

        $brands = $this->byId($this->fetch($browser));

        self::assertSame([
            'id' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'name' => 'Ravensburger',
            'slug' => 'ravensburger',
            'logo_url' => null,
            'puzzles_count' => $brands[ManufacturerFixture::MANUFACTURER_RAVENSBURGER]['puzzles_count'],
        ], $brands[ManufacturerFixture::MANUFACTURER_RAVENSBURGER]);
        self::assertGreaterThan(0, $brands[ManufacturerFixture::MANUFACTURER_RAVENSBURGER]['puzzles_count']);
        self::assertArrayHasKey(ManufacturerFixture::MANUFACTURER_TREFL, $brands);
        // An unapproved brand's puzzles are searchable, so the brand is listed too
        self::assertArrayHasKey(ManufacturerFixture::MANUFACTURER_UNAPPROVED, $brands);

        // By name, letter case ignored
        $names = array_map(mb_strtolower(...), array_column($this->fetch($browser), 'name'));
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);
    }

    /**
     * The id filters GET /api/v1/puzzles, and puzzles_count is exactly its total
     */
    public function testPuzzlesCountIsTheSearchTotal(): void
    {
        $browser = self::createClient();
        $this->resetSearchLimiter($browser);
        $this->hideEveryPuzzleOf($browser, ManufacturerFixture::MANUFACTURER_TREFL, keepOne: true);
        $this->authenticatePat($browser);

        foreach ($this->fetch($browser) as $brand) {
            $browser->request('GET', '/api/v1/puzzles', ['brand' => $brand['id'], 'limit' => 1]);
            self::assertResponseIsSuccessful();

            /** @var array{total: int} $search */
            $search = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($search['total'], $brand['puzzles_count'], $brand['name']);
        }
    }

    /**
     * A brand without puzzles is no filter worth offering; a brand whose every puzzle is still hidden (a secret
     * competition puzzle) must not leak its name before the reveal
     */
    public function testLeavesOutBrandsWithoutAVisiblePuzzle(): void
    {
        $browser = self::createClient();
        $entityManager = $this->entityManager($browser);
        $empty = new Manufacturer(Uuid::uuid7(), 'Empty Shelf Puzzles', approved: true);
        $entityManager->persist($empty);
        $entityManager->flush();
        $this->hideEveryPuzzleOf($browser, ManufacturerFixture::MANUFACTURER_UNAPPROVED, keepOne: false);
        $this->authenticatePat($browser);

        $ids = array_column($this->fetch($browser), 'id');

        self::assertNotContains($empty->id->toString(), $ids);
        self::assertNotContains(ManufacturerFixture::MANUFACTURER_UNAPPROVED, $ids);
        self::assertContains(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $ids);
    }

    /**
     * "query" finds brands by a part of the name, accents and case ignored, the closest first
     */
    public function testQuerySearchesTheNames(): void
    {
        $browser = self::createClient();
        $entityManager = $this->entityManager($browser);

        foreach (['Grand Ravens Studio', 'Bärenravens', 'Ravensburger Junior', '100%_Ravens'] as $name) {
            $brand = new Manufacturer(Uuid::uuid7(), $name, approved: true);
            $entityManager->persist($brand);
            $entityManager->persist(new Puzzle(Uuid::uuid7(), 500, $name . ' puzzle', approved: true, manufacturer: $brand));
        }

        $entityManager->flush();
        $this->authenticatePat($browser);

        $names = array_column($this->fetch($browser, ['query' => ' RAVENSBURGER ']), 'name');
        self::assertSame(['Ravensburger', 'Ravensburger Junior'], $names);

        $names = array_column($this->fetch($browser, ['query' => 'ravens']), 'name');
        self::assertSame(['Ravensburger', 'Ravensburger Junior', 'Grand Ravens Studio', '100%_Ravens', 'Bärenravens'], $names);

        // Accents ignored; % and _ are letters, not wildcards
        self::assertSame(['Bärenravens'], array_column($this->fetch($browser, ['query' => 'barenr']), 'name'));
        self::assertSame(['100%_Ravens'], array_column($this->fetch($browser, ['query' => '%_']), 'name'));
        self::assertSame([], $this->fetch($browser, ['query' => 'Nothing Like It']));

        // An empty query lists every brand
        self::assertCount(count($this->fetch($browser)), $this->fetch($browser, ['query' => '  ']));

        $browser->request('GET', self::ENDPOINT, ['query' => str_repeat('x', 101)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testLogoIsAnAbsoluteUrl(): void
    {
        $browser = self::createClient();
        $entityManager = $this->entityManager($browser);
        $trefl = $entityManager->find(Manufacturer::class, ManufacturerFixture::MANUFACTURER_TREFL);
        self::assertNotNull($trefl);
        $trefl->logo = 'manufacturers/trefl.png';
        $entityManager->flush();
        $this->authenticatePat($browser);

        $logoUrl = $this->byId($this->fetch($browser))[ManufacturerFixture::MANUFACTURER_TREFL]['logo_url'];

        self::assertNotNull($logoUrl);
        self::assertMatchesRegularExpression('#^https?://.+/original/manufacturers/trefl\.png$#', $logoUrl);
    }

    public function testAnyTokenMayListAndNoTokenMayNot(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::ENDPOINT);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // A client_credentials token - no player behind it, no scope needed: the catalog is public
        OAuth2TestHelper::addBearerToken($browser, OAuth2TestHelper::createAccessToken($browser, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID));
        $browser->request('GET', self::ENDPOINT);
        self::assertResponseIsSuccessful();
    }

    /**
     * One statement whatever the number of brands, after the token's own (PAT lookup)
     */
    public function testQueryBudget(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser);

        $this->startCountingQueries($browser);
        $this->fetch($browser);

        self::assertSame(2, $this->queryCount($browser), 'The PAT lookup and the list.');
    }

    public function testOpenApiDocumentsTheEndpoint(): void
    {
        $browser = self::createClient();

        $document = $this->openApiDocument($browser);

        /** @var array<string, array{get: array{tags: list<string>, summary: string, description: string}}> $paths */
        $paths = $document['paths'];
        self::assertArrayHasKey(self::ENDPOINT, $paths);
        self::assertSame(['Puzzles'], $paths[self::ENDPOINT]['get']['tags']);
        self::assertStringContainsString('"brand"', $paths[self::ENDPOINT]['get']['description']);

        /** @var array{schemas: array<string, array{properties: array<string, mixed>}>} $components */
        $components = $document['components'];
        $schemas = $components['schemas'];
        self::assertSame(['brands', 'count'], array_keys($schemas['BrandList']['properties']));
        $this->assertOpenApiHasParameters($browser, self::ENDPOINT, ['query']);
        self::assertSame(
            ['id', 'name', 'slug', 'logo_url', 'puzzles_count'],
            array_keys($schemas['BrandListItemResponse']['properties']),
        );
    }

    private function authenticatePat(KernelBrowser $browser): void
    {
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));
    }

    private function resetSearchLimiter(KernelBrowser $browser): void
    {
        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = $browser->getContainer()->get('limiter.api_puzzle_search');
        $limiter->create(PlayerFixture::PLAYER_REGULAR)->reset();
    }

    /**
     * Hides the brand's puzzles until a month from now - every one, or every one but one (a partly hidden brand)
     */
    private function hideEveryPuzzleOf(KernelBrowser $browser, string $manufacturerId, bool $keepOne): void
    {
        $entityManager = $this->entityManager($browser);
        $puzzles = $entityManager->getRepository(Puzzle::class)->findBy(['manufacturer' => $manufacturerId], ['id' => 'ASC']);
        self::assertNotSame([], $puzzles);

        if ($keepOne) {
            self::assertGreaterThan(1, count($puzzles));
            array_shift($puzzles);
        }

        foreach ($puzzles as $puzzle) {
            $puzzle->hideUntil = TestClock::now()->modify('+30 days');
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $browser): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<BrandItem>
     */
    private function fetch(KernelBrowser $browser, array $parameters = []): array
    {
        $browser->request('GET', self::ENDPOINT, $parameters);
        self::assertResponseIsSuccessful();

        /** @var array{count: int, brands: list<BrandItem>} $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount($response['count'], $response['brands']);

        return $response['brands'];
    }

    /**
     * @param list<BrandItem> $brands
     *
     * @return array<string, BrandItem>
     */
    private function byId(array $brands): array
    {
        return array_column($brands, null, 'id');
    }
}
