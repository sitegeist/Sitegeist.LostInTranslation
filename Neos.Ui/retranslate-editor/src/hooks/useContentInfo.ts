import { useQuery } from '@tanstack/react-query';
import { endpoints } from './backend';

export const useContentInfo = (
    nodeId: string | null,
    workspace: string | null,
    dimensions: Record<string, string | null>,
    contentRepositoryId: string
) => {
    const enabled = Boolean(nodeId && workspace && Object.keys(dimensions).length);

    return useQuery({
        queryKey: ['lost-in-translation', 'content-info', nodeId, workspace, dimensions, contentRepositoryId],
        queryFn: async () => {
            return endpoints().getContentInfo({
                nodeAggregateId: nodeId as string,
                workspaceName: workspace as string,
                coordinates: dimensions,
                contentRepositoryId
            });
        },
        enabled,
        staleTime: 0,
        cacheTime: 0
    });
};
