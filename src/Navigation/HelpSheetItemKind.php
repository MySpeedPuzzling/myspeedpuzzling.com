<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * What a Help sheet item is (README §4.5, HELP-02): how help/_content.html.twig renders it.
 */
enum HelpSheetItemKind: string
{
    // The feedback box (signed-in players, HELP-01b)
    case Feedback = 'feedback';
    // "Sign in to send feedback" (guests, HELP-03)
    case SignInToSend = 'sign_in';
    // "Suggest a correction to this puzzle" (puzzle pages, first)
    case Correction = 'correction';
    // A registry link (Ideas & feature requests, FAQ, …)
    case Link = 'link';
    // The underlying page in the other five languages
    case Language = 'language';
    // "Reload page" (installed app only - rendered with standalone-only)
    case Reload = 'reload';
}
