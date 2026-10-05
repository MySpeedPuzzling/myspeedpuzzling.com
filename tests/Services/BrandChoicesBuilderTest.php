<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use SpeedPuzzling\Web\FormType\ProposePuzzleChangesFormType;
use SpeedPuzzling\Web\Services\BrandChoicesBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * docs/features/brand-duplicates.md - every picker where a player chooses a puzzle's brand lists
 * every brand, approved or not, whoever added it.
 */
final class BrandChoicesBuilderTest extends KernelTestCase
{
    public function testListsEveryBrandWithItsPlainName(): void
    {
        self::bootKernel();

        $choices = [];

        foreach (self::getContainer()->get(BrandChoicesBuilder::class)->build() as $choice) {
            $choices[$choice['value']] = $choice;
        }

        // Unapproved and added by PLAYER_REGULAR - listed for everybody, no player involved
        self::assertArrayHasKey(ManufacturerFixture::MANUFACTURER_UNAPPROVED, $choices);
        self::assertSame('Unknown Brand', $choices[ManufacturerFixture::MANUFACTURER_UNAPPROVED]['name']);
        self::assertArrayHasKey(ManufacturerFixture::MANUFACTURER_TREFL, $choices);
    }

    public function testTheNameIsEscapedInTheOptionHtml(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $id = Uuid::uuid7();
        $entityManager->persist(new Manufacturer($id, '<img src=x onerror=alert(1)>', false, null, new DateTimeImmutable()));
        $entityManager->flush();

        $choice = null;

        foreach (self::getContainer()->get(BrandChoicesBuilder::class)->build() as $option) {
            if ($option['value'] === $id->toString()) {
                $choice = $option;
            }
        }

        self::assertNotNull($choice);
        self::assertStringNotContainsString('<img src=x', $choice['text']);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $choice['text']);
        self::assertSame('<img src=x onerror=alert(1)>', $choice['name'], 'the plain name is compared as text, never rendered');
    }

    public function testTheChangeRequestFormKeepsAPuzzlesUnapprovedBrand(): void
    {
        self::bootKernel();

        $data = new ProposePuzzleChangesFormData();
        $data->brand = ManufacturerFixture::MANUFACTURER_UNAPPROVED;

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(ProposePuzzleChangesFormType::class, $data);

        self::assertSame(ManufacturerFixture::MANUFACTURER_UNAPPROVED, $form->get('brand')->createView()->vars['value']);
        $options = $form->get('brand')->getConfig()->getOption('tom_select_options');
        self::assertIsArray($options);
        self::assertIsArray($options['options']);
        $choices = array_column($options['options'], 'value');
        self::assertContains(ManufacturerFixture::MANUFACTURER_UNAPPROVED, $choices);
    }
}
