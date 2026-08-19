<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleDifficulty;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared by the puzzle-library endpoint tests (library summary, wishlist,
 * unsolved puzzles, lend/borrow, sell/swap): the three kinds of token, the
 * owner's visibility settings, the seeding the insight gates need, and the
 * JSON helpers. Every list visibility defaults to private in the fixtures,
 * so a "public list" test switches it on first.
 *
 * @phpstan-type StatisticsGroup array{count: int, fastest_seconds: null|int, average_seconds: null|int, slowest_seconds: null|int, median_seconds: null|int}
 * @phpstan-type SolvesGroup array{count: int, best_time_seconds: null|int, last_time_seconds: null|int, first_solved_at: null|string, last_solved_at: null|string}
 * @phpstan-type Prediction array{predicted_seconds: null|int, range_low_seconds: null|int, range_high_seconds: null|int, is_personalized: bool, personal_solve_count: null|int, predicted_attempt_number: null|int, last_time_seconds: null|int}
 * @phpstan-type Statistics array{solved_times: int, solo: StatisticsGroup, duo: StatisticsGroup, team: StatisticsGroup}
 * @phpstan-type Difficulty array{score: null|float, level: null|string, confidence: string, sample_size: int}
 * @phpstan-type Solves array{solo: SolvesGroup, duo: SolvesGroup, team: SolvesGroup}
 */
trait PuzzleLibraryEndpointTestHelpers
{
    /** @var list<string> the four trailing objects of every library item, in this order */
    private const array INSIGHT_KEYS = ['statistics', 'difficulty', 'prediction', 'solves'];

