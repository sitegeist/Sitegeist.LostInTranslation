# Sitegeist.LostInTranslation
## Automatic Translations for Neos via DeeplApi

Documents and Contents are translated automatically once editors choose to "create and copy" a version in another language.
The included DeeplService can be used for other purposes aswell.

The development was a collaboration of Sitegeist and Code Q.

### Authors & Sponsors

* Martin Ficzel - ficzel@sitegeist.de
* Felix Gradinaru - fg@codeq.at

*The development and the public-releases of this package is generously sponsored
by our employers http://www.sitegeist.de and http://www.codeq.at.*

## Installation

Sitegeist.LostInTranslation is available via packagist. Run `composer require sitegeist/lostintranslation`.

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

## Configuration

This package needs an authenticationKey for the DeeplL Api from https://www.deepl.com/pro-api.
There are free plans that support a limited number but for productive use we recommend using a payed plan.

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
      authenticationKey: '.........................'
```

The translation of nodes can is configured via settings:

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
      # If this is disabled iline editables can be configured for translation by setting
      # `options.translateOnAdoption: true` for each property seperatly
      #
      translateInlineEditables: true

      #
      # The name of the language dimension. Usually needs no modification
      #
      languageDimensionName: 'language'
```

To enable automated translations for a language preset, set `options.translationStrategy` to  `once`, `sync` or `none`.
The default mode is `once`;

* `once` will translate the node only once when the editor switches the language in the backend while editing this node. This is useful if you want to get an initial translation, but work on the different variants on your own after that.
* `sync` will translate and sync the node every time the node in the default language is published. Thus, it will not make sense to edit the node variant in an automatically translated language using this options, as your changed will be overwritten every time.
* `none` will not translate variants for this dimension.

If a preset of the language dimension uses a locale identifier that is not compatible with DeepL the deeplLanguage can
be configured explicitly for this preset via `options.deeplLanguage`.

```yaml
Neos:
  ContentRepository:
    contentDimensions:
      'language':

        #
        # The `defaultPreset` marks the source of for all translations whith mode `sync`
        #
        label: 'Language'
        default: 'en'
        defaultPreset: 'en'

        presets:

          #
          # English is the main language of the editors and spoken by editors,
          # the automatic translation is disabled therefore
          #
          'en':
            label: 'English'
            values: ['en']
            uriSegment: 'en'
            options:
              translationStrategy: 'none'

          #
          # Danish uses a different locale identifier then DeepL so the `deeplLanguage` has to be configured explicitly
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
          # The bavarian language is not supported by DeepL and is disabled
          #
          'de_bar':
            label: 'Bayrisch'
            values: ['de_bar','de']
            uriSegment: 'de_bar'
            options:
              translationStrategy: 'none'
```

### Ignoring Terms

You can define terms that should be ignored by DeepL in the configuration.
The terms will are evaluated case-insensitive when searching for them, however
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

The package also provides two Eel Helper to translate texts in Fusion.

**:warning: Every one of these Eel helpers make an individual request to DeepL.** Thus having many of them on one page can significantly slow down the performance for if the page is uncached.
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
and their translated result for up to one week.
By default, the cache is enabled. To disable the cache, you need to set the following setting:

```yaml
Sitegeist:
  LostInTranslation:
    DeepLApi:
      enableCache: false
```

## Performance

For every translated node a single request is made to the DeepL API. This can lead to significant delay when Documents with lots of nodes are translated. It is likely that future versions will improve this.

## Contribution

We will gladly accept contributions. Please send us pull requests.

## Changelog

### 2.0.0

* The preset option `translationStrategy` was introduced. There are now two auto-translation strategies
  * Strategy `once` will auto-translate the node once "on adoption", i.e. the editor switches to a different language dimension
  * Strategy `sync` will auto-translate and sync the node every time a node is updated in the default preset language
* The node setting `options.translateOnAdoption` as been renamed to `options.automaticTranslation`
* The new node option `options.automaticTranslation` was introduced
