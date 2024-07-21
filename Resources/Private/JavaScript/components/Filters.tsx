import * as React from 'react';

import { useIntl } from '../providers';

interface FiltersProps {
    hasEntries: boolean;
    hasMorePages: boolean;
    currentPage: number;
    pagingParameters: number[];
    handleUpdateSearch: (searchWord: string) => void;
    handlePagination: (action: Pagination) => void;
}

export enum Pagination {
    Left,
    Right,
    Start,
    End,
}

export default function Filters({
    hasEntries,
    hasMorePages,
    currentPage,
    pagingParameters,
    handleUpdateSearch,
    handlePagination,
}: FiltersProps) {
    const { translate } = useIntl();

    return (
        <div className="entries-filter">
            <div className="row">
                <div className="neos-control-group neos-control-group--large">
                    <label htmlFor="entries-search">{translate('filter.search', 'Search')}</label>
                    <input
                        id="entries-search"
                        type="text"
                        placeholder={translate('filter.search.placeholder', 'Search for entries')}
                        onChange={e => handleUpdateSearch(e.target.value)}
                    />
                </div>

                <div className="neos-control-group neos-control-group--right neos-control-group--fill">
                    <div className="entries-filter__pagination">
                        {hasEntries && (
                            <button
                                role="button"
                                disabled={currentPage <= 0}
                                className="neos-button"
                                onClick={() => currentPage > 0 && handlePagination(Pagination.Left)}
                            >
                                <i className="fas fa-caret-left" />
                            </button>
                        )}
                        <span>
                            {hasEntries > 0
                                ? translate('pagination.position', 'Showing {0}-{1} of {2}', pagingParameters)
                                : translate('pagination.noResults', 'No entries match your search.')}
                        </span>
                        {hasEntries && (
                            <button
                                role="button"
                                disabled={!hasMorePages}
                                className="neos-button"
                                onClick={() => hasMorePages && handlePagination(Pagination.Right)}
                            >
                                <i className="fas fa-caret-right" />
                            </button>
                        )}
                    </div>
                </div>

            </div>
        </div>
    );
}
