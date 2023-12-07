import manifest from "@neos-project/neos-ui-extensibility";
import { actions } from "@neos-project/neos-ui-redux-store";

manifest("Sitegeist.LostInTranslation:HostFrame", {}, (globalRegistry, {store}) => {
    const plugin = (changes) => {
        store.dispatch(
            actions.Changes.persistChanges(changes)
        )
    }
    window.sitegeistLostInTranslationHostPlugin = plugin;
});
