<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Approves a puzzle merge, recording who decided it and why.
 *
 * A merge deletes puzzles and moves solving times onto the survivor, so the
 * handler writes a full before/after snapshot to the audit trail first. The note
 * and confidence supplied here are stored with it - they are what make a merge
 * decided in bulk reviewable afterwards.
 */
final class ApprovePuzzleMergeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-merge-requests/{mergeRequestId}/approve',
        methods: ['POST'],
    )]
    public function __invoke(string $mergeRequestId, Request $request): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the merge to.',
            );
        }

        $body = InternalApiJsonBody::parse($request);

        $survivorPuzzleId = self::requiredString($body, 'survivorPuzzleId');
        $mergedName = self::requiredString($body, 'mergedName');
        $mergedPiecesCount = $body['mergedPiecesCount'] ?? null;

        if (is_int($mergedPiecesCount) === false || $mergedPiecesCount <= 0) {
            throw new BadRequestHttpException('"mergedPiecesCount" must be a positive integer.');
        }

        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: $this->reviewerPlayerId,
            survivorPuzzleId: $survivorPuzzleId,
            mergedName: $mergedName,
            mergedEan: self::optionalString($body, 'mergedEan'),
            mergedIdentificationNumber: self::optionalString($body, 'mergedIdentificationNumber'),
            mergedPiecesCount: $mergedPiecesCount,
            mergedManufacturerId: self::optionalString($body, 'mergedManufacturerId'),
            selectedImagePuzzleId: self::optionalString($body, 'selectedImagePuzzleId'),
            decisionSource: MergeDecisionSource::InternalApi,
            decisionConfidence: self::confidence($body),
            decisionNote: self::optionalString($body, 'decisionNote'),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        if (is_string($value) === false || trim($value) === '') {
            throw new BadRequestHttpException(sprintf('"%s" is required.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalString(array $body, string $key): null|string
    {
        $value = $body[$key] ?? null;

        if (is_string($value) === false || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function confidence(array $body): null|MergeDecisionConfidence
    {
        $value = self::optionalString($body, 'decisionConfidence');

        if ($value === null) {
            return null;
        }

        return MergeDecisionConfidence::tryFrom($value) ?? throw new BadRequestHttpException(sprintf(
            '"decisionConfidence" must be one of: %s.',
            implode(', ', array_column(MergeDecisionConfidence::cases(), 'value')),
        ));
    }
}
