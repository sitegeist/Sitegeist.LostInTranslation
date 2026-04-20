<?php

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Neos\Behat\FlowBootstrapTrait;
use Neos\Behat\FlowEntitiesTrait;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\TestSuite\Behavior\Features\Bootstrap\CRBehavioralTestsSubjectProvider;
use Neos\ContentRepository\TestSuite\Behavior\Features\Bootstrap\CRTestSuiteTrait;
use Neos\ContentRepository\TestSuite\Fakes\FakeContentDimensionSourceFactory;
use Neos\ContentRepository\TestSuite\Fakes\FakeNodeTypeManagerFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationProjectionFactory;

trait StaleTranslations
{

    /**
     * @Then /^I expect exactly the following stale translations:$/
     * @param TableNode $payloadTable
     * @throws \Exception
     */
    public function IExpectExactlyTheFollowingStaleTranslations(TableNode $payloadTable): void
    {
        $staleTranslationFinder = $this->contentRepositoryRegistry->get($this->getCurrentSubgraph()->getContentRepositoryId())
            ->projectionState(StaleTranslationFinder::class);





        $this->getContentRepositoryService(StaleTranslationProjectionFactory::class);
        $eventPayload = $this->readPayloadTable($payloadTable);
        $contentStreamId = ContentStreamId::fromString($eventPayload['contentStreamId']);
        $streamName = ContentStreamEventStreamName::fromContentStreamId($contentStreamId);

        $this->publishEvent('NodeAggregateWasMoved', $streamName->getEventStreamName(), $eventPayload);
    }
}
