<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\DismissBoxTextMismatch;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\BoxTextField;
use SpeedPuzzling\Web\Value\BoxTextMismatchSort;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Dismiss" on the box-text mismatch list: remembered per puzzle, field and value - the mismatch never returns while
 * the box reads the same. Back to the same page of the list.
 */
final class DismissBoxTextMismatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/box-text-mismatches/{puzzleId}/dismiss',
        name: 'admin_box_text_mismatch_dismiss',
        methods: ['POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(string $puzzleId, Request $request): Response
    {
        if ($this->isCsrfTokenValid(BoxTextMismatchesController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $field = BoxTextField::tryFrom($request->request->getString('field')) ?? throw new BadRequestHttpException('Unknown field.');
        $valueKey = $request->request->getString('value');

        if (trim($valueKey) === '') {
            throw new BadRequestHttpException('No value to dismiss.');
        }

        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();

        // A puzzle hidden from a moderator answers 404 (PuzzleNotFound) - nothing is remembered
        $this->messageBus->dispatch(new DismissBoxTextMismatch(
            puzzleId: $puzzleId,
            field: $field,
            valueKey: $valueKey,
            decidedByPlayerId: $player->playerId,
            mayDecideHiddenPuzzles: $this->isGranted(AdminAccessVoter::ADMIN_ACCESS),
        ));

        $this->addFlash('success', $this->translator->trans('admin.box_text_mismatches.dismissed'));

        // Back to the list as it was: the filter, the order and the page ride along
        $listField = BoxTextField::tryFrom($request->request->getString('list_field'));
        $listSort = BoxTextMismatchSort::tryFrom($request->request->getString('list_sort'));

        return $this->redirectToRoute('admin_box_text_mismatches', array_filter([
            'field' => $listField?->value,
            'sort' => $listSort?->value,
            'page' => max(1, $request->request->getInt('list_page', 1)),
        ], static fn (null|int|string $value): bool => $value !== null && $value !== 1));
    }
}
