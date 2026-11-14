<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * The words of a written date in the six languages of the site: month names with their short forms (Czech also in the
 * form a date uses - "3. března"), weekday names, and the small words in between. All in lower case without accents,
 * the way ImportText::plain() hands a text over.
 */
final readonly class ImportCalendarWords
{
    /** @var array<string, int> */
    public const array MONTHS = [
        // English
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
        'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9,
        'oct' => 10, 'nov' => 11, 'dec' => 12,
        // Czech: the month's name, the form after a day, the short forms
        'leden' => 1, 'unor' => 2, 'brezen' => 3, 'duben' => 4, 'kveten' => 5, 'cerven' => 6, 'cervenec' => 7,
        'srpen' => 8, 'zari' => 9, 'rijen' => 10, 'listopad' => 11, 'prosinec' => 12,
        'ledna' => 1, 'unora' => 2, 'brezna' => 3, 'dubna' => 4, 'kvetna' => 5, 'cervna' => 6, 'cervence' => 7,
        'srpna' => 8, 'rijna' => 10, 'listopadu' => 11, 'prosince' => 12,
        'led' => 1, 'uno' => 2, 'bre' => 3, 'dub' => 4, 'kve' => 5, 'cvn' => 6, 'cvc' => 7, 'srp' => 8, 'zar' => 9,
        'rij' => 10, 'lis' => 11, 'pro' => 12,
        // German
        'januar' => 1, 'janner' => 1, 'februar' => 2, 'marz' => 3, 'maerz' => 3, 'mai' => 5, 'juni' => 6, 'juli' => 7,
        'oktober' => 10, 'dezember' => 12,
        'mrz' => 3, 'okt' => 10, 'dez' => 12,
        // Spanish
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'ene' => 1, 'abr' => 4, 'ago' => 8, 'set' => 9, 'dic' => 12,
        // French
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'juin' => 6, 'juillet' => 7, 'aout' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
        'janv' => 1, 'fevr' => 2, 'fev' => 2, 'avr' => 4, 'juil' => 7,
    ];

    /** @var list<string> */
    public const array WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'mon', 'tue', 'tues', 'wed', 'thu', 'thur', 'thurs', 'fri', 'sat', 'sun',
        'pondeli', 'utery', 'streda', 'ctvrtek', 'patek', 'sobota', 'nedele',
        'po', 'ut', 'st', 'ct', 'pa', 'so', 'ne',
        'montag', 'dienstag', 'mittwoch', 'donnerstag', 'freitag', 'samstag', 'sonnabend', 'sonntag',
        'mo', 'di', 'mi', 'do', 'fr', 'sa',
        'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo',
        'lun', 'mie', 'jue', 'vie', 'sab', 'dom',
        'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche',
        'mer', 'jeu', 'ven', 'sam', 'dim',
    ];

    /** Words between the parts of a date that say nothing: "3 de marzo de 2024", "the 3rd of March", "dne 3. března" */
    public const array FILLERS = ['de', 'del', 'of', 'the', 'le', 'el', 'den', 'dne', 'am', 'vom', 'roku', 'on'];
}
