<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\UploaderHelper;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UploadTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('uploaded_asset', [$this->uploaderHelper, 'getPublicPath']),
        ];
    }
}
