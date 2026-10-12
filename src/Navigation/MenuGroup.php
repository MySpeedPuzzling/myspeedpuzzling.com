<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The Menu's groups in their fixed orders (navigation README §4.12 for the phone panel, §4.3 for the docked column).
 * A null order = the group does not exist on that surface: the phone panel has no Home group (Home is a tab), the
 * docked column has no Inbox row (top bar) and no Log out (the avatar ▾ menu).
 */
enum MenuGroup: string
{
    case Home = 'home';
    case You = 'you';
    case Inbox = 'inbox';
    case Roles = 'roles';
    case Puzzling = 'puzzling';
    case Library = 'library';
    case Community = 'community';
    case Puzzles = 'puzzles';
    case Add = 'add';
    case Account = 'account';
    case Help = 'help';
    case About = 'about';
    case LogOut = 'logout';

    public function order(MenuSurface $surface): null|int
    {
        if ($surface === MenuSurface::Phone) {
            return match ($this) {
                self::Home => null,
                self::You => 10,
                self::Inbox => 20,
                self::Roles => 30,
                self::Puzzling => 40,
                self::Library => 50,
                self::Community => 60,
                self::Puzzles => 70,
                self::Add => 80,
                self::Account => 90,
                self::Help => 100,
                self::About => 110,
                self::LogOut => 120,
            };
        }

        return match ($this) {
            self::Home => 10,
            self::Puzzles => 20,
            self::Community => 30,
            // The guest card follows the places in the column: docking hides the rail's Sign in (decision 31)
            self::You => 35,
            self::Roles => 40,
            self::Puzzling => 50,
            self::Library => 60,
            self::Add => 70,
            self::Account => 80,
            self::Help => 90,
            self::About => 100,
            self::Inbox, self::LogOut => null,
        };
    }

    /**
     * The heading's translation key - created with the Menu (MENU-01); until then a consumer falls back to docTitle().
     */
    public function titleKey(): string
    {
        return 'nav.group.' . $this->value;
    }

    /**
     * English heading for the generated mirror (navigation.md) and the fallback of menuPath() - never shown to players.
     */
    public function docTitle(): string
    {
        return match ($this) {
            self::Home => 'Home',
            self::You => 'You',
            self::Inbox => 'Inbox',
            self::Roles => 'Your roles',
            self::Puzzling => 'Your puzzling',
            self::Library => 'Your puzzles',
            self::Community => 'Community',
            self::Puzzles => 'Puzzles',
            self::Add => 'Add & track',
            self::Account => 'Account',
            self::Help => 'Help & learn',
            self::About => 'About',
            self::LogOut => 'Log out',
        };
    }

    /**
     * Groups whose rows are shown without a heading (the You card, the Inbox row, Log out).
     */
    public function hasHeading(): bool
    {
        return !in_array($this, [self::You, self::Inbox, self::LogOut], true);
    }
}
