<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Billing\AndroidPlayBilling;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AndroidPurchaseVerificationController extends AbstractController
{
    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly PlayerRepository $playerRepository,
        private readonly AndroidPlayBilling $androidPlayBilling,
    ) {
    }

    #[Route(path: '/api/android/verify-purchase', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $userProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($userProfile === null) {
            return $this->json(
                ['error' => 'Authentication required'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $content = $request->getContent();
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return $this->json(
                ['error' => 'Invalid JSON payload'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        /** @var array<string, mixed> $data */

        if (!isset($data['purchaseToken']) || !isset($data['productId'])) {
            return $this->json(
                ['error' => 'Missing purchaseToken or productId field'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $player = $this->playerRepository->get($userProfile->playerId);
        } catch (PlayerNotFound) {
            return $this->json(
                ['error' => 'Player not found'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $success = $this->androidPlayBilling->verifyAndActivate($player, $data);

        if ($success) {
            return $this->json([
                'success' => true,
                'message' => 'Subscription activated successfully',
            ]);
        }

        return $this->json(
            ['error' => 'Purchase verification failed'],
            Response::HTTP_BAD_REQUEST,
        );
    }
}
