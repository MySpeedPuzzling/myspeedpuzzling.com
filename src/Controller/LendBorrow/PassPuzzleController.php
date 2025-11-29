<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LendBorrow;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\PassLentPuzzleFormData;
use SpeedPuzzling\Web\FormType\PassLentPuzzleFormType;
use SpeedPuzzling\Web\Message\PassLentPuzzle;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
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

final class PassPuzzleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private LentPuzzleRepository $lentPuzzleRepository,
        readonly private PlayerRepository $playerRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/predat-puzzle/{lentPuzzleId}',
            'en' => '/en/pass-puzzle/{lentPuzzleId}',
            'es' => '/es/pasar-puzzle/{lentPuzzleId}',
            'ja' => '/ja/pass-puzzle/{lentPuzzleId}',
            'fr' => '/fr/passer-puzzle/{lentPuzzleId}',
            'de' => '/de/puzzle-weitergeben/{lentPuzzleId}',
        ],
        name: 'pass_puzzle',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $lentPuzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        // Get the lent puzzle for puzzle info
        $lentPuzzle = $this->lentPuzzleRepository->get($lentPuzzleId);
        $puzzleId = $lentPuzzle->puzzle->id->toString();

        $formData = new PassLentPuzzleFormData();
        $form = $this->createForm(PassLentPuzzleFormType::class, $formData);
        $form->handleRequest($request);

        // Handle POST - pass puzzle
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PassLentPuzzleFormData $formData */
            $formData = $form->getData();

            assert($formData->newHolderCode !== null);

            // Parse input - if starts with # try to find registered player, otherwise use as plain text
            $input = $formData->newHolderCode;
            $isRegisteredPlayer = str_starts_with($input, '#');
            $cleanedInput = trim($input, "# \t\n\r\0");

            $newHolderPlayerId = null;
            $newHolderName = null;

            if ($isRegisteredPlayer) {
                try {
                    $newHolder = $this->playerRepository->getByCode($cleanedInput);
                    $newHolderPlayerId = $newHolder->id->toString();

                    // Cannot pass to yourself
                    if ($newHolderPlayerId === $loggedPlayer->playerId) {
                        $this->addFlash('danger', $this->translator->trans('lend_borrow.flash.cannot_pass_to_self'));

                        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                    }
                } catch (PlayerNotFound) {
                    $this->addFlash('danger', $this->translator->trans('lend_borrow.flash.player_not_found'));

                    return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                }
            } else {
                // Use plain text name for non-registered person
                $newHolderName = $cleanedInput;
            }

            $this->messageBus->dispatch(new PassLentPuzzle(
                lentPuzzleId: $lentPuzzleId,
                currentHolderPlayerId: $loggedPlayer->playerId,
                newHolderPlayerId: $newHolderPlayerId,
                newHolderName: $newHolderName,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

                return $this->render('lend-borrow/_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'action' => 'passed',
                    'message' => $this->translator->trans('lend_borrow.flash.passed'),
                ]);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('lend_borrow.flash.passed'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Handle GET - show modal/form
        $templateParams = [
            'lentPuzzleId' => $lentPuzzleId,
            'puzzle' => $lentPuzzle->puzzle,
            'form' => $form,
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('lend-borrow/pass_modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('lend-borrow/pass.html.twig', $templateParams);
    }
}
