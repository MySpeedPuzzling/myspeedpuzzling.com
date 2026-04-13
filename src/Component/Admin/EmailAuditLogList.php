<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Admin;

use SpeedPuzzling\Web\Query\GetEmailAuditLogs;
use SpeedPuzzling\Web\Results\EmailAuditLogOverview;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class EmailAuditLogList
{
    use DefaultActionTrait;

    private const int PER_PAGE = 50;

    #[LiveProp(writable: true, url: true)]
    public string $recipient = '';

    #[LiveProp(writable: true, url: true)]
    public string $status = '';

    #[LiveProp(writable: true, url: true)]
    public string $emailType = '';

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    #[LiveProp]
    public string $filterHash = '';

    /** @var null|array<EmailAuditLogOverview> */
    private null|array $cachedEntries = null;

    private null|int $cachedTotalCount = null;

    public function __construct(
        private readonly GetEmailAuditLogs $getEmailAuditLogs,
    ) {
    }

    #[PreReRender]
    public function preReRender(): void
    {
        $this->cachedEntries = null;
        $this->cachedTotalCount = null;

        $currentHash = $this->computeFilterHash();

        if ($this->filterHash !== '' && $this->filterHash !== $currentHash) {
            $this->page = 1;
        }

        $this->filterHash = $currentHash;
    }

    /**
     * @return array<EmailAuditLogOverview>
     */
    public function getEntries(): array
    {
        if ($this->cachedEntries !== null) {
            return $this->cachedEntries;
        }

        $offset = (max(1, $this->page) - 1) * self::PER_PAGE;

        $this->cachedEntries = $this->getEmailAuditLogs->list(
            limit: self::PER_PAGE,
            offset: $offset,
            recipient: $this->recipient !== '' ? $this->recipient : null,
            status: $this->status !== '' ? $this->status : null,
            emailType: $this->emailType !== '' ? $this->emailType : null,
        );

        return $this->cachedEntries;
    }

    public function getTotalCount(): int
    {
        if ($this->cachedTotalCount !== null) {
            return $this->cachedTotalCount;
        }

        $this->cachedTotalCount = $this->getEmailAuditLogs->count(
            recipient: $this->recipient !== '' ? $this->recipient : null,
            status: $this->status !== '' ? $this->status : null,
            emailType: $this->emailType !== '' ? $this->emailType : null,
        );

        return $this->cachedTotalCount;
    }

    public function getTotalPages(): int
    {
        return max(1, (int) ceil($this->getTotalCount() / self::PER_PAGE));
    }

    /**
     * @return array{sent: int, failed: int, all: int}
     */
    public function getStatusCounts(): array
    {
        return $this->getEmailAuditLogs->countByStatus();
    }

    /**
     * @return array<string>
     */
    public function getEmailTypes(): array
    {
        return $this->getEmailAuditLogs->distinctEmailTypes();
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, min($page, $this->getTotalPages()));
    }

    #[LiveAction]
    public function filterByStatus(#[LiveArg] string $status): void
    {
        $this->status = $status;
        $this->page = 1;
    }

    private function computeFilterHash(): string
    {
        return md5(serialize([$this->recipient, $this->status, $this->emailType]));
    }
}
