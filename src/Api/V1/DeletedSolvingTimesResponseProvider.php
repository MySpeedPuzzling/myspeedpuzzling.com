<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDeletedSolvingTimes;
use SpeedPuzzling\Web\Results\DeletedSolvingTime;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\SolvingTimeSyncRateLimit;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * docs/features/api/solving-time-sync.md - the cursor is the last row of the page (deletion moment + id), opaque to
 * clients: base64url of "Y-m-d H:i:s|uuid".
 *
 * @implements ProviderInterface<DeletedSolvingTimesResponse>
 */
final readonly class DeletedSolvingTimesResponseProvider implements ProviderInterface
{
    private const int PAGE_SIZE = 1000;
    private const string CURSOR_DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private GetDeletedSolvingTimes $getDeletedSolvingTimes,
        private SolvingTimeSyncRateLimit $rateLimit,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DeletedSolvingTimesResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $request = $this->requestStack->getCurrentRequest();
        $since = self::since($request?->query->getString('since') ?? '');
        $after = self::cursor($request?->query->getString('cursor') ?? '');

        // After validation: a 422 never consumes the budget
        $this->rateLimit->consume();

        $rows = $this->getDeletedSolvingTimes->forPlayer(
            $user->getPlayer()->id->toString(),
            $since,
            $after,
            self::PAGE_SIZE,
        );

        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $last = $rows[array_key_last($rows) ?? 0] ?? null;

        return new DeletedSolvingTimesResponse(
            count: count($rows),
            deleted: array_map(
                static fn (DeletedSolvingTime $row): DeletedSolvingTimeResponse => new DeletedSolvingTimeResponse(
                    timeId: $row->timeId,
                    deletedAt: $row->deletedAt->format('c'),
                ),
                $rows,
            ),
            nextCursor: $hasMore && $last !== null ? self::encodeCursor($last) : null,
        );
    }

    private static function since(string $value): null|DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            // Stored in UTC: an offset keeps its instant
            return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw self::violation('since', $value, 'since must be an ISO 8601 date and time.');
        }
    }

    private static function cursor(string $value): null|DeletedSolvingTime
    {
        if ($value === '') {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        [$deletedAtText, $timeId] = is_string($decoded) && substr_count($decoded, '|') === 1
            ? explode('|', $decoded)
            : ['', ''];
        $deletedAt = DateTimeImmutable::createFromFormat('!' . self::CURSOR_DATE_FORMAT, $deletedAtText, new DateTimeZone('UTC'));

        if ($deletedAt === false || Uuid::isValid($timeId) === false) {
            throw self::violation('cursor', $value, 'cursor must be a next_cursor of this endpoint.');
        }

        return new DeletedSolvingTime(strtolower($timeId), $deletedAt);
    }

    private static function encodeCursor(DeletedSolvingTime $last): string
    {
        $raw = $last->deletedAt->setTimezone(new DateTimeZone('UTC'))->format(self::CURSOR_DATE_FORMAT) . '|' . $last->timeId;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function violation(string $parameter, string $value, string $message): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, null, [], null, $parameter, $value),
        ]));
    }
}
