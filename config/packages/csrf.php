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
                // "Yes, subscribe me" on the page the confirmation e-mail's link opens, always anonymous
                'newsletter-confirm',
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
                // The round stopwatch's forms (AbstractRoundStopwatchController): its control page and the public
                // projector page stay open for hours on a venue laptop, the Start button on the projector too
                'round_stopwatch',
                // The follow star on the events page (docs/features/events-page/README.md, "Follow"): it sits on a page
                // every player opens, on every row - a session-backed token would write the session on each view
                'event_follow',
                // Selecting several puzzles on a collection page (docs/features/collections/bulk-actions.md): the bar's
                // token is rendered on every collection page a member opens
                'collection_selection',
                // The × hiding a team in the co-puzzler picker (ArchiveCoPuzzlerTeamController): rendered on every
                // add-time form
                'copuzzler_archive',
                // "Connect to my profile" on a personal link of an online competition (CompeteConnectController): the
                // participant page is session-free for everyone, and a phone keeps it open for a whole round
                'compete_connect',
                // "Duplicate" of an event (DuplicateCompetitionController): in every ⋯ menu, on "You organize" and the
                // edit page
                'competition_duplicate',
                // Partner finder (docs/features/partner-finder.md): "List me" / "Found my partner" and "I'd like to puzzle
                // with you" sit on every row of a finder page and on the event pages
                'partner_listing',
                'partner_request',
                // "Ask to borrow" (docs/features/wishlist-availability.md): the wishlist's selection bar, the ask sheet, the
                // answer page and the withdraw buttons
                'borrow_request',
                // Sell/swap requests (docs/features/marketplace/12-listing-requests.md): the buyer's selection bar on
                // sell/swap lists and the marketplace, the request sheet, the answer page and the withdraw buttons
                'listing_selection',
                'listing_request',
                // Background photo uploads (PhotoUploadStartController): the add-time form's photos, sent as the
                // X-CSRF-Token header - the form is rendered on every add/edit page and kept open while it is filled in
                'photo_upload',
            ],
        ],
    ],
]);
