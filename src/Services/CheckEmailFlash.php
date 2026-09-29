<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Hands the address a mail was just sent to from the request form's POST to
 * its "Check your email" screen (auth UX redesign §4.4/§4.5) - through the
 * flash bag, so it never sits in a URL, the browser history or an access log.
 *
 * The flash types are not among the ones base.html.twig renders, so the entry
 * is never shown as a message. Writing it starts a session on the POST - the
 * same as the success flash these forms always set; the GET screen only reads
 * a session that already exists.
 */
final class CheckEmailFlash
{
    public const string SIGN_IN_LINK = 'auth_sign_in_link_sent';
    public const string PASSWORD_RESET = 'auth_password_reset_sent';

    public static function add(Request $request, string $type, string $email, bool $resent): void
    {
        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, ['email' => $email, 'resent' => $resent]);
        }
    }

    /**
     * @return null|array{email: string, resent: bool}
     */
    public static function take(Request $request, string $type): null|array
    {
        // A visitor without a session has no flash either, and asking would start
        // a session on an anonymous GET (#164)
        if (!$request->hasPreviousSession()) {
            return null;
        }

        $session = $request->getSession();

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return null;
        }

        $sent = null;

        // The last one wins - an earlier unread one belongs to an abandoned tab
        foreach ($session->getFlashBag()->get($type) as $flash) {
            if (is_array($flash) && is_string($flash['email'] ?? null) && $flash['email'] !== '') {
                $sent = [
                    'email' => $flash['email'],
                    'resent' => ($flash['resent'] ?? false) === true,
                ];
            }
        }

        return $sent;
    }
}
