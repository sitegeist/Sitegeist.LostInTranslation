<?php

namespace Sitegeist\LostInTranslation\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryRepository;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCacheService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLGlossaryService;

class GlossaryCommandController extends CommandController
{
    #[Flow\Inject]
    protected DeepLGlossaryService $deepLGlossaryService;

    #[Flow\Inject]
    protected DeepLCacheService $deepLCacheService;

    #[Flow\Inject]
    protected GlossaryRepository $glossaryRepository;

    public function uploadAllCommand(): void
    {
        foreach ($this->glossaryRepository->findAll() as $glossary) {
            /**
             * @var Glossary $glossary
             */
            $id = $this->deepLGlossaryService->uploadRemoteGlossary($glossary);
            if ($id) {
                $this->output->outputLine(sprintf('Glossary %s was uploaded with id %s', $glossary->getLabel(), $id));
            }
        }
        $this->deepLCacheService->flush();
    }

    public function cleanupAllCommand(): void
    {
        $deleted = $this->deepLGlossaryService->cleanupRemoteGlossaries();
        $this->output->outputLine(sprintf('Removed %s outdated glossaries', count($deleted)));
    }
}
