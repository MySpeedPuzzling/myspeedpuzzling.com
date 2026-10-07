<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The event / series forms' logo upload and the logo kept from a refused submit (FormPhotoStash).
 */
trait UploadsCompetitionLogos
{
    private static function attachLogo(Form $form): void
    {
        $path = sys_get_temp_dir() . '/' . uniqid('logo-', true) . '.jpg';
        $image = imagecreatetruecolor(300, 200);
        assert($image !== false);
        imagejpeg($image, $path);

        $field = $form['competition_form[logo]'];
        Assert::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
    }

    /**
     * The refused form shows the kept logo and carries its token for the next submit.
     */
    private static function assertLogoKept(Crawler $crawler): void
    {
        $token = $crawler->filter('input[name="photo_stash[logo]"]')->attr('value');
        Assert::assertNotNull($token);
        Assert::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        Assert::assertCount(1, $crawler->filter('img[src$="/photo-stash/' . $token . '"]'));
    }
}
