<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ParameterNotFound;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Services\Api\PuzzleResponseFactory;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * GET /api/v1/puzzles - search / barcode lookup over the same catalog query the
 * website uses (SearchPuzzle), with puzzle cards built for the calling token.
 *
 * The parameters are validated and documented by the declaration on
 * PuzzleListResponse; this provider only reads the validated values, enforces
 * the cross-parameter rules (422), the members-only values (403 - the website
 * silently falls back, the API is explicit), the per-owner rate limit (429),
 * runs the search and builds the response.
 *
 * @implements ProviderInterface<PuzzleListResponse>
 */
final readonly class PuzzleSearchResponseProvider implements ProviderInterface
{
    /** @var list<string> */
    private const array EAN_EXCLUSIVE_WITH = ['query', 'manufacturer', 'pieces_min', 'pieces_max', 'sort', 'difficulty'];

    public function __construct(
        private Security $security,
        private ApiTokenOwner $tokenOwner,
        private SearchPuzzle $searchPuzzle,
        private PuzzleResponseFactory $puzzleResponseFactory,
        private RateLimiterFactoryInterface $apiPuzzleSearchLimiter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PuzzleListResponse
    {
        $this->consumeRateLimit();

        /** @var null|Request $request */
        $request = $context['request'] ?? null;

        $page = $this->intValue($operation, 'page', 1);
        $limit = $this->intValue($operation, 'limit', 20);

        $ean = $this->stringValue($operation, 'ean');

        if ($ean !== null) {
            return $this->byEan($ean, $page, $limit, $request);
        }

        $search = $this->stringValue($operation, 'query');
        $manufacturer = $this->stringValue($operation, 'manufacturer');
        $piecesMin = $this->intValue($operation, 'pieces_min', null);
        $piecesMax = $this->intValue($operation, 'pieces_max', null);
        $sort = $this->stringValue($operation, 'sort') ?? 'most-solved';
        $difficultyTokens = $this->listValue($operation, 'difficulty');

        if ($piecesMin !== null && $piecesMax !== null && $piecesMin > $piecesMax) {
            throw self::violation('pieces_min', $piecesMin, 'pieces_min must not be above pieces_max.');
        }

        $difficultyTiers = [];

        foreach ($difficultyTokens as $token) {
            // The Choice constraint already rejected unknown tokens; this is the mapping only
            $tier = DifficultyTier::fromApiValue($token);

            if ($tier !== null) {
                $difficultyTiers[] = $tier->value;
            }
        }

        // The same normalisation as the website's /puzzle search: what is
        // members-only is decided in one place. The website silently falls back
        // for a non-member; the API turns that downgrade into a 403 instead.
        $criteria = PuzzleSearchCriteria::fromUserInput(
            brandId: $manufacturer,
            search: $search,
            pieces: null,
            tagId: null,
            difficultyTiers: $difficultyTiers,
            sortBy: $sort,
            isMember: $this->tokenOwner->isMember(),
        );

        if ($criteria->sortBy !== $sort) {
            throw new AccessDeniedHttpException(sprintf('sort=%s requires an active membership.', $sort));
        }

        if ($difficultyTiers !== [] && $criteria->difficultyTiers === []) {
            throw new AccessDeniedHttpException('The difficulty filter requires an active membership.');
        }

        $pieces = PiecesRange::between($piecesMin, $piecesMax);

        $total = $this->searchPuzzle->countByUserInput(
            $criteria->brandId,
            $criteria->search,
            $pieces,
            $criteria->tagId,
            $criteria->difficultyTiers,
        );

        $overviews = $this->searchPuzzle->byUserInput(
            $criteria->brandId,
            $criteria->search,
            $pieces,
            $criteria->tagId,
            $criteria->sortBy,
            offset: ($page - 1) * $limit,
            limit: $limit,
            difficultyTiers: $criteria->difficultyTiers,
        );

        $puzzles = $this->puzzleResponseFactory->cards($overviews);

        return new PuzzleListResponse(
            count: count($puzzles),
            total: $total,
            page: $page,
            limit: $limit,
            hasMore: $page * $limit < $total,
            puzzles: $puzzles,
        );
    }

    /**
     * Barcode lookup: exact match with zero tolerance, no count query (the
     * handful of matches is paginated in memory, the response shape stays the same).
     */
    private function byEan(string $ean, int $page, int $limit, null|Request $request): PuzzleListResponse
    {
        foreach (self::EAN_EXCLUSIVE_WITH as $key) {
            if ($request?->query->has($key) === true) {
                throw self::violation('ean', $ean, sprintf('ean cannot be combined with %s.', $key));
            }
        }

        $overviews = $this->searchPuzzle->allByEan($ean);
        $total = count($overviews);

        $puzzles = $this->puzzleResponseFactory->cards(
            array_slice($overviews, ($page - 1) * $limit, $limit),
        );

        return new PuzzleListResponse(
            count: count($puzzles),
            total: $total,
            page: $page,
            limit: $limit,
            hasMore: $page * $limit < $total,
            puzzles: $puzzles,
        );
    }

    /**
     * One budget per token owner: the player behind a PAT / authorization-code
     * token, the client behind a client_credentials token.
     */
    private function consumeRateLimit(): void
    {
        $key = $this->security->getUser()?->getUserIdentifier() ?? 'anonymous';
        $limit = $this->apiPuzzleSearchLimiter->create($key)->consume();

        if ($limit->isAccepted()) {
            return;
        }

        $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException(
            $retryAfterSeconds,
            sprintf('Too many puzzle searches. Try again in %d seconds.', $retryAfterSeconds),
        );
    }

    private static function violation(string $parameter, mixed $value, string $message): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, null, [], null, $parameter, $value),
        ]));
    }

    private function stringValue(Operation $operation, string $key): null|string
    {
        $value = $operation->getParameters()?->get($key)?->getValue();

        if ($value instanceof ParameterNotFound || $value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return ($default is int ? int : null|int)
     */
    private function intValue(Operation $operation, string $key, null|int $default): null|int
    {
        $value = $operation->getParameters()?->get($key)?->getValue();

        return is_int($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private function listValue(Operation $operation, string $key): array
    {
        $value = $operation->getParameters()?->get($key)?->getValue();

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
