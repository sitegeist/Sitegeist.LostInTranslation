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

type NodeAddress = {
    contentRepositoryId?: unknown;
    dimensionSpacePoint?: unknown;
};

const parseNodeAddress = (contextPath: string | null | undefined): NodeAddress | null => {
    if (!contextPath) {
        return null;
    }

    try {
        const nodeAddress = JSON.parse(contextPath);
        return typeof nodeAddress === 'object' && nodeAddress !== null ? (nodeAddress as NodeAddress) : null;
    } catch {
        return null;
    }
};

const extractContentRepositoryId = (nodeAddress: NodeAddress | null): string | null =>
    typeof nodeAddress?.contentRepositoryId === 'string' ? nodeAddress.contentRepositoryId : null;

/**
 * The dimension space point is taken from the inspected node's address instead of the globally active
 * dimensions: when a dimension is switched to one the node does not exist in, the Neos UI marks the new
 * dimension as active before it resolves the document, so the two do not belong together while the
 * "create variant" dialog is open. The status shown would then be the one of a different variant.
 */
const extractDimensions = (nodeAddress: NodeAddress | null): Record<string, string | null> => {
    const dimensionSpacePoint = nodeAddress?.dimensionSpacePoint;
    if (typeof dimensionSpacePoint !== 'object' || dimensionSpacePoint === null) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(dimensionSpacePoint as Record<string, unknown>).map(([dimensionName, value]) => [
            dimensionName,
            typeof value === 'string' ? value : null
        ])
    );
};

export const useNodeInfo = (target: RetranslateTarget): NodeInfoResult => {
    const { dimensions, workspace, nodeId, contentRepositoryId } = useSelector((state: any) => {
        const getNodeByContextPath = selectors.CR.Nodes.nodeByContextPath(state);
        const focusedNodePath = selectors.CR.Nodes.focusedNodePathSelector(state);
        const documentNodePath = state?.cr?.nodes?.documentNode ?? null;
        const focusedNode = focusedNodePath ? getNodeByContextPath(focusedNodePath) : null;
        const documentNode = documentNodePath ? getNodeByContextPath(documentNodePath) : null;
        const activeNode = target === 'document' ? documentNode : focusedNode;
        const activeNodeAddress = parseNodeAddress(activeNode?.contextPath);

        return {
            dimensions: extractDimensions(activeNodeAddress),
            workspace: state?.cr?.workspaces?.personalWorkspace?.name ?? null,
            nodeId: activeNode?.identifier ?? null,
            contentRepositoryId:
                extractContentRepositoryId(activeNodeAddress)
                ?? extractContentRepositoryId(parseNodeAddress(documentNodePath))
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
