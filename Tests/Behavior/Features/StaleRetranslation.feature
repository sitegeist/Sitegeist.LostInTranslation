@contentrepository
Feature: Track the staleness state of translations and run retranslation on stale translations

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
          defaultValue: "autoTranslateMe"
          options:
            automaticTranslation: true
      options:
        automaticTranslation: true
    # Minimal stand-in for the production Neos.Neos:Document mixin. We declare it inline here so the
    # Document-filter scenario can extend it; the Retranslator's filter uses the same name. We do
    # NOT pull in Neos.Neos:Document's real configuration (constraints, properties) because we only
    # need the type identity for the filter to match.
    'Neos.Neos:Document':
      abstract: true
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
        # The Retranslator emits these in depth-first pre-order of the source subtree, so the parent
        # ("sir-david-nodenborough") fires before its child ("nodewyn-tetherton").
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
        # Retranslating a Document must NOT bleed into child Documents (separate pages, separate
        # translation scope). The Retranslator's source-subtree walk is scoped via
        # `NodeTypeCriteria::createWithDisallowedNodeTypeNames(['Neos.Neos:Document'])`, so
        # descendants of the entry Document that are themselves Documents (or subtypes) get filtered
        # out before any stale lookup / variant emission considers them.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                          | initialPropertyValues                              |
            | parent-doc      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation  | {"autoTranslatableStringProperty": "Parent Text"}  |
            | child-doc       | parent-doc             | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation  | {"autoTranslatableStringProperty": "Child Text"}   |
        # Both Documents are translatable, so each gets a stale entry at the target language.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                        |
            | user-workspace | {"language":"de"}         | child-doc       | ["autoTranslatableStringProperty"]   |
            | user-workspace | {"language":"de"}         | parent-doc      | ["autoTranslatableStringProperty"]   |

        When I retranslate node "parent-doc" in workspace "user-workspace" and dimension space point {"language":"de"}

        # parent-doc was the entry node, so the filter still includes it: a `de` variant gets created
        # via CreateNodeVariant → TranslationCommandHook cascades a NodePropertiesWereSet → projection
        # clears parent-doc's stale entry. child-doc is a Document descendant and is excluded from
        # the walk; its stale entry must remain untouched.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                        |
            | user-workspace | {"language":"de"}         | child-doc       | ["autoTranslatableStringProperty"]   |

    Scenario: Retranslating into the source language is a no-op
        # Only `de` has `referenceLanguage: en` in the Background's dimension configuration. Asking
        # the Retranslator to retranslate INTO `en` therefore has no defined source dimension and
        # must not touch the stale-translation projection or emit any new events. This is the skip
        # path a bulk-retranslate-all-languages loop would hit when it reaches the source itself.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                                                        | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} | {"tethered": "nodewyn-tetherton"}  |
        # Setup stale state we expect to remain untouched after the no-op retranslate.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Snapshot event count BEFORE the no-op so we can assert it doesn't change.
        # 1x ContentStreamWasForked (CreateWorkspace user-workspace) + 2x NodeAggregateWithNodeWasCreated
        # (sir-david + tethered nodewyn-tetherton) = 3 events on the user-workspace stream.
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
        # @todo missing steps: removal, node type change, partial publish, discard, partial discard, workspace removed, dimension space point moved
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

        # Rebase other workspace
        When the command RebaseWorkspace is executed with payload:
            | Key           | Value                  |
            | workspaceName | "other-user-workspace" |
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
        And I expect exactly 11 events to be published on stream "ContentStream:user-cs-id"
        And event at index 7 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                            |
            | contentStreamId                                     | "user-cs-id"                        |
            | nodeAggregateId                                     | "sir-david-nodenborough"            |
            | originDimensionSpacePoint                           | {"language": "de"}                  |
            | propertyValues.inlineEditableStringProperty.value   | "My adjusted child Text translated" |
            | propertyValues.autoTranslatableStringProperty.value | "My adjusted child Text translated" |
        And event at index 8 is of type "NodeAggregateWithNodeWasCreated" with payload:
            | Key                                                 | Expected                            |
            | contentStreamId                                     | "user-cs-id"                        |
            | nodeAggregateId                                     | "nody-mc-nodeface"                  |
            | originDimensionSpacePoint                           | {"language": "de"}                  |
            | propertyValues.inlineEditableStringProperty.value   | "My adjusted child Text translated" |
            | propertyValues.autoTranslatableStringProperty.value | "My adjusted child Text translated" |

        # Publish other workspace
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "other-user-workspace" |
            | newContentStreamId | "new-other-user-cs-id" |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Rebase workspace
        When the command RebaseWorkspace is executed with payload:
            | Key           | Value            |
            | workspaceName | "user-workspace" |
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |
