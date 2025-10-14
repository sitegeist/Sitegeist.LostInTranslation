<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Sitegeist\LostInTranslation\Domain\FormalityConnectorInterface;

class DeepLFormalityService
{
    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.formalityConnector")
     * @var string|null
     */
    protected $formalityConnector = null;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    public function getFormality(NodeInterface $sourceNode, ?NodeInterface $targetNode = null): ?string
    {
        if (!$this->formalityConnector) {
            return null;
        }

        $formalityConnectorInstance = $this->objectManager->get($this->formalityConnector);
        assert($formalityConnectorInstance instanceof FormalityConnectorInterface);
        return $formalityConnectorInstance->getFormality($sourceNode, $targetNode);
    }
}
