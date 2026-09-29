<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `profile_name(player)`: the name a profile's sub-pages (library, collections, lists, ratings)
 * may print for their subject. `PlayerProfile::$isPrivate` already says "hidden from this viewer"
 * (docs/features/private-profile-allow-list.md), so only the owner needs telling apart.
 *
 * `profile_head_name(player)`: the name those pages may put in head metadata (title, description,
 * og:*). It follows the player's OWN setting, never the viewer: a friend on the allow list sees
 * the name on the page, but their browser must not emit an indexable / unfurlable identity.
 */
final class PlayerNameTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('profile_name', $this->profileName(...)),
            new TwigFunction('profile_head_name', $this->profileHeadName(...)),
        ];
    }

    public function profileName(PlayerProfile $player): string
    {
        return $this->nameUnless($player->isPrivate, $player);
    }

    public function profileHeadName(PlayerProfile $player): string
    {
        return $this->nameUnless($player->isPrivateProfile, $player);
    }

    private function nameUnless(bool $masked, PlayerProfile $player): string
    {
        $code = '#' . strtoupper($player->code);

        if ($masked && $this->retrieveLoggedUserProfile->getProfile()?->playerId !== $player->playerId) {
            return $this->translator->trans('secret_puzzler_name') . ' ' . $code;
        }

        return $player->playerName ?? $code;
    }
}
