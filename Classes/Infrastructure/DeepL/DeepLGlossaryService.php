<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use DeepL\DeepLException;
use DeepL\GlossaryEntries;
use DeepL\GlossaryInfo;
use DeepL\GlossaryLanguagePair;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Model\GlossaryLanguageKeys;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryRepository;
use Neos\Flow\Annotations as Flow;

class DeepLGlossaryService
{
    private const PREFIX_SEPERATOR = '::';

    /**
     * @Flow\InjectConfiguration(path="DeepLApi.glossaryLabelPrefix")
     * @var string
     */
    protected $glossaryLabelPrefix;

    protected ?LoggerInterface $logger = null;

    public function __construct(
        private readonly DeeplClientFactory $deeplClientFactory,
        private readonly GlossaryRepository $glossaryRepository,
    ) {
    }

    public function injectLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function findGlossaryId(string $sourceLanguage, string $targetLanguage): ?string
    {
        $glossary = $this->glossaryRepository->findOneBySourceAndTargetLanguageKey($sourceLanguage, $targetLanguage);
        return $glossary?->synchronizationIdentifier;
    }

    /**
     * @return GlossaryLanguagePair[]
     */
    public function getGlossaryLanguagePairs(): array
    {
        $client = $this->deeplClientFactory->createDeepLClient();
        return $client->getGlossaryLanguages();
    }

    public function uploadRemoteGlossary(Glossary $glossary): ?string
    {
        try {
            $client = $this->deeplClientFactory->createDeepLClient();
            $info = $client->createGlossary(
                $this->glossaryLabelPrefix . self::PREFIX_SEPERATOR . $glossary->getLabel(),
                $glossary->sourceLanguageKey,
                $glossary->targetLanguageKey,
                GlossaryEntries::fromEntries($glossary->getEntriesAsAssociativeArray())
            );
            return $info->glossaryId;
        } catch (DeepLException $exception) {
            $this->logger?->critical('DeeplException caught: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Delete glossary but check checks before that the prefix matches internally
     */
    public function deleteRemoteGlossary(string $remoteId): void
    {
        try {
            $client = $this->deeplClientFactory->createDeepLClient();
            $remoteGlossaryInfo = $client->getGlossary($remoteId);
            if (str_starts_with($remoteGlossaryInfo->name, $this->glossaryLabelPrefix . self::PREFIX_SEPERATOR)) {
                $client->deleteGlossary($remoteId);
            }
            return;
        } catch (DeepLException $exception) {
            $this->logger?->critical('DeeplException caught: ' . $exception->getMessage());
            return;
        }
    }

    /**
     * The list will only contain items that match the prefix
     *
     * @return GlossaryInfo[]
     */
    public function listRemoteGlossaries(): array
    {
        try {
            $client = $this->deeplClientFactory->createDeepLClient();
            $remoteGlossaries = $client->listGlossaries();
            return array_values(array_filter(
                $remoteGlossaries,
                fn(GlossaryInfo $remoteGlossaryInfo) => str_starts_with($remoteGlossaryInfo->name, $this->glossaryLabelPrefix . self::PREFIX_SEPERATOR)
            ));
        } catch (DeepLException $exception) {
            $this->logger?->critical('DeeplException caught: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * Remove items from remote that are not referenced by any local glossary and thus
     * are either outdated. Will identify the items that are to be checked via prefix
     *
     * @return int The number of items that were removed
     */
    public function cleanupRemoteGlossaries(): int
    {
        $count = 0;
        $localGlossaries = $this->glossaryRepository->findAll()->toArray();
        $localGlossarySyncIdentifiers = array_filter(array_map(
            fn (Glossary $glossary) => $glossary->synchronizationIdentifier,
            $localGlossaries
        ));

        $remoteGlossaries = $this->listRemoteGlossaries();
        foreach ($remoteGlossaries as $remoteGlossary) {
            if (!in_array($remoteGlossary->glossaryId, $localGlossarySyncIdentifiers)) {
                $this->deleteRemoteGlossary($remoteGlossary->glossaryId);
                $count++;
            }
        }
        return $count;
    }
}
