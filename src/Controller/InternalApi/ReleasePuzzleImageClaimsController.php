<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Entity\PuzzleImageAnalysisClaim;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Message\ReleasePuzzleImageClaims;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A worker lets go of its claims - on shutdown, or of images it gave up on (docs/features/internal-api.md, "Puzzle
 * image analysis" - claims): body `{worker, analyzer?, puzzleIds?}`. Only this worker's claims; answers
 * `{"released": n}`.
 */
final class ReleasePuzzleImageClaimsController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-image-claims/release',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $input = InternalApiInput::fromRequest($request, ['worker', 'analyzer', 'puzzleIds']);
        $worker = $input->string('worker', required: true, maxLength: PuzzleImageAnalysisClaim::MAX_WORKER_LENGTH);
        if ($worker !== null && str_contains($worker, "\0")) {
            $input->addError('worker', 'must not contain NUL characters.');
        }

        $analyzerValue = $input->string('analyzer');
        $analyzer = $analyzerValue !== null ? ImageAnalyzer::catalogue($analyzerValue) : null;

        if ($analyzerValue !== null && $analyzer === null) {
            $input->addError('analyzer', sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::catalogueValues())));
        }

        $puzzleIds = $input->idList('puzzleIds');
        $input->throwIfInvalid();
        assert($worker !== null);

        $envelope = $this->messageBus->dispatch(new ReleasePuzzleImageClaims($worker, $analyzer, $puzzleIds));
        $handled = $envelope->last(HandledStamp::class);
        assert($handled instanceof HandledStamp);
        $released = $handled->getResult();
        assert(is_int($released));

        $request->attributes->set(InternalApiAuditSubscriber::SUMMARY_ATTRIBUTE, ['worker' => $worker, 'released' => $released]);

        return new JsonResponse(['released' => $released]);
    }
}
