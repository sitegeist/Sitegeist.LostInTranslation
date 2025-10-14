<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Domain\Model\NodeInterface;

interface FormalityConnectorInterface
{
    /**
     * @param NodeInterface $sourceNode
     * @param NodeInterface|null $targetNode
     * @return string|null 'less', 'more', 'default', 'prefer_less', 'prefer_more' or null if not specified
     */
    public function getFormality(NodeInterface $sourceNode, ?NodeInterface $targetNode = null): ?string;
}
