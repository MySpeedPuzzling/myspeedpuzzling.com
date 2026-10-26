<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * One clock (docs/features/time-and-clock/README.md R1): code reads "now" only through the injected
 * Psr\Clock\ClockInterface, so a test can freeze it. This rule reports every PHP call that reads the system clock:
 * new DateTime(Immutable)() / DatePoint / MockClock without a moment or with a relative one ('now', '-3 days',
 * 'tomorrow', '12:00' …), time(), date()/gmdate()/idate() without a timestamp, getdate()/localtime() without one,
 * mktime()/gmmktime() missing a part, strtotime() without a base (unless its value is an absolute date),
 * date_create(_immutable)() without a moment or with a relative one, gettimeofday(), new NativeClock(), Clock::get().
 * microtime()/hrtime() measure durations and are left to SystemClockCoverageTest.
 *
 * @implements Rule<CallLike>
 */
final readonly class SystemClockRule implements Rule
{
    public const string IDENTIFIER = 'clock.systemTime';

    public const string MESSAGE = '%s reads the system clock. Inject Psr\Clock\ClockInterface and use ->now() (an entity takes the moment from its caller, SQL binds :now); in tests use TestClock::now() (->modify(…)) and TestClock::SQL_NOW. A deliberate real-time read carries // @phpstan-ignore clock.systemTime (the reason). See docs/features/time-and-clock/README.md.';

    /**
     * Functions reading the clock unless they get at least this many arguments (PHP_INT_MAX = always)
     */
    private const array FUNCTIONS = [
        'time' => PHP_INT_MAX,
        'gettimeofday' => PHP_INT_MAX,
        'date' => 2,
        'gmdate' => 2,
        'idate' => 2,
        'getdate' => 1,
        'localtime' => 1,
        'mktime' => 6,
        'gmmktime' => 6,
    ];

    /** Constructors taking a moment string that defaults to the current time */
    private const array MOMENT_CONSTRUCTORS = [
        'DateTime',
        'DateTimeImmutable',
        'Symfony\Component\Clock\DatePoint',
        'Symfony\Component\Clock\MockClock',
    ];

    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable()) {
            return [];
        }

        $args = $node->getArgs();

        foreach ($args as $arg) {
            if ($arg->unpack) {
                return [];
            }
        }

        $read = match (true) {
            $node instanceof FuncCall => $this->functionRead($node, $args, $scope),
            $node instanceof New_ => $this->constructorRead($node, $args, $scope),
            $node instanceof StaticCall => $this->staticCallRead($node, $scope),
            default => null,
        };

        if ($read === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(self::MESSAGE, $read))
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
    }

    /**
     * Whether a moment string is counted from the current time ('now', 'today', '-3 days', '12:00', 'next monday',
     * 'october 14', '' …) - parsed against two different midnights, an absolute date ('2026-10-14 12:00:00',
     * '@1700000000', 'first monday of october 2026') gives the same instant both times.
     */
    public static function isRelative(string $moment): bool
    {
        if (trim($moment) === '') {
            return true;
        }

        try {
            $one = new \DateTimeImmutable('@0')->modify($moment);
            $two = new \DateTimeImmutable('@946684800')->modify($moment);
        } catch (\Throwable) {
            // Not a moment at all - it throws at runtime, no clock is read
            return false;
        }

        return $one->format('U.u e') !== $two->format('U.u e');
    }

    /**
     * @param array<Arg> $args
     */
    private function functionRead(FuncCall $node, array $args, Scope $scope): null|string
    {
        if (!$node->name instanceof Name) {
            return null;
        }

        $name = strtolower($this->reflectionProvider->resolveFunctionName($node->name, $scope) ?? $node->name->toString());
        $read = $name . '()';

        if (array_key_exists($name, self::FUNCTIONS)) {
            return count($args) < self::FUNCTIONS[$name] ? $read : null;
        }

        if ($name === 'strtotime') {
            // With a base timestamp it counts from the given value; without one only an absolute date reads no clock
            $moment = array_values($args)[0] ?? null;

            return count($args) === 1 && $moment !== null && $this->isAbsolute($moment, $scope) === false ? $read : null;
        }

        if ($name === 'date_create' || $name === 'date_create_immutable') {
            return $this->readsNow($this->momentArgument($args, 'datetime'), $scope) ? $read : null;
        }

        return null;
    }

    /**
     * @param array<Arg> $args
     */
    private function constructorRead(New_ $node, array $args, Scope $scope): null|string
    {
        if (!$node->class instanceof Name) {
            return null;
        }

        $className = $scope->resolveName($node->class);

        if ($this->reflectionProvider->hasClass($className) === false) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($className);
        $read = 'new ' . $class->getName() . '()';

        if ($class->getName() === 'Symfony\Component\Clock\NativeClock') {
            return $read;
        }

        $parameter = $this->momentParameter($class);

        if ($parameter === null) {
            return null;
        }

        return $this->readsNow($this->momentArgument($args, $parameter), $scope) ? $read : null;
    }

    private function staticCallRead(StaticCall $node, Scope $scope): null|string
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier || $node->name->toLowerString() !== 'get') {
            return null;
        }

        return $scope->resolveName($node->class) === 'Symfony\Component\Clock\Clock' ? 'Clock::get()' : null;
    }

    /**
     * The name of the constructor parameter holding the moment, when the class takes one that defaults to now
     * (DateTime, DateTimeImmutable, DatePoint, MockClock and subclasses keeping their constructor)
     */
    private function momentParameter(ClassReflection $class): null|string
    {
        if ($class->hasConstructor() === false) {
            return null;
        }

        $constructor = $class->getConstructor();

        if (in_array($constructor->getDeclaringClass()->getName(), self::MOMENT_CONSTRUCTORS, true) === false) {
            return null;
        }

        $variants = $constructor->getVariants();
        $parameters = isset($variants[0]) ? $variants[0]->getParameters() : [];

        return isset($parameters[0]) ? $parameters[0]->getName() : null;
    }

    /**
     * The argument given for the moment parameter (first positional or named), null = left out
     *
     * @param array<Arg> $args
     */
    private function momentArgument(array $args, string $parameter): null|Arg
    {
        foreach ($args as $position => $arg) {
            if ($arg->name === null ? $position === 0 : $arg->name->toString() === $parameter) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * Left out = now; a constant relative moment = now; anything else (a variable, an absolute date, a DateTime) is not
     * reported - the value is given
     */
    private function readsNow(null|Arg $argument, Scope $scope): bool
    {
        if ($argument === null) {
            return true;
        }

        foreach ($scope->getType($argument->value)->getConstantStrings() as $constant) {
            if (self::isRelative($constant->getValue())) {
                return true;
            }
        }

        return false;
    }

    private function isAbsolute(Arg $argument, Scope $scope): bool
    {
        $constants = $scope->getType($argument->value)->getConstantStrings();

        if ($constants === []) {
            return false;
        }

        foreach ($constants as $constant) {
            if (self::isRelative($constant->getValue())) {
                return false;
            }
        }

        return true;
    }
}
