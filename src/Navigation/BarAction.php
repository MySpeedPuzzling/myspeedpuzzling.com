<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The top-bar tools (D2): the value of data-bar-action, rendered through the Twig function bar_action() so a typo
 * fails at render (NAV-14: the native app's path configuration matches these values).
 */
enum BarAction: string
{
    case Search = 'search';
    case Help = 'help';
    case Inbox = 'inbox';
    case Menu = 'menu';
}
