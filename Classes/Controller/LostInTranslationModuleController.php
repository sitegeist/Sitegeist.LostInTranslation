<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use DeepL\GlossaryInfo;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Dimension\Exception\ContentDimensionIdIsInvalid;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Error\Messages\Message;
use Neos\Flow\I18n\Translator;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Model\GlossaryEntry;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryEntryRepository;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryRepository;
use Sitegeist\LostInTranslation\Domain\StaleTranslationProjectionStatusProvider;
use Sitegeist\LostInTranslation\Domain\SynchronizationRule;
use Sitegeist\LostInTranslation\Domain\SynchronizationRules;
use Sitegeist\LostInTranslation\Domain\SynchronizationStatusProvider;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizationResult;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizer;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCacheService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCustomAuthenticationKeyService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLGlossaryService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

class LostInTranslationModuleController extends AbstractModuleController
{
    #[Flow\Inject]
    protected DeepLTranslationService $translationService;

    #[Flow\Inject]
    protected DeepLCacheService $cacheService;

    #[Flow\Inject]
    protected DeepLGlossaryService $glossaryService;

    #[Flow\Inject]
    protected GlossaryRepository $glossaryRepository;

    #[Flow\Inject]
    protected GlossaryEntryRepository $glossaryEntryRepository;

    #[Flow\Inject]
    protected DeepLCustomAuthenticationKeyService $customAuthenticationKeyService;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SynchronizationStatusProvider $synchronizationStatusProvider;

    #[Flow\Inject]
    protected StaleTranslationProjectionStatusProvider $staleTranslationProjectionStatusProvider;

