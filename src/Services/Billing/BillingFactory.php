<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Billing;

use SpeedPuzzling\Web\Services\PlatformDetector;
use SpeedPuzzling\Web\Value\Platform;

readonly final class BillingFactory
{
    public function __construct(
        private PlatformDetector $platformDetector,
        private WebStripeBilling $webStripeBilling,
        private IosAppStoreBilling $iosAppStoreBilling,
        private AndroidPlayBilling $androidPlayBilling,
    ) {
    }

    public function getBillingService(): PlatformBillingInterface
    {
        return $this->getBillingServiceForPlatform($this->platformDetector->detect());
    }

    public function getBillingServiceForPlatform(Platform $platform): PlatformBillingInterface
    {
        return match ($platform) {
            Platform::Web => $this->webStripeBilling,
            Platform::Ios => $this->iosAppStoreBilling,
            Platform::Android => $this->androidPlayBilling,
        };
    }
}
