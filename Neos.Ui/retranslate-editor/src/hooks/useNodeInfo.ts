import { selectors } from '@neos-project/neos-ui-redux-store';
import { useSelector } from 'react-redux';
import type { RetranslateTarget } from './backend';

type NodeInfoResult = {
    nodeId: string | null;
    dimensions: Record<string, string | null>;
    workspace: string | null;
    translate: 'nodes' | 'document';
};

/**
 * Context paths look like "/sites/example/page@user-jdoe;language=de,en&country=at". The first value of
 * each dimension is the one the node was requested for.
 *
 * The dimensions are read from the inspected node instead of the globally active ones: when a dimension is
 * switched to one the node does not exist in, the Neos UI updates the active dimension before it resolves
 * the node, so the two do not match while the "create variant" dialog is open.
 */
const dimensionsFromContextPath = (contextPath: string): Record<string, string | null> => {
    const dimensionString = contextPath.split('@').pop()?.split(';')[1] ?? '';
    if (dimensionString === '') {
        return {};
    }

    return Object.fromEntries(
        dimensionString.split('&').map((dimension) => {
            const [dimensionName, values = ''] = dimension.split('=');
            return [dimensionName, values.split(',')[0] || null];
        })
    );
};

export const useNodeInfo = (target: RetranslateTarget): NodeInfoResult => {
    const { dimensions, workspace, nodeId } = useSelector((state: any) => {
        const getNodeByContextPath = selectors.CR.Nodes.nodeByContextPath(state);
        const focusedNodePath = selectors.CR.Nodes.focusedNodePathSelector(state);
        const documentNodePath = state?.cr?.nodes?.documentNode ?? null;
        const focusedNode = focusedNodePath ? getNodeByContextPath(focusedNodePath) : null;
        const documentNode = documentNodePath ? getNodeByContextPath(documentNodePath) : null;
        const activeNode = target === 'document' ? documentNode : focusedNode;

        return {
            dimensions: activeNode?.contextPath ? dimensionsFromContextPath(activeNode.contextPath) : {},
            workspace: state?.cr?.workspaces?.personalWorkspace?.name ?? null,
            nodeId: activeNode?.identifier ?? null
        };
    });

    return {
        nodeId,
        dimensions,
        workspace,
        translate: target === 'document' ? 'document' : 'nodes'
    };
};
