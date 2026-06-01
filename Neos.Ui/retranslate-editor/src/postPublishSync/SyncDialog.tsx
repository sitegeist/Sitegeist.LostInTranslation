import React, { useEffect, useState } from 'react';
import { useDispatch } from 'react-redux';
import { Button, Dialog } from '@neos-project/react-ui-components';
import { actions } from '@neos-project/neos-ui-redux-store';
import { endpoints } from '../hooks/backend';
import { syncPromptBus, type SyncPromptPayload } from './promptBus';

/**
 * Modal shown after a publish when `ask`-mode synchronization rules have out-of-sync translations. Always mounted (via
 * the `Modals` container registry) but renders nothing until the post-publish saga emits a prompt. "Sync now" runs the
 * deferred synchronization in its own request and reports the outcome through a Neos UI flash message.
 */
export const SyncDialog: React.FC = () => {
    const dispatch = useDispatch();
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
            const translated = result.stalePropertyCommandsDispatched + result.variantCommandsDispatched;
            dispatch(actions.UI.FlashMessages.add(
                `lost-in-translation-sync-${Date.now()}`,
                `Translated ${translated} change(s) into the configured languages.`,
                'success'
            ));
            setPrompt(null);
        } catch (error) {
            dispatch(actions.UI.FlashMessages.add(
                `lost-in-translation-sync-error-${Date.now()}`,
                'Translation synchronization failed. You can retry from the Lost In Translation backend module.',
                'error'
            ));
        } finally {
            setIsSyncing(false);
        }
    };

    return (
        <Dialog
            isOpen
            title="Translate published changes"
            onRequestClose={close}
            actions={[
                <Button key="cancel" style="lighter" isDisabled={isSyncing} onClick={close}>
                    Not now
                </Button>,
                <Button key="sync" style="success" isDisabled={isSyncing} onClick={sync}>
                    {isSyncing ? 'Translating…' : 'Sync now'}
                </Button>
            ]}
        >
            <div style={{ padding: '16px' }}>
                {prompt.pendingCount} translation(s) are out of sync with the content you just published. Translate them
                into the configured languages now?
            </div>
        </Dialog>
    );
};
