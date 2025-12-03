<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LendBorrow;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\LendPuzzleFormData;
use SpeedPuzzling\Web\FormType\LendPuzzleFormType;
use SpeedPuzzling\Web\Message\LendPuzzleToPlayer;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
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

final class LendPuzzleController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetLentPuzzles $getLentPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private PlayerRepository $playerRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pujcit/{puzzleId}',
            'en' => '/en/lend/{puzzleId}',
            'es' => '/es/prestar/{puzzleId}',
            'ja' => '/ja/lend/{puzzleId}',
            'fr' => '/fr/preter/{puzzleId}',
            'de' => '/de/verleihen/{puzzleId}',
        ],
        name: 'lend_puzzle',
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

        $isAlreadyLent = $this->getLentPuzzles->isPuzzleLentByOwner($loggedPlayer->playerId, $puzzleId);

        $formData = new LendPuzzleFormData();
        $form = $this->createForm(LendPuzzleFormType::class, $formData);
        $form->handleRequest($request);

        // Handle POST - lend puzzle
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$loggedPlayer->activeMembership) {
                $this->addFlash('warning', $this->translator->trans('lend_borrow.membership_required.message'));

                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }

            /** @var LendPuzzleFormData $formData */
            $formData = $form->getData();

            assert($formData->borrowerCode !== null);

            // Parse input - if starts with # try to find registered player, otherwise use as plain text
            $input = $formData->borrowerCode;
            $isRegisteredPlayer = str_starts_with($input, '#');
            $cleanedInput = trim($input, "# \t\n\r\0");

            $borrowerPlayerId = null;
            $borrowerName = null;
            $borrowerDisplayName = null;

            if ($isRegisteredPlayer) {
                try {
                    $borrower = $this->playerRepository->getByCode($cleanedInput);
                    $borrowerPlayerId = $borrower->id->toString();
                    $borrowerDisplayName = $borrower->name ?? $cleanedInput;

                    // Cannot lend to yourself
                    if ($borrowerPlayerId === $loggedPlayer->playerId) {
                        $this->addFlash('danger', $this->translator->trans('lend_borrow.flash.cannot_lend_to_self'));

                        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                    }
                } catch (PlayerNotFound) {
                    $this->addFlash('danger', $this->translator->trans('lend_borrow.flash.player_not_found'));

                    return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                }
            } else {
                // Use plain text name for non-registered person
                $borrowerName = $cleanedInput;
                $borrowerDisplayName = $cleanedInput;
            }

            $this->messageBus->dispatch(new LendPuzzleToPlayer(
                ownerPlayerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
                borrowerPlayerId: $borrowerPlayerId,
                borrowerName: $borrowerName,
                notes: $formData->notes,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);
                $context = $request->request->get('context', 'detail');

                return $this->render('lend-borrow/_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'action' => 'lent',
                    'message' => $this->translator->trans('lend_borrow.flash.lent'),
                    'context' => $context,
                    'borrower_name' => $borrowerDisplayName,
                ]);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('lend_borrow.flash.lent'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Handle GET - show modal/form
        $templateParams = [
            'puzzle' => $puzzle,
            'form' => $form,
            'is_already_lent' => $isAlreadyLent,
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('lend-borrow/lend_modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('lend-borrow/lend.html.twig', $templateParams);
    }
}
