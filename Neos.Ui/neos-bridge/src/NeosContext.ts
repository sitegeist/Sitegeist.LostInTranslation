import * as React from 'react';

import {IGlobalRegistry} from './GlobalRegistry';

export interface INeosContextProperties {
    globalRegistry: IGlobalRegistry
}

export const NeosContext = React.createContext<null | INeosContextProperties>(null);

export function useNeos() {
    const neos = React.useContext(NeosContext);

    if (!neos) {
        throw new Error('[Sitegeist.LostInTranslation: Could not determine Neos Context.');
    }

    return neos;
}