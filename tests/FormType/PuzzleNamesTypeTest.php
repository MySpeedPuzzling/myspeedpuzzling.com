<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\FormType;

use SpeedPuzzling\Web\FormData\PuzzleNamesFormData;
use SpeedPuzzling\Web\FormType\PuzzleNamesType;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Twig\Environment;

final class PuzzleNamesTypeTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheRowsComeBackInTheOrderSentWithoutTheEmptyOnes(): void
    {
        $form = $this->form(PuzzleNamesFormData::fromNames('Seashells', null, new PuzzleNames([
            new PuzzleName('Mušle', 'cs'),
            new PuzzleName('Muscheln', 'de'),
        ])));

        $form->submit([
            'name' => 'Seashells',
            'nameLanguage' => '',
            'alternativeNames' => [
                ['name' => 'Muscheln', 'language' => 'de'],
                ['name' => '   ', 'language' => 'cs'],
                ['name' => 'Conchas', 'language' => 'es'],
                ['name' => 'Mušle', 'language' => ''],
            ],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));

        $data = $form->getData();
        self::assertSame('Seashells', $data->mainTitle());
        self::assertNull($data->nameLanguage);
        self::assertSame([
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Conchas', 'language' => 'es'],
            ['name' => 'Mušle', 'language' => null],
        ], $data->toPuzzleNames()->toArray());
    }

    public function testATagOutsideTheListIsKeptAndCanBeSentFromAnyRow(): void
    {
        $form = $this->form(PuzzleNamesFormData::fromNames('Seashells', 'pt-BR', new PuzzleNames([
            new PuzzleName('Conchas', 'pt-BR'),
        ])));

        $view = $form->createView();
        self::assertContains('pt-BR', self::choiceValues($view['nameLanguage']));
        self::assertContains('pt-BR', self::choiceValues($view['alternativeNames'][0]['language']));
        self::assertSame('pt-BR', $view['alternativeNames'][0]['language']->vars['value']);

        // "Make main title" swapped them in the browser: the tag now comes from another row and the main title's select
        $form->submit([
            'name' => 'Conchas',
            'nameLanguage' => 'pt-BR',
            'alternativeNames' => [
                3 => ['name' => 'Seashells', 'language' => 'en'],
                7 => ['name' => 'Conchas do mar', 'language' => 'pt-BR'],
            ],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertSame('pt-BR', $data->nameLanguage);
        self::assertSame([
            ['name' => 'Seashells', 'language' => 'en'],
            ['name' => 'Conchas do mar', 'language' => 'pt-BR'],
        ], $data->toPuzzleNames()->toArray());
    }

    public function testTheMainTitlesLanguageNeverOffersEnglish(): void
    {
        // A tag of English the API stored on an other name stays offered there, never for the main title
        $form = $this->form(PuzzleNamesFormData::fromNames('Kruh barev', 'cs', new PuzzleNames([new PuzzleName('Circle of Colors', 'en-GB')])));

        $view = $form->createView();
        self::assertNotContains('en', self::choiceValues($view['nameLanguage']));
        self::assertContains('en', self::choiceValues($view['alternativeNames'][0]['language']));
        self::assertContains('en-GB', self::choiceValues($view['alternativeNames'][0]['language']));

        $form->submit([
            'name' => 'Circle of Colors',
            'nameLanguage' => 'en-GB',
            'alternativeNames' => [['name' => 'Kruh barev', 'language' => 'cs']],
        ]);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('nameLanguage')->getErrors());
    }

    public function testALanguageThatIsNoTagIsRefused(): void
    {
        $form = $this->form(PuzzleNamesFormData::fromNames('Seashells', null, new PuzzleNames()));

        $form->submit([
            'name' => 'Seashells',
            'nameLanguage' => '',
            'alternativeNames' => [['name' => 'Mušle', 'language' => 'czech']],
        ]);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('alternativeNames')->get('0')->get('language')->getErrors());
    }

    public function testTheMainTitleIsRequiredAndNamesAreCapped(): void
    {
        $form = $this->form(PuzzleNamesFormData::fromNames('Seashells', null, new PuzzleNames([new PuzzleName('Mušle', 'cs')])));

        $form->submit([
            'name' => " \u{200B} ",
            'alternativeNames' => array_map(
                static fn (int $i): array => ['name' => 'Name ' . $i, 'language' => ''],
                range(1, PuzzleNames::FORM_MAX_NAMES + 1),
            ),
        ]);

        self::assertFalse($form->isValid());
        self::assertSame(['The puzzle needs a main title.'], self::messages($form->get('name')));
        self::assertSame(['A puzzle can have at most 20 other names.'], self::messages($form->get('alternativeNames')));
    }

    public function testAPuzzleWithMoreNamesThanAFormMayAddCanLoseOne(): void
    {
        $names = new PuzzleNames(array_map(
            static fn (int $i): PuzzleName => new PuzzleName('Merged ' . $i, null),
            range(1, PuzzleNames::FORM_MAX_NAMES + 5),
        ));
        $form = $this->form(PuzzleNamesFormData::fromNames('Seashells', null, $names));

        $rows = $names->toArray();
        array_shift($rows);
        $form->submit(['name' => 'Seashells', 'alternativeNames' => $rows]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testANameTooLongIsRefusedOnItsRow(): void
    {
        $form = $this->form(PuzzleNamesFormData::fromNames('Seashells', null, new PuzzleNames()));

        $form->submit([
            'name' => 'Seashells',
            'alternativeNames' => [['name' => str_repeat('ř', PuzzleNames::MAX_NAME_LENGTH + 1), 'language' => '']],
        ]);

        self::assertFalse($form->isValid());
        self::assertSame(['A name can be at most 255 characters long.'], self::messages($form->get('alternativeNames')->get('0')->get('name')));
    }

    public function testANewRowIsInThePageLanguageExceptOnEnglishPages(): void
    {
        $this->pageLanguage('cs');
        $language = self::prototypeLanguage($this->form(new PuzzleNamesFormData())->createView());
        self::assertSame('cs', $language->vars['value']);
        self::assertSame('Čeština', array_search('cs', self::choiceLabels($language), true));

        $this->pageLanguage('en');
        self::assertSame('', self::prototypeLanguage($this->form(new PuzzleNamesFormData())->createView())->vars['value']);
    }

    /**
     * What templates/puzzle/_names_editor.html.twig renders - the 422 re-render included: the rows sent come back
     */
    public function testTheEditorRendersTheMainTitleSlotTheRowsAndThePrototype(): void
    {
        $form = $this->form(PuzzleNamesFormData::fromNames('Seashells', null, new PuzzleNames([new PuzzleName('Mušle', 'cs')])));

        $crawler = $this->render($form->createView());
        self::assertSame('Seashells', $crawler->filter('[data-names-editor-target="main"]')->attr('value'));
        self::assertNotNull($crawler->filter('[data-names-editor-target="mainLanguage"]')->attr('hidden'));
        self::assertNull($crawler->filter('[data-names-editor-target="mainLanguageToggle"]')->attr('hidden'));
        self::assertCount(1, $crawler->filter('.names-editor__rows [data-names-editor-target="row"]'));
        self::assertSame('Mušle', $crawler->filter('input[name="puzzle_names[alternativeNames][0][name]"]')->attr('value'));
        self::assertStringContainsString('puzzle_names[alternativeNames][__name__][name]', (string) $crawler->filter('template[data-names-editor-target="template"]')->html());
        self::assertSame('puzzle_names[alternativeNames]', $crawler->filter('.names-editor')->attr('data-names-editor-collection-name-value'));

        $form->submit([
            'name' => 'Kruh barev: Mušle',
            'nameLanguage' => 'cs',
            'alternativeNames' => [
                0 => ['name' => 'Mušle', 'language' => 'cs'],
                1 => ['name' => str_repeat('a', PuzzleNames::MAX_NAME_LENGTH + 1), 'language' => 'de'],
                2 => ['name' => 'Seashells', 'language' => 'en'],
            ],
        ]);
        self::assertFalse($form->isValid());

        $crawler = $this->render($form->createView());
        self::assertNull($crawler->filter('[data-names-editor-target="mainLanguage"]')->attr('hidden'), 'A main title language is shown');
        self::assertSame('cs', $crawler->filter('[data-names-editor-target="mainLanguageSelect"] option[selected]')->attr('value'));
        self::assertCount(3, $crawler->filter('.names-editor__rows [data-names-editor-target="row"]'));
        self::assertSame('Seashells', $crawler->filter('input[name="puzzle_names[alternativeNames][2][name]"]')->attr('value'));
        self::assertSame('en', $crawler->filter('select[name="puzzle_names[alternativeNames][2][language]"] option[selected]')->attr('value'));
        self::assertCount(1, $crawler->filter('.names-editor__rows .invalid-feedback'));
    }

    /**
     * @return FormInterface<PuzzleNamesFormData>
     */
    private function form(PuzzleNamesFormData $data): FormInterface
    {
        return self::getContainer()->get(FormFactoryInterface::class)->createNamed('puzzle_names', PuzzleNamesType::class, $data, [
            'csrf_protection' => false,
        ]);
    }

    private function pageLanguage(string $locale): void
    {
        self::getContainer()->get('translator')->setLocale($locale);
    }

    private function render(FormView $view): Crawler
    {
        $html = self::getContainer()->get(Environment::class)->render('puzzle/_names_editor.html.twig', ['names' => $view]);

        return new Crawler($html);
    }

    /**
     * @return list<string>
     */
    private static function choiceValues(FormView $view): array
    {
        return array_values(self::choiceLabels($view));
    }

    /**
     * @return array<string, string>
     */
    private static function choiceLabels(FormView $view): array
    {
        $choices = $view->vars['choices'];
        self::assertIsArray($choices);
        $labels = [];

        foreach ($choices as $choice) {
            self::assertInstanceOf(ChoiceView::class, $choice);
            self::assertIsString($choice->label);
            $labels[$choice->label] = $choice->value;
        }

        return $labels;
    }

    private static function prototypeLanguage(FormView $view): FormView
    {
        $prototype = $view['alternativeNames']->vars['prototype'];
        self::assertInstanceOf(FormView::class, $prototype);

        return $prototype['language'];
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return list<string>
     */
    private static function messages(FormInterface $form): array
    {
        $messages = [];

        foreach ($form->getErrors() as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }
}
