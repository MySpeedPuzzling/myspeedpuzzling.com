<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The surfaces of the navigation before the redesign (docs/features/navigation README §8, LAUNCH-02) - the order
 * "Where did it go?" lists them in. The values are the surfaces of the P0-02 baseline
 * (docs/features/navigation/delivery/baseline-chrome-routes.json; the account dropdown is its `account_dropdown`).
 */
enum FormerSurface: string
{
    // The thin band above the header: the admin key, Send feedback, Membership, Feature requests, the language
    case Topbar = 'topbar';
    // The key menu in that band (moderators and admins)
    case AdminKey = 'admin_key';
    // The header's icons: logo, search, sign in / register, Add, notifications, messages
    case Toolbar = 'toolbar';
    // "My profile ▾" in the header
    case AccountMenu = 'account_dropdown';
    // The header's main menu (☰ on phones, the row of links on a computer)
    case Hamburger = 'hamburger';
    case Footer = 'footer';

    public function titleKey(): string
    {
        return 'where_did_it_go.surface.' . $this->value;
    }
}
