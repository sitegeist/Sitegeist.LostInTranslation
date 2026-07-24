@contentrepository
Feature: Retranslate a whole workspace into a target language dimension
  Background:
    Given using the following content dimensions yaml configuration:
        """yaml
        language:
          values:
            en: {}
            de:
              options:
                referenceLanguage: en
            fr:
              options:
                referenceLanguage: en
        """
    And using the following node types:
        """yaml
        'Neos.ContentRepository:Root': []
        # Because we build our own test CR from scratch we also need to define this NodeType because we do not read any NodeType definitions
        'Neos.Neos:Content':
          abstract: true
        'Neos.Neos:ContentCollection':
          abstract: true
          options:
            automaticTranslation: true
        'Neos.Neos:Document':
          abstract: true
          options:
            automaticTranslation: true
        'Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation':
          superTypes:
            'Neos.Neos:Content': true
          childNodes:
            tethered:
              type: 'Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation'
          properties:
            inlineEditableStringProperty:
              type: string
              ui:
                inlineEditable: true
            stringProperty:
              type: string
            autoTranslatableStringProperty:
              type: string
              options:
                automaticTranslation: true
          options:
            automaticTranslation: true
        'Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation':
          superTypes:
            'Neos.Neos:Content': true
          properties:
            inlineEditableStringProperty:
              type: string
              ui:
                inlineEditable: true
            stringProperty:
              type: string
            autoTranslatableStringProperty:
              type: string
              defaultValue: "autoTranslateMe"
              options:
                automaticTranslation: true
          options:
            automaticTranslation: true
        'Sitegeist.LostInTranslation.Testing:OtherLeafNodeWithAutomaticTranslation':
          superTypes:
            'Neos.Neos:Content': true
          properties:
            inlineEditableStringProperty:
              type: string
              ui:
                inlineEditable: true
            stringProperty:
              type: string
            replacementAutoTranslatableStringProperty:
              type: string
              defaultValue: "replacementAutoTranslateMe"
              options:
                automaticTranslation: true
          options:
            automaticTranslation: true
        'Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation':
          superTypes:
            'Neos.Neos:Document': true
          properties:
            autoTranslatableStringProperty:
              type: string
              options:
                automaticTranslation: true
          options:
            automaticTranslation: true
        'Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations':
          superTypes:
            'Neos.Neos:Content': true
          properties:
            inlineEditableStringProperty:
              type: string
              ui:
                inlineEditable: true
            stringProperty:
              type: string
          options:
            automaticTranslation: true
        'Sitegeist.LostInTranslation.Testing:DocumentExcludedFromAutomaticTranslation':
          superTypes:
            'Neos.Neos:Document': true
          properties:
            autoTranslatableStringProperty:
              type: string
              options:
                automaticTranslation: true
            inlineEditableStringProperty:
              type: string
              ui:
                inlineEditable: true
          options:
            automaticTranslation: false
        """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And I am user identified by "initiating-user-identifier"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
    And I am in workspace "live" and dimension space point {"language":"en"}
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "lady-eleonode-rootford"      |
      | nodeTypeName    | "Neos.ContentRepository:Root" |
    And the command CreateWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | baseWorkspaceName  | "live"           |
      | newContentStreamId | "user-cs-id"     |
    And the command CreateWorkspace is executed with payload:
      | Key                | Value                  |
      | workspaceName      | "other-user-workspace" |
      | baseWorkspaceName  | "live"                 |
      | newContentStreamId | "other-user-cs-id"     |


  Scenario: Retranslating a workspace varies in hierarchical order and skips nodes with ancestors that have to be translated manually
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId            | parentNodeAggregateId  | nodeTypeName                                                                 | initialPropertyValues                                         | tetheredDescendantNodeAggregateIds    |
      | z-translate-me-first       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation             | {"autoTranslatableStringProperty": "Ancestor Text"}           | {"tethered": "translate-me-tethered"} |
      | a-translate-me-second      | translate-me-tethered  | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation         | {"autoTranslatableStringProperty": "Descendant Text"}         | {}                                    |
      | b-translate-me-later       | translate-me-tethered  | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation         | {"autoTranslatableStringProperty": "Another Descendant Text"} | {}                                    |
      | do-not-translate-me        | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentExcludedFromAutomaticTranslation | {}                                                            | {}                                    |
      | skip-me-during-translation | do-not-translate-me    | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation         | {"autoTranslatableStringProperty": "Descendant Text"}         | {}                                    |
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId            | propertyNames                      |
      | live          | {"language":"de"}         | a-translate-me-second      | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | a-translate-me-second      | ["autoTranslatableStringProperty"] |
      | live          | {"language":"de"}         | b-translate-me-later       | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | b-translate-me-later       | ["autoTranslatableStringProperty"] |
      | live          | {"language":"de"}         | skip-me-during-translation | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | skip-me-during-translation | ["autoTranslatableStringProperty"] |
      | live          | {"language":"de"}         | translate-me-tethered      | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | translate-me-tethered      | ["autoTranslatableStringProperty"] |
      | live          | {"language":"de"}         | z-translate-me-first       | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | z-translate-me-first       | ["autoTranslatableStringProperty"] |

    When I retranslate workspace "live" in dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId            | propertyNames                      |
      | live          | {"language":"fr"}         | a-translate-me-second      | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | b-translate-me-later       | ["autoTranslatableStringProperty"] |
      | live          | {"language":"de"}         | skip-me-during-translation | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | skip-me-during-translation | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | translate-me-tethered      | ["autoTranslatableStringProperty"] |
      | live          | {"language":"fr"}         | z-translate-me-first       | ["autoTranslatableStringProperty"] |

    And I expect exactly 16 events to be published on stream "ContentStream:cs-identifier"
        # 1x ContentStreamWasCreated
        # 1x RootNodeAggregateWithNodeWasCreated
        # 6x NodeAggregateWithNodeWasCreated (5 commands and 1 tethered)
        # 4x NodeVariantWasCreated
        # 4x NodePropertiesWereSet for automatic translation
    And event at index 8 is of type "NodePeerVariantWasCreated" with payload:
      | Key                    | Expected                                                           |
      | workspaceName          | "live"                                                             |
      | contentStreamId        | "cs-identifier"                                                    |
      | nodeAggregateId        | "z-translate-me-first"                                             |
      | sourceOrigin           | {"language": "en"}                                                 |
      | peerOrigin             | {"language": "de"}                                                 |
      | peerSucceedingSiblings | [{"dimensionSpacePoint":{"language":"de"},"nodeAggregateId":null}] |
    And event at index 9 is of type "NodePeerVariantWasCreated" with payload:
      | Key                    | Expected                                                           |
      | workspaceName          | "live"                                                             |
      | contentStreamId        | "cs-identifier"                                                    |
      | nodeAggregateId        | "translate-me-tethered"                                            |
      | sourceOrigin           | {"language": "en"}                                                 |
      | peerOrigin             | {"language": "de"}                                                 |
      | peerSucceedingSiblings | [{"dimensionSpacePoint":{"language":"de"},"nodeAggregateId":null}] |
    And event at index 10 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                   |
      | workspaceName                                       | "live"                     |
      | contentStreamId                                     | "cs-identifier"            |
      | nodeAggregateId                                     | "z-translate-me-first"     |
      | originDimensionSpacePoint                           | {"language": "de"}         |
      | propertyValues.autoTranslatableStringProperty.value | "Ancestor Text translated" |
    And event at index 11 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                     |
      | workspaceName                                       | "live"                       |
      | contentStreamId                                     | "cs-identifier"              |
      | nodeAggregateId                                     | "translate-me-tethered"      |
      | originDimensionSpacePoint                           | {"language": "de"}           |
      | propertyValues.autoTranslatableStringProperty.value | "autoTranslateMe translated" |
    And event at index 12 is of type "NodePeerVariantWasCreated" with payload:
      | Key                    | Expected                                                           |
      | workspaceName          | "live"                                                             |
      | contentStreamId        | "cs-identifier"                                                    |
      | nodeAggregateId        | "a-translate-me-second"                                            |
      | sourceOrigin           | {"language": "en"}                                                 |
      | peerOrigin             | {"language": "de"}                                                 |
      | peerSucceedingSiblings | [{"dimensionSpacePoint":{"language":"de"},"nodeAggregateId":null}] |
    And event at index 13 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                     |
      | workspaceName                                       | "live"                       |
      | contentStreamId                                     | "cs-identifier"              |
      | nodeAggregateId                                     | "a-translate-me-second"      |
      | originDimensionSpacePoint                           | {"language": "de"}           |
      | propertyValues.autoTranslatableStringProperty.value | "Descendant Text translated" |
    And event at index 14 is of type "NodePeerVariantWasCreated" with payload:
      | Key                    | Expected                                                           |
      | workspaceName          | "live"                                                             |
      | contentStreamId        | "cs-identifier"                                                    |
      | nodeAggregateId        | "b-translate-me-later"                                             |
      | sourceOrigin           | {"language": "en"}                                                 |
      | peerOrigin             | {"language": "de"}                                                 |
      | peerSucceedingSiblings | [{"dimensionSpacePoint":{"language":"de"},"nodeAggregateId":null}] |
    And event at index 15 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                             |
      | workspaceName                                       | "live"                               |
      | contentStreamId                                     | "cs-identifier"                      |
      | nodeAggregateId                                     | "b-translate-me-later"               |
      | originDimensionSpacePoint                           | {"language": "de"}                   |
      | propertyValues.autoTranslatableStringProperty.value | "Another Descendant Text translated" |

