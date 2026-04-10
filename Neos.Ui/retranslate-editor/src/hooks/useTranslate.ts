import { useMutation, useQueryClient } from '@tanstack/react-query';
import { actions } from '@neos-project/neos-ui-redux-store';
import { useStore } from 'react-redux';
import { endpoints, type RetranslateTarget } from './backend';
import { useNodeInfo } from './useNodeInfo';

type UseTranslateParams = {
    target: RetranslateTarget;
};

export const useTranslate = ({target}: UseTranslateParams) => {
    const store = useStore<any>();
    const queryClient = useQueryClient();
    const nodeInfo = useNodeInfo(target);
    const { nodeId, dimensions, workspace } = nodeInfo;

    return useMutation({
        mutationKey: ['lost-in-translation', 'translate', nodeId, dimensions, workspace, target],
        mutationFn: async () => {
            if (!nodeId) {
                throw new Error('Missing nodeId');
            }

            if (!Object.keys(dimensions).length) {
                throw new Error('Missing dimensions');
            }

            if (!workspace) {
                throw new Error('Missing workspace');
            }

            return endpoints().translate({
                nodeAggregateId: nodeId,
                workspaceName: workspace,
                targetCoordinates: JSON.stringify(dimensions)
            });
        },
        onSuccess: () => {
            const contentCanvasSrc = store.getState()?.ui?.contentCanvas?.src as string | undefined;
            queryClient.invalidateQueries({
                queryKey: ['lost-in-translation', 'content-info', nodeId, workspace, dimensions]
            });
            store.dispatch(actions.UI.ContentCanvas.reload(contentCanvasSrc));
        }
    });
};
