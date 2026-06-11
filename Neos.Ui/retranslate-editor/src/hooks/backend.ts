export type RetranslateTarget = 'node' | 'document';

export type ContentInfoResponse = {
    isUpToDate: boolean;
    referenceLanguage: {
        label: string;
    } | null;
    staleNodeCount: number;
};

export type ContentInfoRequest = {
    nodeAggregateId: string;
    workspaceName: string;
    coordinates: Record<string, string | null>;
};

export type TranslateRequest = {
    nodeAggregateId: string;
    workspaceName: string;
    targetCoordinates: string;
};

export type TranslateResponse = {
    message: string;
    stalePropertyCommandsDispatched: number;
    variantCommandsDispatched: number;
    skippedReason: string | null;
};

export type PendingSynchronizationResponse = {
    pendingCount: number;
    perRule: {
        targetWorkspaceName: string;
        targetDimension: string;
        count: number;
    }[];
};

export type SynchronizeResponse = {
    stalePropertyCommandsDispatched: number;
    variantCommandsDispatched: number;
    // Source-language deletions / subtree-tag changes (e.g. hide/show) mirrored into the target by `remove-target` /
    // `sync-to-target` rules.
    removalCommandsDispatched: number;
    tagCommandsDispatched: number;
    skippedNodes: number;
    // Per-rule short-circuit reasons (e.g. target workspace missing or not based on source). Empty when all rules ran.
    errors: string[];
};

const CONTENT_INFO_ENDPOINT = '/lostintranslation/retranslation/getmetadata';
const TRANSLATE_ENDPOINT = '/lostintranslation/retranslation/retranslatenode';
const SYNCHRONIZATION_PENDING_ENDPOINT = '/lostintranslation/synchronization/pending';
const SYNCHRONIZATION_SYNCHRONIZE_ENDPOINT = '/lostintranslation/synchronization/synchronize';

async function parseJsonResponse<T>(response: Response): Promise<T> {
    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return response.json() as Promise<T>;
}

export const endpoints = () => ({
    getContentInfo: async (payload: ContentInfoRequest): Promise<ContentInfoResponse> => {
        const searchParams = new URLSearchParams({
            nodeAggregateId: payload.nodeAggregateId,
            workspaceName: payload.workspaceName,
            coordinates: JSON.stringify(payload.coordinates)
        });

        return parseJsonResponse<ContentInfoResponse>(
            await fetch(`${CONTENT_INFO_ENDPOINT}?${searchParams.toString()}`, {
                method: 'GET',
                credentials: 'same-origin'
            })
        );
    },
    translate: async (payload: TranslateRequest): Promise<TranslateResponse> => {
        const csrfToken = document.getElementById('appContainer')!.dataset.csrfToken as string;

        return parseJsonResponse<TranslateResponse>(
            await fetch(TRANSLATE_ENDPOINT, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Flow-Csrftoken': csrfToken,
                },
                body: JSON.stringify(payload)
            })
        );
    },
    getPending: async (payload: { workspaceName: string }): Promise<PendingSynchronizationResponse> => {
        const searchParams = new URLSearchParams({ workspaceName: payload.workspaceName });

        return parseJsonResponse<PendingSynchronizationResponse>(
            await fetch(`${SYNCHRONIZATION_PENDING_ENDPOINT}?${searchParams.toString()}`, {
                method: 'GET',
                credentials: 'same-origin'
            })
        );
    },
    synchronize: async (payload: { workspaceName: string }): Promise<SynchronizeResponse> => {
        const csrfToken = document.getElementById('appContainer')!.dataset.csrfToken as string;

        return parseJsonResponse<SynchronizeResponse>(
            await fetch(SYNCHRONIZATION_SYNCHRONIZE_ENDPOINT, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Flow-Csrftoken': csrfToken,
                },
                body: JSON.stringify(payload)
            })
        );
    }
});
