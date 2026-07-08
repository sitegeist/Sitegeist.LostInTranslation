# Sitegeist.LostInTranslation

## Automatic Translations for Neos via DeepLApi

Documents and contents are translated automatically once editors choose to "create and copy" a version in another language.
The included DeeplService can be used for other purposes as well.

The development was a collaboration of Sitegeist and Code Q.

### Authors & Sponsors

* Martin Ficzel - <ficzel@sitegeist.de>
* Felix Gradinaru - <fg@codeq.at>

*The development and the public-releases of this package is generously sponsored
by our employers <http://www.sitegeist.de> and <http://www.codeq.at>.*

## Installation

Sitegeist.LostInTranslation is available via Packagist. Run `composer require sitegeist/lostintranslation`.

We use semantic-versioning so every breaking change will increase the major-version number.

## How it works

By default all inline editable properties are translated using DeepL (see Setting `translateInlineEditables`).
To include other `string` properties into the automatic translation the `options.automaticTranslation: true`
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

## Configuration

This package needs an authenticationKey for the DeepL API from <https://www.deepl.com/pro-api>.
There are free plans that support a limited number, but for productive use we recommend using a paid plan.

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
      authenticationKey: '.........................'
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
            # The label prefix can be used to prevent different instances from overwriting or deleting each other's
            # glossaries. The default value is the FLOW_CONTEXT, but this may need adjustment based on your use case
            #
            labelPrefix: '%env:FLOW_CONTEXT%'
            #
            # The number of outdated remote glossaries to keep to reduce problems when systems are cloned
            #
            keepNumber: 10
```

The commands `./flow glossary:uploadall` and `./flow glossary:cleanupall` allow automating those tasks and may
be integrated into backup and restore or synchronization scripts.

### Content Repository

The translation of nodes can be configured via settings:

```yaml
Sitegeist:
  LostInTranslation:
    nodeTranslation:
      #
      # Enable the automatic translation of nodes when they are adopted to another dimension
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

To enable automated translations for a language preset, set `options.translationStrategy` to  `once`, `sync` or `none`.
The default mode is `once`.

* `once` will translate the node only once when the editor switches the language in the backend while editing this node. This is useful if you want to get an initial translation, but work on the different variants on your own after that.
* `sync` will translate and sync the node every time the node in the default language is published. Thus, it will not make sense to edit the node variant in an automatically translated language using this options, as your changed will be overwritten every time.
* `none` will not translate variants for this dimension.

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
          # English is the main language of the editors and spoken by editors,
          # the automatic translation is disabled therefore
          #
          # English has to be configured differently for source and target as DeepL requires;
          # The source and target are separated by a `:`
          #
          'en':
            label: 'English'
            values: ['en']
            uriSegment: 'en'
            options:
              deeplLanguage: 'en:en-GB'
              translationStrategy: 'none'

          #
          # Danish uses a different locale identifier than DeepL so the `deeplLanguage` has to be configured explicitly
          # Here we use the "once" strategy, which will translate nodes only once on switching the language
          #
          'dk':
            label: 'Dansk'
            values: ['dk']
            uriSegment: 'dk'
            options:
              deeplLanguage: 'da'
              translationStrategy: 'once'

          #
          # For German, we want to have a steady sync of nodes
          #
          'de':
            label: 'Bayrisch'
            values: ['de']
            uriSegment: 'de'
            options:
              translationStrategy: 'sync'

          #
          # The Bavarian language is not supported by DeepL and is disabled
          #
          'de_bar':
            label: 'Bayrisch'
            values: ['de_bar','de']
            uriSegment: 'de_bar'
            options:
              translationStrategy: 'none'
```

### Ignoring terms

You can define terms that should be ignored by DeepL in the configuration.
The terms are evaluated case-insensitively when searching for them; however,
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

The package also provides two Eel Helpers to translate texts in Fusion.

**:warning: Every one of these Eel helpers makes an individual request to DeepL.** Thus having many of them on one page can significantly slow down performance if the page is uncached.
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

### Compare and update translations

The Lost in Translation package contains two prototypes that visualize differences between the current and the `default`
translation.

To show the information in the backend you can render the `Sitegeist.LostInTranslation:Collection.TranslationInformation` adjacent to a ContentCollection.

```neosfusion
content = Neos.Fusion:Join {
     info = Sitegeist.LostInTranslation:Collection.TranslationInformation {
          nodePath = 'content'
     }
     content = Neos.Neos:ContentCollection {
          nodePath = 'content'
     }
}
```

![DDEV__WebPage_test](https://github.com/sitegeist/Sitegeist.LostInTranslation/assets/1309380/7d268e18-5a2a-4292-8844-4800020b0ddb)

## Sync Command

The package comes with a sync command to manually sync a subtree from one language to the other.
This can be used from time to time, or for creating a new language from scratch.

## Usage

```bash
./flow translation:sync <nodePath> <from> <to> [<nodeTypeFilter>]
```

### Parameters

- **`nodePath`** *(string)*:  
  The path of the root node to start the synchronization from.  
  **Example**: `/sites/example.com/home`.  
  **Note**: The path must not be `/sites`.

- **`from`** *(string)*:  
  The ISO language code to take the contents for translation *from*.  
  This must exist as a language dimension preset in the configuration.

- **`to`** *(string)*:  
  The ISO language code to translate the contents *to*.  
  This must exist as a language dimension preset in the configuration.

- **`nodeTypeFilter`** *(string, optional)*:  
  Filters the nodes to be synchronized by their type.  
  Defaults to `Neos.Neos:Document`.

### Example

```bash
./flow translation:sync /sites/example.com/home en_US de Neos.Neos:Document
```

This command synchronizes all document nodes under `/sites/example.com/home` from English (`en_US`) to German (`de`).

### `Sitegeist.LostInTranslation:Document.TranslationInformation`

Show information about missing and outdated translations on document level. Allows to "translate missing" and "update outdated" nodes.
The prototype is only shown in backend + edit mode.

- `node`:  (Node, default `documentNode` from fusion context) The document node that shall be compared
- `referenceLanguage`: (string, default language preset) The preset used to compare against

### `Sitegeist.LostInTranslation:Collection.TranslationInformation`

Show information about missing and outdated translations on content collection level. Allows to "translate missing" and "update outdated" nodes.
The prototype is only shown in backend + edit mode.

- `nodePath`: (string, default null)
- `node`:  (Node, default `node` from fusion context)
- `referenceLanguage`: (string, default language preset) The preset used to compare against

### Translation Cache

The plugin includes a translation cache for the DeepL API that stores the individual text parts
and their translated result for up to one week.
By default, the cache is enabled. To disable the cache, set the following setting:

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
      enableCache: false
```

## Performance

For every translated node a single request is made to the DeepL API. This can lead to significant delay when documents with lots of nodes are translated. It is likely that future versions will improve this.

## Contribution

We will gladly accept contributions. Please send us pull requests.

## Changelog

### 2.0.0

* The preset option `translationStrategy` was introduced. There are now two auto-translation strategies
  * Strategy `once` will auto-translate the node once "on adoption", i.e. the editor switches to a different language dimension
  * Strategy `sync` will auto-translate and sync the node every time a node is updated in the default preset language
* The node setting `options.translateOnAdoption` has been renamed to `options.automaticTranslation`
* The new node option `options.automaticTranslation` was introduced
