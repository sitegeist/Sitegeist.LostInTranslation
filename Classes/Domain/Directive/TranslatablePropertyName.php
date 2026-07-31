<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Directive;

use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Sitegeist\LostInTranslation\Domain\PostProcessor\TranslatedPropertyPostProcessorInterface;
use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;

readonly class TranslatablePropertyName
{
    /**
     * @param TranslationConnectorInterface<object>|null $translationConnector
     */
    public function __construct(
        public PropertyName $propertyName,
        public ?TranslationConnectorInterface $translationConnector = null,
        public ?TranslatedPropertyPostProcessorInterface $postProcessor = null,
    ) {
    }
}
