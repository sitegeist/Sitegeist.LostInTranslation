<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;

/**
 * Dispatches a Content Repository command with AI authorship active, so the resulting events are attributed to the AI
 * translation service rather than the editor who triggered the run.
 *
 * The `try/finally` is load-bearing: an exception inside `handle()` must still reset the singleton runtime state.
 */
#[Flow\Scope('singleton')]
class AiCommandDispatcher
{
    #[Flow\Inject]
    protected AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState;

    #[Flow\Inject]
    protected TranslationServiceInterface $translationService;

    public function dispatch(ContentRepository $contentRepository, CommandInterface $command): void
    {
        $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
        try {
            $contentRepository->handle($command);
        } finally {
            $this->aiSystemTranslationRuntimeState->resetActiveAIServiceId();
        }
    }
}
