import React from 'react';
import { format, parseISO } from 'date-fns';
import { useI18n } from '@sitegeist/lostintranslation-neos-bridge';
import { useContentInfo } from './hooks/useContentInfo';
import { useNodeInfo } from './hooks/useNodeInfo';
import { useTranslate } from './hooks/useTranslate';
import { Button } from '@neos-project/react-ui-components'
import { Container, Info, LoadingContainer, Spinner } from './components';

type RetranslateViewTarget = 'node' | 'document';

type RetranslateViewProps = {
    for: RetranslateViewTarget;
};

export const RetranslateView = ({for: target}: RetranslateViewProps) => {
    const t = useI18n();
    const nodeInfo = useNodeInfo(target);
    const { data: contentData, isLoading: contentIsLoading } = useContentInfo(nodeInfo.nodeId, nodeInfo.workspace, nodeInfo.dimensions);
    const { isPending: translationPending, mutate: translate } = useTranslate({target});
    const formattedReferenceDate = contentData?.referenceLanguage?.dateModified
        ? format(parseISO(contentData.referenceLanguage.dateModified), 'dd.MM.yyyy')
        : 'no date';

    if (contentIsLoading) {
        return (
            <Container>
                <LoadingContainer>
                    <Spinner />
                    <Info>
                        {t('view.loading', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                    </Info>
                </LoadingContainer>
            </Container>
        );
    }

    if (!contentData) {
        return null;
    }

    if (!contentData.referenceLanguage) {
        return null;
    }

    const handleTranslate = () => {
        translate();
    };

    return (
        <Container>
            {contentData && contentData.isUpToDate ?
                <Info>
                    {t('view.upToDate', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                </Info>
                : <Info>
                    {t(
                        'view.outdated',
                        '',
                        {
                            language: contentData?.referenceLanguage?.label ?? '',
                            date: formattedReferenceDate
                        },
                        'Sitegeist.LostInTranslation',
                        'Main'
                    )}
                </Info>
            }
            {translationPending && (
                <LoadingContainer>
                    <Spinner />
                    <Info>
                        {t('view.translating', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                    </Info>
                </LoadingContainer>
            )}
            {!contentData.isUpToDate && !translationPending && (
                <Button onClick={handleTranslate}>
                    {t('button.translate', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                </Button>
            )}
        </Container>
    );
};
