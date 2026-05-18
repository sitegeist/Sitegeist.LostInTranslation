<?php

use Behat\Gherkin\Node\TableNode;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use PHPUnit\Framework\Assert;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\Domain\Retranslator;

trait StaleTranslations
{

    /**
     * @Then /^I expect exactly the following stale translations:$/
     * @param TableNode $payloadTable
     * @throws \Exception
     */
    public function IExpectExactlyTheFollowingStaleTranslations(TableNode $payloadTable): void
    {
        $staleTranslationFinder = $this->contentRepositoryRegistry->get($this->currentContentRepository->id)
            ->projectionState(StaleTranslationFinder::class);

        $expectedStaleTranslations = $payloadTable->getColumnsHash();

        $actualStaleTranslations = array_map(
            fn (StaleTranslation $staleTranslation): array => [
                'workspaceName' => $staleTranslation->workspaceName->value,
                'originDimensionSpacePoint' => $staleTranslation->originDimensionSpacePoint->toJson(),
                'nodeAggregateId' => $staleTranslation->nodeAggregateId->value,
                'propertyNames' => \json_encode($staleTranslation->propertyNames),
            ],
            iterator_to_array($staleTranslationFinder->findAll()),
        );

        Assert::assertEquals($expectedStaleTranslations, $actualStaleTranslations);
    }

    /**
     * @When /^I retranslate node "([^"]*)" in dimension space point (.*)$/
     * @throws Exception
     */
    public function iRetranslateNode(string $nodeAggregateId, string $dimensionSpacePoint): void
    {
        $this->getObject(Retranslator::class)->retranslateNode(
            contentRepositoryId: $this->currentContentRepository->id,
            workspaceName: $this->currentWorkspaceName,
            nodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($dimensionSpacePoint),
        );
    }

    /**
     * @template T ob object
     * @param class-string<T> $className
     * @return T
     */
    abstract protected function getObject(string $className): object;
}