    private function authenticatePat(KernelBrowser $browser, string $playerId): void
    {
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, $playerId));
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

    /**
     * @param array<non-empty-string> $scopes
     */
    private function authenticateClientCredentials(KernelBrowser $browser, array $scopes): void
    {
        // sub = aud = client id is exactly what the client_credentials grant issues
        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            $scopes,
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
    }

    private function setWishListVisibility(KernelBrowser $browser, string $playerId, CollectionVisibility $visibility): void
    {
        $this->changePlayer($browser, $playerId, static fn (Player $player) => $player->changeWishListVisibility($visibility));
    }

    private function setUnsolvedPuzzlesVisibility(KernelBrowser $browser, string $playerId, CollectionVisibility $visibility): void
    {
        $this->changePlayer($browser, $playerId, static fn (Player $player) => $player->changeUnsolvedPuzzlesVisibility($visibility));
    }

    private function setLendBorrowListVisibility(KernelBrowser $browser, string $playerId, CollectionVisibility $visibility): void
    {
        $this->changePlayer($browser, $playerId, static fn (Player $player) => $player->changeLendBorrowListVisibility($visibility));
    }

    private function setSolvedPuzzlesVisibility(KernelBrowser $browser, string $playerId, CollectionVisibility $visibility): void
    {
        $this->changePlayer($browser, $playerId, static fn (Player $player) => $player->changeSolvedPuzzlesVisibility($visibility));
    }

    private function setPuzzleCollectionVisibility(KernelBrowser $browser, string $playerId, CollectionVisibility $visibility): void
    {
        $this->changePlayer($browser, $playerId, static fn (Player $player) => $player->changePuzzleCollectionVisibility($visibility));
    }

    private function optOutOfTimePredictions(KernelBrowser $browser, string $playerId): void
    {
        $this->changePlayer($browser, $playerId, static fn (Player $player) => $player->changeTimePredictionsOptedOut(true));
    }

    /**
     * @param callable(Player): void $change
     */
    private function changePlayer(KernelBrowser $browser, string $playerId, callable $change): void
    {
        $entityManager = $this->entityManager($browser);

        $player = $entityManager->find(Player::class, $playerId);
        $this->assertNotNull($player);

        $change($player);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function seedDifficulty(KernelBrowser $browser, string $puzzleId, float $score, MetricConfidence $confidence): void
    {
        $entityManager = $this->entityManager($browser);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        $difficulty = $entityManager->find(PuzzleDifficulty::class, $puzzleId) ?? new PuzzleDifficulty($puzzle);
        $difficulty->updateDifficulty($score, $confidence, 20, new DateTimeImmutable());

        $entityManager->persist($difficulty);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function setImage(KernelBrowser $browser, string $puzzleId, string $image, null|DateTimeImmutable $hideUntil): void
    {
        $entityManager = $this->entityManager($browser);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        $this->assertNotNull($puzzle);

        $puzzle->image = $image;
        $puzzle->hideImageUntil = $hideUntil;
        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $browser): EntityManagerInterface
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(KernelBrowser $browser): array
    {
        $content = $browser->getResponse()->getContent();
        $this->assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The decoded "items" of a list response, asserting the wrapper shape.
     *
     * @return list<array<string, mixed>>
     */
    private function items(KernelBrowser $browser, string $expectedPlayerId, int $expectedCount): array
    {
        $response = $this->decodeJson($browser);
        // the wrapper of every list endpoint (the serializer emits the declared
        // "items" property before the promoted ones - the existing style)
        $this->assertEqualsCanonicalizing(['player_id', 'count', 'items'], array_keys($response));
        $this->assertSame($expectedPlayerId, $response['player_id']);
        $this->assertSame($expectedCount, $response['count']);
        $this->assertIsArray($response['items']);
        $this->assertCount($expectedCount, $response['items']);

        /** @var list<array<string, mixed>> $items */
        $items = array_values($response['items']);

        return $items;
    }

    /**
     * The item of the given puzzle (the first one, where a list may repeat a puzzle).
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function itemOf(array $items, string $puzzleId): array
    {
        foreach ($items as $item) {
            if ($item['puzzle_id'] === $puzzleId) {
                return $item;
            }
        }

        $this->fail(sprintf('Puzzle %s is not in the response', $puzzleId));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<mixed>
     */
    private function column(array $items, string $key): array
    {
        return array_map(static fn (array $item): mixed => $item[$key] ?? null, $items);
    }

    /**
     * @return list<int|string>
     */
    private function keys(mixed $value): array
    {
        $this->assertIsArray($value);

        return array_keys($value);
    }

    /**
     * The four insight objects close every item, in a fixed order; statistics
     * is always an object, the other three are objects exactly when the token
     * is entitled.
     *
     * @param array<string, mixed> $item
     */
    private function assertInsightsGated(array $item, bool $difficulty, bool $prediction, bool $solves): void
    {
        $this->assertSame(self::INSIGHT_KEYS, array_slice(array_keys($item), -4));
        $this->assertSame(['solved_times', 'solo', 'duo', 'team'], $this->keys($item['statistics']));
        $this->assertSame($difficulty, is_array($item['difficulty'] ?? null), 'difficulty');
        $this->assertSame($prediction, is_array($item['prediction'] ?? null), 'prediction');
        $this->assertSame($solves, is_array($item['solves'] ?? null), 'solves');
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return Statistics
     */
    private function statisticsOf(array $item): array
    {
        $this->assertIsArray($item['statistics'] ?? null);

        /** @var Statistics $statistics */
        $statistics = $item['statistics'];

        return $statistics;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return Difficulty
     */
    private function difficultyOf(array $item): array
    {
        $this->assertIsArray($item['difficulty'] ?? null, 'difficulty is null');

        /** @var Difficulty $difficulty */
        $difficulty = $item['difficulty'];

        return $difficulty;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return Prediction
     */
    private function predictionOf(array $item): array
    {
        $this->assertIsArray($item['prediction'] ?? null, 'prediction is null');

        /** @var Prediction $prediction */
        $prediction = $item['prediction'];

        return $prediction;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return Solves
     */
    private function solvesOf(array $item): array
    {
        $this->assertIsArray($item['solves'] ?? null, 'solves is null');

        /** @var Solves $solves */
        $solves = $item['solves'];

        return $solves;
    }

    /**
     * The OpenAPI document is generated from the attributes; both paths of a
     * list pair must be in it, each as a GET with a summary and a description.
     *
     * @param list<string> $paths
     */
    private function assertOpenApiDocumentsPaths(KernelBrowser $browser, array $paths): void
    {
        $browser->request('GET', '/api/docs.jsonopenapi');
        $this->assertResponseIsSuccessful();

        $document = $this->decodeJson($browser);
        $this->assertIsArray($document['paths'] ?? null);

        foreach ($paths as $path) {
            $this->assertArrayHasKey($path, $document['paths'], sprintf('OpenAPI does not document %s', $path));
            /** @var array{get?: array{tags?: list<string>, summary?: string, description?: string}} $pathItem */
            $pathItem = $document['paths'][$path];
            $this->assertArrayHasKey('get', $pathItem);
            $this->assertSame(['Puzzle Library'], $pathItem['get']['tags'] ?? null);
            $this->assertNotSame('', trim($pathItem['get']['summary'] ?? ''), sprintf('%s has no summary', $path));
            $this->assertNotSame('', trim($pathItem['get']['description'] ?? ''), sprintf('%s has no description', $path));
        }
    }
}
