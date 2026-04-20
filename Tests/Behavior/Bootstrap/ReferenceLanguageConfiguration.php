<?php

use Behat\Gherkin\Node\TableNode;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\TestSuite\Fakes\FakeContentDimensionSourceFactory;
use Neos\Utility\Arrays;

trait ReferenceLanguageConfiguration
{
    /**
     * @Given /^using the following reference languages:$/
     * @param TableNode $payloadTable
     * @throws \Exception
     */
    public function AndUsingTheFollowingReferenceLanguages(TableNode $payloadTable): void
    {
        $factoryReflection = new \ReflectionClass(FakeContentDimensionSourceFactory::class);
        $contentDimensionSource = $factoryReflection->getStaticPropertyValue('contentDimensionSource');
        $contentDimensionSourceReflection = new \ReflectionClass($contentDimensionSource);
        /** @var ContentDimension $languageDimension */
        $languageDimension = $contentDimensionSourceReflection->getProperty('contentDimensions')
            ->getValue($contentDimensionSource)['language'] ?? null;
        if (!$languageDimension) {
            throw new \Exception('Language dimension missing');
        }
        foreach ($languageDimension->values as $language) {
            foreach ($payloadTable->getColumnsHash() as $referenceDeclaration) {
                if ($referenceDeclaration['Language'] === $language->value) {
                    $configuration = Arrays::arrayMergeRecursiveOverrule(
                        $language->configuration,
                        [
                            'options' => [
                                'referenceLanguage' => $referenceDeclaration['ReferenceLanguage'],
                            ]
                        ]
                    );
                    $languageReflection = new \ReflectionClass($language);
                    $languageReflection->getProperty('configuration')->setValue($language, $configuration);
                }
            }
        }
    }
}
