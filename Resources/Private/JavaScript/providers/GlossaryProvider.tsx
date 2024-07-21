import * as React from 'react';
import { useContext, createContext } from 'react';

export interface GlossaryContextInterface {
    csrfToken: string;
}

export const GlossaryContext = createContext({});
export const useGlossary = () => useContext(GlossaryContext);

export const GlossaryProvider = ({ value, children }: { value: GlossaryContextInterface; children: any }) => {
    return <GlossaryContext.Provider value={value}>{children}</GlossaryContext.Provider>;
};
