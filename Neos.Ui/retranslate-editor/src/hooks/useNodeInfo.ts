import { selectors } from '@neos-project/neos-ui-redux-store';
import { useSelector } from 'react-redux';
import type { RetranslateTarget } from './backend';

type NodeInfoResult = {
    nodeId: string | null;
    dimensions: Record<string, string | null>;
    workspace: string | null;
    contentRepositoryId: string;
    translate: 'nodes' | 'document';
};

const extractContentRepositoryId = (contextPath: string | null | undefined): string | null => {
    if (!contextPath) {
        return null;
    }

    try {
        const nodeAddress = JSON.parse(contextPath);
        return typeof nodeAddress?.contentRepositoryId === 'string'
            ? nodeAddress.contentRepositoryId
            : null;
    } catch {
        return null;
    }
};

export const useNodeInfo = (target: RetranslateTarget): NodeInfoResult => {
    const { dimensions, workspace, nodeId, contentRepositoryId } = useSelector((state: any) => {
        const activeDimensions = selectors.CR.ContentDimensions.active(state) ?? {};
        const getNodeByContextPath = selectors.CR.Nodes.nodeByContextPath(state);
        const focusedNodePath = selectors.CR.Nodes.focusedNodePathSelector(state);
        const documentNodePath = state?.cr?.nodes?.documentNode ?? null;
        const focusedNode = focusedNodePath ? getNodeByContextPath(focusedNodePath) : null;
        const documentNode = documentNodePath ? getNodeByContextPath(documentNodePath) : null;
        const activeNode = target === 'document' ? documentNode : focusedNode;
        const normalizedDimensions = Object.fromEntries(
            Object.entries(activeDimensions).map(([dimensionName, values]) => [
                dimensionName,
                Array.isArray(values) ? values[0] ?? null : null
            ])
        ) as Record<string, string | null>;

        return {
            dimensions: normalizedDimensions,
            workspace: state?.cr?.workspaces?.personalWorkspace?.name ?? null,
            nodeId: activeNode?.identifier ?? null,
            contentRepositoryId:
                extractContentRepositoryId(activeNode?.contextPath)
                ?? extractContentRepositoryId(documentNodePath)
                ?? 'default'
        };
    });

    return {
        nodeId,
        dimensions,
        workspace,
        contentRepositoryId,
        translate: target === 'document' ? 'document' : 'nodes'
    };
};
