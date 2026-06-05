# Sitegeist.LostInTranslation
## Automatic Translations for Neos via DeeplApi

Documents and contents are translated automatically once editors choose to "create and copy" a version in another language.
The included DeeplService can be used for other purposes as well.

The development was a collaboration of Sitegeist and Code Q.

### Authors & Sponsors

* Martin Ficzel - ficzel@sitegeist.de
* Felix Gradinaru - fg@codeq.at

*The development and the public releases of this package are generously sponsored
by our employers http://www.sitegeist.de and http://www.codeq.at.*

## Installation

Sitegeist.LostInTranslation is available via Packagist. Run `composer require sitegeist/lostintranslation`.

We use semantic versioning so every breaking change will increase the major version number.

## How it works

By default, all inline editable properties are translated using DeepL (see setting `translateInlineEditables`).
To include other `string` properties in the automatic translation, the `options.automaticTranslation: true`
can be used in the property configuration. Also, you can disable automatic translation in general for certain node types
by setting `options.automaticTranslation: false`.

Some very common fields from `Neos.Neos:Document` are already configured to do so by default.

```yaml
'Neos.Neos:Document':
  options:
      automaticTranslation: true
  properties:
    title:
      options:
        automaticTranslation: true
    titleOverride:
      options:
        automaticTranslation: true
    metaDescription:
      options:
        automaticTranslation: true
    metaKeywords:
      options:
        automaticTranslation: true
```

Also, automatic translation for all types derived from `Neos.Neos:Node` is enabled by default:

```yaml
'Neos.Neos:Node':
  options:
      automaticTranslation: true
```

### Retranslate View

This package adds a Retranslate View to both `Neos.Neos:Document` and `Neos.Neos:Node`.

If the active language preset has `options.referenceLanguage` configured, the view checks whether the current
translation is up to date compared to that reference language. If it is outdated, editors can trigger a retranslation
directly in the inspector.

The resulting changes are created in the current workspace and can be reviewed via the normal editing and publishing
workflow.

> Staleness is tracked by a Content Repository projection (`stale_translations`). For the full mechanics see
> [Documentation/RetranslationAndSynchronization.md](Documentation/RetranslationAndSynchronization.md).

Example configuration:

```yaml
Neos:
  ContentRepository:
    contentDimensions:
      'language':
        presets:
          'en':
            label: 'English'
            values: ['en']
            flag: 'english'
            options:
              referenceLanguage: 'de'
```

### Synchronization

In addition to the on-demand Retranslate View, translations can be reconciled automatically across
workspaces and/or dimensions via configurable rules. A typical use case is translating published
`live/en` content into a `de-review/de` workspace where it is reviewed before going live.

Rules are opt-in (default `[]`) and configured under `nodeTranslation.synchronization`:

```yaml
Sitegeist:
  LostInTranslation:
    nodeTranslation:
      synchronization:
        - sourceWorkspaceName: live      # a publish into this workspace triggers the rule
          sourceDimension: en            # must equal the target dimension preset's referenceLanguage
          targetWorkspaceName: de-review # translated variants are written here
          targetDimension: de
          scope: Document                # Document (mirror documents + content) | Content (content only)
          mode: auto                     # auto (translate on publish) | ask (defer to a manual "sync now")
```

Automatic synchronization is always stale-driven. The Flow CLI offers the same operations for scripting,
cron jobs and one-off catch-ups:

**`lostintranslation:retranslate-node`** — retranslate the stale properties and create the missing target
variants below a single node, the CLI equivalent of the inspector's Retranslate button. The source language is
derived from the target dimension's `referenceLanguage`, so only the target is passed. All four arguments are
required.

```
./flow lostintranslation:retranslate-node <nodeAggregateId> <target> <contentRepository> <workspace>

#   nodeAggregateId    the node whose subtree is brought up to date
#   target             the target dimension value, e.g. "de"
#   contentRepository  the content repository id, usually "default"
#   workspace          the workspace to operate in, e.g. "live"
```

