export type RetranslateTarget = 'node' | 'document';

export type ContentInfoResponse = {
    isUpToDate: boolean;
    referenceLanguage: {
        label: string;
        dateModified: string;
    } | null;

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
};

const CONTENT_INFO_ENDPOINT = '/lostintranslation/retranslation/getmetadata';
const TRANSLATE_ENDPOINT = '/lostintranslation/retranslation/retranslatenode';

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
    }
});
