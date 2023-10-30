<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use SpeedPuzzling\Web\Entity\Product;
use SpeedPuzzling\Web\Entity\ProductVariant;
use SpeedPuzzling\Web\Value\Currency;
use SpeedPuzzling\Web\Value\Price;
use Ramsey\Uuid\Uuid;

final class TestDataFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
    }
}
