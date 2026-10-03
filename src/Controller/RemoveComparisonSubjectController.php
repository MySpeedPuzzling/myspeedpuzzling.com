<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\CanNotRemoveYourselfFromComparison;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotFound;
use SpeedPuzzling\Web\Message\RemoveComparisonSubject;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Remove from comparison" in the player header's ⋯ menu (docs/features/player-comparison.md, D11). The form names
 * the subject, never the row: the row comes from the viewer's own line-up on their profile row, so nobody can name
 * someone else's. Always a redirect - Turbo Drive drops a 200 answer to a form submission.
 */
final class RemoveComparisonSubjectController extends AbstractController
{
    // Stateless (config/packages/csrf.php): the form sits on pages that are cached and shared
    public const string CSRF_TOKEN_ID = 'comparison_remove';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/compare/remove',
        name: 'comparison_remove',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('homepage');
        }

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('warning', $this->translator->trans('comparison_entry.flash.try_again'));

            return $this->back($returnUrl, null);
        }

        $ref = ComparisonSubjectRef::tryFromString($request->request->getString('subject'));
        $lineUp = $viewer->comparisonLineUp;
        $rowId = $ref !== null ? $lineUp->rowIdOf($ref) : null;
        $kind = null;

        foreach ($lineUp->items as $item) {
            if ($item->rowId === $rowId) {
                $kind = $item->kind;
            }
        }

        // Not in the line-up (any more - a double submit, another tab): already the outcome asked for
        if ($rowId !== null) {
            try {
                $this->messageBus->dispatch(new RemoveComparisonSubject($viewer->playerId, $rowId));
            } catch (CanNotRemoveYourselfFromComparison) {
                $this->addFlash('warning', $this->translator->trans('comparison_entry.flash.cannot_remove_yourself'));

                return $this->back($returnUrl, $kind?->value);
            } catch (ComparisonSubjectNotFound) {
                // Removed by another request between reading the line-up and now
            }
        }

        $this->addFlash('success', $this->translator->trans('comparison_entry.flash.removed'));

        return $this->back($returnUrl, $kind?->value);
    }

    private function back(null|ReturnUrl $returnUrl, null|string $kind): RedirectResponse
    {
        if ($returnUrl !== null) {
            return $this->redirect($returnUrl->path);
        }

        return $this->redirectToRoute('comparison', $kind !== null ? ['kind' => $kind] : []);
    }
}
