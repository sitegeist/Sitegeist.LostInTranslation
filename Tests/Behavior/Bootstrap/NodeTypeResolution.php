<?php

use Behat\Gherkin\Node\TableNode;
use Neos\ContentRepository\Core\Feature\NodeRenaming\Command\ChangeNodeAggregateName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\Assert;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;

trait NodeTypeResolution
{
    /**
     * @Then /^I expect the following node type resolution:$/
     * @param TableNode $payloadTable
     * @throws \Exception
     */
    public function IExpectTheFollowingNodeTypeResolution(TableNode $payloadTable): void
    {
        $readModel = $this->contentRepositoryRegistry->get($this->currentContentRepository->id)
            ->projectionState(StaleTranslationReadModel::class);

        $expectedResolutions = $payloadTable->getColumnsHash();
        $actualResolution = [];
        foreach ($expectedResolutions as $expectedResolution) {
            $actualResolution[] = [
                'workspaceName' => $expectedResolution['workspaceName'],
                'nodeAggregateId' => $expectedResolution['nodeAggregateId'],
                'nodeTypeName' => $readModel->nodeTypeResolver->resolveByNodeAggregateId(
                    nodeAggregateId: NodeAggregateId::fromString($expectedResolution['nodeAggregateId']),
                    workspaceName: WorkspaceName::fromString($expectedResolution['workspaceName']),
                )?->name->value,
            ];
        }

        Assert::assertEquals($expectedResolutions, $actualResolution);
    }

    /**
     * @Then /^I expect exactly the following node type resolution entries:$/
     * @param TableNode $payloadTable
     * @throws \Exception
     */
    public function IExpectTheFollowingNodeTypeResolutionEntries(TableNode $payloadTable): void
    {
        $readModel = $this->contentRepositoryRegistry->get($this->currentContentRepository->id)
            ->projectionState(StaleTranslationReadModel::class);

        $expectedResolutions = $payloadTable->getColumnsHash();
        $actualResolution = $readModel->nodeTypeResolver->findAll();

        Assert::assertEquals($expectedResolutions, $actualResolution);
    }
}
