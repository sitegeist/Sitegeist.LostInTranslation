<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use DeepL\GlossaryInfo;
use Neos\Error\Messages\Message;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Model\GlossaryEntry;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryEntryRepository;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryRepository;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCacheService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCustomAuthenticationKeyService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLGlossaryService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

class LostInTranslationModuleController extends AbstractModuleController
{
    /**
     * @var DeepLTranslationService
     * @Flow\Inject
     */
    protected $translationService;

    /**
     * @var DeepLCacheService
     * @Flow\Inject
     */
    protected $cacheService;

    /**
     * @var DeepLGlossaryService
     * @Flow\Inject
     */
    protected $glossaryService;

    /**
     * @var GlossaryRepository
     * @Flow\Inject
     */
    protected $glossaryRepository;

    /**
     * @var GlossaryEntryRepository
     * @Flow\Inject
     */
    protected $glossaryEntryRepository;

    /**
     * @Flow\Inject
     * @var DeepLCustomAuthenticationKeyService
     */
    protected $customAuthenticationKeyService;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array<string,array{'default': string, 'defaultPreset': string, 'presets': array<string,mixed> }>
     */
    protected $contentDimensionConfiguration;

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
        $languagePresets = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'];
        foreach ($languagePresets as $key => $languagePreset) {
            $deeplLanguage = $languagePreset['options']['deeplLanguage'] ?? null;
            if ($deeplLanguage) {
                $deeplLanguagesParts = explode(':', $deeplLanguage);
                foreach ($deeplLanguagesParts as $deeplLanguagesPart) {
                    $keyParts = explode('-', $deeplLanguagesPart);
                    $languageKeysOfInterest[] = strtolower($keyParts[0]);
                }
            } else {
                $keyParts = explode('-', $key);
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
            $this->addFlashMessage('Glossary already exists!', '', Message::SEVERITY_WARNING);
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
                $this->addFlashMessage("Glossary was uploaded", "");
            } elseif ($removedNumber == 1) {
                $this->addFlashMessage(sprintf("Glossary was uploaded, %s outdated glossary was removed", $removedNumber), "");
            } else {
                $this->addFlashMessage(sprintf("Glossary was uploaded, %s outdated glossaries were removed", $removedNumber), "");
            }
        } else {
            $this->addFlashMessage("Upload failed", "", Message::SEVERITY_ERROR);
        }

        if ($toIndex === true) {
            $this->forward(actionName: 'index');
        } else {
            $this->forward(actionName: 'showGlossary', arguments: ['glossary' => $glossary]);
        }
    }

    public function deleteGlossaryAction(Glossary $glossary): void
    {
        $this->glossaryRepository->remove($glossary);
        $this->addFlashMessage('Glossary deleted');
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
