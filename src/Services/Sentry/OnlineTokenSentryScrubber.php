<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sentry;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Stacktrace;
use SpeedPuzzling\Web\Services\Logging\OnlineTokenRedactor;

/**
 * No personal link's token in what goes to Sentry (OnlineTokenRedactor) - `send_default_pii` sends the request's URL,
 * headers (a phone's Referer is its competition page) and body: the request, the message and its parameters, the
 * exceptions' values and their stack frames' arguments (`zend.exception_ignore_args` is off, so a frame of a
 * controller or OnlineAccess::forToken() carries the token), extra, tags, contexts, the transaction name, the
 * breadcrumbs and a transaction's spans. A token found in a link's path anywhere in the event is redacted wherever else
 * it stands too (an event captured after the response, at kernel.terminate, has no current request to tell it). Wired
 * as `before_send`, `before_send_transaction` and `before_breadcrumb` (config/packages/sentry.php, config/services.php).
 */
final readonly class OnlineTokenSentryScrubber
{
    public function __construct(
        private OnlineTokenRedactor $redactor,
    ) {
    }

    /**
     * @return \Closure(Event, null|EventHint): Event
     */
    public function beforeSend(): \Closure
    {
        return fn (Event $event, null|EventHint $hint = null): Event => $this->scrub($event);
    }

    /**
     * @return \Closure(Breadcrumb): Breadcrumb
     */
    public function beforeBreadcrumb(): \Closure
    {
        return fn (Breadcrumb $breadcrumb): Breadcrumb => $this->scrubBreadcrumb($breadcrumb);
    }

    public function scrub(Event $event): Event
    {
        $known = $this->knownTokens($event);

        /** @var array<string, mixed> $request */
        $request = $this->redactor->redactValue($event->getRequest(), $known);
        $event->setRequest($request);

        /** @var array<string, mixed> $extra */
        $extra = $this->redactor->redactValue($event->getExtra(), $known);
        $event->setExtra($extra);

        $event->setTags(array_map(fn (string $tag): string => $this->redactor->redact($tag, $known), $event->getTags()));

        foreach ($event->getContexts() as $name => $context) {
            /** @var array<string, mixed> $context */
            $context = $this->redactor->redactValue($context, $known);
            $event->setContext($name, $context);
        }

        $transaction = $event->getTransaction();

        if ($transaction !== null) {
            $event->setTransaction($this->redactor->redact($transaction, $known));
        }

        $message = $event->getMessage();

        if ($message !== null) {
            $formatted = $event->getMessageFormatted();
            /** @var array<string> $params */
            $params = $this->redactor->redactValue($event->getMessageParams(), $known);
            $event->setMessage($this->redactor->redact($message, $known), $params, $formatted === null ? null : $this->redactor->redact($formatted, $known));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($this->redactor->redact($exception->getValue(), $known));
            $this->scrubFrames($exception->getStacktrace(), $known);
        }

        $this->scrubFrames($event->getStacktrace(), $known);

        $event->setBreadcrumb(array_map(fn (Breadcrumb $breadcrumb): Breadcrumb => $this->scrubBreadcrumb($breadcrumb, $known), $event->getBreadcrumbs()));

        foreach ($event->getSpans() as $span) {
            $description = $span->getDescription();

            if ($description !== null) {
                $span->setDescription($this->redactor->redact($description, $known));
            }

            /** @var array<string, mixed> $data */
            $data = $this->redactor->redactValue($span->getData(), $known);
            $span->setData($data);
        }

        return $event;
    }

    /**
     * @param list<string> $known
     */
    public function scrubBreadcrumb(Breadcrumb $breadcrumb, array $known = []): Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage($this->redactor->redact($message, $known));
        }

        foreach ($breadcrumb->getMetadata() as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata($name, $this->redactor->redactValue($value, $known));
        }

        return $breadcrumb;
    }

    /**
     * The arguments of every frame - the function's own (`$token`) and anything that holds a link's path
     *
     * @param list<string> $known
     */
    private function scrubFrames(null|Stacktrace $stacktrace, array $known): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            /** @var array<string, mixed> $vars */
            $vars = $this->redactor->redactValue($frame->getVars(), $known);
            $frame->setVars($vars);
        }
    }

    /**
     * Every token in a link's path anywhere in the event
     *
     * @return list<string>
     */
    private function knownTokens(Event $event): array
    {
        $frameVars = [];

        foreach ([$event->getStacktrace(), ...array_map(static fn (ExceptionDataBag $exception): null|Stacktrace => $exception->getStacktrace(), $event->getExceptions())] as $stacktrace) {
            foreach ($stacktrace?->getFrames() ?? [] as $frame) {
                $frameVars[] = $frame->getVars();
            }
        }

        return $this->redactor->tokensIn([
            $event->getRequest(),
            $event->getTransaction(),
            $event->getMessage(),
            $event->getMessageFormatted(),
            $event->getExtra(),
            $event->getTags(),
            $event->getContexts(),
            array_map(static fn (ExceptionDataBag $exception): string => $exception->getValue(), $event->getExceptions()),
            array_map(static fn (Breadcrumb $breadcrumb): array => [$breadcrumb->getMessage(), $breadcrumb->getMetadata()], $event->getBreadcrumbs()),
            $frameVars,
        ]);
    }
}
