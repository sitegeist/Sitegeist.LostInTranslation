<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class ReferenceDimensionSpacePointResolver
{
    public function __construct(
        private DimensionSpacePointSet $allowedDimensionSubspace,
        private ContentDimensionSourceInterface $contentDimensionSource,
        private ContentDimensionId $languageDimensionId,
    ) {
    }

    public function tryResolveTargetDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): ?DimensionSpacePoint
    {
        $languageDimension = $this->contentDimensionSource->getDimension($this->languageDimensionId);
        if ($languageDimension === null) {
            return null;
        }

        $languageValue = $dimensionSpacePoint->coordinates[$this->languageDimensionId->value] ?? null;
        if ($languageValue === null) {
            return null;
        }

        foreach ($languageDimension->values as $language) {
            if (($language->configuration['options']['referenceLanguage'] ?? null) === $languageValue) {
                $coordinates = $dimensionSpacePoint->coordinates;
                $coordinates[$this->languageDimensionId->value] = $language->value;
                $targetDimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);

                return $this->allowedDimensionSubspace->contains($targetDimensionSpacePoint)
                    ? $targetDimensionSpacePoint
                    : null;
            }
        }

        return null;
    }

    public function tryResolveSourceDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): ?DimensionSpacePoint
    {
        $languageDimension = $this->contentDimensionSource->getDimension($this->languageDimensionId);
        if ($languageDimension === null) {
            return null;
        }

        $languageValue = $dimensionSpacePoint->coordinates[$this->languageDimensionId->value] ?? null;
        if ($languageValue === null) {
            return null;
        }

        $language = $languageDimension->getValue($languageValue);
        $sourceLanguageValue = $language->configuration['options']['referenceLanguage'] ?? null;
        if ($sourceLanguageValue === null) {
            return null;
        }

        $coordinates = $dimensionSpacePoint->coordinates;
        $coordinates[$this->languageDimensionId->value] = $sourceLanguageValue;
        $sourceDimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);

        return $this->allowedDimensionSubspace->contains($sourceDimensionSpacePoint)
            ? $sourceDimensionSpacePoint
            : null;
    }
}
