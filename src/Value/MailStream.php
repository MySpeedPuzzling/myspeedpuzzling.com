<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The three kinds of e-mail MySpeedPuzzling sends (docs/features/mass-mailing/README.md). Each one is a mailer
 * transport of its own and, on Amazon SES, a configuration set of the same name - events coming back say which
 * stream a message belonged to. The newsletter is sent by Listmonk, never by the app.
 */
enum MailStream: string
{
    case Transactional = 'transactional';
    case Notifications = 'notifications';
    case Newsletter = 'newsletter';

    /**
     * The stream of an app message, read from its X-Transport header (no header = the default transport)
     */
    public static function fromTransportName(null|string $transportName): self
    {
        return $transportName === self::Notifications->value ? self::Notifications : self::Transactional;
    }

    public static function fromConfigurationSet(null|string $configurationSet): null|self
    {
        return $configurationSet === null ? null : self::tryFrom($configurationSet);
    }

    public function configurationSet(): string
    {
        return $this->value;
    }
}
