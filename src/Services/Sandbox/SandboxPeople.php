<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The made-up people of the sandbox (docs/features/sandbox/README.md "Data"). Player N always gets the same name,
 * country, language and e-mail, so the seed can be run again and adds only who is missing. The names are invented
 * combinations - nobody real is meant.
 */
final class SandboxPeople
{
    /**
     * Every generated player's e-mail is `test-user+<N>@myspeedpuzzling.com` - our own domain, so not even a mail that
     * escaped the sandbox's mail catcher could reach a stranger. The simulation picks its players by this pattern.
     */
    public const string EMAIL_DOMAIN = 'myspeedpuzzling.com';
    public const string EMAIL_PREFIX = 'test-user+';
    public const string EMAIL_LIKE = 'test-user+%@myspeedpuzzling.com';

    /**
     * The one generated account that is NOT a member (an ended grant) - to see the site as a free player.
     */
    public const string FREE_PLAYER_EMAIL = 'test-user+free@myspeedpuzzling.com';

    /**
     * country, locale, first names, last names
     *
     * @var list<array{CountryCode, string, list<string>, list<string>}>
     */
    private const array GROUPS = [
        [CountryCode::cz, 'cs', ['Jana', 'Petr', 'Lucie', 'Tomáš', 'Eva', 'Martin', 'Tereza', 'Jakub', 'Klára', 'Ondřej'], ['Nováková', 'Svoboda', 'Dvořáková', 'Černý', 'Procházková', 'Kučera', 'Veselá', 'Horák', 'Němcová', 'Marek']],
        [CountryCode::us, 'en', ['Emily', 'James', 'Olivia', 'Noah', 'Ava', 'Liam', 'Sophia', 'Mason', 'Harper', 'Ethan'], ['Parker', 'Bennett', 'Hayes', 'Brooks', 'Foster', 'Reed', 'Carter', 'Sullivan', 'Hughes', 'Price']],
        [CountryCode::gb, 'en', ['Charlotte', 'Oliver', 'Amelia', 'George', 'Isla', 'Harry', 'Poppy', 'Jack', 'Freya', 'Alfie'], ['Whitfield', 'Ashworth', 'Pemberton', 'Hollis', 'Thorne', 'Kendall', 'Barlow', 'Fairley', 'Marsh', 'Linton']],
        [CountryCode::de, 'de', ['Lena', 'Felix', 'Hannah', 'Jonas', 'Mia', 'Lukas', 'Lea', 'Paul', 'Emma', 'Leon'], ['Bergmann', 'Hoffmann', 'Krüger', 'Lindner', 'Vogel', 'Sommer', 'Brandt', 'Winter', 'Kraus', 'Seidel']],
        [CountryCode::es, 'es', ['Lucía', 'Pablo', 'Carmen', 'Diego', 'Elena', 'Javier', 'Sofía', 'Marcos', 'Alba', 'Hugo'], ['Navarro', 'Romero', 'Delgado', 'Ortega', 'Castillo', 'Vidal', 'Molina', 'Herrera', 'Rubio', 'Campos']],
        [CountryCode::fr, 'fr', ['Camille', 'Louis', 'Chloé', 'Jules', 'Inès', 'Hugo', 'Manon', 'Arthur', 'Léa', 'Gabriel'], ['Moreau', 'Lefèvre', 'Girard', 'Fontaine', 'Rousseau', 'Mercier', 'Blanchard', 'Garnier', 'Chevalier', 'Perrin']],
        [CountryCode::jp, 'ja', ['Yui', 'Haruto', 'Sakura', 'Ren', 'Aoi', 'Sota', 'Hina', 'Yuto', 'Mio', 'Kaito'], ['Takahashi', 'Nakamura', 'Kobayashi', 'Yamamoto', 'Ishikawa', 'Fujita', 'Okada', 'Morita', 'Hayashi', 'Endo']],
        [CountryCode::pl, 'en', ['Zofia', 'Jan', 'Maja', 'Antoni', 'Hanna', 'Filip', 'Julia', 'Kacper', 'Oliwia', 'Szymon'], ['Kowalczyk', 'Wiśniewska', 'Zielinski', 'Lewandowska', 'Wójcik', 'Kamiński', 'Dąbrowska', 'Mazur', 'Krawczyk', 'Piotrowski']],
        [CountryCode::nl, 'en', ['Sanne', 'Daan', 'Fleur', 'Sem', 'Lotte', 'Bram', 'Noor', 'Thijs', 'Femke', 'Ruben'], ['de Vries', 'Jansen', 'Bakker', 'Visser', 'Smit', 'Meijer', 'Mulder', 'Bos', 'Vos', 'Dekker']],
        [CountryCode::sk, 'cs', ['Zuzana', 'Michal', 'Katarína', 'Peter', 'Simona', 'Marek', 'Lenka', 'Juraj', 'Monika', 'Matej'], ['Horváthová', 'Kováč', 'Baláž', 'Tóthová', 'Varga', 'Szabová', 'Molnár', 'Lukáčová', 'Kollár', 'Hudák']],
    ];

    /**
     * @return array{name: string, country: CountryCode, locale: string, email: string}
     */
    public static function person(int $number): array
    {
        $group = self::GROUPS[$number % count(self::GROUPS)];
        $index = intdiv($number, count(self::GROUPS));
        [$country, $locale, $firstNames, $lastNames] = $group;

        $first = $firstNames[$index % count($firstNames)];
        // A different last name for each round through the first names, so player 1 and 101 are not namesakes
        $last = $lastNames[($index + intdiv($index, count($firstNames))) % count($lastNames)];

        return [
            'name' => $first . ' ' . $last,
            'country' => $country,
            'locale' => $locale,
            'email' => self::email($number),
        ];
    }

    /**
     * Spread over the last three years, the same day for the same number - the early ones get the longest history.
     */
    public static function registeredAt(int $number, DateTimeImmutable $now): DateTimeImmutable
    {
        $unit = hexdec(substr(hash('sha256', 'registered' . $number), 0, 12)) / 0x1000000000000;

        return $now->modify(sprintf('-%d minutes', 60 * 24 * 5 + (int) ($unit * 60 * 24 * 1090)));
    }

    public static function email(int $number): string
    {
        return self::EMAIL_PREFIX . $number . '@' . self::EMAIL_DOMAIN;
    }
}
