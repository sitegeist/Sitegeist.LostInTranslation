<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use DateTimeImmutable;
use Neos\Error\Messages\Message;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Model\GlossaryEntry;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryRepository;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCustomAuthenticationKeyService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

class LostInTranslationModuleController extends AbstractModuleController
{
    /**
     * @var DeepLTranslationService
     * @Flow\Inject
     */
    protected $translationService;

    /**
     * @var GlossaryRepository
     * @Flow\Inject
     */
    protected $glossaryRepository;

    /**
     * @Flow\Inject
     * @var DeepLCustomAuthenticationKeyService
     */
    protected $customAuthenticationKeyService;

    /**
     * @var FusionView
     */
    protected $view;


    public function indexAction(): void
    {
        $status = $this->translationService->getStatus();
        $this->view->assign('status', $status);
        $this->view->assign('glossaries', $this->glossaryRepository->findAll());
    }

    public function createGlossaryAction(): void
    {
    }

    public function addGlossaryAction(string $source, string $target): void
    {
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

    public function synchronizeGlossaryAction(Glossary $glossary, bool $toIndex = false): void
    {
        if ($glossary->synchronizationIdentifier) {
            $this->addFlashMessage("Old glossary was deleted", "", Message::SEVERITY_NOTICE);
            $this->translationService->deleteGlossary($glossary->synchronizationIdentifier);
        }

        $identifier = $this->translationService->uploadGlossary($glossary);

        if (is_string($identifier)) {
            $glossary->updateSynchronizationIdentifier($identifier);
            $this->glossaryRepository->update($glossary);
            $this->addFlashMessage("Glossary was uploaded", "");
        } else {
            $this->addFlashMessage("Upload failed", "", Message::SEVERITY_ERROR);
        }

        if ($toIndex) {
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

    public function createEntryForGlossaryAction(Glossary $glossary): void
    {
        $this->view->assign('glossary', $glossary);
    }

    public function addEntryToGlossaryAction(Glossary $glossary, string $sourceText, string $targetText): void
    {
        $entry = new GlossaryEntry();
        $entry->glossary = $glossary;
        $entry->sourceText = $sourceText;
        $entry->targetText = $targetText;
        $glossary->addEntry($entry);
        $this->glossaryRepository->update($glossary);
        $this->forward(actionName: 'showGlossary', arguments: ['glossary' => $glossary]);
    }

    public function removeEntryFromGlossaryAction(GlossaryEntry $entry): void
    {
        $glossary = $entry->glossary;
        $glossary->removeEntry($entry);
        $this->glossaryRepository->update($glossary);
        $this->forward(actionName: 'showGlossary', arguments: ['glossary' => $glossary]);
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
}
