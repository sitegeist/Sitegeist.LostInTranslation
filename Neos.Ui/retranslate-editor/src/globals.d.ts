declare module '@neos-project/react-ui-components'
declare module '@neos-project/neos-ui-i18n'
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
        }
    }

    export const selectors: Selectors
    export const actions: Actions
}
