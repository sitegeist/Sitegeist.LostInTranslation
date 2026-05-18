@contentrepository
Feature: Track the staleness state of translations and run retranslation on stale translations

    Background:
        Given using the following content dimensions:
            | Identifier | Values | Generalizations | Configuration                                   |
            | language   | en, de |                 | {"values": {"de": {"referenceLanguage": "en"}}} |
        And using the following node types:
    """yaml
    'Neos.ContentRepository:Root': []
    'Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation':
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

    Scenario: Complete retranslation cycle: Create a node, translate it, change the original, publish, rebase on another workspace, retranslate there and publish/rebase it back to its origin
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                                                        | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} | {"tethered": "nodewyn-tetherton"}  |

        When the command CreateNodeVariant is executed with payload:
            | Key             | Value                    |
            | nodeAggregateId | "sir-david-nodenborough" |
            | sourceOrigin    | {"language":"en"}        |
            | targetOrigin    | {"language":"de"}        |

        # 1x ContentStreamWasForked, 2x NodeAggregateWithNodeWasCreated, 2x NodeVariantWasCreated, 1x NodePropertiesWereSet via autotranslation
        Then I expect exactly 6 events to be published on stream "ContentStream:user-cs-id"
        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

        # change the original
        When the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                |
            | nodeAggregateId           | "sir-david-nodenborough"                             |
            | originDimensionSpacePoint | {"language": "en"}                                   |
            | propertyValues            | {"inlineEditableStringProperty": "My adjusted Text"} |
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                        |
            | nodeAggregateId           | "sir-david-nodenborough"                                     |
            | originDimensionSpacePoint | {"language": "en"}                                           |
            | propertyValues            | {"autoTranslatableStringProperty": "My adjusted Other Text"} |
        And the command CreateNodeAggregateWithNode is executed with payload:
            | Key                       | Value                                                                                                                                                         |
            | nodeAggregateId           | "nody-mc-nodeface"                                                                                                                                            |
            | parentNodeAggregateId     | "nodewyn-tetherton"                                                                                                                                           |
            | originDimensionSpacePoint | {"language": "en"}                                                                                                                                            |
            | nodeTypeName              | "Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation"                                                                                        |
            | propertyValues            | {"autoTranslatableStringProperty": "My adjusted Other Text"}                                                                                                  |
            | initialPropertyValues     | {"inlineEditableStringProperty": "My Grandchild Text", "autoTranslatableStringProperty": "My Other Grandchild Text", "stringProperty": "Grandchild whatever"} |

        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId          | propertyNames                                                      |
            | user-workspace | {"language": "en"}        | "sir-david-nodenborough" | ["inlineEditableStringProperty", "autoTranslatableStringProperty"] |
            | user-workspace | {"language": "en"}        | "nody-mc-nodeface"       | ["inlineEditableStringProperty", "autoTranslatableStringProperty"] |

        When I retranslate node "sir-david-nodenborough" in dimension space point {"language": "en"}
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |
        Then I expect exactly 11 events to be published on stream "ContentStream:user-cs-id"
        And event at index 9 is of type "NodePropertiesWereSet" with payload:
            | Key                                               | Expected                            |
            | contentStreamId                                   | "user-cs-id"                        |
            | nodeAggregateId                                   | "sir-david-nodenborough"                  |
            | originDimensionSpacePoint                         | {"language": "de"}                  |
            | propertyValues.inlineEditableStringProperty.value | "My adjusted child Text translated" |
            | propertyValues.autoTranslatableStringProperty.value | "My adjusted child Text translated" |
        And event at index 10 is of type "NodeAggregateWithNodeWasCreated" with payload:
            | Key                                               | Expected                            |
            | contentStreamId                                   | "user-cs-id"                        |
            | nodeAggregateId                                   | "nody-mc-nodeface"                  |
            | originDimensionSpacePoint                         | {"language": "de"}                  |
            | propertyValues.inlineEditableStringProperty.value | "My adjusted child Text translated" |
            | propertyValues.autoTranslatableStringProperty.value | "My adjusted child Text translated" |
