<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\AffiliateNotFound;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class TributeCodeInput
{
    use DefaultActionTrait;

    private const string SESSION_KEY = 'tribute_code';

    #[LiveProp(writable: true)]
    public string $code = '';

    #[LiveProp]
    public null|string $validatedAffiliateName = null;

    #[LiveProp]
    public null|string $error = null;

    #[LiveProp]
    public bool $isExpanded = false;

    public function __construct(
        private readonly AffiliateRepository $affiliateRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[LiveAction]
    public function validateCode(): void
    {
        $code = trim($this->code);

        if ($code === '') {
            $this->validatedAffiliateName = null;
            $this->error = null;
            $this->clearSession();
            return;
        }

        try {
            $affiliate = $this->affiliateRepository->getByCode($code);
        } catch (AffiliateNotFound) {
            $this->validatedAffiliateName = null;
            $this->error = 'tribute.code_not_found';
            $this->clearSession();
            return;
        }

        if (!$affiliate->isActive()) {
            $this->validatedAffiliateName = null;
            $this->error = 'tribute.code_not_found';
            $this->clearSession();
            return;
        }

        $this->validatedAffiliateName = $affiliate->player->name ?? ('#' . $affiliate->player->code);
        $this->error = null;
        $this->isExpanded = true;

        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, $affiliate->code);
    }

    #[LiveAction]
    public function expand(): void
    {
        $this->isExpanded = true;
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
