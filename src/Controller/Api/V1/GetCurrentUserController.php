<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api\V1;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Security\OAuth2User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class GetCurrentUserController extends AbstractController
{
    public function __construct(
        private readonly GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: '/api/v1/me',
        name: 'api_v1_me',
        methods: ['GET'],
    )]
    public function __invoke(#[CurrentUser] OAuth2User $user): JsonResponse
    {
        $profile = $this->getPlayerProfile->byId($user->player->id->toString());

        return $this->json([
            'id' => $profile->playerId,
            'name' => $profile->playerName,
            'code' => $profile->code,
            'avatar' => $profile->avatar,
            'country' => $profile->country,
            'city' => $profile->city,
            'bio' => $profile->bio,
            'facebook' => $profile->facebook,
            'instagram' => $profile->instagram,
            'is_private' => $profile->isPrivate,
            'has_active_membership' => $profile->activeMembership,
        ]);
    }
}
