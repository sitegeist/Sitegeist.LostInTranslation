@contentrepository
Feature: Track the staleness state of translations and run retranslation on stale translations
    # Note: We deliberately ignore hierarchy due to complexity reasons until depending projections are implemented.
    # If this becomes a performance issue due to lots of orphaned stale translation records, we might need an additional
    # cleanup mechanism like comparing the projection with the graph projection.

    Background:
        Given using the following content dimensions yaml configuration:
        """yaml
        language:
          values:
            en: {}
            de:
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
        'Neos.Neos:Document':
          abstract: true
          properties:
            title:
              type: string
              options:
                automaticTranslation: true
            # TODO: add uriPathSegment to test correct handling of auto-translatable properties with special processing
        'Sitegeist.LostInTranslation.Document.Page':
          superTypes:
            'Neos.Neos:Document': true
          childNodes:
            main:
              type: 'Neos.Neos:ContentCollection'
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

    Scenario: Retranslate a node
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                                                        | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} | {"tethered": "nodewyn-tetherton"}  |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            # ordered by node aggregate id by default
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command CreateNodeVariant is executed with payload:
            | Key             | Value                    |
            | nodeAggregateId | "sir-david-nodenborough" |
            | sourceOrigin    | {"language":"en"}        |
            | targetOrigin    | {"language":"de"}        |

        # 1x ContentStreamWasForked, 2x NodeAggregateWithNodeWasCreated, 2x NodeVariantWasCreated, 2x NodePropertiesWereSet via autotranslation
        Then I expect exactly 7 events to be published on stream "ContentStream:user-cs-id"
        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

        # change the original
        When the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                                                                            |
            | nodeAggregateId           | "sir-david-nodenborough"                                                                                         |
            | originDimensionSpacePoint | {"language": "en"}                                                                                               |
            | propertyValues            | {"inlineEditableStringProperty": "My adjusted Text", "autoTranslatableStringProperty": "My adjusted Other Text"} |

        # change tethered child node
        When the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                           |
            | nodeAggregateId           | "nodewyn-tetherton"                                             |
            | originDimensionSpacePoint | {"language": "en"}                                              |
            | propertyValues            | {"autoTranslatableStringProperty": "My adjusted tethered Text"} |

        # Add another node
        And the command CreateNodeAggregateWithNode is executed with payload:
            | Key                       | Value                                                                                                                                                         |
            | nodeAggregateId           | "nody-mc-nodeface"                                                                                                                                            |
            | parentNodeAggregateId     | "nodewyn-tetherton"                                                                                                                                           |
            | originDimensionSpacePoint | {"language": "en"}                                                                                                                                            |
            | nodeTypeName              | "Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation"                                                                                        |
            | propertyValues            | {"autoTranslatableStringProperty": "My adjusted Other Text"}                                                                                                  |
            | initialPropertyValues     | {"inlineEditableStringProperty": "My Grandchild Text", "autoTranslatableStringProperty": "My Other Grandchild Text", "stringProperty": "Grandchild whatever"} |

        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When I retranslate node "sir-david-nodenborough" in workspace "user-workspace" and dimension space point {"language":"de"}
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |
        And I expect exactly 14 events to be published on stream "ContentStream:user-cs-id"
        # 2 NodePropertiesWereSet for translations of "sir-david-nodenborough" and its tethered child "nodewyn-tetherton".
        # The Retranslator emits these in based on the node hierarchy (parent -> children -> grandchildren etc)
        And event at index 10 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                            |
            | workspaceName                                       | "user-workspace"                    |
            | contentStreamId                                     | "user-cs-id"                        |
            | nodeAggregateId                                     | "sir-david-nodenborough"            |
            | originDimensionSpacePoint                           | {"language": "de"}                  |
            | propertyValues.inlineEditableStringProperty.value   | "My adjusted Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "My adjusted Other Text translated" |
        And event at index 11 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                               |
            | workspaceName                                       | "user-workspace"                       |
            | contentStreamId                                     | "user-cs-id"                           |
            | nodeAggregateId                                     | "nodewyn-tetherton"                    |
            | originDimensionSpacePoint                           | {"language": "de"}                     |
            | propertyValues.autoTranslatableStringProperty.value | "My adjusted tethered Text translated" |
        # 1 Node Created for missing node in target dimension space point: "nody-mc-nodeface" in {"language": "de"}
        And event at index 12 is of type "NodePeerVariantWasCreated" with payload:
            | Key                    | Expected                                                           |
            | workspaceName          | "user-workspace"                                                   |
            | contentStreamId        | "user-cs-id"                                                       |
            | nodeAggregateId        | "nody-mc-nodeface"                                                 |
            | sourceOrigin           | {"language": "en"}                                                 |
            | peerOrigin             | {"language": "de"}                                                 |
            | peerSucceedingSiblings | [{"dimensionSpacePoint":{"language":"de"},"nodeAggregateId":null}] |
        And event at index 13 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                              |
            | workspaceName                                       | "user-workspace"                      |
            | contentStreamId                                     | "user-cs-id"                          |
            | nodeAggregateId                                     | "nody-mc-nodeface"                    |
            | originDimensionSpacePoint                           | {"language": "de"}                    |
            | propertyValues.inlineEditableStringProperty.value   | "My Grandchild Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "My Other Grandchild Text translated" |

    Scenario: Retranslate skips nested Document subtrees
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                             |
            | parent-doc      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Parent Text"} |
            | child-doc       | parent-doc             | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Child Text"}  |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                      |
            | user-workspace | {"language":"de"}         | child-doc       | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | parent-doc      | ["autoTranslatableStringProperty"] |

        When I retranslate node "parent-doc" in workspace "user-workspace" and dimension space point {"language":"de"}

        # child document is not retranslated and still stale
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                      |
            | user-workspace | {"language":"de"}         | child-doc       | ["autoTranslatableStringProperty"] |

    Scenario: Retranslating into the source language is a no-op
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                                                        | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} | {"tethered": "nodewyn-tetherton"}  |
        # Setup stale state we expect to remain untouched after the no-op retranslate.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # 1x ContentStreamWasForked (CreateWorkspace user-workspace) + 2x NodeAggregateWithNodeWasCreated
        And I expect exactly 3 events to be published on stream "ContentStream:user-cs-id"

        When I retranslate node "sir-david-nodenborough" in workspace "user-workspace" and dimension space point {"language":"en"}

        # Stale state untouched.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
        # Event count untouched.
        And I expect exactly 3 events to be published on stream "ContentStream:user-cs-id"

    Scenario: Complete retranslation cycle: Create a node, translate it, change the original, publish, rebase on another workspace, retranslate there and publish/rebase it back to its origin
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                                                        | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} | {"tethered": "nodewyn-tetherton"}  |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            # ordered by node aggregate id by default
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command CreateNodeVariant is executed with payload:
            | Key             | Value                    |
            | nodeAggregateId | "sir-david-nodenborough" |
            | sourceOrigin    | {"language":"en"}        |
            | targetOrigin    | {"language":"de"}        |

        # 1x ContentStreamWasForked, 2x NodeAggregateWithNodeWasCreated, 2x NodeVariantWasCreated, 2x NodePropertiesWereSet via autotranslation
        Then I expect exactly 7 events to be published on stream "ContentStream:user-cs-id"
        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

        # change the original
        When the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                                                                            |
            | nodeAggregateId           | "sir-david-nodenborough"                                                                                         |
            | originDimensionSpacePoint | {"language": "en"}                                                                                               |
            | propertyValues            | {"inlineEditableStringProperty": "My adjusted Text", "autoTranslatableStringProperty": "My adjusted Other Text"} |
        # Add another node
        And the command CreateNodeAggregateWithNode is executed with payload:
            | Key                       | Value                                                                                                                                                         |
            | nodeAggregateId           | "nody-mc-nodeface"                                                                                                                                            |
            | parentNodeAggregateId     | "nodewyn-tetherton"                                                                                                                                           |
            | originDimensionSpacePoint | {"language": "en"}                                                                                                                                            |
            | nodeTypeName              | "Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation"                                                                                        |
            | propertyValues            | {"autoTranslatableStringProperty": "My adjusted Other Text"}                                                                                                  |
            | initialPropertyValues     | {"inlineEditableStringProperty": "My Grandchild Text", "autoTranslatableStringProperty": "My Other Grandchild Text", "stringProperty": "Grandchild whatever"} |

        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Change base workspace
        When the command ChangeBaseWorkspace is executed with payload:
            | Key               | Value                  |
            | workspaceName     | "other-user-workspace" |
            | baseWorkspaceName | "user-workspace"       |
        Then I expect exactly the following stale translations:
            | workspaceName        | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | other-user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | other-user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace       | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace       | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command ChangeBaseWorkspace is executed with payload:
            | Key               | Value                  |
            | workspaceName     | "other-user-workspace" |
            | baseWorkspaceName | "live"                 |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Publish workspace
        When the command PublishWorkspace is executed with payload:
            | Key                | Value            |
            | workspaceName      | "user-workspace" |
            | newContentStreamId | "new-user-cs-id" |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Rebase other workspace.
        # Pin the rebased content stream to a stable name so we can assert against it below — without `rebasedContentStreamId`,
        # the CR generates a fresh UUID each run and event-index assertions cannot be written.
        When the command RebaseWorkspace is executed with payload:
            | Key                    | Value                      |
            | workspaceName          | "other-user-workspace"     |
            | rebasedContentStreamId | "rebased-other-user-cs-id" |
        Then I expect exactly the following stale translations:
            | workspaceName        | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live                 | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live                 | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | other-user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | other-user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace       | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace       | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Retranslate in other workspace
        When I retranslate node "sir-david-nodenborough" in workspace "other-user-workspace" and dimension space point {"language":"de"}
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
        # Retranslate fires its commands against the workspace's CURRENT content stream,
        # which after the rebase above is the freshly-forked `rebased-other-user-cs-id` stream — NOT the original `user-cs-id`
        # (that one is frozen since the user-workspace publish).
        # The rebased stream contains exactly the fork event + the 3 retranslate-cascade events.
        And I expect exactly 4 events to be published on stream "ContentStream:rebased-other-user-cs-id"
        # Stale-property fix-up for sir-david
        And event at index 1 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                            |
            | workspaceName                                       | "other-user-workspace"              |
            | contentStreamId                                     | "rebased-other-user-cs-id"          |
            | nodeAggregateId                                     | "sir-david-nodenborough"            |
            | originDimensionSpacePoint                           | {"language": "de"}                  |
            | propertyValues.inlineEditableStringProperty.value   | "My adjusted Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "My adjusted Other Text translated" |
        # nody-mc-nodeface had no `de` variant yet, so the Retranslator emits CreateNodeVariant.
        And event at index 2 is of type "NodePeerVariantWasCreated" with payload:
            | Key             | Expected                   |
            | workspaceName   | "other-user-workspace"     |
            | contentStreamId | "rebased-other-user-cs-id" |
            | nodeAggregateId | "nody-mc-nodeface"         |
            | sourceOrigin    | {"language": "en"}         |
            | peerOrigin      | {"language": "de"}         |
        # …and the TranslationCommandHook then cascades the translated property values into the
        # newly-created variant via a follow-up SetNodeProperties → NodePropertiesWereSet.
        And event at index 3 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                              |
            | workspaceName                                       | "other-user-workspace"                |
            | contentStreamId                                     | "rebased-other-user-cs-id"            |
            | nodeAggregateId                                     | "nody-mc-nodeface"                    |
            | originDimensionSpacePoint                           | {"language": "de"}                    |
            | propertyValues.inlineEditableStringProperty.value   | "My Grandchild Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "My Other Grandchild Text translated" |

        # Publish other workspace
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "other-user-workspace" |
            | newContentStreamId | "new-other-user-cs-id" |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Rebase workspace
        When the command RebaseWorkspace is executed with payload:
            | Key           | Value            |
            | workspaceName | "user-workspace" |
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

    Scenario: Node Type Change
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                                                        |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command ChangeNodeAggregateType is executed with payload:
            | Key             | Value                                                                       |
            | nodeAggregateId | "sir-david-nodenborough"                                                    |
            | newNodeTypeName | "Sitegeist.LostInTranslation.Testing:OtherLeafNodeWithAutomaticTranslation" |
            | strategy        | "happypath"                                                                 |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","replacementAutoTranslatableStringProperty"] |

        When the command PublishWorkspace is executed with payload:
            | Key                | Value            |
            | workspaceName      | "user-workspace" |
            | newContentStreamId | "new-user-cs-id" |
        And the command ChangeNodeAggregateType is executed with payload:
            | Key             | Value                                                                  |
            | nodeAggregateId | "sir-david-nodenborough"                                               |
            | newNodeTypeName | "Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation" |
            | strategy        | "happypath"                                                            |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                                |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","replacementAutoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"]            |

    # We explicitly ignore "NodeAggregateWasRemoved" events for now

    Scenario: Changing a node aggregate's type thins out properties not translatable in the new type
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                     | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other"} | {"tethered": "nodewyn-tetherton"}  |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command ChangeNodeAggregateType is executed with payload:
            | Key             | Value                                                           |
            | nodeAggregateId | "sir-david-nodenborough"                                        |
            | newNodeTypeName | "Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations" |
            | strategy        | "happypath"                                                     |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
            # children are ignored (see top-level comment)
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            # NodeWithFewerTranslations has no `autoTranslatableStringProperty`
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty"]   |

    Scenario: Publishing a workspace propagates stale records to the base workspace
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                     | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other"} | {"tethered": "nodewyn-tetherton"}  |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command PublishWorkspace is executed with payload:
            | Key                | Value            |
            | workspaceName      | "user-workspace" |
            | newContentStreamId | "new-user-cs-id" |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Partial publish propagates only the published nodes' stale records
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                 | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"}         | {"tethered": "nodewyn-tetherton"}  |
            | nody-mc-nodeface       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text Sibling"} | {"tethered": "nodenberg"}          |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
            | user-workspace | {"language":"de"}         | nodenberg              | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

        When the command PublishIndividualNodesFromWorkspace is executed with payload:
            | Key            | Value                      |
            | workspaceName  | "user-workspace"           |
            | nodesToPublish | ["sir-david-nodenborough"] |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodenberg              | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

    Scenario: Discarding a workspace drops all its stale records
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                         | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} | {"tethered": "nodewyn-tetherton"}  |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

        When the command DiscardWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "user-workspace"       |
            | newContentStreamId | "user-cs-id-discarded" |
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

    Scenario: Partial discard drops only the discarded nodes' stale records
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                 | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"}         | {"tethered": "nodewyn-tetherton"}  |
            | nody-mc-nodeface       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text Sibling"} | {"tethered": "nodenberg"}          |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
            | user-workspace | {"language":"de"}         | nodenberg              | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

        When the command DiscardIndividualNodesFromWorkspace is executed with payload:
            | Key                | Value                      |
            | workspaceName      | "user-workspace"           |
            | nodesToDiscard     | ["sir-david-nodenborough"] |
            | newContentStreamId | "user-cs-id-after-partial" |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId  | propertyNames                      |
            | user-workspace | {"language":"de"}         | nodenberg        | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface | ["autoTranslatableStringProperty"] |

    Scenario: Deleting a workspace drops all its stale records
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                       | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "first"} | {"tethered": "nodewyn-tetherton"}  |
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

        When the command DeleteWorkspace is executed with payload:
            | Key           | Value            |
            | workspaceName | "user-workspace" |
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

    Scenario: Moving a dimension space point
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                       | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "first"} | {"tethered": "nodewyn-tetherton"}  |
        And the command PublishWorkspace is executed with payload:
            | Key           | Value            |
            | workspaceName | "user-workspace" |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

        When I change the content dimensions in content repository "default" to:
            | Identifier | Values  | Generalizations |
            | language   | en, ltz |                 |
        And the command MoveDimensionSpacePoint is executed with payload:
            | Key                  | Value              |
            | workspaceName        | "live"             |
            | source               | {"language":"de"}  |
            | target               | {"language":"ltz"} |
            | initialWorkspaceName | "live"             |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
            | live           | {"language":"ltz"}        | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | live           | {"language":"ltz"}        | sir-david-nodenborough | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"ltz"}        | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
            | user-workspace | {"language":"ltz"}        | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

    Scenario: Document nodes are recorded as stale translations
        # A Document.Page carries the auto-translatable `title` inherited from Neos.Neos:Document, so
        # creating one records a stale translation for `title` in the target dimension — Documents
        # are tracked for retranslation just like Content nodes. Its tethered `main`
        # ContentCollection has no translatable properties and is recorded with an empty list.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                              | initialPropertyValues | tetheredDescendantNodeAggregateIds |
            | homepage        | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page | {"title": "Home"}     | {"main": "homepage-main"}          |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames |
            | user-workspace | {"language":"de"}         | homepage        | ["title"]     |
            | user-workspace | {"language":"de"}         | homepage-main   | []            |

