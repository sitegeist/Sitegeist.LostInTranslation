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

### Integrating into an existing project

Beyond `composer require`, integrating the package into a site that already has content requires a few
one-time steps so the stale-translation projection is built and existing content is reconciled.

1. **Run the database migrations** (glossary and bookkeeping tables):

   ```shell
   ./flow doctrine:migrate
   ```

2. **Check and adjust the Content Repository.** The projection replays the full event stream, so the
   CR should be healthy first:

   ```shell
   ./flow cr:setup
   ./flow cr:status
   ./flow contentgraphintegrity:runviolationdetection   # minor integrity issues are usually fine
   ./flow structureadjustments:fix                      # if the previous step reports adjustments
   ```

3. **Build the stale-translation projection** by replaying the event stream onto it. The
   `stale_translations` tables are created automatically by the projection's setup — there is no
   dedicated Doctrine migration for them.

   ```shell
   ./flow subscription:replay Sitegeist.LostInTranslation:StaleTranslations
   ```

4. **(Optional) Translate existing content** with a one-off full synchronization. The source dimension
   must equal the target dimension preset's `referenceLanguage`. Always dry-run first:

   ```shell
   # preview what would happen
   ./flow lostintranslation:synchronize --source-workspace=live --source-dimension=de --target-workspace=live --target-dimension=en --full --dry-run
   # apply
   ./flow lostintranslation:synchronize --source-workspace=live --source-dimension=de --target-workspace=live --target-dimension=en --full
   ```

5. **Prune orphaned stale markers** left behind by content that no longer exists:

   ```shell
   ./flow lostintranslation:reconcile
   ```

6. **(Optional) Rebase outdated workspaces** so existing user workspaces pick up the new content:

   ```shell
   ./flow workspace:list
   ./flow workspace:rebaseoutdated
   ```

> The `stale_translations` tables are a separate Content Repository projection, not part of the content
> graph — replaying it works retroactively for all Neos installations from 9 on and does not affect your
> site's state.

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

Example configuration for retranslating German from English:

```yaml
Neos:
  ContentRepositoryRegistry:
    contentRepositories:
      default: # or other
        contentDimensions:
          language: # or similar
            values:
              'en': ...
              'de':
                options:
                  referenceLanguage: 'en'
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

**What synchronization means.** The target dimension is a **projection of the source dimension**: the source
language is the single source of truth for properties, structure and subtree tags. Changes made on the target
side are overwritten when a synchronization runs. That is intended — nothing tries to preserve them — and it is
why a rule has only two knobs.

`scope` governs what synchronization may **create**: `Document` mirrors the whole structure, while `Content`
only fills in content below Documents that already exist in the target dimension, because adopting a Document
into a target language stays a deliberate manual action. It governs nothing else.

`mode` governs **when the translation runs** — inline on publish (`auto`) or on a deliberate "sync now"
(`ask`) — and never what synchronization does. For the same source-side change both modes reach the same
target state. Everything cheap happens inline on every publish under either mode: subtree tags, deletions,
and the cross-workspace rebase. Only translating, which costs a DeepL call per node, is deferred by `ask`.

**Subtree tags and deletions are always synchronized**, with no per-rule setting. Source-language tag changes —
the `disabled` hide/show tag, the `removed` soft-removal tag and any custom `SubtreeTag` — are mirrored onto the
target variant. Deleting a node *is* a tag change: Neos 9.1 soft-removes by tagging `removed`, so deletions ride
the same mirror, and restoring from the trash bin mirrors back. When a soft removal becomes a hard one is Neos's
own garbage collector's business, per dimension. Because the source owns the target's tag state, hiding or
deleting only the *translation* does not stick — the next synchronization converges it back. If a dimension needs
independent visibility, do not point a rule at it.

> **Upgrading:** the `onSourceRemoval` and `onSourceTagging` rule keys have been **removed**. Both defaulted to
> "leave the target alone", and mirroring is now unconditional, so a configuration still carrying them is
> rejected with a clear error rather than silently starting to hide and delete nodes in the target dimension.
> Remove the keys; if you relied on the old default, remove the rule instead.

#### Triggering synchronization from the backend

The **Lost in Translation** backend module has a *Synchronization* overview listing every configured rule
with how many nodes are currently out of sync — or, if the rule cannot run at all, why: its target workspace
does not exist (rules never auto-create it), or the target is not based on the source workspace, which a
cross-workspace rule requires. A blocked rule reports no pending count, because nothing would ever act on it.
From here editors can run **Sync now** for a single rule or **Sync all** — the manual
counterpart to the CLI, useful for `mode: ask` rules. The overview reads its counts from the
stale-translation projection; if that projection has not been set up yet (a fresh install before
`./flow cr:setup`) the module shows a guidance banner instead of failing.

For `mode: ask` rules, publishing into the source workspace also raises a **post-publish prompt** in the
Neos UI ("translations are out of date — synchronize now?") so reviewers are nudged without sync happening
automatically. `mode: auto` rules need none of this — they translate inline on publish.

Automatic synchronization is always stale-driven. The Flow CLI offers the same operations for scripting,
cron jobs and one-off catch-ups:

**`lostintranslation:retranslate-node`** — retranslate the stale properties and create the missing target
variants below a single node, the CLI equivalent of the inspector's Retranslate button. The source language is
derived from the target dimension's `referenceLanguage`, so only the target is passed. All four arguments are
required.

```
./flow lostintranslation:retranslate-node --node-aggregate-id=<nodeAggregateId> --target=<target> --content-repository=<contentRepository> --workspace=<workspace>

