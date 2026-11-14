<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sentry;

use Sentry\Event;
use Sentry\Stacktrace;
use SpeedPuzzling\Web\Results\CustomFieldsForm;

/**
 * Nothing a player typed into their own fields goes to Sentry (docs/features/custom-fields.md "Nothing of a value
 * leaves the app"). The SDK attaches the request's body to every event (`send_default_pii`, RequestIntegration) - so a
 * warning or an exception during any POST that carries `my_fields[…]` (the add-result form, the values form of a
 * result, the collection item's edit form) would send what was typed as `request.data`.
 *
 * - **The body:** the `my_fields` entry is replaced by "[withheld]" whatever the SDK made of the body - the parsed
 *   form or JSON (an array, at any depth) or the raw text (then the whole body is withheld: there is no telling where
 *   a value ends in every encoding).
 * - **Stack frames:** the arguments of a frame inside the custom fields' own code (`CustomValues::normalise()`,
 *   `SubmittedValuesChange::apply()`, …) are the values themselves - withheld, the frame stays.
 *
 * The messages that carry values are kept out by GenericObjectSerializer. Runs in `before_send` and
 * `before_send_transaction` after OnlineTokenSentryScrubber (SentryBeforeSend).
 */
final readonly class OwnFieldsSentryScrubber
{
    public const string WITHHELD = '[withheld]';

    /** Functions whose arguments are a player's values, by a part of their class name */
    private const string OWN_FIELDS_CODE = '/Custom(?:Value|Field|Columns)|SubmittedValuesChange/';

    public function scrub(Event $event): Event
    {
        $request = $event->getRequest();

        if (array_key_exists('data', $request)) {
            $request['data'] = self::withoutOwnFields($request['data']);
            $event->setRequest($request);
        }

        foreach ($event->getExceptions() as $exception) {
            self::scrubFrames($exception->getStacktrace());
        }

        self::scrubFrames($event->getStacktrace());

        return $event;
    }

    private static function withoutOwnFields(mixed $body): mixed
    {
        if (is_array($body)) {
            foreach ($body as $key => $value) {
                $body[$key] = $key === CustomFieldsForm::INPUT ? self::WITHHELD : self::withoutOwnFields($value);
            }

            return $body;
        }

        // A raw body (a content type PHP does not parse, a broken one): withheld as a whole
        if (is_string($body) && stripos(rawurldecode($body), CustomFieldsForm::INPUT) !== false) {
            return self::WITHHELD;
        }

        return $body;
    }

    private static function scrubFrames(null|Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $function = $frame->getRawFunctionName() ?? $frame->getFunctionName();

            if ($function === null || $frame->getVars() === [] || preg_match(self::OWN_FIELDS_CODE, $function) !== 1) {
                continue;
            }

            $frame->setVars(array_fill_keys(array_keys($frame->getVars()), self::WITHHELD));
        }
    }
}
