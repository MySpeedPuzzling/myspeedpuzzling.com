<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\AnnouncementModals\ResolveAnnouncementModal;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AnnouncementModalTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private ResolveAnnouncementModal $resolveAnnouncementModal,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            // Calling it claims the modal for this page view - the layout is its only caller
            new TwigFunction('announcement_modal', $this->resolveAnnouncementModal->forCurrentRequest(...)),
        ];
    }
}
