<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

/**
 * An answer of the import's screens that is shown in place (docs/features/data-import/pairing-screens.md "Answers in
 * place"): the form's controller answers with Turbo Streams - the card, the pile or the screen as it stands after the
 * answer - instead of the redirect that loads the page again and lands at its top.
 *
 * Asked for by the request itself: Turbo names its stream type first in `Accept` on every form it sends, and so does
 * the import's own script (data_import_answers_controller.js). A plain browser post never does - it gets the
 * redirect, as before.
 */
final class ImportInPlace
{
    /** The route of the screen a part is rendered again for - `_flow_footer.html.twig` links the guide by it */
    public const string SCREEN_ROUTE = '_import_screen_route';
    /** The path of that screen - where a link of the part comes back to */
    public const string SCREEN_PATH = '_import_screen_path';

    public static function asked(Request $request): bool
    {
        return $request->getPreferredFormat() === TurboBundle::STREAM_FORMAT;
    }

    /**
     * The answer is a Turbo Stream, and the parts in it belong to this screen - not to the address the form was
     * posted to.
     */
    public static function answerFor(Request $request, string $screenRoute, string $screenPath): void
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
        $request->attributes->set(self::SCREEN_ROUTE, $screenRoute);
        $request->attributes->set(self::SCREEN_PATH, $screenPath);
    }
}
