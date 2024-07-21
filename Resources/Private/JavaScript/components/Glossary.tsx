import * as React from 'react';
import { FormEvent } from 'react';

import { NeosNotification } from '../interfaces';
import { Row } from './Row';
import { Form } from './Form';
import { GlossaryContext } from '../providers';
import Filters, { Pagination } from './Filters';

const ITEMS_PER_PAGE = 10;

export interface GlossaryProps {
    entries: {};
    languages: string[],
    requiredLanguages: string[],
    actions: {
        delete: string;
        update: string;
        create: string;
    };
    translate: (id: string, label: string, args?: any[]) => string;
    updateGlossaryStatus: (data: {}) => void;
    notificationHelper: NeosNotification;
}

export interface GlossaryState {
    entries: {};
    searchValue: string;
    currentPage: number;
    filteredEntries: {};
    editedAggregateIdentifier: string;
    showForm: boolean;
}

const initialState: GlossaryState = {
    entries: {},
    searchValue: '',
    currentPage: 0,
    filteredEntries: [],
    editedAggregateIdentifier: null,
    showForm: false,
};

export class Glossary extends React.Component<GlossaryProps, GlossaryState> {
    static contextType = GlossaryContext;

    constructor(props: GlossaryProps) {
        super(props);
        this.state = {
            ...initialState,
            entries: props.entries,
            filteredEntries: props.entries,
        };
    }

    /**
     * Filters the full list of entries by the search value.
     * The result is stored in the state, so it doesn't need to be recomputed for pagination or sorting.
     *
     * @param searchValue
     */
    private handleUpdateSearch = (searchValue: string): void => {
        const {
            entries,
            currentPage,
        } = this.state;
        let filteredEntries: {} = { ...entries };

        const cleanSearchValue = searchValue.trim().toLowerCase();

        // Filter by search value
        if (cleanSearchValue) {
            console.log('cleanSearchValue');
            console.log(cleanSearchValue);

            for (const aggregate in filteredEntries) {
                let keepEntry = false;
                for (const language in filteredEntries[aggregate]) {
                    if (filteredEntries[aggregate][language].toLowerCase().includes(cleanSearchValue)) {
                        keepEntry = true;
                        break;
                    }
                }
                if (!keepEntry) {
                    delete filteredEntries[aggregate];
                }
            }

        }

        this.setState({
            searchValue: cleanSearchValue,
            filteredEntries: filteredEntries,
            currentPage: Math.min(currentPage, Glossary.getMaxPage(filteredEntries)),
        });
    };

    /**
     * Refreshes the list
     */
    private refresh = (): void => {
        this.setState(
            {},
            () => this.handleUpdateSearch(this.state.searchValue),
        );
    }

    /**
     * Updates the pagination state based on the pagination action
     *
     * @param action
     */
    private handlePagination = (action: Pagination): void => {
        const { currentPage } = this.state;

        switch (action) {
            case Pagination.Left:
                if (currentPage > 0) {
                    this.setState({
                        currentPage: currentPage - 1,
                    });
                }
                break;
            case Pagination.Right:
                this.setState({
                    currentPage: currentPage + 1,
                });
                break;
            default:
                break;
        }
    };

