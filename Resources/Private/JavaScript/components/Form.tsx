import * as React from 'react';
import { ChangeEvent, PureComponent } from 'react';

import { NeosNotification } from '../interfaces';
import { GlossaryContext } from '../providers';

const MAX_INPUT_LENGTH = 500;

export interface FormProps {
    aggregateIdentifier: string;
    languages: string[];
    requiredLanguages: string[];
    texts: {};
    translate: (id: string, label: string, args?: any[]) => string;
    notificationHelper: NeosNotification;
    actions: {
        create: string;
        update: string;
    };
    idPrefix: string;
    handleNewEntry: (entries: {}, glossaryStatus: {}) => void;
    handleUpdatedEntry: (entries: {}, glossaryStatus: {}) => void;
    handleCancelAction: () => void;
}

export interface FormState {
    texts: {};
    isSendingData: boolean;
    activeHelpMessage: string;
}

const initialState: FormState = {
    texts: {},
    isSendingData: false,
    activeHelpMessage: '',
};

export class Form extends PureComponent<FormProps, FormState> {
    static contextType = GlossaryContext;

    constructor(props: FormProps) {
        super(props);
        this.state = {
            ...initialState,
            texts: props.texts,
        };
    }

    /**
     * Edits an existing entry or creates a new one
     *
     * @param event
     */
    private handleSubmit = (event: React.FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const {
            aggregateIdentifier,
            notificationHelper,
            actions,
            handleNewEntry,
            handleUpdatedEntry,
        } = this.props;

        const { csrfToken } = this.context;

        const { texts } = this.state;

        const data = {
            __csrfToken: csrfToken,
            moduleArguments: {
                aggregateIdentifier: aggregateIdentifier ? aggregateIdentifier : null,
                texts: texts,
            },
        };

        this.setState({ isSendingData: true });

        this.postEntry(aggregateIdentifier ? actions.update : actions.create, data)
            .then(data => {
                const { messages, entries, glossaryStatus } = data;

                if (aggregateIdentifier) {
                    handleUpdatedEntry(entries, glossaryStatus);
                } else {
                    handleNewEntry(entries, glossaryStatus);

                    // Reset form when an entry was created but not when it was just updated
                    this.setState({
                        ...initialState,
                        isSendingData: false,
                    });
                }

                messages.forEach(({ title, message, severity }) => {
                    notificationHelper[severity.toLowerCase()](title || message, message);
                });
            })
            .catch(() => {
                this.setState({
                    isSendingData: false,
                });
            })
        ;
    };

    private postEntry = (path: string, body?: any): Promise<any> => {
        const { notificationHelper } = this.props;

        return fetch(path, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json; charset=UTF-8',
            },
            body: body && JSON.stringify(body),
        })
            .then(res => res.json())
            .then(async data => {
                if (data.success) {
                    return data;
                }
                data.messages.forEach(({ title, message, severity }) => {
                    notificationHelper[severity.toLowerCase()](title || message, message);
                });
                throw new Error();
            });
    };

    /**
     * Stores any change to the form in the state
     *
     * @param event
     */
    private handleInputChange = (event: ChangeEvent): void => {
        const target: HTMLInputElement = event.target as HTMLInputElement;
        const { name, value } = target;
        let { texts } = this.state;

        // the update form uses this name format: 1e8f83d7-cc97-42de-b069-7c4d45ae73b1[DE]
        // the create form uses the language directly: DE
        const regex = new RegExp(/[0-9a-f-]+\[([A-Z]{2})]/);
        let language;
        if (regex.test(name)) {
            const matches = name.match(regex);
            language = matches[1];
        } else {
            language = name;
        }

        texts[language] = value.substring(0, MAX_INPUT_LENGTH);

        this.setState({
            texts: texts,
        });
        // necessary because fof nested state
        this.forceUpdate();
    };

    public render(): React.ReactElement {
        const {
            languages,
            requiredLanguages,
            idPrefix,
            aggregateIdentifier,
            translate,
            handleCancelAction
        } = this.props;

        const {
            texts,
            isSendingData,
        } = this.state;

        return (
            <form onSubmit={e => this.handleSubmit(e)} className="add-entry-form">
                <div className="row">
                    {languages.map((language, index) => (
                        <div className="neos-control-group">
                            <React.Fragment key={index}>
                                <label className="neos-control-label" htmlFor={idPrefix + language}>
                                    {language}
                                </label>
                                <input
                                    name={(aggregateIdentifier ? (aggregateIdentifier + '[' + language + ']') : language)}
                                    id={idPrefix + language}
                                    type="text"
                                    onChange={this.handleInputChange}
                                    autoFocus={index == 0}
                                    required={requiredLanguages.includes(language)}
                                    placeholder={language + ' text'}
                                    autoComplete="off"
                                    autoCorrect="off"
                                    autoCapitalize="off"
                                    spellCheck={false}
                                    value={texts ? texts[language] : ''}
                                />
                            </React.Fragment>
                        </div>
                    ))}
                </div>
                <div className="row row--actions">
                    {handleCancelAction && (
                        <div className="neos-control-group">
                            <a
                                role="button"
                                className="neos-button add-entry-form__cancel"
                                onClick={() => handleCancelAction()}
                            >
                                {translate('action.cancel', 'Cancel')}
                            </a>
                        </div>
                    )}
                    <div className="neos-control-group">
                        <button type="submit" disabled={isSendingData} className="neos-button neos-button-primary">
                            {aggregateIdentifier
                                ? translate('action.update', 'Save')
                                : translate('action.create.short', 'Add')}
                        </button>
                    </div>
                </div>
            </form>
        );
    }
}
