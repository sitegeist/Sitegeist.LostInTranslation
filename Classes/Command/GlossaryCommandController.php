<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Command;

use DateTime;
use Neos\Cache\Exception;
use Neos\Cache\Exception\InvalidDataException;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Annotations\Scope;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\Exception\InvalidQueryException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryEntryRepository;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

#[Scope("singleton")]
class GlossaryCommandController extends CommandController
{
    protected const GLOSSARY_ENTRY_SEPARATOR = "\t";
    protected const GLOSSARY_ENTRIES_SEPARATOR = "\n";
    protected const GLOSSARY_ENTRIES_FORMAT = 'tsv';

    /** @var VariableFrontend */
    #[Inject]
    protected $storage;
    /** @var LoggerInterface */
    #[Inject]
    protected $logger;
    #[Inject]
    protected GlossaryEntryRepository $glossaryEntryRepository;
    #[Inject]
    protected DeepLTranslationService $deepLApi;

    /**
     * Maps internal glossary keys to DeepL glossary keys.
     * @var array<string, string>
     */
    protected array|null $glossaryKeyMapping = null;

    /**
     * DeepL glossaries are immutable, so we have to sync all texts of a specific language
     * even if only one entry for a single language was updated.
     *
     * @param bool $fullSync Sync every locally stored entry independently of the last sync and entry modification dates.
     * @throws Exception
     * @throws InvalidDataException
     * @throws InvalidQueryException
     * @throws ClientExceptionInterface
     * @throws \Exception
     */
    public function syncCommand(bool $fullSync = false): void
    {
        $currentTime = time();

        if ($this->storage->has('forceCompleteSync')) {
            $completeSyncIsForced = (bool)$this->storage->get('forceCompleteSync');
        } else {
            $completeSyncIsForced = false;
        }
        if ($this->storage->has('lastExecutionTimestamp')) {
            $lastExecutionTimestamp = (int)$this->storage->get('lastExecutionTimestamp');
        } else {
            $lastExecutionTimestamp = 0;
        }
        if ($fullSync || $completeSyncIsForced || $lastExecutionTimestamp === 0) {
            $lastExecutionTimestamp = 0;
        }

        $lastExecutedAt = new DateTime('@' . $lastExecutionTimestamp);
        $languagesWithUpdates = $this->glossaryEntryRepository->findLanguagesThatRequireSyncing($lastExecutedAt);
        if (count($languagesWithUpdates) === 0) {
            return;
        }

        // fetching the required language pairs can extend the languages we have to sync
        [$languagePairs, $languagesToSync] = $this->deepLApi->getLanguagePairs($languagesWithUpdates);

        // aggregate entries
        $aggregates = [];
        $entries = $this->glossaryEntryRepository->findByLanguages($languagesToSync);
        foreach ($entries as $entry) {
            $identifier = $entry->aggregateIdentifier;
            if (!array_key_exists($entry->aggregateIdentifier, $aggregates)) {
                $aggregates[$identifier] = [];
            }
            $aggregates[$identifier][$entry->glossaryLanguage] = $entry->text;
        }

        // build glossary entries in DeepL format
        $glossaries = [];
        foreach ($aggregates as $aggregate) {
            foreach ($languagePairs as $languagePair) {
                $source = $languagePair['source'];
                $target = $languagePair['target'];
                if (array_key_exists($source, $aggregate) && array_key_exists($target, $aggregate)) {
                    $sourceText = trim($aggregate[$source]);
                    $targetText = trim($aggregate[$target]);
                    if (!empty($sourceText) && !empty($targetText)) {
                        $internalGlossaryKey = $this->deepLApi->getInternalGlossaryKey($source, $target);
                        if (!array_key_exists($internalGlossaryKey, $glossaries)) {
                            $glossaries[$internalGlossaryKey] = [];
                        }
                        $entry = $sourceText . self::GLOSSARY_ENTRY_SEPARATOR . $targetText;
                        $glossaries[$internalGlossaryKey][] = $entry;
                    }
                }
            }
        }

        $this->updateDeepLGlossaries($glossaries);

        $this->storage->set('lastExecutionTimestamp', $currentTime);
        $this->storage->set('forceCompleteSync', false);
    }

    /**
     * DeepL glossaries are immutable,
     * so we need to delete existing glossaries and create them afterwards.
     * @throws ClientExceptionInterface
     */
    protected function updateDeepLGlossaries(array $newGlossaries): void
    {
        foreach ($newGlossaries as $internalGlossaryKey => $entries) {

            if ($this->doesGlossaryExistInDeepL($internalGlossaryKey)) {
                $this->deepLApi->deleteGlossary($this->glossaryKeyMapping[$internalGlossaryKey]);
            }

            [$sourceLangauge, $targetLangauge] = $this->deepLApi->getLanguagesFromInternalGlossaryKey($internalGlossaryKey);
            $createData = [
                'name' => "Solarwatt Website, source $sourceLangauge, target $targetLangauge",
                'source_lang' => $sourceLangauge,
                'target_lang' => $targetLangauge,
                'entries' => implode(self::GLOSSARY_ENTRIES_SEPARATOR, $entries),
                'entries_format' => self::GLOSSARY_ENTRIES_FORMAT,
            ];
            $body = http_build_query($createData, '', null, PHP_QUERY_RFC3986);

            $this->deepLApi->createGlossary($body);

        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    protected function doesGlossaryExistInDeepL(string $internalGlossaryKey): bool
    {
        if ($this->glossaryKeyMapping === null) {
            $this->glossaryKeyMapping = [];
            $deepLGlossaries = $this->deepLApi->getGlossaries();
            foreach ($deepLGlossaries as $deepLGlossary) {
                $internalGlossaryKey = $this->deepLApi->getInternalGlossaryKey(
                    $deepLGlossary['source_lang'],
                    $deepLGlossary['target_lang']
                );
                $this->glossaryKeyMapping[$internalGlossaryKey] = $deepLGlossary['glossary_id'];
            }
        }
        return array_key_exists($internalGlossaryKey, $this->glossaryKeyMapping);
    }

}
