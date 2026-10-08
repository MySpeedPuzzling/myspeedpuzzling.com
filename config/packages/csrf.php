<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

// Enable stateless CSRF protection for forms and logins/logouts
return App::config([
    'framework' => [
        'form' => [
            'csrf_protection' => [
                'token_id' => 'submit',
            ],
        ],
        'csrf_protection' => [
            'stateless_token_ids' => [
                'submit',
                'authenticate',
                'logout',
                // Sign-in link request form: rendered on anonymous pages that must stay
                // session-free (#164), so its token id must not fall back to the
                // session-backed manager
                'sign_in_link',
                // The 6-digit code form on the "Check your email" screen - stateless
                // like the request form it follows; the token must not depend on
                // the session that SignInCodePending keeps
                'sign_in_code',
                // Same reason: the "forgot password" form is rendered for anonymous
                // visitors, and a session-backed token would put a cookie on the page
                'request_password_reset',
                // The newsletter signup form sits in the footer of EVERY anonymous
                // page - a session-backed token would kill shared caching site-wide
                'newsletter-subscribe',
                // Unsubscribe landing page is reached from e-mail links, always
                // anonymous
                'newsletter-unsubscribe',
                // E-mail preferences page is reached from e-mail links, always
                // anonymous (token-authenticated)
                'email-preferences',
                // "Delete my account" last-chance page is reached from an e-mail link,
                // possibly anonymous, and must stay session-free (#164)
                'confirm_account_deletion',
                // The "start my free trial" form sits in the layout of every page an eligible
                // player opens (members modal, offer modal) - a session-backed token would
                // start a session on each of them (#164)
                'start_free_trial',
                // The footer's "Turn it on" form for players who switched the newsletter off sits
                // on every page they open - a session-backed token would write the session each time
                'newsletter-turn-on',
                // "Add to / Remove from comparison" sit in the player header and on the pair/team page,
                // pages a player opens all the time - a session-backed token would write the session on each
                'comparison_add',
                'comparison_remove',
                // Marketplace at events (docs/features/marketplace/11-events.md): the picker's Save and the "Bring to
                // event" menu, which sits on every conversation page a seller opens
                'event_offers_picker',
                'sell_swap_bring_to_event',
                // Official results JSON endpoints (OfficialResultsApi): the organiser devices send it as the
                // X-CSRF-Token header, often long after the page was loaded and from a page kept open offline
                'official_results',
                // The follow star on the events page (docs/features/events-page/README.md, "Follow"): it sits on a page
                // every player opens, on every row - a session-backed token would write the session on each view
                'event_follow',
            ],
        ],
    ],
]);
