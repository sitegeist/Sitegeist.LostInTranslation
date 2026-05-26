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

    /**
     * Find every dimension space point that declares `$dimensionSpacePoint`'s language as its
     * `referenceLanguage`. A source language can drive translation into more than one target
     * (e.g. `de.referenceLanguage = en` and `es.referenceLanguage = en`), so the result is a set
     * — empty if no target references this source or no resolved point lives in the allowed
     * subspace.
     */
    public function findAllTargetDimensionSpacePoints(DimensionSpacePoint $dimensionSpacePoint): DimensionSpacePointSet
    {
        $languageDimension = $this->contentDimensionSource->getDimension($this->languageDimensionId);
        if ($languageDimension === null) {
            return new DimensionSpacePointSet([]);
        }

        $languageValue = $dimensionSpacePoint->coordinates[$this->languageDimensionId->value] ?? null;
        if ($languageValue === null) {
            return new DimensionSpacePointSet([]);
        }

        $targets = [];
        foreach ($languageDimension->values as $language) {
            if (($language->configuration['referenceLanguage'] ?? null) !== $languageValue) {
                continue;
            }
            $coordinates = $dimensionSpacePoint->coordinates;
            $coordinates[$this->languageDimensionId->value] = $language->value;
            $targetDimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);
            if ($this->allowedDimensionSubspace->contains($targetDimensionSpacePoint)) {
                $targets[] = $targetDimensionSpacePoint;
            }
        }

        return new DimensionSpacePointSet($targets);
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
        $sourceLanguageValue = $language->configuration['referenceLanguage'] ?? null;
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
