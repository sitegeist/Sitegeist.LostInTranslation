import React from 'react';
import { useContentInfo } from './hooks/useContentInfo';
import { useNodeInfo } from './hooks/useNodeInfo';
import { useTranslate } from './hooks/useTranslate';

type RetranslateViewTarget = 'node' | 'document';

type RetranslateViewProps = {
    for: RetranslateViewTarget;
};

export const RetranslateView = ({for: target}: RetranslateViewProps) => {
    const nodeInfo = useNodeInfo(target);
    const contentInfoQuery = useContentInfo(nodeInfo.nodeId, nodeInfo.workspace, nodeInfo.dimensions);
    const translateMutation = useTranslate({target});

    const currentLanguage = nodeInfo.dimensions.language ?? 'unknown';

    console.log('RETRANSLATE VIEW NODE INFO', nodeInfo);
    console.log('RETRANSLATE VIEW CONTENT INFO QUERY', contentInfoQuery);
    console.log('RETRANSLATE VIEW TRANSLATE MUTATION', translateMutation);

    return (
        <div>
            <div>LOST IN TRANSLATION ({target})</div>
            <div>Language: {currentLanguage}</div>
            <div>Workspace: {nodeInfo.workspace ?? 'unknown'}</div>
            <div>Translate: {nodeInfo.translate}</div>
            <div>Node identifier: {nodeInfo.nodeId ?? 'unknown'}</div>

            ---------------

            {contentInfoQuery.isFetching && <div>fetching</div>}

            <div>Reference language: {contentInfoQuery.data?.referenceLang ?? 'unknown'}</div>
            <div>Last modification: {contentInfoQuery.data?.lastModification ?? 'unknown'}</div>
        </div>
    );
};
