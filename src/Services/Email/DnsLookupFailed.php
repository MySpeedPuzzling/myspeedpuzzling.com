<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Email;

/**
 * Internal to EmailDomainMailCheck: aborts the cache callback so a failed lookup is not stored
 */
final class DnsLookupFailed extends \RuntimeException
{
}
