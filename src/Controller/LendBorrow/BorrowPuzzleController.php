<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LendBorrow;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\BorrowPuzzleFormData;
use SpeedPuzzling\Web\FormType\BorrowPuzzleFormType;
use SpeedPuzzling\Web\Message\BorrowPuzzleFromPlayer;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class BorrowPuzzleController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetBorrowedPuzzles $getBorrowedPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private PlayerRepository $playerRepository,
        readonly private GetWishListItems $getWishListItems,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vypujcit/{puzzleId}',
            'en' => '/en/borrow/{puzzleId}',
            'es' => '/es/tomar-prestado/{puzzleId}',
            'ja' => '/ja/borrow/{puzzleId}',
            'fr' => '/fr/emprunter/{puzzleId}',
            'de' => '/de/ausleihen/{puzzleId}',
        ],
        name: 'borrow_puzzle',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $puzzle = $this->getPuzzleOverview->byId($puzzleId);

        $isAlreadyBorrowed = $this->getBorrowedPuzzles->isPuzzleBorrowedByHolder($loggedPlayer->playerId, $puzzleId);

        $formData = new BorrowPuzzleFormData();
        $form = $this->createForm(BorrowPuzzleFormType::class, $formData);
        $form->handleRequest($request);

        // Handle POST - borrow puzzle
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$loggedPlayer->activeMembership) {
                $this->addFlash('warning', $this->translator->trans('lend_borrow.membership_required.message'));

                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }

            /** @var BorrowPuzzleFormData $formData */
            $formData = $form->getData();

            assert($formData->ownerCode !== null);

            // Parse input - if starts with # try to find registered player, otherwise use as plain text
            $input = $formData->ownerCode;
            $isRegisteredPlayer = str_starts_with($input, '#');
            $cleanedInput = trim($input, "# \t\n\r\0");

            $ownerPlayerId = null;
            $ownerName = null;

            if ($isRegisteredPlayer) {
                try {
                    $owner = $this->playerRepository->getByCode($cleanedInput);
                    $ownerPlayerId = $owner->id->toString();

                    // Cannot borrow from yourself
                    if ($ownerPlayerId === $loggedPlayer->playerId) {
                        $this->addFlash('danger', $this->translator->trans('lend_borrow.flash.cannot_borrow_from_self'));

                        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                    }
                } catch (PlayerNotFound) {
                    $this->addFlash('danger', $this->translator->trans('lend_borrow.flash.player_not_found'));

                    return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                }
            } else {
                // Use plain text name for non-registered person
                $ownerName = $cleanedInput;
            }

            $this->messageBus->dispatch(new BorrowPuzzleFromPlayer(
                borrowerPlayerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
                ownerPlayerId: $ownerPlayerId,
                ownerName: $ownerName,
                notes: $formData->notes,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $context = $request->request->getString('context', 'detail');
                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

                $templateParams = [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'action' => 'borrowed',
                    'message' => $this->translator->trans('lend_borrow.flash.borrowed'),
                    'context' => $context,
                ];

                // For list context (from wishlist page), fetch remaining count
                if ($context === 'list') {
                    $templateParams['remaining_count'] = $this->getWishListItems->countByPlayerId($loggedPlayer->playerId);
                }

                return $this->render('lend-borrow/_stream.html.twig', $templateParams);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('lend_borrow.flash.borrowed'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Handle GET - show modal/form
        $templateParams = [
            'puzzle' => $puzzle,
            'form' => $form,
            'is_already_borrowed' => $isAlreadyBorrowed,
            'context' => $request->query->getString('context', 'detail'),
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('lend-borrow/borrow_modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('lend-borrow/borrow.html.twig', $templateParams);
    }
}
