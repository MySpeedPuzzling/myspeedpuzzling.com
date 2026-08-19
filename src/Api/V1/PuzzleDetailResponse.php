<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;

/**
 * GET /api/v1/puzzles/{puzzleId} - one puzzle, with exactly the fields of the
 * catalog card (PuzzleResponse, same names, same order): the public community
 * statistics and the three insight objects built for the calling token -
 * difficulty (members), prediction (member, not opted out, PAT or results:read)
 * and solves (the token owner's own history, PAT or results:read). The objects
 * are always present; null means "not available to this token", never an error.
 *
 * A separate DTO (not a subclass) so that the OpenAPI schema of the detail is
 * its own, flat object; it is always built from the card, never by hand.
 */
#[ApiResource(
    shortName: 'Puzzle',
    operations: [
        new Get(
            uriTemplate: '/v1/puzzles/{puzzleId}',
            // Declared explicitly: with an "id" property on the class API Platform
            // would otherwise derive a uri variable named "id" and 404 on {puzzleId}.
            uriVariables: ['puzzleId' => new Link(fromClass: self::class, identifiers: ['id'])],
            openapi: new OpenApiOperation(
                tags: ['Puzzles'],
                summary: 'Puzzle detail with community statistics and the token owner\'s insights',
                description: 'Returns one puzzle with exactly the fields of a card of GET /v1/puzzles: the catalog data, '
                    . 'the public "statistics" split by discipline (solo, duo, team), and the three insight objects that '
                    . 'are always present and null when the token is not entitled to them - "difficulty" (the token owner '
                    . 'must be a member), "prediction" (a member who has not opted out of time predictions, with a PAT or '
                    . 'results:read) and "solves" (the token owner\'s own history, PAT or results:read). A '
                    . 'client_credentials token has no player behind it, so it gets null for all three. '
                    . 'An unknown or malformed id is a 404, and so is a secret competition puzzle until it is revealed; '
                    . 'an embargoed image is null until its release.',
                responses: [
                    '401' => new OpenApiResponse(description: 'Missing, invalid or expired token.'),
                    '404' => new OpenApiResponse(description: 'Unknown or malformed puzzle id, or a secret competition puzzle that has not been revealed yet.'),
                ],
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: PuzzleDetailResponseProvider::class,
        ),
    ],
)]
final class PuzzleDetailResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $alternativeName,
        public PuzzleManufacturerResponse $manufacturer,
        public int $piecesCount,
        public null|string $image,
        public null|string $ean,
        public null|string $identificationNumber,
        public bool $isAvailable,
        public bool $isApproved,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty,
        public null|TimePredictionResponse $prediction,
        public null|PlayerSolvesResponse $solves,
    ) {
    }

    /**
     * The detail is the card: the same gates and the same insight objects as
     * the catalog list (PuzzleResponseFactory), one code path for both.
     */
    public static function fromCard(PuzzleResponse $card): self
    {
        return new self(
            id: $card->id,
            name: $card->name,
            alternativeName: $card->alternativeName,
            manufacturer: $card->manufacturer,
            piecesCount: $card->piecesCount,
            image: $card->image,
            ean: $card->ean,
            identificationNumber: $card->identificationNumber,
            isAvailable: $card->isAvailable,
            isApproved: $card->isApproved,
            statistics: $card->statistics,
            difficulty: $card->difficulty,
            prediction: $card->prediction,
            solves: $card->solves,
        );
    }
}
