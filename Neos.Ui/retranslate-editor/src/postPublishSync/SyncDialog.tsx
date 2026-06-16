import React, { useEffect, useState } from 'react';
import { useDispatch } from 'react-redux';
import { Button, Dialog } from '@neos-project/react-ui-components';
import { actions } from '@neos-project/neos-ui-redux-store';
import { useI18n } from '@sitegeist/lostintranslation-neos-bridge';
import { endpoints } from '../hooks/backend';
import { syncPromptBus, type SyncPromptPayload } from './promptBus';

/**
 * Modal shown after a publish when `ask`-mode synchronization rules have out-of-sync translations. Always mounted (via
 * the `Modals` container registry) but renders nothing until the post-publish saga emits a prompt. "Sync now" runs the
 * deferred synchronization in its own request and reports the outcome through a Neos UI flash message.
 */
export const SyncDialog: React.FC = () => {
    const dispatch = useDispatch();
    const t = useI18n();
    const [prompt, setPrompt] = useState<SyncPromptPayload | null>(null);
    const [isSyncing, setIsSyncing] = useState(false);

    useEffect(() => syncPromptBus.subscribe(setPrompt), []);

    if (!prompt) {
        return null;
    }

    const close = () => {
        if (!isSyncing) {
            setPrompt(null);
        }
    };

    const sync = async () => {
        setIsSyncing(true);
        try {
            const result = await endpoints().synchronize({ workspaceName: prompt.workspaceName });
            // One or more rules short-circuited (e.g. their target workspace is missing or not based on the source).
            // Surface those reasons as an error notification rather than silently reporting a partial success.
            if (result.errors && result.errors.length > 0) {
                dispatch(actions.UI.FlashMessages.add(
                    `lost-in-translation-sync-error-${Date.now()}`,
                    t('syncDialog.error.couldNotRun', '', { errors: result.errors.join('; ') }, 'Sitegeist.LostInTranslation', 'Main'),
                    'error'
                ));
                setPrompt(null);
                return;
            }
            const translated = result.stalePropertyCommandsDispatched + result.variantCommandsDispatched;
            // Removals and subtree-tag changes (e.g. hide/show) are only mirrored by `remove-target` / `sync-to-target`
            // rules, so only mention them when they actually happened — and surface removals explicitly because they are
            // destructive. Each extra is its own appended sentence so the message localizes without English "X and Y"
            // conjunction grammar.
            let message = t('syncDialog.success', '', { count: String(translated) }, 'Sitegeist.LostInTranslation', 'Main');
            if (result.removalCommandsDispatched > 0) {
                message += ' ' + t('syncDialog.success.removed', '', { count: String(result.removalCommandsDispatched) }, 'Sitegeist.LostInTranslation', 'Main');
            }
            if (result.tagCommandsDispatched > 0) {
                message += ' ' + t('syncDialog.success.tags', '', { count: String(result.tagCommandsDispatched) }, 'Sitegeist.LostInTranslation', 'Main');
            }
            dispatch(actions.UI.FlashMessages.add(
                `lost-in-translation-sync-${Date.now()}`,
                message,
                'success'
            ));
            setPrompt(null);
        } catch (error) {
            dispatch(actions.UI.FlashMessages.add(
                `lost-in-translation-sync-error-${Date.now()}`,
                t('syncDialog.error.failed', '', {}, 'Sitegeist.LostInTranslation', 'Main'),
                'error'
            ));
        } finally {
            setIsSyncing(false);
        }
    };

    return (
        <Dialog
            isOpen
            title={t('syncDialog.title', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
            onRequestClose={close}
            actions={[
                <Button key="cancel" style="lighter" disabled={isSyncing} onClick={close}>
                    {t('syncDialog.cancel', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                </Button>,
                <Button key="sync" style="success" disabled={isSyncing} onClick={sync}>
                    {isSyncing
                        ? t('syncDialog.syncing', '', {}, 'Sitegeist.LostInTranslation', 'Main')
                        : t('syncDialog.sync', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                </Button>
            ]}
        >
            <div style={{ padding: '16px' }}>
                {t('syncDialog.body', '', { pendingCount: String(prompt.pendingCount) }, 'Sitegeist.LostInTranslation', 'Main')}
            </div>
        </Dialog>
    );
};