    /**
     * Asks for confirmation and then sends the deletion request to the backend.
     * A flash message will be created based on the result.
     *
     * @param event
     * @param aggregateIdentifier
     */
    private handleDeleteAction = (event: FormEvent, aggregateIdentifier: string): void => {
        const { entries} = this.state;
        const { languages, actions, notificationHelper } = this.props;
        const { csrfToken } = this.context;

        event.preventDefault();

        const entryToDelete = entries[aggregateIdentifier];
        const firstLanguage = languages[0];
        if (
            !confirm(
                this.props.translate('list.action.confirmDelete', 'Delete entry "{0}" ({1})?', [
                    entryToDelete[firstLanguage],
                    firstLanguage
                ]),
            )
        ) {
            return;
        }

        const data = {
            __csrfToken: csrfToken,
            moduleArguments: {
                aggregateIdentifier: aggregateIdentifier,
            },
        };

        fetch(actions.delete, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json; charset=UTF-8',
            },
            body: JSON.stringify(data),
        })
            .then(response => response.json())
            .then(data => {
                const { success, entries, glossaryStatus, messages } = data;
                if (success) {
                    this.refreshListAndStatus(entries, glossaryStatus);
                }
                messages.forEach(({ title, message, severity }) => {
                    notificationHelper[severity.toLowerCase()](title || message, message);
                });
            })
            .catch(error => {
                notificationHelper.error(error);
            });
    };

    /**
     * Sets the current edited entry that should be edited which will show the editing form
     */
    private handleEditAction = (event: FormEvent, aggregateIdentifier: string): void => {
        event.preventDefault();
        this.setState({ editedAggregateIdentifier: aggregateIdentifier });
    };

    /**
     * Unset the currently edited entry which will hide the editing form
     */
    private handleCancelAction = (): void => {
        this.setState({ editedAggregateIdentifier: null });
    };

    /**
     * Toggles the entry creation form
     */
    private handleToggleForm = (): void => {
        this.setState({ showForm: !this.state.showForm });
    };

    /**
     * Triggers a refresh with the current entry list.
     *
     * @param entries
     * @param glossaryStatus
     */
    private refreshListAndStatus = (entries: {}, glossaryStatus: {}): void => {
        this.setState(
            {
                entries: entries,
                editedAggregateIdentifier: null,
            },
            this.refresh,
        );
        this.props.updateGlossaryStatus(glossaryStatus);
    };

    /**
     * Return the highest page number for the pagination
     */
    private static getMaxPage(entries: {}): number {
        return Math.max(0, Math.ceil(Object.keys(entries).length / ITEMS_PER_PAGE) - 1);
    }

    public render(): JSX.Element {
        const {
            languages,
            requiredLanguages,
            actions,
            translate,
            notificationHelper
        } = this.props;

        const {
            filteredEntries,
            currentPage,
            searchValue,
            editedAggregateIdentifier,
            showForm,
        } = this.state;

        const pagingParameters = [
            currentPage * ITEMS_PER_PAGE + 1,
            Math.min((currentPage + 1) * ITEMS_PER_PAGE, Object.keys(filteredEntries).length),
            Object.keys(filteredEntries).length,
        ];

        const columnCount = languages.length;
        const hasEntries = (Object.keys(filteredEntries).length > 0);
        const hasMorePages = Glossary.getMaxPage(filteredEntries) > currentPage;

        // Show only a limited number of entries
        const visibleEntries = {};
        const start = (pagingParameters[0] - 1);
        const end = pagingParameters[1];
        let counter = 0;
        for (const aggregate in filteredEntries) {
            if (counter >= start && counter < end) {
                visibleEntries[aggregate] = filteredEntries[aggregate];
            }
            counter++;
        }

        return (
            <React.Fragment>
                {!showForm && (
                    <button className="neos-button neos-button-primary" onClick={() => this.handleToggleForm()}>
                        {translate('action.create', 'Add new entry')}
                    </button>
                )}

                {showForm && (
                    <>
                        <h2 className="entries-list__header">{translate('action.create', 'Add new entry')}</h2>

                        <Form
                            aggregateIdentifier={null}
                            languages={languages}
                            requiredLanguages={requiredLanguages}
                            texts={{}}
                            translate={translate}
                            actions={actions}
                            notificationHelper={notificationHelper}
                            handleNewEntry={this.refreshListAndStatus}
                            handleUpdatedEntry={this.refreshListAndStatus}
                            handleCancelAction={this.handleToggleForm}
                            idPrefix=""
                        />
                    </>
                )}

                <h2 className="entries-list__header">{translate('header.manageGlossary', 'Manage glossary')}</h2>

                <Filters
                    handleUpdateSearch={this.handleUpdateSearch}
                    currentPage={currentPage}
                    hasEntries={hasEntries}
                    handlePagination={this.handlePagination}
                    hasMorePages={hasMorePages}
                    pagingParameters={pagingParameters}
                />
                {hasEntries ? (
                    <div className="entries-table-wrap">
                        <table className="neos-table entries-table">
                            <thead>
                                <tr>
                                    {languages.map((language) => (
                                        <th>{language}</th>
                                    ))}
                                    <th className="entry-table__heading-actions">
                                        {translate('actions', 'Actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {Object.keys(visibleEntries).map((aggregateIdentifier, index) => {
                                    let aggregate = visibleEntries[aggregateIdentifier];
                                    return (
                                    <React.Fragment key={index}>
                                        <Row
                                            aggregateIdentifier={aggregateIdentifier}
                                            languages={languages}
                                            texts={aggregate}
                                            searchValue={searchValue}
                                            rowClassNames={['entries-table__row', index % 2 ? '' : 'odd']}
                                            translate={translate}
                                            handleDeleteAction={this.handleDeleteAction}
                                            handleEditAction={this.handleEditAction}
                                        />
                                        {editedAggregateIdentifier === aggregateIdentifier && (
                                            <tr className="entries-table__single-column-row">
                                                <td colSpan={columnCount}>
                                                    <h6>{translate('header.editAggregate', 'Edit entry')}</h6>
                                                    <Form
                                                        aggregateIdentifier={aggregateIdentifier}
                                                        languages={languages}
                                                        requiredLanguages={requiredLanguages}
                                                        texts={aggregate}
                                                        translate={translate}
                                                        actions={actions}
                                                        notificationHelper={notificationHelper}
                                                        handleNewEntry={this.refreshListAndStatus}
                                                        handleUpdatedEntry={this.refreshListAndStatus}
                                                        handleCancelAction={this.handleCancelAction}
                                                        idPrefix={'entry-' + index + '-'}
                                                    />
                                                </td>
                                            </tr>
                                        )}
                                    </React.Fragment>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div>{translate('list.empty', 'No entries found.')}</div>
                )}
            </React.Fragment>
        );
    }
}
