<?php

namespace Sitegeist\LostInTranslation\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryRepository;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLGlossaryService;

class GlossaryCommandController extends CommandController
{
    #[Flow\Inject]
    protected DeepLGlossaryService $deepLGlossaryService;

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
    }

    public function cleanupAllCommand(): void
    {
        $num = $this->deepLGlossaryService->cleanupRemoteGlossaries();
        $this->output->outputLine(sprintf('Removed %s outdated glossaries', $num));
    }
}
