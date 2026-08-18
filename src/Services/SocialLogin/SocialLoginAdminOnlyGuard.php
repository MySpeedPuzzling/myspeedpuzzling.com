<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use SpeedPuzzling\Web\Exceptions\SocialLoginRestrictedToAdmins;
use SpeedPuzzling\Web\Repository\PlayerRepository;

/**
 * The one server-side admin check of the SOCIAL_LOGIN_ADMIN_ONLY rollout
 * stage, shared by the account resolver (login callbacks) and the link/unlink
 * handlers. Admin = player.isAdmin, same source as AdminAccessVoter - but
 * resolved from a user_id string because OAuth callbacks and Messenger
 * handlers have no security token to vote on.
 */
final readonly class SocialLoginAdminOnlyGuard
{
    public function __construct(
        private SocialLoginSettings $settings,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws SocialLoginRestrictedToAdmins
     */
    public function assertAllowedFor(string $userId): void
    {
        if ($this->settings->isAdminOnly() === false) {
            return;
        }

        $player = $this->playerRepository->findByUserId($userId);

        if ($player === null || $player->isAdmin === false) {
            throw new SocialLoginRestrictedToAdmins();
        }
    }
}
