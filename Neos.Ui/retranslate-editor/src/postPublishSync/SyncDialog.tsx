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
            // One or more rules short-circuited (e.g. their target workspace is missing or not based on the source).
            // Surface those reasons as an error notification rather than silently reporting a partial success.
            if (result.errors && result.errors.length > 0) {
                dispatch(actions.UI.FlashMessages.add(
                    `lost-in-translation-sync-error-${Date.now()}`,
                    `Translation synchronization could not run: ${result.errors.join('; ')}`,
                    'error'
                ));
                setPrompt(null);
                return;
            }
            const translated = result.stalePropertyCommandsDispatched + result.variantCommandsDispatched;
            // Removals and subtree-tag changes (e.g. hide/show) are only mirrored by `remove-target` / `sync-to-target`
            // rules, so only mention them when they actually happened — and surface removals explicitly because they are
            // destructive.
            const extras = [
                result.removalCommandsDispatched > 0 ? `removed ${result.removalCommandsDispatched} node(s)` : null,
                result.tagCommandsDispatched > 0 ? `updated ${result.tagCommandsDispatched} visibility/tag change(s)` : null,
            ].filter(Boolean);
            const message = `Translated ${translated} change(s) into the configured languages.`
                + (extras.length > 0 ? ` Also ${extras.join(' and ')}.` : '');
            dispatch(actions.UI.FlashMessages.add(
                `lost-in-translation-sync-${Date.now()}`,
                message,
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
