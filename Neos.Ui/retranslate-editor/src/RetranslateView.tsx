import React from 'react';
import { useI18n } from '@sitegeist/lostintranslation-neos-bridge';
import { useContentInfo } from './hooks/useContentInfo';
import { useNodeInfo } from './hooks/useNodeInfo';
import { useTranslate } from './hooks/useTranslate';
import { Button } from '@neos-project/react-ui-components'
import { ButtonsContainer, Container, Info, LoadingContainer, Spinner } from './components';

type RetranslateViewTarget = 'node' | 'document';

type RetranslateViewProps = {
    for: RetranslateViewTarget;
};

export const RetranslateView = ({for: target}: RetranslateViewProps) => {
    const t = useI18n();
    const nodeInfo = useNodeInfo(target);
    const { data: contentData, isFetching: contentIsFetching } = useContentInfo(nodeInfo.nodeId, nodeInfo.workspace, nodeInfo.dimensions);
    const { isPending: translationPending, mutate: translate } = useTranslate({target});

    if (contentIsFetching) {
        return (
            <Container>
                <LoadingContainer>
                    <Spinner />
                    <Info>
                        {t('view.loading', 'Loading translation status...', {}, 'Sitegeist.LostInTranslation', 'Main')}
                    </Info>
                </LoadingContainer>
            </Container>
        );
    }

    if (!contentData) {
        return null;
    }

    const handleTranslate = (translateTarget: RetranslateViewTarget) => () => {
        console.log(translateTarget);
        translate(translateTarget);
    };

    const renderButtonLabel = (label: string) => (
        <LoadingContainer>
            {translationPending && <Spinner />}
            <span>{label}</span>
        </LoadingContainer>
    );

    return (
        <Container>
            {contentData && contentData.isUpToDate ?
                <Info>
                    {t('view.upToDate', 'The translations are up to date.', {}, 'Sitegeist.LostInTranslation', 'Main')}
                </Info>
                : <Info>
                    {t(
                        'view.outdated',
                        'Changes in language {language} from {date} found. Retranslate this content now?',
                        {
                            language: contentData?.referenceLanguage?.label ?? '',
                            date: contentData?.referenceLanguage?.lastModification ?? ''
                        },
                        'Sitegeist.LostInTranslation',
                        'Main'
                    )}
                </Info>
            }
            {!contentData.isUpToDate && (
                target === 'document' ? (
                    <ButtonsContainer>
                        <Button onClick={handleTranslate('node')} isDisabled={translationPending}>
                            {renderButtonLabel(t('button.translateContents', 'Retranslate all contents', {}, 'Sitegeist.LostInTranslation', 'Main'))}
                        </Button>
                        <Button onClick={handleTranslate('document')} isDisabled={translationPending}>
                            {renderButtonLabel(t('button.translateDocument', 'Retranslate document properties', {}, 'Sitegeist.LostInTranslation', 'Main'))}
                        </Button>
                    </ButtonsContainer>
                ) : (
                    <Button onClick={handleTranslate('node')} isDisabled={translationPending}>
                        {renderButtonLabel(t('button.translate', 'Translate', {}, 'Sitegeist.LostInTranslation', 'Main'))}
                    </Button>
                )
            )}
        </Container>
    );
};
