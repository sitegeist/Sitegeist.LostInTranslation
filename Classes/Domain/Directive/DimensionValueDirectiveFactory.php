<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Directive;

use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\Dimension\ContentDimensionValue;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;

class DimensionValueDirectiveFactory
{
    public function createForDimensionValue(ContentDimensionValue $contentDimensionValue): DimensionValueDirective
    {
        $deeplLanguageOption = $contentDimensionValue->getConfigurationValue('options.deeplLanguage');
        if ($deeplLanguageOption === false) {
            return new DimensionValueDirective(null, null);
        } elseif (is_string($deeplLanguageOption)) {
            if (str_contains($deeplLanguageOption, ':')) {
                list($deeplSourceLanguage, $deeplTargetLanguage) = explode(':', $deeplLanguageOption, 2);
            } else {
                $deeplSourceLanguage = $deeplLanguageOption;
                $deeplTargetLanguage = $deeplLanguageOption;
            }
            return new DimensionValueDirective($deeplSourceLanguage, $deeplTargetLanguage);
        } elseif ($deeplLanguageOption === null) {
            $valueAsString = strtoupper($contentDimensionValue->value);
            return new DimensionValueDirective($valueAsString, $valueAsString);
        }
        throw new \Exception(sprintf("language ContentDimensionValue %s could not be handled", $contentDimensionValue->value));
    }

    public function tryCreateForDimensionAndOriginDimensionSpacePoint(ContentDimension $languageDimension, OriginDimensionSpacePoint $dimensionSpacePoint): ?DimensionValueDirective
    {
        $coordinate = $dimensionSpacePoint->getCoordinate($languageDimension->id);
        if ($coordinate === null) {
            return null;
        }
        $dimensionValue = $languageDimension->getValue($coordinate);
        if ($dimensionValue instanceof ContentDimensionValue) {
            return self::createForDimensionValue($dimensionValue);
        } else {
            return null;
        }
    }

    /**
     * Resolve the DeepL source/target language pair for translating from `$sourceDimensionSpacePoint` into
     * `$targetDimensionSpacePoint`. Returns null when either side has no resolvable DeepL language (e.g. translation
     * disabled for the dimension value), which callers surface as a "skip" of the synchronization.
     */
    public function tryResolveLanguagePair(
        ContentDimension $languageDimension,
        DimensionSpacePoint $sourceDimensionSpacePoint,
        DimensionSpacePoint $targetDimensionSpacePoint,
    ): ?DeeplLanguagePair {
        $sourceLanguage = $this->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDimensionSpacePoint),
        )?->deeplSourceId;
        $targetLanguage = $this->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
        )?->deeplTargetId;
        if ($sourceLanguage === null || $targetLanguage === null) {
            return null;
        }
        return new DeeplLanguagePair($sourceLanguage, $targetLanguage);
    }
}
