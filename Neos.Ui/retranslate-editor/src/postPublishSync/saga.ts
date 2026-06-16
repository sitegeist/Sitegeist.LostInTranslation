import { call, select, takeEvery } from 'redux-saga/effects';
import { endpoints } from '../hooks/backend';
import { syncPromptBus } from './promptBus';

// Neos UI publishing actions. We subscribe to the literal type strings rather than imported constants so we do not
// depend on the (version-specific) public export shape of @neos-project/neos-ui-redux-store.
//
// A successful publish in this Neos UI dispatches STARTED -> FINISHED (the SUCEEDED action only occurs on the
// confirmation-dialog path). FINISHED is therefore the reliable completion signal; STARTED carries the `mode` so we can
// act on publishes only, not discards.
const PUBLISHING_STARTED = '@neos/neos-ui/CR/Publishing/STARTED';
const PUBLISHING_FINISHED = '@neos/neos-ui/CR/Publishing/FINISHED';
// PublishingMode enum: 0 = PUBLISH, 1 = DISCARD.
const PUBLISHING_MODE_PUBLISH = 0;

/**
 * After a workspace publish finishes, ask the backend how many `ask`-mode translations are now out of sync for the
 * published (base) workspace. If there are any, open the {@link SyncDialog} via the prompt bus.
 *
 * We gate on `mode === PUBLISH` (ignoring discards) and on `pendingCount > 0`, and guard against the duplicate FINISHED
 * the publishing flow emits.
 */
export function* watchPublishSucceeded(): Generator<unknown, void, any> {
    let lastMode: number | null = null;
    let handling = false;

    yield takeEvery(PUBLISHING_STARTED, function* captureMode(action: any) {
        lastMode = typeof action?.payload?.mode === 'number' ? action.payload.mode : null;
    });

    yield takeEvery(PUBLISHING_FINISHED, function* handlePublishFinished(): Generator<unknown, void, any> {
        if (lastMode !== PUBLISHING_MODE_PUBLISH || handling) {
            return;
        }
        handling = true;
        lastMode = null;
        try {
            const state = yield select();
            const workspaceName: string | null = state?.cr?.workspaces?.personalWorkspace?.baseWorkspace || null;
            if (!workspaceName) {
                return;
            }

            const pending = yield call(endpoints().getPending, { workspaceName });
            if (pending.pendingCount > 0) {
                syncPromptBus.emit({ workspaceName, pendingCount: pending.pendingCount });
            }
        } catch (error) {
            // Best-effort: a failed pending check simply means no prompt. The backend module remains the manual fallback.
            // eslint-disable-next-line no-console
            console.warn('[Sitegeist.LostInTranslation] could not determine pending translations after publish', error);
        } finally {
            handling = false;
        }
    });
}
