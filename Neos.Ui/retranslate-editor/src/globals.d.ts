declare module '@neos-project/react-ui-components'
declare module '@neos-project/neos-ui-i18n'

declare module '@neos-project/neos-ui-extensibility' {
    export class SynchronousRegistry<T> {
        set(key: string, value: T): void
        get<R = T>(key: string): R | undefined
        getAllAsList<R = T>(): R[]
    }
}

declare module 'react-redux' {
    export function useSelector<T>(selector: (state: any) => T): T
    export function useDispatch(): (action: { type: string; payload?: unknown }) => void
}

declare module 'redux-saga/effects' {
    type Effect = { type: string; payload?: unknown }
    export function call(fn: (...args: any[]) => any, ...args: any[]): Effect
    export function select(selector?: (state: any) => any): Effect
    export function takeEvery(pattern: string, saga: (...args: any[]) => any): Effect
}

declare module '@neos-project/neos-ui-redux-store' {
    type Action = {
        type: string
        payload?: unknown
    }

    type Node = {
        contextPath?: string | null
        identifier?: string | null
        dimensions?: {
            language?: string | null
        }
    }

    type Selectors = {
        CR: {
            ContentDimensions: {
                active: (state: any) => {
                    [dimensionName: string]: string[] | undefined
                }
            }
            Nodes: {
                focusedNodePathSelector: (state: any) => string | null
                nodeByContextPath: (state: any) => (contextPath: string) => Node | null
            }
        }
    }

    type Actions = {
        UI: {
            ContentCanvas: {
                reload: (uri?: string) => Action
            }
            FlashMessages: {
                add: (id: string, message: string, severity: 'success' | 'error' | 'info') => Action
            }
        }
    }

    export const selectors: Selectors
    export const actions: Actions
}