#   --node-aggregate-id    the node whose subtree is brought up to date
#   --target               the target dimension value, e.g. "de"
#   --content-repository   the content repository id, usually "default"
#   --workspace            the workspace to operate in, e.g. "live"
```

**`lostintranslation:synchronize`** — reconcile a whole workspace/dimension instead of a single node. By default
it is stale-driven (only nodes the projection flagged as out of date); with `--full` it walks the entire source
subtree and considers every translatable node, re-translating existing target variants as well. Source and target
workspace may differ for cross-workspace flows — the target is force-rebased onto its base (which must be the
source workspace) before translating. `--dry-run` reports what would happen — variants, property updates,
removals and tag changes, per node and as totals — without dispatching any command, without translating anything
(so it costs no DeepL calls) and without rebasing the target.
Either mode also converges the target dimension's subtree tags onto the source.

```
./flow lostintranslation:synchronize --source-workspace=<sourceWorkspace> --source-dimension=<sourceDimension> --target-workspace=<targetWorkspace> --target-dimension=<targetDimension> [--content-repository=default] [--full] [--dry-run] [--skip-existing]

#   --source-workspace   workspace the source content is read from
#   --source-dimension   source language value; must equal the target dimension's referenceLanguage
#   --target-workspace   workspace the translated variant/property commands are dispatched into
#   --target-dimension   target language value, e.g. "de"
#   --full               walk the whole subtree instead of only stale records
#   --dry-run            report the affected records/nodes without writing anything or calling DeepL
#   --skip-existing      only with --full: keep target variants that already exist and have no stale row, instead
#                        of re-translating them. Preserves target-side edits the source has not touched — the one
#                        deliberate exception to "the target is a projection of the source", since re-asserting a
#                        property costs a DeepL call per node.
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
      language: # or similar
        values:

          #
          # English has to be configured differently for source and target as DeepL requires so,
          # the source and target are separated by a `:`
          #
          'en':
            deeplLanguage: 'EN:EN-GB'

          #
          # Danish uses a different locale identifier than DeepL, so the `deeplLanguage` has to be configured explicitly
          #
          'dk':
            options:
              deeplLanguage: 'DA'

          #
          # For German, the dimension value de is used in uppercase
          #
          'de': ...

          #
          # The Bavarian language is not supported by DeepL and is disabled
          #
          'de_bar':
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

## The Retranslator service

To build your own (re)translation workflow, the Retranslator allows for translating subtrees (e.g. documents and all their content). For a whole workspace (e.g. when publishing to live), use `WorkspaceSynchronizer::synchronizeWorkspace()`, which dispatches one subtree retranslation per stale record.


## Translation Cache

The plugin includes a translation cache for the DeepL API that stores the individual text parts
and their translated results for up to one week.
By default, the cache is enabled. To disable the cache, you need to set the following setting:

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
      enableCache: false
```

## Content Governance Mode

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

### 3.1.0

* The retranslation feature was upmerged from 2.1 and backed by a custom projection to keep track precisely
of what has changed in the reference language
