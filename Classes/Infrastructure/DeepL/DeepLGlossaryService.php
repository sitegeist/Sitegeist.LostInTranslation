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

use function Symfony\Component\String\u;

class DeepLGlossaryService
{
    private const PREFIX_SEPERATOR = '::';

    /**
     * @Flow\InjectConfiguration(path="DeepLApi.glossary.labelPrefix")
     * @var string
     */
    protected $labelPrefix;

    /**
     * @Flow\InjectConfiguration(path="DeepLApi.glossary.keepNumber")
     * @var int
     */
    protected $keepNumber = 2;

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

    /**
     * Uploads the glossary to DeepL and returns the new remote id.
     *
     * DeepL's glossary API is immutable - an existing remote glossary cannot be edited in place.
     */
    public function uploadRemoteGlossary(Glossary $glossary): ?string
    {
        try {
            $client = $this->deeplClientFactory->createDeepLClient();
            $info = $client->createGlossary(
                $this->labelPrefix . self::PREFIX_SEPERATOR . $glossary->getLabel(),
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
            if (str_starts_with($remoteGlossaryInfo->name, $this->labelPrefix . self::PREFIX_SEPERATOR)) {
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
                fn(GlossaryInfo $remoteGlossaryInfo) => str_starts_with($remoteGlossaryInfo->name, $this->labelPrefix . self::PREFIX_SEPERATOR)
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
     * @return array<string> The ids of remote glossaries that were deleted
     */
    public function cleanupRemoteGlossaries(): array
    {
        $client = $this->deeplClientFactory->createDeepLClient();
        $localGlossaries = $this->glossaryRepository->findAll()->toArray();
        $remoteGlossaries = $this->listRemoteGlossaries();

        $localGlossarySyncIdentifiers = [];
        $localGlossaryLabels = [];
        foreach ($localGlossaries as $localGlossary) {
            $localGlossarySyncIdentifiers[] = $localGlossary->synchronizationIdentifier;
            $localGlossaryLabels[]  = $localGlossary->getLabel();
        }

        $remoteGlossariesToKeepIdentifiers = [];
        if ($this->keepNumber > 0) {
            foreach ($localGlossaryLabels as $localGlossaryLabel) {
                $remoteGlossariesForLabel = array_filter(
                    $remoteGlossaries,
                    fn(GlossaryInfo $remoteGlossary) => $remoteGlossary->name === $this->labelPrefix . self::PREFIX_SEPERATOR . $localGlossaryLabel
                );
                usort($remoteGlossariesForLabel, fn(GlossaryInfo $a, GlossaryInfo $b) => $b->creationTime <=> $a->creationTime);
                $remoteGlossariesToKeepForLabel = array_map(
                    fn(GlossaryInfo $remote) => $remote->glossaryId,
                    array_slice($remoteGlossariesForLabel, 0, $this->keepNumber)
                );
                array_push($remoteGlossariesToKeepIdentifiers, ...$remoteGlossariesToKeepForLabel);
            }
        }

        $deletedIdentifier = [];
        foreach ($remoteGlossaries as $remoteGlossary) {
            // do not touch foreign glossaries
            if (!str_starts_with($remoteGlossary->name, $this->labelPrefix . self::PREFIX_SEPERATOR)) {
                continue;
            }
            if (
                !in_array($remoteGlossary->glossaryId, $localGlossarySyncIdentifiers)
                && !in_array($remoteGlossary->glossaryId, $remoteGlossariesToKeepIdentifiers)
            ) {
                $client->deleteGlossary($remoteGlossary->glossaryId);
                $deletedIdentifier[] = $remoteGlossary->glossaryId;
            }
        }

        return $deletedIdentifier;
    }
}
