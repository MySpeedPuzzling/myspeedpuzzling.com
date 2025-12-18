<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Billing\IosAppStoreBilling;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class IosReceiptVerificationController extends AbstractController
{
    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly PlayerRepository $playerRepository,
        private readonly IosAppStoreBilling $iosAppStoreBilling,
    ) {
    }

    #[Route(path: '/api/ios/verify-receipt', methods: ['POST'])]
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

        if (!isset($data['receiptData'])) {
            return $this->json(
                ['error' => 'Missing receiptData field'],
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

        $success = $this->iosAppStoreBilling->verifyAndActivate($player, $data);

        if ($success) {
            return $this->json([
                'success' => true,
                'message' => 'Subscription activated successfully',
            ]);
        }

        return $this->json(
            ['error' => 'Receipt verification failed'],
            Response::HTTP_BAD_REQUEST,
        );
    }
}
