import React from 'react';
import { NeosContext, type IGlobalRegistry } from '@sitegeist/lostintranslation-neos-bridge';
import { SynchronousRegistry } from '@neos-project/neos-ui-extensibility';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RetranslateView } from './RetranslateView';
import { watchPublishSucceeded } from './postPublishSync/saga';
import { SyncDialog } from './postPublishSync/SyncDialog';

const queryClient = new QueryClient();

const registerView = (
    viewsRegistry: SynchronousRegistry<any>,
    globalRegistry: IGlobalRegistry,
    viewName: string,
    target: 'node' | 'document'
) => {
    viewsRegistry.set(viewName, {
        component: () => (
            <QueryClientProvider client={queryClient}>
                <NeosContext.Provider value={{globalRegistry}}>
                    <RetranslateView for={target} />
                </NeosContext.Provider>
            </QueryClientProvider>
        )
    });
};

export function registerRetranslateView(globalRegistry: IGlobalRegistry): void {
    const inspectorRegistry = globalRegistry.get('inspector');
    if (!inspectorRegistry) {
        console.warn('[Sitegeist.LostInTranslation.RetranslateView]: Could not find inspector registry.');
        console.warn('[Sitegeist.LostInTranslation.RetranslateView]: Skipping registration of RetranslateView...');
        return;
    }

    const viewsRegistry = inspectorRegistry.get<SynchronousRegistry<any>>('views');
    if (!viewsRegistry) {
        console.warn('[Sitegeist.LostInTranslation.RetranslateView]: Could not find inspector views registry.');
        console.warn('[Sitegeist.LostInTranslation.RetranslateView]: Skipping registration of RetranslateView...');
        return;
    }

    registerView(
        viewsRegistry,
        globalRegistry,
        'Sitegeist.LostInTranslation/Inspector/Views/RetranslateNodeView',
        'node'
    );
    registerView(
        viewsRegistry,
        globalRegistry,
        'Sitegeist.LostInTranslation/Inspector/Views/RetranslateDocumentView',
        'document'
    );
}

/**
 * Registers the post-publish "sync now" prompt for `ask`-mode synchronization rules: a saga that checks for out-of-sync
 * translations after a successful publish, and the dialog (mounted via the `Modals` container) it opens.
 */
export function registerPostPublishSync(globalRegistry: IGlobalRegistry): void {
    const sagasRegistry = globalRegistry.get('sagas');
    if (sagasRegistry) {
        sagasRegistry.set('Sitegeist.LostInTranslation/watchPublishSucceeded', { saga: watchPublishSucceeded });
    } else {
        console.warn('[Sitegeist.LostInTranslation]: Could not find sagas registry; post-publish sync prompt disabled.');
    }

    const containersRegistry = globalRegistry.get('containers');
    if (containersRegistry) {
        // The dialog is mounted by the core `Modals` container, which sits inside the Redux provider (so `useDispatch`
        // works) but outside our `NeosContext`. Wrap it so its `useI18n` hook can reach the global registry — without
        // this provider the hook throws "Could not determine Neos Context." on every content-module load.
        containersRegistry.set('Modals/LostInTranslationSyncDialog', () => (
            <NeosContext.Provider value={{globalRegistry}}>
                <SyncDialog />
            </NeosContext.Provider>
        ));
    } else {
        console.warn('[Sitegeist.LostInTranslation]: Could not find containers registry; post-publish sync dialog disabled.');
    }
}
