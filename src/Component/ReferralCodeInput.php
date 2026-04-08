<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class ReferralCodeInput
{
    use DefaultActionTrait;

    private const string SESSION_KEY = 'referral_code';

    #[LiveProp(writable: true)]
    public string $code = '';

    #[LiveProp]
    public null|string $validatedAffiliateName = null;

    #[LiveProp]
    public null|string $error = null;

    public function __construct(
        private readonly PlayerRepository $playerRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[LiveAction]
    public function validateCode(): void
    {
        $code = ltrim(trim($this->code), '#');

        if ($code === '') {
            $this->validatedAffiliateName = null;
            $this->error = null;
            $this->clearSession();
            return;
        }

        try {
            $player = $this->playerRepository->getByCode($code);
        } catch (PlayerNotFound) {
            $this->validatedAffiliateName = null;
            $this->error = 'referral.code_not_found';
            $this->clearSession();
            return;
        }

        if (!$player->isInReferralProgram()) {
            $this->validatedAffiliateName = null;
            $this->error = 'referral.code_not_found';
            $this->clearSession();
            return;
        }

        $this->validatedAffiliateName = $player->name ?? ('#' . $player->code);
        $this->error = null;

        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, $player->code);
    }

    #[LiveAction]
    public function clearCode(): void
    {
        $this->code = '';
        $this->validatedAffiliateName = null;
        $this->error = null;
        $this->clearSession();
    }

    private function clearSession(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);
    }
}
