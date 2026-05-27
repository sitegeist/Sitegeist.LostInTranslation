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

    Scenario: Publishing a freshly-created en node to live auto-translates it to es in live
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                                                        | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} | {"tethered": "nodewyn-tetherton"}  |

        # Stale rows recorded for both de AND es: en has two configured target languages, so the
        # projection fans out one row per (target dimension, node). The tethered child gets its
        # own rows for its translatable property too.
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

        # After publish, the auto-sync hook fires on `live` because the rule `(live, en) → (live, es)`
        # matches the publish target. It iterates stale records in (live, es) and emits CreateNodeVariant
        # per node; the existing TranslationCommandHook cascades SetNodeProperties with translated values;
        # the projection then drops the stale rows.
        #
        # `de` stale rows survive (no rule covers them). `user-workspace/*` rows also survive — the rule
        # only scrubs live, and replaceWorkspaceEntries already copied them back before the hook ran.
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live           | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Publishing a property update to en,live auto-syncs the existing es variant
        # Exercises the "target variant already exists" branch of the auto-sync hook: instead of
        # emitting CreateNodeVariant the hook must build a translated SetNodeProperties from
        # StalePropertyCommandBuilder. Uses a NodeWithAutomaticTranslation with a tethered child so
        # the first publish has to build a real variant + cascade before the update is exercised.
        #
        # (Scenario title used to say "DE" but the test rule covers en → es, so we test the es
        # variant. Adding a de rule would require two rules and complicate the unrelated bits.)
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                            | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |

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

        # Now mutate the en source and re-publish. The auto-sync hook detects the fresh
        # `(live, sir-david, es)` stale row, sees that the es variant already exists, and emits a
        # translated SetNodeProperties (no NodePeerVariantWasCreated this time).
        When I am in workspace "user-workspace"
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                                                           |
            | nodeAggregateId           | "sir-david-nodenborough"                                                                        |
            | originDimensionSpacePoint | {"language": "en"}                                                                              |
            | propertyValues            | {"inlineEditableStringProperty": "Updated Text", "autoTranslatableStringProperty": "Updated Other Text"} |
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id3" |

        # live/es stale cleared again by auto-sync; live/de remains (no rule covers it). The
        # tethered child's es row was already cleared in the first sync and is not re-staled,
        # because the en update only touched the parent.
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
        #   9   NodePropertiesWereSet     (sir-david es re-translated, auto-sync builds it directly — NO NodePeerVariantWasCreated)
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
        # The configured rule targets `sourceWorkspaceName: live`. Publishing onto a *different*
        # base workspace must therefore leave stale rows untouched: no auto-sync hook activity at
        # all on that workspace's content stream. Uses a NodeWithAutomaticTranslation + tethered
        # child to prove the whole subtree is left alone.
        #
        # Reuse the Background's user-workspace as the publish source; rebase it onto a fresh
        # `preview` base so the publish lands on preview rather than live.
        When the command CreateWorkspace is executed with payload:
            | Key                | Value             |
            | workspaceName      | "preview"         |
            | baseWorkspaceName  | "live"            |
            | newContentStreamId | "preview-cs-id"   |
        And the command ChangeBaseWorkspace is executed with payload:
            | Key               | Value            |
            | workspaceName     | "user-workspace" |
            | baseWorkspaceName | "preview"        |
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                            | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |

        # Publish onto `preview` — NOT live — so no rule matches and the auto-sync hook short-circuits.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value            |
            | workspaceName      | "user-workspace" |
            | newContentStreamId | "user-cs-id-new" |

        # Both de and es stale rows survive on preview AND user-workspace (replaceWorkspaceEntries
        # copies preview→user-workspace after publish).
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
        # No NodePeerVariantWasCreated, no translated NodePropertiesWereSet — proving the hook
        # did not fire for the uncovered workspace.
        And I expect exactly 3 events to be published on stream "ContentStream:preview-cs-id"

    Scenario: Manual workspace synchronization only affects the target workspace and dimension
        # The `synchronize` CLI (WorkspaceSynchronizer) walks the stale records for ONE
        # (workspace, dimension) pair. Here two elaborate subtrees live in user-workspace and one
        # document lives in other-user-workspace; synchronizing user-workspace en→de must clear
        # only the user-workspace `de` rows and leave user-workspace `es` + all of
        # other-user-workspace untouched.
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
        # The `synchronize --full` mode (FullWorkspaceSynchronizer) walks every translatable node
        # from the root down, independent of stale state. Here a deep subtree (document + content
        # with tethered child + grandchild) lives only in live/en; full sync en→de must create and
        # translate the de variant of every node in that subtree.
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

        When I am in workspace "live" and dimension space point {"language":"de"}
        Then I expect node aggregate identifier "sir-david-nodenborough" to lead to node cs-identifier;sir-david-nodenborough;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                          | Value                       |
            | inlineEditableStringProperty | "My Text translated"        |
            | autoTranslatableStringProperty | "My Other Text translated" |
        # the deep grandchild was reached by the walk
        And I expect node aggregate identifier "nody-mc-nodeface" to lead to node cs-identifier;nody-mc-nodeface;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                            | Value                          |
            | inlineEditableStringProperty   | "Grandchild Text translated"   |
            | autoTranslatableStringProperty | "Grandchild Other Text translated" |
        # the sibling document too
        And I expect node aggregate identifier "parent-doc" to lead to node cs-identifier;parent-doc;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                            | Value                       |
            | autoTranslatableStringProperty | "Parent Doc Text translated" |

    Scenario: Full sync with default skip-existing leaves already-translated subtrees untouched
        # One subtree is pre-translated (variant exists, de stale already cleared); another is fresh.
        # Default skip-existing=true skips the already-translated one and only builds the missing variant.
        When I am in workspace "live"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |
        # Pre-translate the sir-david subtree by issuing CreateNodeVariant — TranslationCommandHook
        # cascades the translation and the projection clears its de stale rows.
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

        # nody-mc-nodeface subtree's de rows cleared; sir-david subtree untouched (was already done);
        # all es rows remain.
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | live          | {"language":"es"}         | nodenberg              | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"]                                |
            | live          | {"language":"es"}         | nody-mc-nodeface       | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        When I am in workspace "live" and dimension space point {"language":"de"}
        Then I expect node aggregate identifier "nody-mc-nodeface" to lead to node cs-identifier;nody-mc-nodeface;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                            | Value                |
            | inlineEditableStringProperty   | "Face Text translated" |
            | autoTranslatableStringProperty | "Face Other translated" |

    Scenario: Full sync with skipExisting=false re-translates existing variants too
        # A manually-overridden de variant gets clobbered by re-translation of the current en source.
        When I am in workspace "live"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |
        When the command CreateNodeVariant is executed with payload:
            | Key             | Value                    |
            | nodeAggregateId | "sir-david-nodenborough" |
            | sourceOrigin    | {"language":"en"}        |
            | targetOrigin    | {"language":"de"}        |
        # Editor overrides the auto-translation by hand.
        When the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                                                                |
            | nodeAggregateId           | "sir-david-nodenborough"                                                                             |
            | originDimensionSpacePoint | {"language": "de"}                                                                                   |
            | propertyValues            | {"inlineEditableStringProperty": "Hand Crafted DE", "autoTranslatableStringProperty": "Hand Other"}  |

        When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"} including existing variants

        # The hand-crafted text was clobbered by re-translation of the current source.
        When I am in workspace "live" and dimension space point {"language":"de"}
        Then I expect node aggregate identifier "sir-david-nodenborough" to lead to node cs-identifier;sir-david-nodenborough;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                            | Value                      |
            | inlineEditableStringProperty   | "My Text translated"       |
            | autoTranslatableStringProperty | "My Other Text translated" |

    Scenario: Stale-mode auto-sync re-translates a tethered child whose source changed
        # Regression guard for the stale-driven path (the default `live` rule): a property change
        # confined to a tethered child is picked up from its stale record and re-translated on publish.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |
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

        When I am in workspace "live" and dimension space point {"language":"es"}
        Then I expect node aggregate identifier "nodewyn-tetherton" to lead to node cs-identifier;nodewyn-tetherton;{"language":"es"}
        And I expect this node to have the following properties:
            | Key                            | Value                         |
            | autoTranslatableStringProperty | "Tethered Changed translated" |

    Scenario: Document-scope auto-sync mirrors a published document and its content into the target dimension
        # The `live` rule is Document scope: publishing a freshly-created Document.Page that holds
        # content in its `main` collection must create AND translate the es variant of BOTH the
        # document and its content. The content's aggregate id ("intro-text") deliberately sorts
        # BEFORE the document's ("page-home"), proving the hook orders the document's variant
        # creation ahead of the content's — the content's parent (the document's tethered `main`)
        # only exists once the document variant has been created.
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                  | tetheredDescendantNodeAggregateIds |
            | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                      | {"main": "page-home-main"}         |
            | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |                                    |

        # The document's `title` and the content's properties are recorded stale in both target
        # dimensions. The tethered `main` ContentCollection has no translatable properties, so it is
        # recorded with an empty property list (it is created structurally alongside the document).
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

        # Synchronizing cleared every es stale record in `live` — the document's `title`, the
        # content's properties AND the (empty) content-collection record. live keeps only its
        # unsynced de rows (no rule covers de). The user-workspace es rows survive: the rule only
        # scrubs live, and replaceWorkspaceEntries already copied them back before the hook ran
        # (same as the other publish-on-live scenarios).
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

        # Document scope created + translated the es variant of the document …
        When I am in workspace "live" and dimension space point {"language":"es"}
        Then I expect node aggregate identifier "page-home" to lead to node cs-identifier;page-home;{"language":"es"}
        And I expect this node to have the following properties:
            | Key   | Value             |
            | title | "Home translated" |
        # … and of the content nested inside its `main` collection.
        And I expect node aggregate identifier "intro-text" to lead to node cs-identifier;intro-text;{"language":"es"}
        And I expect this node to have the following properties:
            | Key                            | Value                |
            | inlineEditableStringProperty   | "Welcome translated" |
            | autoTranslatableStringProperty | "Intro translated"   |

    Scenario: Content-scope auto-sync never creates documents and skips content whose document is missing in the target
        # The `content-review` rule is Content scope: it must NOT create Document variants
        # automatically, and only fills in content whose containing Document already exists in the
        # target dimension. Here neither the document nor its content exists in es yet, so publishing
        # creates nothing in es — adopting the document into es stays a manual editor action.
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
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "content-user"         |
            | newContentStreamId | "content-user-cs-id-2" |

        # Nothing was created in es: the document is not auto-created, and the content's document is
        # absent from the target dimension, so the content is skipped too.
        When I am in workspace "content-review" and dimension space point {"language":"es"}
        Then I expect node aggregate identifier "page-home" to lead to no node
        And I expect node aggregate identifier "intro-text" to lead to no node

    Scenario: Content-scope auto-sync fills in content below a document that already exists in the target
        # The `content-review` rule is Content scope. Once a Document has been manually adopted into
        # the target dimension, publishing fresh content inside it auto-creates and translates the
        # content's es variant — while the manually-translated document itself is left untouched.
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
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "content-user"         |
            | newContentStreamId | "content-user-cs-id-2" |

        # The content was created + translated in es because its document exists there …
        When I am in workspace "content-review" and dimension space point {"language":"es"}
        Then I expect node aggregate identifier "intro-text" to lead to node content-review-cs-id;intro-text;{"language":"es"}
        And I expect this node to have the following properties:
            | Key                            | Value                |
            | inlineEditableStringProperty   | "Welcome translated" |
            | autoTranslatableStringProperty | "Intro translated"   |
        # … and the manually-adopted document keeps its translation, untouched by the content sync.
        And I expect node aggregate identifier "page-home" to lead to node content-review-cs-id;page-home;{"language":"es"}
        And I expect this node to have the following properties:
            | Key   | Value             |
            | title | "Home translated" |
