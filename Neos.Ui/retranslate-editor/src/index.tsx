import React from 'react';
import { NeosContext, type IGlobalRegistry } from '@sitegeist/lostintranslation-neos-bridge';
import { SynchronousRegistry } from '@neos-project/neos-ui-extensibility';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RetranslateView } from './RetranslateView';

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
