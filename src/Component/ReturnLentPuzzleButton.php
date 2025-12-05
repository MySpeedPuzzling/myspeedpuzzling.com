<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Simple Twig Component for return puzzle button.
 * Renders a form that submits to ReturnPuzzleController.
 */
#[AsTwigComponent]
final class ReturnLentPuzzleButton extends AbstractController
{
    public string $lentPuzzleId = '';
}
