@contentrepository
Feature: Track the staleness state of translations and run retranslation on stale translations
    # Note: Hierarchy is NOT cascaded by the projection on node removal — the CR emits only one
    # NodeAggregateWasRemoved event for the directly removed aggregate. Descendant rows are pruned via
    # `flow lostintranslation:reconcile` (compares the projection with the graph projection), and the
    # workspace synchronizers skip orphans at read time so they cannot cause spurious retranslate calls.

  Background:
    Given using the following content dimensions yaml configuration:
        """yaml
        language:
          values:
            en: {}
            de:
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
        # Opted OUT at type level while still declaring translatable properties — the exclusion must win over
        # every per-property opt-in (including the `title` inherited from Neos.Neos:Document).
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

  Scenario: Nodes of a type excluded from automatic translation don't get stale translation entries
        # Type-level `automaticTranslation: false` short-circuits BOTH projection write paths: the creation
        # handler and the property-set handler. The empty expectation table is meaningful rather than vacuous —
        # a translation-enabled type is recorded even when nothing translatable carries a value (see "Changing a
        # non-translatable property must not remove an existing (empty) stale record"), so a row would appear
        # here the moment either guard regressed.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                                 | initialPropertyValues                                                                           |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentExcludedFromAutomaticTranslation | {"autoTranslatableStringProperty": "My Text", "inlineEditableStringProperty": "My Inline Text"} |
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                       |
      | nodeAggregateId           | "sir-david-nodenborough"                                    |
      | originDimensionSpacePoint | {"language": "en"}                                          |
      | propertyValues            | {"inlineEditableStringProperty": "My Modified Inline Text"} |
        # The property-set handler resolves the node type through the memorized-type table rather than from the
        # event, so it needs its own exclusion guard — an edit on an excluded type must still record nothing.
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

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
        # The whole retranslation is a system/AI operation: every emitted event is attributed to the AI service, NOT
        # the editor who triggered the run — including the structural NodePeerVariantWasCreated (index 12), consistent
        # with the publish-driven SynchronizationCommandHook.
    And event data at index 10 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |
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
    And event data at index 12 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |
    And event at index 13 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                              |
      | workspaceName                                       | "user-workspace"                      |
      | contentStreamId                                     | "user-cs-id"                          |
      | nodeAggregateId                                     | "nody-mc-nodeface"                    |
      | originDimensionSpacePoint                           | {"language": "de"}                    |
      | propertyValues.inlineEditableStringProperty.value   | "My Grandchild Text translated"       |
      | propertyValues.autoTranslatableStringProperty.value | "My Other Grandchild Text translated" |
    And event data at index 13 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |

  Scenario: A translatable property left at its NodeType default value is translated
    # `autoTranslatableStringProperty` is not set explicitly here, so it takes its NodeType default ("autoTranslateMe").
    # Defaults are part of the node's initial property values, so the projection records the property stale and the
    # CreateNodeVariant cascade translates the default just like an explicitly-set value.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                       |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text"} |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    When the command CreateNodeVariant is executed with payload:
      | Key             | Value                    |
      | nodeAggregateId | "sir-david-nodenborough" |
      | sourceOrigin    | {"language":"en"}        |
      | targetOrigin    | {"language":"de"}        |
    When I am in dimension space point {"language": "de"}
    Then I expect node aggregate identifier "sir-david-nodenborough" to lead to node user-cs-id;sir-david-nodenborough;{"language":"de"}
    And I expect this node to have the following properties:
      | Key                            | Value                        |
      | inlineEditableStringProperty   | "My Text translated"         |
      | autoTranslatableStringProperty | "autoTranslateMe translated" |

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

  Scenario: Changing a node's type to one excluded from automatic translation clears the stale translations
        # Retyping into a type with `automaticTranslation: false` leaves no translatable properties, so the
        # aggregate's stale records must be deleted rather than linger for a type that is never translated.
        # StalePropertyCommandBuilder relies on exactly this invariant ("stale records only exist for
        # translation-enabled node types") and therefore drops its own `directive->enabled` guard.
        #
        # `plain-node` covers the empty-record variant: a translation-enabled node whose translatable properties
        # carry no value is recorded with an empty property list, so retyping it produces no CHANGE to the stored
        # list. The handler's "nothing changed" short-circuit must not let that empty record survive the retype.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                                                        |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} |
      | plain-node             | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations        | {"stringProperty": "Plain"}                                                                                                  |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | plain-node             | []                                                                |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    When the command ChangeNodeAggregateType is executed with payload:
      | Key             | Value                                                                          |
      | nodeAggregateId | "sir-david-nodenborough"                                                       |
      | newNodeTypeName | "Sitegeist.LostInTranslation.Testing:DocumentExcludedFromAutomaticTranslation" |
      | strategy        | "happypath"                                                                    |
        # The non-empty record is gone; `plain-node` is untouched by its sibling's retype.
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames |
      | user-workspace | {"language":"de"}         | plain-node      | []            |

    When the command ChangeNodeAggregateType is executed with payload:
      | Key             | Value                                                                          |
      | nodeAggregateId | "plain-node"                                                                   |
      | newNodeTypeName | "Sitegeist.LostInTranslation.Testing:DocumentExcludedFromAutomaticTranslation" |
      | strategy        | "happypath"                                                                    |
        # The already-empty record must go too: an empty record is only legitimate for a translation-enabled type.
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

  Scenario: Removing a node aggregate drops its own stale records but leaves descendants alone
        # Hierarchy is deliberately ignored (see top-level comment) — removing a parent does not
        # cascade to descendant stale rows. The CR emits NodeAggregateWasRemoved only for the
        # directly removed aggregate; tethered children cannot themselves be removed, and
        # non-tethered descendants would need their own RemoveNodeAggregate command.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                              | tetheredDescendantNodeAggregateIds |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"}      | {"tethered": "nodewyn-tetherton"}  |
      | nody-mc-nodeface       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Sibling Text"} | {"tethered": "nodenberg"}          |
    And I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
      | user-workspace | {"language":"de"}         | nodenberg              | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

    When the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allVariants"            |
        # sir-david's stale row in `de` is gone; its tethered child `nodewyn-tetherton` is not
        # touched (no cascade), and the sibling `nody-mc-nodeface` is untouched.
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId   | propertyNames                      |
      | user-workspace | {"language":"de"}         | nodenberg         | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | nodewyn-tetherton | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | nody-mc-nodeface  | ["autoTranslatableStringProperty"] |

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
        # A Document.Page carries the auto-translatable `title` inherited from Neos.Neos:Document, so creating one
        # records a stale translation for `title` in the target dimension — Documents are tracked for retranslation
        # just like Content nodes. Its tethered `main` ContentCollection has no translatable properties and is
        # recorded with an empty list.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                              | initialPropertyValues | tetheredDescendantNodeAggregateIds |
      | homepage        | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page | {"title": "Home"}     | {"main": "homepage-main"}          |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames |
      | user-workspace | {"language":"de"}         | homepage        | ["title"]     |
      | user-workspace | {"language":"de"}         | homepage-main   | []            |

  Scenario: Changing a non-translatable property must not remove an existing (empty) stale record
        # Regression: a node created with no stale translatable values is recorded with an empty property list. Editing a
        # NON-translatable property on the source used to wrongly DELETE that record in the target dimension — the
        # source-side merge produced an empty intersection and fell into a delete branch. A source-side property set can
        # only ADD newly-stale properties to a target record; stale rows are removed only by retranslation/sync and by
        # node/variant/workspace lifecycle events, never by an unrelated source edit.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                    | initialPropertyValues       |
      | plain-node      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations   | {"stringProperty": "Plain"} |
        # Only the non-translatable `stringProperty` carries a value, so the stale record is empty.
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames |
      | user-workspace | {"language":"de"}         | plain-node      | []            |

    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                         |
      | nodeAggregateId           | "plain-node"                  |
      | originDimensionSpacePoint | {"language": "en"}            |
      | propertyValues            | {"stringProperty": "Changed"} |
        # The empty stale record must survive the non-translatable change (not be deleted).
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames |
      | user-workspace | {"language":"de"}         | plain-node      | []            |

  Scenario: Retranslating a document removes all of its stale translations including its content collection
        # A document's tethered ContentCollection carries no translatable properties and is recorded with an empty stale
        # list. Retranslating the document must clear ALL stale records below it — the document's own properties, the
        # content inside it, AND the empty content-collection record — leaving nothing behind (no orphaned stale rows).
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                  | tetheredDescendantNodeAggregateIds |
      | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                      | {"main": "page-home-main"}         |
      | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |                                    |
    And I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | page-home       | ["title"]                                                         |
      | user-workspace | {"language":"de"}         | page-home-main  | []                                                                |

    When I retranslate node "page-home" in workspace "user-workspace" and dimension space point {"language":"de"}

        # Every stale record below the document is gone — including the empty content-collection record.
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

  Scenario: Reconcile prunes descendant orphans left behind by a parent removal
        # When a Document with a tethered ContentCollection and nested content is removed, the CR cascades
        # the removal in its hierarchy but only emits NodeAggregateWasRemoved for the document itself. The
        # projection deletes the document's stale row but leaves the descendants' rows behind. Reconcile
        # walks the ContentGraph and prunes those orphans.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                  | tetheredDescendantNodeAggregateIds |
      | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                      | {"main": "page-home-main"}         |
      | page-home-2     | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home 2"}                                                                    | {"main": "page-home-main-2"}       |
      | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |                                    |
    And I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId  | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | intro-text       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | page-home        | ["title"]                                                         |
      | user-workspace | {"language":"de"}         | page-home-2      | ["title"]                                                         |
      | user-workspace | {"language":"de"}         | page-home-main   | []                                                                |
      | user-workspace | {"language":"de"}         | page-home-main-2 | []                                                                |

    When the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value             |
      | workspaceName                | "user-workspace"  |
      | nodeAggregateId              | "page-home"       |
      | coveredDimensionSpacePoint   | {"language":"en"} |
      | nodeVariantSelectionStrategy | "allVariants"     |
        # page-home is gone but its tethered `main` and the nested `intro-text` linger as orphans.
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId  | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | intro-text       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | page-home-2      | ["title"]                                                         |
      | user-workspace | {"language":"de"}         | page-home-main   | []                                                                |
      | user-workspace | {"language":"de"}         | page-home-main-2 | []                                                                |

    When I reconcile stale translations in workspace "user-workspace"
        # Orphans are gone after reconcile.
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId  | propertyNames |
      | user-workspace | {"language":"de"}         | page-home-2      | ["title"]     |
      | user-workspace | {"language":"de"}         | page-home-main-2 | []            |

  Scenario: Clearing Text should produce a StaleTranslation record and translating it should result in a cleared Text in target dimension
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                                                        |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Publish workspace — both workspaces end up with the stale row (live receives the create events,
    # user-workspace is reset to match live by `replaceWorkspaceEntries`).
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "new-user-cs-id" |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Retranslate in live — clears the live stale row and creates the `de` variant with translated values.
    When I retranslate node "sir-david-nodenborough" in workspace "live" and dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Rebase user-workspace on live — picks up live's translated state, clearing user-workspace's stale row.
    When the command RebaseWorkspace is executed with payload:
      | Key                    | Value                |
      | workspaceName          | "user-workspace"     |
      | rebasedContentStreamId | "rebased-user-cs-id" |
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

    # Clear the source property to "" in user-workspace — projection records a stale row at the target.
    When I am in workspace "user-workspace"
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                   |
      | nodeAggregateId           | "sir-david-nodenborough"                |
      | originDimensionSpacePoint | {"language": "en"}                      |
      | propertyValues            | {"autoTranslatableStringProperty": ""}  |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

    # Retranslate — the cleared source must propagate to the target as "" (not skipped, not " translated").
    When I retranslate node "sir-david-nodenborough" in workspace "user-workspace" and dimension space point {"language":"de"}

    # Retranslate emits a SetNodeProperties at the target dimension with the literal empty value.
    # The rebased stream contains: 1 ContentStreamWasForked + 1 NodePropertiesWereSet (the "en" clear)
    # + 1 NodePropertiesWereSet (the "de" cleared propagation from retranslate).
    Then I expect exactly 3 events to be published on stream "ContentStream:rebased-user-cs-id"
    And event at index 2 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                 |
      | workspaceName                                       | "user-workspace"         |
      | contentStreamId                                     | "rebased-user-cs-id"     |
      | nodeAggregateId                                     | "sir-david-nodenborough" |
      | originDimensionSpacePoint                           | {"language": "de"}       |
      | propertyValues.autoTranslatableStringProperty.value | ""                       |

    # Stale row cleared.
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

  Scenario: Retranslating a no-op stale node (nothing translatable to set) still clears its stale row
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                   |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Create the de variant so the target exists — the cascade translates and clears the stale row.
    When the command CreateNodeVariant is executed with payload:
      | Key             | Value                    |
      | nodeAggregateId | "sir-david-nodenborough" |
      | sourceOrigin    | {"language":"en"}        |
      | targetOrigin    | {"language":"de"}        |
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

    # Unset a translatable source property on en — the projection records a fresh stale row at de for it.
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                    |
      | nodeAggregateId           | "sir-david-nodenborough"                 |
      | originDimensionSpacePoint | {"language": "en"}                       |
      | propertyValues            | {"autoTranslatableStringProperty": null} |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

    # Retranslate de — the source property is unset, so there is nothing to translate and no
    # SetNodeProperties (hence no NodePropertiesWereSet) is emitted. The stale row must still be cleared
    # rather than lingering forever and reporting the node as perpetually out of sync.
    When I retranslate node "sir-david-nodenborough" in workspace "user-workspace" and dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

  Scenario: A partially cleared stale row stays a JSON list, not a JSON object
    # `array_diff` and `array_intersect` PRESERVE keys, so clearing the FIRST of two stale properties leaves
    # `[1 => "autoTranslatableStringProperty"]` — and `json_encode` writes a PHP array with a gap in its keys as the
    # object `{"1":"..."}`, not the list `["..."]`. Every writer of the column re-indexes to prevent that; this pins it.
    #
    # It has to read the raw column to do so. `PropertyNames::fromArray` re-indexes on the way back in, so the
    # read-model assertions used everywhere else in this file launder the corruption and pass either way — which is
    # also why nothing caught it before.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Translate once so the de variant exists and the row is cleared, then make BOTH properties stale again.
    When the command CreateNodeVariant is executed with payload:
      | Key             | Value                    |
      | nodeAggregateId | "sir-david-nodenborough" |
      | sourceOrigin    | {"language":"en"}        |
      | targetOrigin    | {"language":"de"}        |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                                                                            |
      | nodeAggregateId           | "sir-david-nodenborough"                                                                                         |
      | originDimensionSpacePoint | {"language": "en"}                                                                                               |
      | propertyValues            | {"inlineEditableStringProperty": "My adjusted Text", "autoTranslatableStringProperty": "My adjusted Other Text"} |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # An editor fixes ONE of the two translations by hand, directly in de: that property is no longer stale, the other
    # still is. Clearing the FIRST entry is what leaves the gap — clearing the second would re-index harmlessly and
    # prove nothing.
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                  |
      | nodeAggregateId           | "sir-david-nodenborough"                               |
      | originDimensionSpacePoint | {"language": "de"}                                     |
      | propertyValues            | {"inlineEditableStringProperty": "Von Hand uebersetzt"} |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |
    # The assertion the one above cannot make.
    And I expect the stale translation row for node "sir-david-nodenborough" in workspace "user-workspace" and dimension space point {"language":"de"} to store propertyNames as the JSON list ["autoTranslatableStringProperty"]
