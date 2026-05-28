@contentrepository
Feature: Automatic retranslation on workspace publish
    # This test needs the `synchronization` config in Configuration/Testing/Settings.yaml

    Background:
        Given using the following content dimensions yaml configuration:
        """yaml
        language:
          values:
            en: {}
            de:
              referenceLanguage: en
            # auto-sync from en_live to es_live
            es:
              referenceLanguage: en
        """
        And using the following node types:
        """yaml
        'Neos.ContentRepository:Root': []
        # Because we build our own test CR from scratch we also need to define this NodeType because we do not read any
        # NodeType definitions
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

    Scenario: Publishing a freshly-created en node to live auto-translates it to es in live
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                                                        | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} | {"tethered": "nodewyn-tetherton"}  |

        # Stale rows recorded for both de AND es: en has two configured target languages, so the projection fans out one
        # row per (target dimension, node). The tethered child gets its own rows for its translatable property too.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Publish
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id2" |

        # After publish, the auto-sync hook fires on `live` because the rule `(live, en) → (live, es)` matches the
        # publish target. It iterates stale records in (live, es) and emits CreateNodeVariant per node; the existing
        # TranslationCommandHook cascades SetNodeProperties with translated values; the projection then drops the stale
        # rows.
        #
        # `de` stale rows survive (no rule covers them). `user-workspace/*` rows also survive — the rule only scrubs
        # live, and replaceWorkspaceEntries already copied them back before the hook ran.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Publishing a property update to en,live auto-syncs the existing es variant
        # Exercises the "target variant already exists" branch of the auto-sync hook: instead of emitting
        # CreateNodeVariant the hook must build a translated SetNodeProperties from StalePropertyCommandBuilder. Uses a
        # NodeWithAutomaticTranslation with a tethered child so the first publish has to build a real variant + cascade
        # before the update is exercised.
        #
        # (Scenario title used to say "DE" but the test rule covers en → es, so we test the es variant. Adding a de rule
        # would require two rules and complicate the unrelated bits.)
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |

        # Initial stale state after setting up the content.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # First publish: the es variants (parent + tethered child) are freshly created and translated.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id2" |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Now mutate the en source and re-publish. The auto-sync hook detects the fresh `(live, sir-david, es)` stale
        # row, sees that the es variant already exists, and emits a translated SetNodeProperties (no
        # NodePeerVariantWasCreated this time).
        When I am in workspace "user-workspace"
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                                                                    |
            | nodeAggregateId           | "sir-david-nodenborough"                                                                                 |
            | originDimensionSpacePoint | {"language": "en"}                                                                                       |
            | propertyValues            | {"inlineEditableStringProperty": "Updated Text", "autoTranslatableStringProperty": "Updated Other Text"} |
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id3" |

        # live/es stale cleared again by auto-sync; live/de remains (no rule covers it). The tethered child's es row was
        # already cleared in the first sync and is not re-staled, because the en update only touched the parent.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # cs-identifier event timeline:
        #   0 ContentStreamWasCreated (Background)
        #   1 RootNodeAggregateWithNodeWasCreated (Background)
        #   2-3 NodeAggregateWithNodeWasCreated (sir-david + nodewyn-tetherton, replicated by first publish)
        #   4-5 NodePeerVariantWasCreated (sir-david + nodewyn-tetherton en→es, auto-sync first publish)
        #   6-7 NodePropertiesWereSet     (sir-david es + nodewyn-tetherton es translated, cascade)
        #   8   NodePropertiesWereSet     (sir-david en updated, replicated by second publish)
        #   9   NodePropertiesWereSet     (sir-david es re-translated, auto-sync builds it directly — no
        #                                  NodePeerVariantWasCreated)
        And I expect exactly 10 events to be published on stream "ContentStream:cs-identifier"
        And event at index 9 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                        |
            | workspaceName                                       | "live"                          |
            | contentStreamId                                     | "cs-identifier"                 |
            | nodeAggregateId                                     | "sir-david-nodenborough"        |
            | originDimensionSpacePoint                           | {"language": "es"}              |
            | propertyValues.inlineEditableStringProperty.value   | "Updated Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "Updated Other Text translated" |

    Scenario: Publishing from a workspace not covered by any rule does not trigger sync
        # The configured rule targets `sourceWorkspaceName: live`. Publishing onto a *different* base workspace must
        # therefore leave stale rows untouched: no auto-sync hook activity at all on that workspace's content stream.
        # Uses a NodeWithAutomaticTranslation + tethered child to prove the whole subtree is left alone.
        #
        # Reuse the Background's user-workspace as the publish source; rebase it onto a fresh `preview` base so the
        # publish lands on preview rather than live.
        When the command CreateWorkspace is executed with payload:
            | Key                | Value           |
            | workspaceName      | "preview"       |
            | baseWorkspaceName  | "live"          |
            | newContentStreamId | "preview-cs-id" |
        And the command ChangeBaseWorkspace is executed with payload:
            | Key               | Value            |
            | workspaceName     | "user-workspace" |
            | baseWorkspaceName | "preview"        |
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |

        # Initial stale state after setting up the content (only user-workspace, since the creates happened there;
        # ChangeBaseWorkspace earlier wiped user-workspace and then the creates re-populated it).
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Publish onto `preview` — NOT live — so no rule matches and the auto-sync hook short-circuits.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value            |
            | workspaceName      | "user-workspace" |
            | newContentStreamId | "user-cs-id-new" |

        # Both de and es stale rows survive on preview AND user-workspace (replaceWorkspaceEntries copies
        # preview→user-workspace after publish).
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | preview        | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | preview        | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | preview        | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | preview        | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # preview-cs-id timeline (no auto-sync cascade):
        #   0 ContentStreamWasForked (CreateWorkspace preview)
        #   1-2 NodeAggregateWithNodeWasCreated (sir-david + nodewyn-tetherton, replicated by publish)
        # No NodePeerVariantWasCreated, no translated NodePropertiesWereSet — proving the hook did not fire for the
        # uncovered workspace.
        And I expect exactly 3 events to be published on stream "ContentStream:preview-cs-id"

    Scenario: Manual workspace synchronization only affects the target workspace and dimension
        # The `synchronize` CLI (WorkspaceSynchronizer) walks the stale records for ONE (workspace, dimension) pair.
        # Here two elaborate subtrees live in user-workspace and one document lives in other-user-workspace;
        # synchronizing user-workspace en→de must clear only the user-workspace `de` rows and leave user-workspace `es`
        # + all of other-user-workspace untouched.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |
            | nody-mc-nodeface       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Face Text", "autoTranslatableStringProperty": "Face Other"}  | {"tethered": "nodenberg"}          |
        When I am in workspace "other-user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                             |
            | parent-doc      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Parent Text"} |

        And I expect exactly the following stale translations:
            | workspaceName        | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | other-user-workspace | {"language":"de"}         | parent-doc             | ["autoTranslatableStringProperty"]                                |
            | other-user-workspace | {"language":"es"}         | parent-doc             | ["autoTranslatableStringProperty"]                                |
            | user-workspace       | {"language":"de"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | user-workspace       | {"language":"es"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | user-workspace       | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace       | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace       | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace       | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace       | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace       | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"de"}

        # Only user-workspace/de rows cleared. user-workspace/es and all other-user-workspace rows remain.
        Then I expect exactly the following stale translations:
            | workspaceName        | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | other-user-workspace | {"language":"de"}         | parent-doc             | ["autoTranslatableStringProperty"]                                |
            | other-user-workspace | {"language":"es"}         | parent-doc             | ["autoTranslatableStringProperty"]                                |
            | user-workspace       | {"language":"es"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | user-workspace       | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace       | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace       | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Full sync into an empty target dimension creates variants across the whole subtree
        # The `synchronize --full` mode (FullWorkspaceSynchronizer) walks every translatable node from the root down,
        # independent of stale state. Here a deep subtree (document + content with tethered child + grandchild) lives
        # only in live/en; full sync en→de must create and translate the de variant of every node in that subtree.
        When I am in workspace "live"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation     | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"}                 | {"tethered": "nodewyn-tetherton"}  |
            | nody-mc-nodeface       | nodewyn-tetherton      | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Grandchild Text", "autoTranslatableStringProperty": "Grandchild Other Text"} |                                    |
            | parent-doc             | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Parent Doc Text"}                                                          |                                    |

        # Every node is stale in de AND es on create.
        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live          | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"de"}         | parent-doc             | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | parent-doc             | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}

        # All de rows cleared; es rows survive (full sync targeted de only).
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live          | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"es"}         | parent-doc             | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # cs-identifier event timeline (full sync walks the source subtree top-down):
        #   0     ContentStreamWasCreated (Background)
        #   1     RootNodeAggregateWithNodeWasCreated (Background)
        #   2-5   NodeAggregateWithNodeWasCreated (sir-david + tethered nodewyn-tetherton, nody-mc-nodeface, parent-doc)
        #   6-7   NodePeerVariantWasCreated (sir-david + nodewyn-tetherton en→de)
        #   8-9   NodePropertiesWereSet     (sir-david de + nodewyn-tetherton de translated, CreateNodeVariant cascade)
        #   10    NodePropertiesWereSet     (nodewyn-tetherton de re-translated — the walk's pre-fetched stale set
        #                                    still lists it, so it is refreshed once more after the cascade)
        #   11    NodePeerVariantWasCreated (nody-mc-nodeface en→de — the deep grandchild reached by the walk)
        #   12    NodePropertiesWereSet     (nody-mc-nodeface de translated)
        #   13    NodePeerVariantWasCreated (parent-doc en→de — the sibling document)
        #   14    NodePropertiesWereSet     (parent-doc de translated)
        Then I expect exactly 15 events to be published on stream "ContentStream:cs-identifier"
        And event at index 8 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                   |
            | nodeAggregateId                                     | "sir-david-nodenborough"   |
            | originDimensionSpacePoint                           | {"language": "de"}         |
            | propertyValues.inlineEditableStringProperty.value   | "My Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "My Other Text translated" |
        And event at index 12 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                           |
            | nodeAggregateId                                     | "nody-mc-nodeface"                 |
            | originDimensionSpacePoint                           | {"language": "de"}                 |
            | propertyValues.inlineEditableStringProperty.value   | "Grandchild Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "Grandchild Other Text translated" |
        And event at index 14 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                     |
            | nodeAggregateId                                     | "parent-doc"                 |
            | originDimensionSpacePoint                           | {"language": "de"}           |
            | propertyValues.autoTranslatableStringProperty.value | "Parent Doc Text translated" |

    Scenario: Full sync with default skip-existing leaves already-translated subtrees untouched
        # One subtree is pre-translated (variant exists, de stale already cleared); another is fresh. Default
        # skip-existing=true skips the already-translated one and only builds the missing variant.
        When I am in workspace "live"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |
        # Pre-translate the sir-david subtree by issuing CreateNodeVariant — TranslationCommandHook cascades the
        # translation and the projection clears its de stale rows.
        When the command CreateNodeVariant is executed with payload:
            | Key             | Value                    |
            | nodeAggregateId | "sir-david-nodenborough" |
            | sourceOrigin    | {"language":"en"}        |
            | targetOrigin    | {"language":"de"}        |
        # Add a second, untranslated subtree.
        When the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId  | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                         | tetheredDescendantNodeAggregateIds |
            | nody-mc-nodeface | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Face Text", "autoTranslatableStringProperty": "Face Other"} | {"tethered": "nodenberg"}          |

        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live          | {"language":"de"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}

        # nody-mc-nodeface subtree's de rows cleared; sir-david subtree untouched (was already done); all es rows
        # remain.
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live          | {"language":"es"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # cs-identifier event timeline:
        #   0     ContentStreamWasCreated (Background)
        #   1     RootNodeAggregateWithNodeWasCreated (Background)
        #   2-3   NodeAggregateWithNodeWasCreated (sir-david + tethered nodewyn-tetherton)
        #   4-7   NodePeerVariantWasCreated + NodePropertiesWereSet (sir-david subtree pre-translated to de)
        #   8-9   NodeAggregateWithNodeWasCreated (nody-mc-nodeface + tethered nodenberg)
        #   10-11 NodePeerVariantWasCreated (nody-mc-nodeface + nodenberg en→de — only the untranslated subtree is
        #                                    built)
        #   12-13 NodePropertiesWereSet     (nody-mc-nodeface de + nodenberg de translated)
        #   14    NodePropertiesWereSet     (nodenberg de re-translated — the walk's pre-fetched stale set still
        #                                    lists it, so it is refreshed once more after the cascade)
        # The already-translated sir-david subtree is skipped: no further events for it.
        Then I expect exactly 15 events to be published on stream "ContentStream:cs-identifier"
        And event at index 12 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                |
            | nodeAggregateId                                     | "nody-mc-nodeface"      |
            | originDimensionSpacePoint                           | {"language": "de"}      |
            | propertyValues.inlineEditableStringProperty.value   | "Face Text translated"  |
            | propertyValues.autoTranslatableStringProperty.value | "Face Other translated" |

    Scenario: Full sync with skipExisting=false re-translates existing variants too
        # A manually-overridden de variant gets clobbered by re-translation of the current en source.
        When I am in workspace "live"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |

        # Initial stale state after setting up the content directly on live.
        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live          | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command CreateNodeVariant is executed with payload:
            | Key             | Value                    |
            | nodeAggregateId | "sir-david-nodenborough" |
            | sourceOrigin    | {"language":"en"}        |
            | targetOrigin    | {"language":"de"}        |
        # Editor overrides the auto-translation by hand.
        When the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                                                               |
            | nodeAggregateId           | "sir-david-nodenborough"                                                                            |
            | originDimensionSpacePoint | {"language": "de"}                                                                                  |
            | propertyValues            | {"inlineEditableStringProperty": "Hand Crafted DE", "autoTranslatableStringProperty": "Hand Other"} |

        When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"} including existing variants

        # cs-identifier event timeline:
        #   0   ContentStreamWasCreated (Background)
        #   1   RootNodeAggregateWithNodeWasCreated (Background)
        #   2-3 NodeAggregateWithNodeWasCreated (sir-david + tethered nodewyn-tetherton)
        #   4-5 NodePeerVariantWasCreated (sir-david + nodewyn-tetherton en→de, CreateNodeVariant)
        #   6-7 NodePropertiesWereSet     (sir-david de + nodewyn-tetherton de translated, cascade)
        #   8   NodePropertiesWereSet     (sir-david de hand-override "Hand Crafted DE")
        #   9   NodePropertiesWereSet     (sir-david de re-translated from current source — full sync,
        #                                  skipExisting=false)
        #   10  NodePropertiesWereSet     (nodewyn-tetherton de re-translated)
        # The hand-crafted text at index 8 was clobbered by the re-translation at index 9.
        Then I expect exactly 11 events to be published on stream "ContentStream:cs-identifier"
        And event at index 9 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                   |
            | workspaceName                                       | "live"                     |
            | contentStreamId                                     | "cs-identifier"            |
            | nodeAggregateId                                     | "sir-david-nodenborough"   |
            | originDimensionSpacePoint                           | {"language": "de"}         |
            | propertyValues.inlineEditableStringProperty.value   | "My Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "My Other Text translated" |

        # Final stale state: the de variants were created (publish #1's CreateNodeVariant) and re-translated (full
        # sync), so all live/de rows are cleared. es was never touched (full sync targeted de only), so live/es rows
        # for both nodes survive.
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live          | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Stale-mode auto-sync re-translates a tethered child whose source changed
        # Regression guard for the stale-driven path (the default `live` rule): a property change confined to a tethered
        # child is picked up from its stale record and re-translated on publish.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |

        # Initial stale state after setting up the content.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Publish #1 → live/es rule creates + translates the es variants of sir-david AND its tethered child.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id2" |

        # Change ONLY the tethered child's en source, then publish again.
        When I am in workspace "user-workspace"
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                  |
            | nodeAggregateId           | "nodewyn-tetherton"                                    |
            | originDimensionSpacePoint | {"language": "en"}                                     |
            | propertyValues            | {"autoTranslatableStringProperty": "Tethered Changed"} |
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id3" |

        # cs-identifier event timeline:
        #   0   ContentStreamWasCreated (Background)
        #   1   RootNodeAggregateWithNodeWasCreated (Background)
        #   2-3 NodeAggregateWithNodeWasCreated (sir-david + nodewyn-tetherton, replicated by publish #1)
        #   4-5 NodePeerVariantWasCreated (sir-david + nodewyn-tetherton en→es, auto-sync publish #1)
        #   6-7 NodePropertiesWereSet     (sir-david es + nodewyn-tetherton es translated, cascade)
        #   8   NodePropertiesWereSet     (nodewyn-tetherton en "Tethered Changed", replicated by publish #2)
        #   9   NodePropertiesWereSet     (nodewyn-tetherton es re-translated from its changed source, auto-sync
        #                                  publish #2)
        Then I expect exactly 10 events to be published on stream "ContentStream:cs-identifier"
        And event at index 9 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                      |
            | workspaceName                                       | "live"                        |
            | contentStreamId                                     | "cs-identifier"               |
            | nodeAggregateId                                     | "nodewyn-tetherton"           |
            | originDimensionSpacePoint                           | {"language": "es"}            |
            | propertyValues.autoTranslatableStringProperty.value | "Tethered Changed translated" |

        # Final stale state. live/es is fully cleared again: nodewyn-tetherton/es was the only re-staled row and the
        # auto-sync scrubbed it. live/de survives (no rule covers de). user-workspace was overwritten by live's rows
        # during publish #2 (replaceWorkspaceEntries runs BEFORE the auto-sync hook clears es), so it now mirrors live's
        # rows AT THAT MOMENT — which still included the just-replicated nodewyn-tetherton/es. sir-david/es is absent
        # from user-workspace because publish #1's auto-sync already cleared it on live, and the post-publish-#2 copy
        # reflected that cleared state.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Document-scope auto-sync mirrors a published document and its content into the target dimension
        # The `live` rule is Document scope: publishing a freshly-created Document.Page that holds content in its
        # `main` collection must create AND translate the es variant of BOTH the document and its content. The
        # content's aggregate id ("intro-text") deliberately sorts BEFORE the document's ("page-home"), proving the
        # hook orders the document's variant creation ahead of the content's — the content's parent (the document's
        # tethered `main`) only exists once the document variant has been created.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                  | tetheredDescendantNodeAggregateIds |
            | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                      | {"main": "page-home-main"}         |
            | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |                                    |

        # The document's `title` and the content's properties are recorded stale in both target dimensions. The tethered
        # `main` ContentCollection has no translatable properties, so it is recorded with an empty property list (it is
        # created structurally alongside the document).
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | page-home       | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home       | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-main  | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main  | []                                                                |

        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id2" |

        # Synchronizing cleared every es stale record in `live` — the document's `title`, the content's properties
        # AND the (empty) content-collection record. live keeps only its unsynced de rows (no rule covers de). The
        # user-workspace es rows survive: the rule only scrubs live, and replaceWorkspaceEntries already copied them
        # back before the hook ran (same as the other publish-on-live scenarios).
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                                                     |
            | live           | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live           | {"language":"de"}         | page-home       | ["title"]                                                         |
            | live           | {"language":"de"}         | page-home-main  | []                                                                |
            | user-workspace | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | page-home       | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home       | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-main  | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main  | []                                                                |

        # cs-identifier event timeline (Document scope mirrors document + content into es):
        #   0     ContentStreamWasCreated (Background)
        #   1     RootNodeAggregateWithNodeWasCreated (Background)
        #   2-4   NodeAggregateWithNodeWasCreated (page-home + tethered page-home-main + intro-text, replicated by
        #                                          publish)
        #   5     NodePeerVariantWasCreated (page-home en→es — document created first, ancestor-before-descendant)
        #   6     NodePeerVariantWasCreated (page-home-main en→es, the tethered collection materialises with it)
        #   7     NodePropertiesWereSet     (page-home es title translated; the collection has no translatable
        #                                    properties)
        #   8     NodePeerVariantWasCreated (intro-text en→es — the nested content, created after its parent
        #                                    collection exists)
        #   9     NodePropertiesWereSet     (intro-text es translated)
        Then I expect exactly 10 events to be published on stream "ContentStream:cs-identifier"
        # Document scope created + translated the es variant of the document …
        And event at index 7 is of type "NodePropertiesWereSet" with payload:
            | Key                        | Expected           |
            | nodeAggregateId            | "page-home"        |
            | originDimensionSpacePoint  | {"language": "es"} |
            | propertyValues.title.value | "Home translated"  |
        # … and of the content nested inside its `main` collection.
        And event at index 9 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected             |
            | nodeAggregateId                                     | "intro-text"         |
            | originDimensionSpacePoint                           | {"language": "es"}   |
            | propertyValues.inlineEditableStringProperty.value   | "Welcome translated" |
            | propertyValues.autoTranslatableStringProperty.value | "Intro translated"   |

    Scenario: Content-scope auto-sync never creates documents and skips content whose document is missing in the target
        # The `content-review` rule is Content scope: it must NOT create Document variants automatically, and only fills
        # in content whose containing Document already exists in the target dimension. Here neither the document nor its
        # content exists in es yet, so publishing creates nothing in es — adopting the document into es stays a manual
        # editor action.
        When the command CreateWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "content-review"       |
            | baseWorkspaceName  | "live"                 |
            | newContentStreamId | "content-review-cs-id" |
        And the command CreateWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "content-user"       |
            | baseWorkspaceName  | "content-review"     |
            | newContentStreamId | "content-user-cs-id" |
        When I am in workspace "content-user"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                  | tetheredDescendantNodeAggregateIds |
            | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                      | {"main": "page-home-main"}         |
            | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |                                    |

        # Initial stale state after setting up the content in content-user.
        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames                                                     |
            | content-user  | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-user  | {"language":"es"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-user  | {"language":"de"}         | page-home       | ["title"]                                                         |
            | content-user  | {"language":"es"}         | page-home       | ["title"]                                                         |
            | content-user  | {"language":"de"}         | page-home-main  | []                                                                |
            | content-user  | {"language":"es"}         | page-home-main  | []                                                                |

        When the command PublishWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "content-user"         |
            | newContentStreamId | "content-user-cs-id-2" |

        # content-review-cs-id event timeline — Content scope synced nothing:
        #   0   ContentStreamWasForked (CreateWorkspace content-review)
        #   1-3 NodeAggregateWithNodeWasCreated (page-home + tethered page-home-main + intro-text, replicated by
        #                                        publish)
        # No NodePeerVariantWasCreated and no NodePropertiesWereSet are appended: the document is not auto-created, and
        # the content's document is absent from es, so the content is skipped too.
        Then I expect exactly 4 events to be published on stream "ContentStream:content-review-cs-id"

        # Final stale state. content-review has the full set of replicated stale rows (de + es for every node), and
        # content-user mirrors it because replaceWorkspaceEntries ran after the publish replication. Critically, NO row
        # was cleared by the sync — Content scope refused to adopt the document and therefore skipped the content
        # underneath it too. The empty content-collection rows survive too (their variant was never created).
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                                                     |
            | content-review | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-review | {"language":"es"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-review | {"language":"de"}         | page-home       | ["title"]                                                         |
            | content-review | {"language":"es"}         | page-home       | ["title"]                                                         |
            | content-review | {"language":"de"}         | page-home-main  | []                                                                |
            | content-review | {"language":"es"}         | page-home-main  | []                                                                |
            | content-user   | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-user   | {"language":"es"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-user   | {"language":"de"}         | page-home       | ["title"]                                                         |
            | content-user   | {"language":"es"}         | page-home       | ["title"]                                                         |
            | content-user   | {"language":"de"}         | page-home-main  | []                                                                |
            | content-user   | {"language":"es"}         | page-home-main  | []                                                                |

    Scenario: Content-scope auto-sync fills in content below a document that already exists in the target
        # The `content-review` rule is Content scope. Once a Document has been manually adopted into the target
        # dimension, publishing fresh content inside it auto-creates and translates the content's es variant — while
        # the manually-translated document itself is left untouched.
        When the command CreateWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "content-review"       |
            | baseWorkspaceName  | "live"                 |
            | newContentStreamId | "content-review-cs-id" |
        And the command CreateWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "content-user"       |
            | baseWorkspaceName  | "content-review"     |
            | newContentStreamId | "content-user-cs-id" |
        When I am in workspace "content-user"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                              | initialPropertyValues | tetheredDescendantNodeAggregateIds |
            | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page | {"title": "Home"}     | {"main": "page-home-main"}         |
        # The editor manually adopts the document into the es dimension (the deliberate manual action).
        When the command CreateNodeVariant is executed with payload:
            | Key             | Value             |
            | nodeAggregateId | "page-home"       |
            | sourceOrigin    | {"language":"en"} |
            | targetOrigin    | {"language":"es"} |
        # Now add content inside the already-adopted document.
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId | nodeTypeName                                                         | initialPropertyValues                                                                  |
            | intro-text      | page-home-main        | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |

        # Pre-publish stale state. page-home/es and page-home-main/es are absent — the manual adoption cleared them
        # (cascade's NodePropertiesWereSet for page-home/es title, variant event for the empty page-home-main/es).
        # intro-text was just created, so it is fresh-stale in both target dimensions.
        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames                                                     |
            | content-user  | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-user  | {"language":"es"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-user  | {"language":"de"}         | page-home       | ["title"]                                                         |
            | content-user  | {"language":"de"}         | page-home-main  | []                                                                |

        When the command PublishWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "content-user"         |
            | newContentStreamId | "content-user-cs-id-2" |

        # content-review-cs-id event timeline:
        #   0   ContentStreamWasForked (CreateWorkspace content-review)
        #   1-2 NodeAggregateWithNodeWasCreated (page-home + tethered page-home-main, replicated by publish)
        #   3-4 NodePeerVariantWasCreated (page-home + page-home-main en→es — the manual adoption, replicated)
        #   5   NodePropertiesWereSet     (page-home es title translated — the manual adoption's cascade, replicated)
        #   6   NodeAggregateWithNodeWasCreated (intro-text, replicated by publish)
        #   7   NodePeerVariantWasCreated (intro-text en→es — Content scope fills it in because its document exists
        #                                  in es)
        #   8   NodePropertiesWereSet     (intro-text es translated)
        Then I expect exactly 9 events to be published on stream "ContentStream:content-review-cs-id"
        # The content was created + translated in es because its document exists there …
        And event at index 8 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected             |
            | nodeAggregateId                                     | "intro-text"         |
            | originDimensionSpacePoint                           | {"language": "es"}   |
            | propertyValues.inlineEditableStringProperty.value   | "Welcome translated" |
            | propertyValues.autoTranslatableStringProperty.value | "Intro translated"   |
        # … and the manually-adopted document keeps its translation: the only NodePropertiesWereSet for page-home/es is
        # the replicated manual one (index 5) — the content sync never re-touched it.
        And event at index 5 is of type "NodePropertiesWereSet" with payload:
            | Key                        | Expected           |
            | nodeAggregateId            | "page-home"        |
            | originDimensionSpacePoint  | {"language": "es"} |
            | propertyValues.title.value | "Home translated"  |

        # Final stale state. content-review/es is fully cleared: intro-text was synced, and page-home plus its content
        # collection were already adopted to es before the publish. The de rows survive (no rule covers de).
        # content-user keeps intro-text/es — replaceWorkspaceEntries copied content-review's rows back before the
        # auto-sync hook scrubbed es (the same idiom as the other publish-on-* scenarios). page-home/es and
        # page-home-main/es are absent from content-user too: the manual adoption already cleared them there before
        # the publish.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                                                     |
            | content-review | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-review | {"language":"de"}         | page-home       | ["title"]                                                         |
            | content-review | {"language":"de"}         | page-home-main  | []                                                                |
            | content-user   | {"language":"de"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-user   | {"language":"es"}         | intro-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | content-user   | {"language":"de"}         | page-home       | ["title"]                                                         |
            | content-user   | {"language":"de"}         | page-home-main  | []                                                                |

    Scenario: Partial publish auto-syncs only the published subtree
        # PublishIndividualNodesFromWorkspace publishes only the selected nodes (plus their tethered descendants). The
        # auto-sync hook fires on the publish target (live), but the stale set on live only contains the
        # partially-published nodes — so only their es variants are created and translated. The unpublished sibling
        # subtree (nody-mc-nodeface + nodenberg) stays in user-workspace alone.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |
            | nody-mc-nodeface       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Face Text", "autoTranslatableStringProperty": "Face Other"}  | {"tethered": "nodenberg"}          |

        # Initial stale state after setting up both sibling subtrees.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command PublishIndividualNodesFromWorkspace is executed with payload:
            | Key            | Value                      |
            | workspaceName  | "user-workspace"           |
            | nodesToPublish | ["sir-david-nodenborough"] |

        # cs-identifier event timeline:
        #   0   ContentStreamWasCreated (Background)
        #   1   RootNodeAggregateWithNodeWasCreated (Background)
        #   2-3 NodeAggregateWithNodeWasCreated (sir-david + tethered nodewyn-tetherton — only the partially-published
        #                                       subtree replicates; nody-mc-nodeface and its tethered nodenberg stay in
        #                                       user-workspace)
        #   4-5 NodePeerVariantWasCreated (sir-david + nodewyn-tetherton en→es, partial-publish auto-sync)
        #   6-7 NodePropertiesWereSet     (sir-david es + nodewyn-tetherton es translated, cascade)
        Then I expect exactly 8 events to be published on stream "ContentStream:cs-identifier"
        And event at index 6 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                   |
            | nodeAggregateId                                     | "sir-david-nodenborough"   |
            | originDimensionSpacePoint                           | {"language": "es"}         |
            | propertyValues.inlineEditableStringProperty.value   | "My Text translated"       |
            | propertyValues.autoTranslatableStringProperty.value | "My Other Text translated" |
        And event at index 7 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                     |
            | nodeAggregateId                                     | "nodewyn-tetherton"          |
            | originDimensionSpacePoint                           | {"language": "es"}           |
            | propertyValues.autoTranslatableStringProperty.value | "autoTranslateMe translated" |

        # Final stale state. live ends up with the published nodes' de rows only (es cleared by the auto-sync, de has no
        # rule). user-workspace keeps its full 8-row set — partial publish does not propagate the source workspace's
        # stale rows back, unlike a full publish.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Partial publish of a Document.Page and some of its content mirrors both into the target dimension
        # Partial publish of a Document.Page TOGETHER with nested content under it. Under Document scope the auto-sync
        # hook must still order ancestor-before-descendant: the document's variant creation (which materialises the
        # tethered `main`) precedes the content's variant creation, so the content's parent collection exists in es by
        # the time intro-text's CreateNodeVariant runs. The content id ("intro-text") sorts BEFORE the document id
        # ("page-home") to expose any ordering bug.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                             | tetheredDescendantNodeAggregateIds |
            | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                                 | {"main": "page-home-main"}         |
            | page-home-2     | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home 2"}                                                                               | {"main": "page-home-main-2"}       |
            | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"}            |                                    |
            | sibling-text    | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Sibling Text", "autoTranslatableStringProperty": "Article"}     |                                    |
            | intro-text-2    | page-home-main-2       | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome 2", "autoTranslatableStringProperty": "Intro 2"}        |                                    |
            | sibling-text-2  | page-home-main-2       | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Sibling Text 2", "autoTranslatableStringProperty": "Article 2"} |                                    |

        # Initial stale state after setting up both document subtrees.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId  | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | intro-text       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | intro-text-2     | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text-2     | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | page-home        | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home        | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-2      | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home-2      | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-main   | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main   | []                                                                |
            | user-workspace | {"language":"de"}         | page-home-main-2 | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main-2 | []                                                                |
            | user-workspace | {"language":"de"}         | sibling-text     | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sibling-text     | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sibling-text-2   | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sibling-text-2   | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command PublishIndividualNodesFromWorkspace is executed with payload:
            | Key            | Value                       |
            | workspaceName  | "user-workspace"            |
            | nodesToPublish | ["page-home", "intro-text"] |

        # cs-identifier event timeline (Document-scope auto-sync, partial publish):
        #   0   ContentStreamWasCreated (Background)
        #   1   RootNodeAggregateWithNodeWasCreated (Background)
        #   2-3 NodeAggregateWithNodeWasCreated (page-home + tethered page-home-main, replicated)
        #   4   NodeAggregateWithNodeWasCreated (intro-text, replicated)
        #   5   NodePeerVariantWasCreated (page-home en→es — document created first, ancestor-before-descendant)
        #   6   NodePeerVariantWasCreated (page-home-main en→es, the tethered collection materialises with it)
        #   7   NodePropertiesWereSet     (page-home es title translated; the collection has no translatable properties)
        #   8   NodePeerVariantWasCreated (intro-text en→es — the nested content, dispatched after its parent
        #                                  collection exists)
        #   9   NodePropertiesWereSet     (intro-text es translated)
        Then I expect exactly 10 events to be published on stream "ContentStream:cs-identifier"
        And event at index 7 is of type "NodePropertiesWereSet" with payload:
            | Key                        | Expected           |
            | nodeAggregateId            | "page-home"        |
            | originDimensionSpacePoint  | {"language": "es"} |
            | propertyValues.title.value | "Home translated"  |
        And event at index 9 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected             |
            | nodeAggregateId                                     | "intro-text"         |
            | originDimensionSpacePoint                           | {"language": "es"}   |
            | propertyValues.inlineEditableStringProperty.value   | "Welcome translated" |
            | propertyValues.autoTranslatableStringProperty.value | "Intro translated"   |

        # Final stale state. live's es rows are fully cleared (document title, content properties AND the empty
        # content-collection record cleared by its variant event). live keeps only its de rows for the published nodes
        # (no rule covers de). user-workspace retains its full 16-row set — partial publish does not propagate stale
        # rows back to the source, and the entire page-home-2 subtree (plus the unpublished sibling-text under
        # page-home) is left alone.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId  | propertyNames                                                     |
            | live           | {"language":"de"}         | intro-text       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live           | {"language":"de"}         | page-home        | ["title"]                                                         |
            | live           | {"language":"de"}         | page-home-main   | []                                                                |
            | user-workspace | {"language":"de"}         | intro-text       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | intro-text-2     | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text-2     | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | page-home        | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home        | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-2      | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home-2      | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-main   | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main   | []                                                                |
            | user-workspace | {"language":"de"}         | page-home-main-2 | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main-2 | []                                                                |
            | user-workspace | {"language":"de"}         | sibling-text     | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sibling-text     | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sibling-text-2   | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sibling-text-2   | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Publishing a Document via Publish button on Document publishes Document and its content
        # PublishChangesInDocument is the application-level "Publish" button on a document in the Neos UI
        # ({@see Neos\Neos\Ui\Application\PublishChangesInDocument\PublishChangesInDocumentCommand}). It resolves the
        # document's subtree (document + descendants up to but not crossing nested documents) and partial-publishes
        # those. So publishing `page-home` here must mirror only page-home + its tethered `main` + the two content nodes
        # inside it to live (and translate them to es), leaving the entire `page-home-2` subtree untouched in
        # user-workspace.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                             | tetheredDescendantNodeAggregateIds |
            | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                                 | {"main": "page-home-main"}         |
            | page-home-2     | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home 2"}                                                                               | {"main": "page-home-main-2"}       |
            | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"}            |                                    |
            | sibling-text    | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Sibling Text", "autoTranslatableStringProperty": "Article"}     |                                    |
            | intro-text-2    | page-home-main-2       | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome 2", "autoTranslatableStringProperty": "Intro 2"}        |                                    |
            | sibling-text-2  | page-home-main-2       | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Sibling Text 2", "autoTranslatableStringProperty": "Article 2"} |                                    |

        # Initial stale state — all 8 freshly created node aggregates are stale in BOTH target dims (de + es). The
        # tethered `main` collections are recorded with an empty property list.
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId   | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | intro-text        | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text        | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | intro-text-2      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text-2      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | page-home         | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home         | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-2       | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home-2       | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-main    | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main    | []                                                                |
            | user-workspace | {"language":"de"}         | page-home-main-2  | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main-2  | []                                                                |
            | user-workspace | {"language":"de"}         | sibling-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sibling-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sibling-text-2    | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sibling-text-2    | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When the command PublishChangesInDocument is executed with payload:
            | Key           | Value            |
            | workspaceName | "user-workspace" |
            | documentId    | "page-home"      |

        # cs-identifier event timeline (Document scope auto-sync, document-level partial publish):
        #   0     ContentStreamWasCreated (Background)
        #   1     RootNodeAggregateWithNodeWasCreated (Background)
        #   2-5   NodeAggregateWithNodeWasCreated (page-home + tethered page-home-main + intro-text + sibling-text —
        #                                          only the page-home subtree replicates; page-home-2 and its
        #                                          descendants stay in user-workspace)
        #   6     NodePeerVariantWasCreated (page-home en→es — document created first)
        #   7     NodePeerVariantWasCreated (page-home-main en→es — the tethered collection materialises with it)
        #   8     NodePropertiesWereSet     (page-home es title translated)
        #   9     NodePeerVariantWasCreated (intro-text en→es)
        #   10    NodePropertiesWereSet     (intro-text es translated)
        #   11    NodePeerVariantWasCreated (sibling-text en→es)
        #   12    NodePropertiesWereSet     (sibling-text es translated)
        Then I expect exactly 13 events to be published on stream "ContentStream:cs-identifier"
        And event at index 8 is of type "NodePropertiesWereSet" with payload:
            | Key                        | Expected           |
            | nodeAggregateId            | "page-home"        |
            | originDimensionSpacePoint  | {"language": "es"} |
            | propertyValues.title.value | "Home translated"  |
        And event at index 10 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected             |
            | nodeAggregateId                                     | "intro-text"         |
            | originDimensionSpacePoint                           | {"language": "es"}   |
            | propertyValues.inlineEditableStringProperty.value   | "Welcome translated" |
            | propertyValues.autoTranslatableStringProperty.value | "Intro translated"   |
        And event at index 12 is of type "NodePropertiesWereSet" with payload:
            | Key                                                 | Expected                  |
            | nodeAggregateId                                     | "sibling-text"            |
            | originDimensionSpacePoint                           | {"language": "es"}        |
            | propertyValues.inlineEditableStringProperty.value   | "Sibling Text translated" |
            | propertyValues.autoTranslatableStringProperty.value | "Article translated"      |

        # Final stale state. live has the page-home subtree's de rows only (es cleared by auto-sync; de unsynced).
        # page-home-2 and its descendants never reached live. user-workspace retains its full 16-row set — like ordinary
        # partial publish, the document-level publish does not propagate stale rows back to the source workspace.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId   | propertyNames                                                     |
            | live           | {"language":"de"}         | intro-text        | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live           | {"language":"de"}         | page-home         | ["title"]                                                         |
            | live           | {"language":"de"}         | page-home-main    | []                                                                |
            | live           | {"language":"de"}         | sibling-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | intro-text        | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text        | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | intro-text-2      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | intro-text-2      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | page-home         | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home         | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-2       | ["title"]                                                         |
            | user-workspace | {"language":"es"}         | page-home-2       | ["title"]                                                         |
            | user-workspace | {"language":"de"}         | page-home-main    | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main    | []                                                                |
            | user-workspace | {"language":"de"}         | page-home-main-2  | []                                                                |
            | user-workspace | {"language":"es"}         | page-home-main-2  | []                                                                |
            | user-workspace | {"language":"de"}         | sibling-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sibling-text      | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sibling-text-2    | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sibling-text-2    | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
