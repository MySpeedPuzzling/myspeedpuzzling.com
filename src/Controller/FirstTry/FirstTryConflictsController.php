<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FirstTry;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The first-try conflicts page grew into "Review your results" (docs/features/duplicate-results.md) - the old
 * address keeps working. Its POST routes stay where they were and lead back to the new page.
 */
final class FirstTryConflictsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'first_try_conflicts';

    // Any uuid - older puzzles and results do not all carry an RFC version digit
    public const string ID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    #[Route(
        path: '/{_locale}/first-try-conflicts',
        name: 'first_try_conflicts',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('review_results', status: Response::HTTP_MOVED_PERMANENTLY);
    }
}
