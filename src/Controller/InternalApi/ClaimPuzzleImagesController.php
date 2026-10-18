<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Entity\PuzzleImageAnalysisClaim;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Message\ClaimPuzzleImages;
use SpeedPuzzling\Web\Query\GetPuzzleImagesForAnalysis;
use SpeedPuzzling\Web\Results\PuzzleImageForAnalysis;
use SpeedPuzzling\Web\Services\UploaderHelper;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A worker of the image analysis pipeline takes its next images (docs/features/internal-api.md, "Puzzle image
 * analysis" - claims): body `{analyzer, version, worker, limit (1-200, 50), leaseSeconds (60-7200, 1800)}`. Up to
 * `limit` puzzles still to be analysed by the analyzer (missing, another picture or another version) that no other
 * worker holds are claimed for the lease - fresh work first, its own unexpired claims (extended) only when there is
 * not enough of it - and answered in the work list's shape (`claims.<analyzer>` = this worker, with the lease's end). Storing a result releases its claim. Two
 * machines claiming at the same moment never get the same image.
 */
final class ClaimPuzzleImagesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleImagesForAnalysis $getPuzzleImagesForAnalysis,
        private readonly UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-image-claims',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $input = InternalApiInput::fromRequest($request, ['analyzer', 'version', 'worker', 'limit', 'leaseSeconds']);
        $analyzerValue = $input->string('analyzer', required: true);
        $analyzer = $analyzerValue !== null ? ImageAnalyzer::tryFrom($analyzerValue) : null;

        if ($analyzerValue !== null && $analyzer === null) {
            $input->addError('analyzer', sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::values())));
        }

        $version = $input->string('version', required: true, maxLength: PuzzleImageAnalysisInput::MAX_VERSION_LENGTH);
        $worker = $input->string('worker', required: true, maxLength: PuzzleImageAnalysisClaim::MAX_WORKER_LENGTH);
        $limit = $input->int('limit', minimum: 1, maximum: ClaimPuzzleImages::MAX_LIMIT) ?? ClaimPuzzleImages::DEFAULT_LIMIT;
        // Postgres text refuses NUL - a 500 instead of an answer
        foreach (['version' => $version, 'worker' => $worker] as $field => $value) {
            if ($value !== null && str_contains($value, "\0")) {
                $input->addError($field, 'must not contain NUL characters.');
            }
        }

        $leaseSeconds = $input->int('leaseSeconds', minimum: ClaimPuzzleImages::MIN_LEASE_SECONDS, maximum: ClaimPuzzleImages::MAX_LEASE_SECONDS)
            ?? ClaimPuzzleImages::DEFAULT_LEASE_SECONDS;
        $input->throwIfInvalid();
        assert($analyzer !== null && $version !== null && $worker !== null);

        $envelope = $this->messageBus->dispatch(new ClaimPuzzleImages($analyzer, $version, $worker, $limit, $leaseSeconds));
        $handled = $envelope->last(HandledStamp::class);
        assert($handled instanceof HandledStamp);
        /** @var list<string> $puzzleIds */
        $puzzleIds = $handled->getResult();

        $request->attributes->set(InternalApiAuditSubscriber::SUMMARY_ATTRIBUTE, [
            'analyzer' => $analyzer->value,
            'worker' => $worker,
            'claimed' => count($puzzleIds),
        ]);

        return new JsonResponse([
            'puzzles' => array_map(
                fn (PuzzleImageForAnalysis $puzzle): array => PuzzleImageForAnalysisJson::of($puzzle, $this->uploaderHelper->getPublicPath($puzzle->image)),
                $this->getPuzzleImagesForAnalysis->byIds($puzzleIds),
            ),
        ]);
    }
}
