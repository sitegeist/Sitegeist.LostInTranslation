import manifest from '@neos-project/neos-ui-extensibility'
import { registerRetranslateView, registerPostPublishSync } from '@sitegeist/lostintranslation-retranslate-editor'

manifest('@sitegeist/lostintranslation', {}, (globalRegistry) => {
    registerRetranslateView(globalRegistry)
    registerPostPublishSync(globalRegistry)
})
