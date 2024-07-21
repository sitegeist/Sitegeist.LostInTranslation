import * as React from 'react';
import { FormEvent } from 'react';

import { highlight } from '../util/helpers';
import { Icon } from './index';

export interface GlossaryEntryAggregateProps {
    aggregateIdentifier: string;
    languages: string[];
    texts: {};
    searchValue: string;
    rowClassNames: string[];
    translate: (id: string, label: string, args?: any[]) => string;
    handleEditAction: (event: FormEvent, identifier: string) => void;
    handleDeleteAction: (event: FormEvent, identifier: string) => void;
}

export class Row extends React.PureComponent<GlossaryEntryAggregateProps, {}> {

    public render(): React.ReactElement {
        const {
            aggregateIdentifier,
            languages,
            texts,
            searchValue,
            rowClassNames,
            translate,
            handleDeleteAction,
            handleEditAction,
        } = this.props;

        return (
            <tr className={rowClassNames.join(' ')}>
                {languages.map((language) => (
                    <td
                        dangerouslySetInnerHTML={{ __html: highlight(texts[language] ?? '', searchValue) }}
                    ></td>
                ))}
                <td className="neos-action">
                    <button
                        type="button"
                        className="neos-button"
                        onClick={e => handleEditAction(e, aggregateIdentifier)}
                        title={translate('list.action.edit', 'Edit')}
                        data-edit-aggregate-id={aggregateIdentifier}
                    >
                        <Icon icon="pencil-alt" />
                    </button>
                    <button
                        type="submit"
                        className="neos-button neos-button-danger"
                        onClick={e => handleDeleteAction(e, aggregateIdentifier)}
                        title={translate('list.action.delete', 'Delete')}
                    >
                        <Icon icon="trash-alt" />
                    </button>
                </td>
            </tr>
        );
    }
}
