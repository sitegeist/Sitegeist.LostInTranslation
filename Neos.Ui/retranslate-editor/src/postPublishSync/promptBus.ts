export type SyncPromptPayload = {
    workspaceName: string;
    pendingCount: number;
};

type Listener = (payload: SyncPromptPayload) => void;

/**
 * Tiny in-memory pub/sub bridging the post-publish saga to the {@link SyncDialog} component, so the dialog can be opened
 * without introducing a custom redux reducer slice. The saga emits once it has confirmed there are out-of-sync
 * translations; the mounted dialog subscribes and opens.
 */
const listeners = new Set<Listener>();

export const syncPromptBus = {
    emit(payload: SyncPromptPayload): void {
        listeners.forEach((listener) => listener(payload));
    },
    subscribe(listener: Listener): () => void {
        listeners.add(listener);
        return () => {
            listeners.delete(listener);
        };
    }
};