    #[Flow\Inject]
    protected WorkspaceSynchronizer $workspaceSynchronizer;

    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\InjectConfiguration(path: "nodeTranslation.contentRepositoryIdentifier")]
    protected string $contentRepositoryIdentifier;

    #[Flow\InjectConfiguration(path: "nodeTranslation.languageDimensionName")]
    protected string $languageDimensionName;

    /**
     * @var array<int,array<string,string>>
     */
    #[Flow\InjectConfiguration(path: "nodeTranslation.synchronization")]
    protected array $synchronization = [];

    /**
     * @var FusionView
     */
    protected $view;


    public function indexAction(): void
    {
        $status = $this->translationService->getStatus();
        $this->view->assign('status', $status);
        $this->view->assign('glossaries', $this->glossaryRepository->findAll()->toArray());
    }

    public function showStatusAction(): void
    {
        $status = $this->translationService->getStatus();
        $this->view->assign('status', $status);
    }

    /**
     * Overview of all configured synchronization rules and how many translations are currently out of sync for each,
     * with a per-rule and a "sync all" manual trigger. Unlike the publish-driven prompt this is mode-agnostic — it
     * lists and can synchronize `auto` and `ask` rules alike.
     */
    public function synchronizationStatusAction(): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($this->contentRepositoryIdentifier);
        $rules = SynchronizationRules::fromArray($this->synchronization);

        // The pending counts are read from the stale-translation projection. If that projection is not set up yet (e.g.
        // a fresh install before `./flow cr:setup`) querying it would fault on missing tables, so report its status and
        // skip the counts rather than crash the module.
        $projectionStatus = $this->staleTranslationProjectionStatusProvider->forContentRepository($contentRepositoryId);
        $this->view->assign('projectionStatus', $projectionStatus);
        if (!$projectionStatus->isReady) {
            $this->view->assign('rules', []);
            $this->view->assign('pendingTotal', 0);
            return;
        }

        $rows = [];
        foreach ($this->synchronizationStatusProvider->forRules($contentRepositoryId, $rules) as $index => $status) {
            $rows[] = [
                'index' => $index,
                'sourceWorkspaceName' => $status->rule->sourceWorkspaceName,
                'sourceDimension' => $status->rule->sourceDimension,
                'targetWorkspaceName' => $status->rule->targetWorkspaceName,
                'targetDimension' => $status->rule->targetDimension,
                'scope' => $status->rule->scope->value,
                'mode' => $status->rule->mode->value,
                'pendingCount' => $status->pendingCount,
                // The enum's VALUE, not the enum: the view uses it as the trailing segment of a translation key, the
                // same way it renders scope and mode. Null (the rule can run) renders as no problem at all.
                'targetProblem' => $status->targetProblem?->value,
            ];
        }

        $this->view->assign('rules', $rows);
        $this->view->assign('pendingTotal', array_sum(array_column($rows, 'pendingCount')));
    }

    public function synchronizeRuleAction(int $ruleIndex): void
    {
        $rules = SynchronizationRules::fromArray($this->synchronization);
        $rule = $rules->items[$ruleIndex] ?? null;
        if (!$rule instanceof SynchronizationRule) {
            $this->addFlashMessage($this->translateById('flash.syncRuleNotFound'), '', Message::SEVERITY_ERROR);
            $this->forward('synchronizationStatus');
        }

        $result = $this->workspaceSynchronizer->synchronizeRule(
            ContentRepositoryId::fromString($this->contentRepositoryIdentifier),
            $rule,
        );
        $this->addSynchronizationResultFlashMessage($rule, $result);
        $this->forward('synchronizationStatus');
    }

    public function synchronizeAllRulesAction(): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($this->contentRepositoryIdentifier);
        foreach (SynchronizationRules::fromArray($this->synchronization) as $rule) {
            $result = $this->workspaceSynchronizer->synchronizeRule($contentRepositoryId, $rule);
            $this->addSynchronizationResultFlashMessage($rule, $result);
        }
        $this->forward('synchronizationStatus');
    }

    private function addSynchronizationResultFlashMessage(SynchronizationRule $rule, WorkspaceSynchronizationResult $result): void
    {
        $label = sprintf('%s → %s', $rule->sourceDimension, $rule->targetDimension);
        if ($result->skippedReason !== null) {
            $this->addFlashMessage(
                $this->translateById('flash.syncSkipped', [$label, $result->skippedReason]),
                '',
                Message::SEVERITY_WARNING,
            );
            return;
        }
        // Counts are passed as separate placeholders with neutral label:count phrasing so the message can be
        // localized without porting English inline pluralization (propert-y/-ies, tag change-/s) to other languages.
        $this->addFlashMessage($this->translateById('flash.syncResult', [
            $label,
            $result->totalStalePropertyCommandsDispatched(),
            $result->totalVariantCommandsDispatched(),
            $result->totalRemovalCommandsDispatched(),
            $result->totalTagCommandsDispatched(),
            $result->totalSkippedNodes(),
        ]));

        $nodesRequiringFullSync = $result->totalNodesRequiringFullSync();
        if ($nodesRequiringFullSync > 0) {
            $this->addFlashMessage(
                $this->translateById('flash.fullSyncNeeded', [
                    $label,
                    $nodesRequiringFullSync,
                    $rule->sourceWorkspaceName,
                    $rule->sourceDimension,
                    $rule->targetWorkspaceName,
                    $rule->targetDimension,
                ]),
                '',
                Message::SEVERITY_WARNING,
            );
        }
    }

    /**
     * Resolves a backend-module flash message from the Modules.xlf catalog in the current backend UI language,
     * falling back to the id itself if the catalog has no matching unit.
     *
     * @param array<int,string|int> $arguments
     */
    private function translateById(string $id, array $arguments = []): string
    {
        return $this->translator->translateById($id, $arguments, null, null, 'Modules', 'Sitegeist.LostInTranslation') ?? $id;
    }

    // Renders the fusion view for the form to store a custom deepl key
    public function setCustomKeyAction(): void
    {
    }

    public function storeCustomKeyAction(string $key): void
    {
        $this->customAuthenticationKeyService->set($key);
        $this->forward('index');
    }

    public function removeCustomKeyAction(): void
    {
        $this->customAuthenticationKeyService->remove();
        $this->forward('index');
    }

    /**
     * The list of supported language pairs is narrowed by only including exising language dimension
     * values and removing already existing glossaries. We only look into the first segments as this
     * deepl glossaries currently do not support country identifiers
     */
    public function createGlossaryAction(): void
    {
        $languageKeysOfInterest = [];
        $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($this->contentRepositoryIdentifier));
        $languageDimension = $contentRepository->getContentDimensionSource()->getDimension(new ContentDimensionId($this->languageDimensionName));
        $languageDimensionValues = $languageDimension?->getRootValues() ?? [];
        foreach ($languageDimensionValues as $languageDimensionValue) {
            $deeplLanguage = $languageDimensionValue->getConfigurationValue('options.deeplLanguage');
            if ($deeplLanguage) {
                $deeplLanguagesParts = explode(':', $deeplLanguage);
                foreach ($deeplLanguagesParts as $deeplLanguagesPart) {
                    $keyParts = explode('-', $deeplLanguagesPart);
                    $languageKeysOfInterest[] = strtolower($keyParts[0]);
                }
            } else {
                $keyParts = explode('-', $languageDimensionValue->value);
                $languageKeysOfInterest[] = strtolower($keyParts[0]);
            }
        }

        $languageKeysOfInterest = array_unique($languageKeysOfInterest);
        $languagePairs = $this->glossaryService->getGlossaryLanguagePairs();
        $sourceTargetCombinations = [];
        foreach ($languagePairs as $languagePair) {
            if (in_array($languagePair->sourceLang, $languageKeysOfInterest) && in_array($languagePair->targetLang, $languageKeysOfInterest)) {
                $existingGlossary = $this->glossaryRepository->findOneBySourceAndTargetLanguageKey($languagePair->sourceLang, $languagePair->targetLang);
                if ($existingGlossary) {
                    continue;
                }
                $sourceTargetCombinations[] = $languagePair->sourceLang . ' -> ' . $languagePair->targetLang;
            }
        }

        $this->view->assign('sourceTargetCombinations', $sourceTargetCombinations);
    }

    public function addGlossaryAction(string $sourceAndTarget): void
    {
        list ($source, $target) = explode(' -> ', $sourceAndTarget);
        $existingGlossary = $this->glossaryRepository->findOneBySourceAndTargetLanguageKey($source, $target);
        if ($existingGlossary instanceof Glossary) {
            $this->addFlashMessage($this->translateById('flash.glossaryExists'), '', Message::SEVERITY_WARNING);
            $this->forward(actionName: 'showGlossary', arguments: ['glossary' => $existingGlossary]);
        }
        $glossary = Glossary::create($source, $target);
        $this->glossaryRepository->add($glossary);
        $this->forward('index');
    }

    public function showGlossaryAction(Glossary $glossary): void
    {
        $this->view->assign('glossary', $glossary);
    }

    public function uploadGlossaryAction(Glossary $glossary, bool $toIndex = false): void
    {
        $identifier = $this->glossaryService->uploadRemoteGlossary($glossary);

        if (is_string($identifier)) {
            $glossary->updateSynchronizationIdentifier($identifier);
            $this->glossaryRepository->update($glossary);
            $this->cacheService->flush();
            $deleted = $this->glossaryService->cleanupRemoteGlossaries();
            $removedNumber = count($deleted);
            if ($removedNumber == 0) {
                $this->addFlashMessage($this->translateById('flash.glossaryUploaded'), "");
            } elseif ($removedNumber == 1) {
                $this->addFlashMessage($this->translateById('flash.glossaryUploadedRemovedOne', [$removedNumber]), "");
            } else {
                $this->addFlashMessage($this->translateById('flash.glossaryUploadedRemovedMany', [$removedNumber]), "");
            }
        } else {
            $this->addFlashMessage($this->translateById('flash.uploadFailed'), "", Message::SEVERITY_ERROR);
        }

        if ($toIndex === true) {
            $this->forward(actionName: 'index');
        } else {
            $this->forward(actionName: 'showGlossary', arguments: ['glossary' => $glossary]);
        }
    }

    public function deleteGlossaryAction(Glossary $glossary): void
    {
        foreach ($glossary->entries as $entry) {
            $this->glossaryEntryRepository->remove($entry);
        }
        $this->glossaryRepository->remove($glossary);
        $this->addFlashMessage($this->translateById('flash.glossaryDeleted'));
        $this->forward('index');
    }

    public function createGlossaryEntryAction(Glossary $glossary): void
    {
        $this->view->assign('glossary', $glossary);
    }

    public function addGlossaryEntryAction(Glossary $glossary, string $sourceText, string $targetText): void
    {
        $entry = new GlossaryEntry();
        $entry->glossary = $glossary;
        $entry->sourceText = $sourceText;
        $entry->targetText = $targetText;
        $glossary->addEntry($entry);
        $this->glossaryRepository->update($glossary);
        $this->forward(actionName: 'showGlossary', arguments: ['glossary' => $glossary]);
    }
    public function editGlossaryEntryAction(GlossaryEntry $entry): void
    {
        $this->view->assign('entry', $entry);
        $this->view->assign('glossary', $entry->glossary);
    }

    public function updateGlossaryEntryAction(GlossaryEntry $entry, string $sourceText, string $targetText): void
    {

        $entry->sourceText = $sourceText;
        $entry->targetText = $targetText;
        $this->glossaryEntryRepository->update($entry);

        $glossary = $entry->glossary;
        $glossary->updateModificationDate();
        $this->glossaryRepository->update($entry->glossary);

        $this->forward(actionName: 'showGlossary', arguments: ['glossary' => $entry->glossary]);
    }

    public function deleteGlossaryEntryAction(GlossaryEntry $entry): void
    {
        $glossary = $entry->glossary;
        $glossary->removeEntry($entry);
        $this->glossaryRepository->update($glossary);
        $this->forward(actionName: 'showGlossary', arguments: ['glossary' => $glossary]);
    }
}