**`lostintranslation:synchronize`** — reconcile a whole workspace/dimension instead of a single node. By default
it is stale-driven (only nodes the projection flagged as out of date); with `--full` it walks the entire source
subtree and considers every translatable node, leaving target variants that already exist and have no stale row
untouched (manual edits are preserved). Source and target workspace may differ for cross-workspace flows — the
target is force-rebased onto its base (which must be the source workspace) before translating. `--dry-run` reports
what would happen without dispatching any commands.

```
./flow lostintranslation:synchronize <sourceWorkspace> <sourceDimension> <targetWorkspace> <targetDimension> \
    [--content-repository default] [--full] [--dry-run]

#   sourceWorkspace    workspace the source content is read from
#   sourceDimension    source language value; must equal the target dimension's referenceLanguage
#   targetWorkspace    workspace the translated variant/property commands are dispatched into
#   targetDimension    target language value, e.g. "de"
#   --full             walk the whole subtree instead of only stale records
#   --dry-run          report the affected records/nodes without writing anything
```

**`lostintranslation:reconcile`** — housekeeping. The projection only clears the directly-removed aggregate on
deletion, so descendants (a document's tethered content collection, nested content) can leave orphaned stale rows
behind. This command walks the Content Graph and prunes stale rows whose node aggregate no longer exists. Run it
after bulk deletes; use `--dry-run` to preview.

```
./flow lostintranslation:reconcile [--workspace live] [--content-repository default] [--dry-run]
```

> Workflows, cross-workspace rebase semantics, scope/mode behaviour and design rationale are documented in
> [Documentation/RetranslationAndSynchronization.md](Documentation/RetranslationAndSynchronization.md).

## Configuration

This package needs an authenticationKey for the DeepL API from https://www.deepl.com/pro-api.
There are free plans that support a limited number, but for productive use we recommend using a paid plan.

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
      authenticationKey: '.........................'
```

The translation of nodes is configured via settings:

```yaml
Sitegeist:
  LostInTranslation:
    nodeTranslation:
      #
      # Enable the automatic translations of nodes while they are adopted to another dimension
      #
      enabled: true

      #
      # Translate all inline editable fields without further configuration.
      #
      # If this is disabled, inline editables can be configured for translation by setting
      # `options.translateOnAdoption: true` for each property separately
      #
      translateInlineEditables: true

      #
      # The name of the language dimension. Usually needs no modification
      #
      languageDimensionName: 'language'
```

If a preset of the language dimension uses a locale identifier that is not compatible with DeepL, the `deeplLanguage` can
be configured explicitly for this preset via `options.deeplLanguage`.

```yaml
Neos:
  ContentRepository:
    contentDimensions:
      'language':

        #
        # The `defaultPreset` marks the source for all translations with mode `sync`
        #
        label: 'Language'
        default: 'en'
        defaultPreset: 'en'

        presets:

          #
          # English has to be configured differently for source and target as DeepL requires so,
          # the source and target are separated by a `:`
          #
          'en':
            label: 'English'
            values: ['en']
            uriSegment: 'en'
            options:
              deeplLanguage: 'EN:EN-GB'

          #
          # Danish uses a different locale identifier than DeepL, so the `deeplLanguage` has to be configured explicitly
          #
          'dk':
            label: 'Dansk'
            values: ['dk']
            uriSegment: 'dk'
            options:
              deeplLanguage: 'DA'

          #
          # For German, the dimension value de is used in uppercase
          #
          'de':
            label: 'Deutsch'
            values: ['de']
            uriSegment: 'de'

          #
          # The Bavarian language is not supported by DeepL and is disabled
          #
          'de_bar':
            label: 'Bayrisch'
            values: ['de_bar','de']
            uriSegment: 'de_bar'
            options:
              deeplLanguage: false
```
### Glossaries

Glossaries are created and uploaded to DeepL via the Lost in Translation Backend Module.
When node-translations are created the matching glossary for the language combination at hand
is chosen automatically.

Glossary names are internally prefixed with a configurable identifier that ensures that different Neos instances
that share a DeepL account will not interfere by cleaning up each others glossaries.

By default the prefix is the `FLOW_CONTEXT` as this is already configured in all Neos Instances and is often used to
seperate multiple environments.

**:warning: If you use multiple environments with the same FLOW_CONTEXT and DeepL Account you should ensure that the
glossary.labelPrefix is configured differently for each environment.**

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
        # 
        # Glossary management
        # 
        glossary:
            #
            # The label prefix can be used to prevent different instances overwriting or deleting each others
            # glossaries. The default value is the FLOW_CONTEXT but this may need adjustment based on your use case
            #
            labelPrefix: '%env:FLOW_CONTEXT%'
            #
            # The number of outdated remote glossaries to keep to reduce problems when systems are cloned
            #
            keepNumber: 10
```

The commands `./flow glossary:uploadall` and `./flow glossary:cleanupall` allow to automate those tasks and may
be integrated in backup and restore or synchronization scripts.


### Ignoring Terms

You can define terms that should be ignored by DeepL in the configuration.
The terms are evaluated case-insensitively when searching for them, however
they will always be replaced with their actual occurrence.

This is how an example configuration could look like:

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
      ignoredTerms:
        - 'Sitegeist'
        - 'Neos.io'
        - 'Hamburg'
```

## Eel Helper

The package also provides two Eel helpers to translate texts in Fusion.

**:warning: Every one of these Eel helpers makes an individual request to DeepL.**
Thus, having many of them on one page can significantly slow down the performance if the page is uncached.
:bulb: Only use while the [translation cache](#translation-cache) is enabled!

To translate a single text you can use:

```neosfusion
# ${Sitegeist.LostInTranslation.translate(string textToBeTranslated, string targetLanguage, string|null sourceLanguage = null): string}
${Sitegeist.LostInTranslation.translate('Hello world!', 'de', 'en')}
# Output: Hallo Welt!
```

To translate an array of texts you can use:

```neosfusion
# ${Sitegeist.LostInTranslation.translate(array textsToBeTranslated, string targetLanguage, string|null sourceLanguage = null): array}
${Sitegeist.LostInTranslation.translate(['Hello world!', 'My name is...'], 'de', 'en')}
# Output: ['Hallo Welt!', 'Mein Name ist...']
```

### Translation Cache

The plugin includes a translation cache for the DeepL API that stores the individual text parts
and their translated results for up to one week.
By default, the cache is enabled. To disable the cache, you need to set the following setting:

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
      enableCache: false
```

### Content Governance Mode

To exactly track what write operations have been performed by human editors or their translation assistant,
you can enable content governance mode by enabling the respective AuthProvider:


```yaml
Neos:
  ContentRepositoryRegistry:
    presets:
      # or whatever preset you use
      default:
        authProvider:
          factoryObjectName: Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AIAwareContentRepositoryAuthProviderFactory
```

## Performance

For every translated node, a single request is made to the DeepL API.
This can lead to significant delay when documents with lots of nodes are translated.
It is likely that future versions will improve this.

## Contribution

We will gladly accept contributions. Please send us pull requests.

## Changelog

### 3.0.0

* Neos 9 / Content Repository 9 support.
* Added the **Retranslate View** (inspector) and **Synchronization** (rule-based, cross-workspace/dimension) features,
  backed by a stale-translation projection. See
  [Documentation/RetranslationAndSynchronization.md](Documentation/RetranslationAndSynchronization.md).
* The preset option `translationStrategy: sync` is replaced by explicit `nodeTranslation.synchronization` rules.

### 2.0.0

* The preset option `translationStrategy` was introduced. There are now two auto-translation strategies:
  * Strategy `once` will auto-translate the node once "on adoption", i.e. the editor switches to a different language dimension
  * Strategy `sync` will auto-translate and sync the node every time a node is updated in the default preset language
* The node setting `options.translateOnAdoption` has been renamed to `options.automaticTranslation`
* The new node option `options.automaticTranslation` was introduced
