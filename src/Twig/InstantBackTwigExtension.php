<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Instant Back (docs/features/navigation README §4.13 G9, requirement GEST-14, decision 64): `viewer_marker()` is the
 * content of <meta name="msp-viewer"> - who the page was rendered for, as a short keyed hash of the signed-in account
 * (empty for every guest: base.html.twig then renders no meta at all, so guest HTML stays exactly as it was and
 * shared-cacheable - the script knows a guest's page by <body data-viewer-audience>). assets/instant_back.js reloads a page the
 * browser kept in its back/forward cache, and never renders a Turbo snapshot, when the viewer changed meanwhile (a sign
 * out or in elsewhere). Never stored, never sent anywhere; no statement (the security token only).
 */
final class InstantBackTwigExtension extends AbstractExtension
{
    public const int MARKER_LENGTH = 16;

    public function __construct(
        readonly private Security $security,
        readonly private string $kernelSecret,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('viewer_marker', $this->viewerMarker(...)),
        ];
    }

    public function viewerMarker(): string
    {
        $user = $this->security->getUser();

        if ($user === null) {
            return '';
        }

        return substr(hash_hmac('sha256', 'msp-viewer:' . $user->getUserIdentifier(), $this->kernelSecret), 0, self::MARKER_LENGTH);
    }
}
