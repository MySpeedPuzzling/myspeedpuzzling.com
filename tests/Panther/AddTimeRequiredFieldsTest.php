<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther;

use Facebook\WebDriver\WebDriverBy;

/**
 * Brand and puzzle are `required` inputs hidden behind Tom Select. The browser used to refuse the
 * submit on its own and point a native "Fill out this field" bubble at an input nobody can see;
 * the form now says it itself, under the field, and takes the visitor there.
 */
final class AddTimeRequiredFieldsTest extends AbstractPantherTestCase
{
    public function testSavingWithoutABrandShowsTheFormsOwnMessageUnderTheField(): void
    {
        $client = self::createBrowserClient();
        self::loginUser($client, 'auth0|regular001', 'player1@speedpuzzling.cz', 'John Doe');

        $client->request('GET', '/en/puzzle-add');
        $client->waitFor('form[name="puzzle_add_form"] .ts-wrapper');
        $urlBefore = $client->getCurrentURL();

        $client->executeScript('document.querySelector(\'form[name="puzzle_add_form"] [type="submit"]\').click();');
        $client->waitFor('.js-puzzle-required-error');

        $error = $client->findElement(WebDriverBy::cssSelector('.js-puzzle-required-error'));
        self::assertSame('This field is required!', $error->getText());
        self::assertSame($urlBefore, $client->getCurrentURL());

        // Right under the brand field, which also has the focus now
        $placement = $client->executeScript(<<<'JS'
            var error = document.querySelector('.js-puzzle-required-error');
            var brand = document.querySelector('[name="puzzle_add_form[brand]"]');
            return {
                afterBrand: error.previousElementSibling === brand.tomselect.wrapper,
                brandFocused: brand.tomselect.isFocused,
                count: document.querySelectorAll('.js-puzzle-required-error').length
            };
        JS);

        self::assertIsArray($placement);
        ksort($placement);
        self::assertSame(['afterBrand' => true, 'brandFocused' => true, 'count' => 1], $placement);

        // The save button is usable again
        self::assertNull($client->findElement(WebDriverBy::cssSelector('form[name="puzzle_add_form"] [type="submit"]'))->getAttribute('disabled'));
    }

    public function testBrandWithoutAPuzzleIsAskedForUnderThePuzzleAndNothingFilledInIsLost(): void
    {
        $client = self::createBrowserClient();
        self::loginUser($client, 'auth0|regular001', 'player1@speedpuzzling.cz', 'John Doe');

        $client->request('GET', '/en/puzzle-add');
        $client->waitFor('form[name="puzzle_add_form"] .ts-wrapper');

        // What the visitor already filled in - and a mark on the photo input: a chosen file lives in that
        // very element, so it survives exactly as long as the element is neither replaced nor the page left
        $client->executeScript(<<<'JS'
            document.querySelector('[name="puzzle_add_form[comment]"]').value = 'Rainy Sunday';
            document.querySelector('[name="puzzle_add_form[timeHours]"]').value = '2';
            document.querySelector('[name="puzzle_add_form[timeMinutes]"]').value = '30';
            document.querySelector('[name="puzzle_add_form[finishedPuzzlesPhoto]"]').dataset.sameElement = 'yes';
            var brand = document.querySelector('[name="puzzle_add_form[brand]"]').tomselect;
            brand.addItem(Object.keys(brand.options)[0]);
        JS);
        $urlBefore = $client->getCurrentURL();

        $client->executeScript('document.querySelector(\'form[name="puzzle_add_form"] [type="submit"]\').click();');
        $client->waitFor('.js-puzzle-required-error');

        $state = $client->executeScript(<<<'JS'
            var error = document.querySelector('.js-puzzle-required-error');
            var puzzle = document.querySelector('[name="puzzle_add_form[puzzle]"]');
            return {
                afterPuzzle: error.previousElementSibling === puzzle.tomselect.wrapper,
                errors: document.querySelectorAll('.js-puzzle-required-error').length,
                brandKept: document.querySelector('[name="puzzle_add_form[brand]"]').value !== '',
                comment: document.querySelector('[name="puzzle_add_form[comment]"]').value,
                hours: document.querySelector('[name="puzzle_add_form[timeHours]"]').value,
                minutes: document.querySelector('[name="puzzle_add_form[timeMinutes]"]').value,
                photoInput: document.querySelector('[name="puzzle_add_form[finishedPuzzlesPhoto]"]').dataset.sameElement || 'replaced'
            };
        JS);

        self::assertSame($urlBefore, $client->getCurrentURL(), 'The form was submitted or the page left');
        self::assertIsArray($state);
        ksort($state);
        self::assertSame([
            'afterPuzzle' => true,
            'brandKept' => true,
            'comment' => 'Rainy Sunday',
            'errors' => 1,
            'hours' => '2',
            'minutes' => '30',
            'photoInput' => 'yes',
        ], $state);

        // Choosing a puzzle clears the message
        $client->executeScript(<<<'JS'
            var puzzle = document.querySelector('[name="puzzle_add_form[puzzle]"]').tomselect;
            puzzle.addOption({value: 'Brand new puzzle', text: 'Brand new puzzle'});
            puzzle.addItem('Brand new puzzle');
        JS);
        self::assertCount(0, $client->findElements(WebDriverBy::cssSelector('.js-puzzle-required-error')));
    }
}
