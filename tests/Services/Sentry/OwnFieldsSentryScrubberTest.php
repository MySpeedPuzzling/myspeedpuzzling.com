<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Sentry;

use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Stacktrace;
use Sentry\State\HubInterface;
use SpeedPuzzling\Web\Message\SetCollectionItemCustomValues;
use SpeedPuzzling\Web\Message\SetSolvingTimeCustomValues;
use SpeedPuzzling\Web\Results\CustomFieldsForm;
use SpeedPuzzling\Web\Services\Sentry\GenericObjectSerializer;
use SpeedPuzzling\Web\Services\Sentry\OwnFieldsSentryScrubber;
use SpeedPuzzling\Web\Value\CustomValues;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Nothing a player typed into their own fields goes to Sentry (docs/features/custom-fields.md "Nothing of a value
 * leaves the app"): the SDK attaches the request's body to every event, the arguments of every stack frame, and
 * prints a message object through GenericObjectSerializer.
 */
final class OwnFieldsSentryScrubberTest extends KernelTestCase
{
    private const string FIELD_ID = '019a0000-0000-7000-8000-00000000f1e1';
    private const string SECRET = 'axolotl-whispers-at-dawn';
    private const string OTHER_SECRET = 'narwhal-grumpy';

    /**
     * @return iterable<string, array{mixed, mixed}> the body as the SDK captured it, and what is sent
     */
    public static function provideBodies(): iterable
    {
        $fields = [self::FIELD_ID => self::SECRET, '019a0000-0000-7000-8000-00000000f1e2' => self::OTHER_SECRET];

        yield 'a parsed form: the add form, the values form, the item\'s edit form' => [
            ['puzzle_add_form' => ['comment' => 'A public comment'], 'time_id' => 'abc', 'my_fields' => $fields],
            ['puzzle_add_form' => ['comment' => 'A public comment'], 'time_id' => 'abc', 'my_fields' => '[withheld]'],
        ];
        yield 'a decoded JSON body, the fields deeper in it' => [
            ['form' => ['my_fields' => $fields, 'comment' => 'kept']],
            ['form' => ['my_fields' => '[withheld]', 'comment' => 'kept']],
        ];
        yield 'fields that are no list at all' => [
            ['my_fields' => self::SECRET],
            ['my_fields' => '[withheld]'],
        ];
        yield 'a raw urlencoded body' => [
            'time_id=abc&my_fields%5B' . self::FIELD_ID . '%5D=' . self::SECRET . '&x=1',
            '[withheld]',
        ];
        yield 'a raw body with the name itself encoded' => [
            'my%5Ffields[' . self::FIELD_ID . ']=' . self::SECRET,
            '[withheld]',
        ];
        yield 'a raw multipart body' => [
            "--b\r\nContent-Disposition: form-data; name=\"my_fields[" . self::FIELD_ID . "]\"\r\n\r\n" . self::SECRET . "\r\n--b--",
            '[withheld]',
        ];
        yield 'a body without the fields is sent as it is' => [
            ['edit_collection_item_comment_form' => ['comment' => 'A comment']],
            ['edit_collection_item_comment_form' => ['comment' => 'A comment']],
        ];
        yield 'a raw body without the fields is sent as it is' => ['{"a":1}', '{"a":1}'];
    }

    #[DataProvider('provideBodies')]
    public function testTheFieldsOfTheRequestBodyAreWithheld(mixed $body, mixed $expected): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://myspeedpuzzling.com/en/puzzle-add', 'method' => 'POST', 'data' => $body]);

        $sent = (new OwnFieldsSentryScrubber())->scrub($event);

        self::assertSame(['url' => 'https://myspeedpuzzling.com/en/puzzle-add', 'method' => 'POST', 'data' => $expected], $sent->getRequest());
        $this->assertCarriesNothing((string) json_encode($sent->getRequest()));
    }

    public function testAnEventWithoutABodyIsLeftAlone(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://myspeedpuzzling.com/en/custom-fields', 'method' => 'GET']);

        self::assertSame(['url' => 'https://myspeedpuzzling.com/en/custom-fields', 'method' => 'GET'], (new OwnFieldsSentryScrubber())->scrub($event)->getRequest());
    }

    public function testTheArgumentsOfAFrameInsideTheFieldsOwnCodeAreWithheld(): void
    {
        $frames = [
            new Frame('Symfony\Component\Messenger\MessageBus::dispatch', 'MessageBus.php', 69, null, null, ['message' => 'Object SetSolvingTimeCustomValues']),
            new Frame('SpeedPuzzling\Web\Services\CustomFields\SubmittedValuesChange::apply', 'SubmittedValuesChange.php', 54, null, null, ['submitted' => [self::FIELD_ID => self::SECRET]]),
            new Frame('SpeedPuzzling\Web\Value\CustomValues::normalise', 'CustomValues.php', 120, null, null, ['type' => 'Object CustomFieldType', 'choices' => [self::OTHER_SECRET], 'submitted' => self::SECRET]),
        ];
        $exception = new ExceptionDataBag(new \TypeError('Unsupported operand types'), new Stacktrace($frames));
        $event = Event::createEvent();
        $event->setExceptions([$exception]);
        $event->setStacktrace(new Stacktrace([new Frame('SpeedPuzzling\Web\Entity\SolvingTimeCustomValues::change', 'SolvingTimeCustomValues.php', 60, null, null, ['values' => self::SECRET])]));

        $sent = (new OwnFieldsSentryScrubber())->scrub($event);

        $sentFrames = $sent->getExceptions()[0]->getStacktrace()?->getFrames() ?? [];
        self::assertCount(3, $sentFrames, 'The frames stay - only what they were called with goes');
        self::assertSame(['message' => 'Object SetSolvingTimeCustomValues'], $sentFrames[0]->getVars(), 'A frame of other code is left alone');
        self::assertSame(['submitted' => '[withheld]'], $sentFrames[1]->getVars());
        self::assertSame(['type' => '[withheld]', 'choices' => '[withheld]', 'submitted' => '[withheld]'], $sentFrames[2]->getVars());
        self::assertSame(['values' => '[withheld]'], $sent->getStacktrace()?->getFrames()[0]->getVars());
    }

    public function testAMessageThatCarriesValuesIsPrintedWithoutThem(): void
    {
        $serializer = new GenericObjectSerializer();
        $values = [self::FIELD_ID => self::SECRET, 'another' => [self::OTHER_SECRET]];

        $ofResult = $serializer(new SetSolvingTimeCustomValues('time-id', 'player-id', $values));
        self::assertSame(['solvingTimeId' => 'time-id', 'playerId' => 'player-id', 'values' => '[REDACTED]'], $ofResult);

        $ofItem = $serializer(new SetCollectionItemCustomValues('item-id', 'player-id', $values));
        self::assertSame(['collectionItemId' => 'item-id', 'playerId' => 'player-id', 'values' => '[REDACTED]'], $ofItem);

        // The objects that hold values, nested in whatever is printed
        $holder = new class (new CustomValues($values), new CustomFieldsForm([], [self::FIELD_ID => self::SECRET])) {
            public function __construct(
                public CustomValues $stored,
                public CustomFieldsForm $form,
            ) {
            }
        };
        $this->assertCarriesNothing((string) json_encode([$ofResult, $ofItem, $serializer($holder)]));
    }

    /**
     * Wired in, not only written: the client's own `before_send` and its serializer of objects.
     */
    public function testTheClientSendsNothingOfTheFields(): void
    {
        self::bootKernel();
        $options = self::getContainer()->get(HubInterface::class)->getClient()?->getOptions();
        self::assertNotNull($options);

        foreach (
            [
                [Event::createEvent(), $options->getBeforeSendCallback()],
                [Event::createTransaction(), $options->getBeforeSendTransactionCallback()],
            ] as [$event, $callback]
        ) {
            $event->setRequest(['url' => 'https://myspeedpuzzling.com/en/puzzle-add', 'data' => ['time_id' => 'abc', 'my_fields' => [self::FIELD_ID => self::SECRET]]]);
            $sent = $callback($event, null);

            self::assertInstanceOf(Event::class, $sent);
            self::assertSame(['time_id' => 'abc', 'my_fields' => '[withheld]'], $sent->getRequest()['data'] ?? null);
        }

        // A message in a frame's arguments or in a context is printed by the client's serializer
        $serializer = new RepresentationSerializer($options);

        foreach (
            [
                new SetSolvingTimeCustomValues('time-id', 'player-id', [self::FIELD_ID => self::SECRET]),
                new SetCollectionItemCustomValues('item-id', 'player-id', [self::FIELD_ID => self::SECRET]),
            ] as $message
        ) {
            $printed = (string) json_encode($serializer->representationSerialize($message));

            self::assertStringContainsString('player-id', $printed, 'The message is printed - without its values');
            $this->assertCarriesNothing($printed);
        }
    }

    private function assertCarriesNothing(string $sent): void
    {
        self::assertNotSame('', $sent);

        foreach ([self::SECRET, self::OTHER_SECRET, self::FIELD_ID] as $private) {
            self::assertStringNotContainsString($private, $sent);
        }
    }
}
