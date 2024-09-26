import * as React from 'react';
import * as ReactDOM from 'react-dom';

import { Glossary } from './components';
import { GlossaryStatus } from "./components/GlossaryStatus";
import { GlossaryProvider, IntlProvider } from './providers';

import '../Styles/styles.scss';

window.onload = async (): Promise<void> => {
    let NeosAPI = window.Typo3Neos || window.NeosCMS;

    while (!NeosAPI || !NeosAPI.I18n || !NeosAPI.I18n.initialized) {
        NeosAPI = window.NeosCMS || window.Typo3Neos;
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    const glossaryApp: HTMLElement = document.getElementById('glossary-app');
    const glossaryData: HTMLElement = document.getElementById('glossary-data');

    if (!glossaryApp || !glossaryData) {
        return;
    }

    const entries: {} = JSON.parse(glossaryData.innerText);
    const languages: string[] = JSON.parse(glossaryApp.dataset.languages);
    const requiredLanguages: string[] = JSON.parse(glossaryApp.dataset.requiredLanguages);
    const glossaryStatus: string[] = JSON.parse(glossaryApp.dataset.glossaryStatus);
    const glossaryStatusComponent = React.createRef();

    const { csrfToken } = glossaryApp.dataset;
    const actions: {
        delete: string;
        create: string;
        update: string;
    } = JSON.parse(glossaryApp.dataset.actions);
    const { I18n, Notification } = NeosAPI;

    /**
     * @param id
     * @param label
     * @param args
     */
    const translate = (id: string, label = '', args = []): string => {
        return I18n.translate(id, label, 'Sitegeist.LostInTranslation', 'GlossaryModule', args);
    };

    const updateGlossaryStatus = (data) => {
        glossaryStatusComponent.current.update(data);
    }

    ReactDOM.render(
        <GlossaryProvider value={{ csrfToken }}>
            <IntlProvider translate={translate}>
                <Glossary
                    entries={entries}
                    languages={languages}
                    requiredLanguages={requiredLanguages}
                    actions={actions}
                    translate={translate}
                    updateGlossaryStatus={updateGlossaryStatus}
                    notificationHelper={Notification}
                />
                <GlossaryStatus
                    ref={glossaryStatusComponent}
                    data={glossaryStatus}
                    translate={translate}
                />
            </IntlProvider>
        </GlossaryProvider>,
        glossaryApp,
    );
};
