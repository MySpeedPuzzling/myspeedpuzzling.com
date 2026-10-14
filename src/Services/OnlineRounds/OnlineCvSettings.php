<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the CV worker of the online competitions' completeness check is (docs/features/online-competitions/README.md
 * §5.8, Phase 3 - tools/online-cv/): `ONLINE_CV_URL`, the worker's base URL as this app reaches it on the internal
 * network (`http://online-cv:8000` - the worker publishes no port). Empty = the check is off (the committed default,
 * production until Jan's yes - docs/features/feature_flags.md): no photo is sent anywhere, the review and the accuracy
 * report show nothing of it - byte for byte as before.
 */
final readonly class OnlineCvSettings
{
    private string $url;

    public function __construct(
        #[Autowire(env: 'ONLINE_CV_URL')]
        string $url,
    ) {
        $this->url = rtrim(trim($url), '/');
    }

    public function isOn(): bool
    {
        return $this->url !== '';
    }

    public function url(): string
    {
        return $this->url;
    }
}
