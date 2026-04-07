import { useMutation } from '@tanstack/react-query';
import { endpoints, type RetranslateTarget } from './backend';
import { useNodeInfo } from './useNodeInfo';

type UseTranslateParams = {
    target: RetranslateTarget;
};

export const useTranslate = ({target}: UseTranslateParams) => {
    const nodeInfo = useNodeInfo(target);
    const { nodeId, dimensions, workspace, translate } = nodeInfo;

    return useMutation({
        mutationKey: ['lost-in-translation', 'translate', nodeId, dimensions, workspace, translate],
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
                nodeId,
                dimensions,
                workspace,
                translate
            });
        }
    });
};
