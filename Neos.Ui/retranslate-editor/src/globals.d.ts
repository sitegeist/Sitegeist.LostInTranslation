declare module '@neos-project/react-ui-components'
declare module '@neos-project/neos-ui-redux-store' {
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

    export const selectors: Selectors
}
