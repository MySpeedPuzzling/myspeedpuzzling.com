<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Logging;

use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A personal link's token is the access right to a participant's online competition (docs/features/online-
 * competitions/README.md §5.4) - it must never reach Sentry or the logs. Replaces with REDACTED:
 * - every token in a link's path: `/api/compete/<token>`, `/<locale>/compete/<token>`, `/compete/<token>` (the link
 *   without a language, stream RP), `/soutezit/<token>` (the Czech page) - also with JSON-escaped (`\/`) or URL-encoded
 *   (`%2F`) slashes;
 * - the current request's own token wherever it appears (route parameters, query parameters of a logged statement),
 *   when the request is one of the `compete_*` routes.
 * Used by RedactOnlineTokensProcessor (Monolog) and OnlineTokenSentryScrubber (Sentry). Stateless - the current token
 * is read from the request stack each time.
 */
final readonly class OnlineTokenRedactor
{
    public const string REDACTED = '[redacted]';

    private const string SLASH = '(?:\\\\?/|%2F)';
    private const string TOKEN_CHARACTER = '[1-9A-HJ-NP-Za-km-z]';
    private const int MAX_DEPTH = 8;

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param list<string> $knownTokens tokens to redact wherever they stand, besides the current request's (tokensIn())
     */
    public function redact(string $text, array $knownTokens = []): string
    {
        if ($text === '') {
            return $text;
        }

        $token = $this->currentToken();

        foreach ($token === null ? $knownTokens : [$token, ...$knownTokens] as $literal) {
            if (str_contains($text, $literal)) {
                $text = str_replace($literal, self::REDACTED, $text);
            }
        }

        if (stripos($text, 'compete') === false && stripos($text, 'soutezit') === false) {
            return $text;
        }

        return (string) preg_replace(self::pattern(), '$1' . self::REDACTED, $text);
    }

    /**
     * Every text in an array (keys stay), recursively - objects and other values as they are
     *
     * @param list<string> $knownTokens
     */
    public function redactValue(mixed $value, array $knownTokens = [], int $depth = 0): mixed
    {
        if (is_string($value)) {
            return $this->redact($value, $knownTokens);
        }

        if (!is_array($value) || $depth >= self::MAX_DEPTH) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->redactValue($item, $knownTokens, $depth + 1);
        }

        return $value;
    }

    public function containsToken(string $text): bool
    {
        return $this->redact($text) !== $text;
    }

    /**
     * An exception's message and its previous ones' - rewritten in place: the same object, so its class (the log level
     * and Sentry's ignore rules go by class), code, trace and headers stay; only the text loses the token. The router's
     * 404 / 405 carry the whole URI ("No route found for "GET …/api/compete/<token>/…""), a lookup's error the token.
     *
     * @param list<string> $knownTokens
     */
    public function redactThrowable(\Throwable $throwable, array $knownTokens = []): void
    {
        foreach (self::chain($throwable) as $link) {
            $message = $link->getMessage();
            $redacted = $this->redact($message, $knownTokens);

            if ($redacted !== $message) {
                (new \ReflectionProperty($link instanceof \Error ? \Error::class : \Exception::class, 'message'))->setValue($link, $redacted);
            }
        }
    }

    /**
     * The tokens in a link's paths in the texts of a value (a string, the strings of an array, an exception's messages -
     * other objects are not looked into) - to redact them wherever else they stand
     *
     * @return list<string>
     */
    public function tokensIn(mixed $value, int $depth = 0): array
    {
        if ($value instanceof \Throwable) {
            return $this->tokensIn(array_map(static fn (\Throwable $link): string => $link->getMessage(), self::chain($value)), $depth + 1);
        }

        if (is_string($value)) {
            if ((stripos($value, 'compete') === false && stripos($value, 'soutezit') === false) || preg_match_all(self::pattern(), $value, $matches) === false) {
                return [];
            }

            return array_values(array_unique(array_filter($matches[2], OnlineToken::looksValid(...))));
        }

        if (!is_array($value) || $depth >= self::MAX_DEPTH) {
            return [];
        }

        $tokens = [];

        foreach ($value as $item) {
            foreach ($this->tokensIn($item, $depth + 1) as $token) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * The token of the request being handled, when it is one of a personal link's routes
     */
    private function currentToken(): null|string
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return null;
        }

        $route = $request->attributes->get('_route');
        $token = $request->attributes->get('token');

        if (!is_string($route) || !str_starts_with($route, 'compete_') || !is_string($token) || OnlineToken::looksValid($token) === false) {
            return null;
        }

        return $token;
    }

    /**
     * @return list<\Throwable> the exception and its previous ones
     */
    private static function chain(\Throwable $throwable): array
    {
        $chain = [];

        for ($link = $throwable; $link !== null && count($chain) < self::MAX_DEPTH; $link = $link->getPrevious()) {
            $chain[] = $link;
        }

        return $chain;
    }

    private static function pattern(): string
    {
        $slash = self::SLASH;
        $character = self::TOKEN_CHARACTER;

        return '~((?:(?:' . $slash . '(?:api|cs|de|en|es|fr|ja))?' . $slash . 'compete|' . $slash . 'soutezit)' . $slash . ')(' . $character . '{' . OnlineToken::LENGTH . '})(?!' . $character . ')~i';
    }
}
