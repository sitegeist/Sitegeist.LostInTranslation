<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Utility;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\StringTranslationMode;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyName;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNames;
use Sitegeist\LostInTranslation\Utility\PlainTextTranslationUtility;

class PlainTextTranslationUtilityTest extends UnitTestCase
{
    /**
     * @test
     */
    public function encodePlainTextPropertiesProtectsHtmlSpecialCharactersOnlyForPlainTextProperties(): void
    {
        $properties = [
            'title' => 'Wellness & Spa <today>',
            'text' => 'Wellness & Spa <today>',
        ];
        $translatableProperties = new TranslatablePropertyNames(
            new TranslatablePropertyName('title', null, StringTranslationMode::Plain),
            new TranslatablePropertyName('text', null, StringTranslationMode::Html)
        );

        $result = PlainTextTranslationUtility::encodePlainTextProperties($properties, $translatableProperties);

        self::assertSame('Wellness &amp; Spa &lt;today&gt;', $result['title']);
        self::assertSame('Wellness & Spa <today>', $result['text']);
    }

    /**
     * @test
     */
    public function decodePlainTextPropertiesDecodesTranslatedEntitiesOnlyForPlainTextProperties(): void
    {
        $properties = [
            'title' => 'Wellness &amp; Spa',
            'text' => 'Wellness &amp; Spa',
        ];
        $translatableProperties = new TranslatablePropertyNames(
            new TranslatablePropertyName('title', null, StringTranslationMode::Plain),
            new TranslatablePropertyName('text', null, StringTranslationMode::Html)
        );

        $result = PlainTextTranslationUtility::decodePlainTextProperties($properties, $translatableProperties);

        self::assertSame('Wellness & Spa', $result['title']);
        self::assertSame('Wellness &amp; Spa', $result['text']);
    }

    /**
     * @test
     */
    public function flattenedKeysAreNotTreatedAsPlainTextByTopLevelPropertyConfiguration(): void
    {
        $properties = [
            'image.title' => 'Wellness & Spa',
        ];
        $translatableProperties = new TranslatablePropertyNames(
            new TranslatablePropertyName('image', null, StringTranslationMode::Plain)
        );

        $result = PlainTextTranslationUtility::encodePlainTextProperties($properties, $translatableProperties);

        self::assertSame('Wellness & Spa', $result['image.title']);
    }
}
