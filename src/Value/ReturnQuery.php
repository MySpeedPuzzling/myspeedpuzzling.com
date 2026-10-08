<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\HttpFoundation\Request;

/**
 * `?return=` and `?return_title=` passed on by a redirect (docs/features/return-url.md) - e.g. a retired page sending
 * its visitors to the page that replaced it: the address only when ReturnUrl proves it safe, the title only with it.
 */
final readonly class ReturnQuery
{
    /**
     * @return array{}|array{return: string, return_title?: string} query parameters to merge into the redirect's route
     */
    public static function from(Request $request): array
    {
        $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'));

        if ($returnUrl === null) {
            return [];
        }

        $query = ['return' => $returnUrl->path];
        $returnTitle = trim($request->query->getString('return_title'));

        if ($returnTitle !== '') {
            $query['return_title'] = $returnTitle;
        }

        return $query;
    }
}
