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

    Scenario: Manual workspace synchronisation only affects the target workspace and dimension
        # The `synchronise` CLI (WorkspaceSynchroniser) walks the stale records for ONE
        # (workspace, dimension) pair. Here two elaborate subtrees live in user-workspace and one
        # document lives in other-user-workspace; synchronising user-workspace en→de must clear
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

        When I synchronise translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"de"}

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
        # The `synchronise --full` mode (FullWorkspaceSynchroniser) walks every translatable node
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

        When I full-synchronise translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}

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

        When I full-synchronise translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}

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

        When I full-synchronise translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"} including existing variants

        # The hand-crafted text was clobbered by re-translation of the current source.
        When I am in workspace "live" and dimension space point {"language":"de"}
        Then I expect node aggregate identifier "sir-david-nodenborough" to lead to node cs-identifier;sir-david-nodenborough;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                            | Value                      |
            | inlineEditableStringProperty   | "My Text translated"       |
            | autoTranslatableStringProperty | "My Other Text translated" |

    Scenario: Auto-sync rule with synchronizationStrategy=full + translationStrategy=force-refresh overwrites a manual translation
        # The `staging` rule is full + force-refresh. On every publish to staging it re-translates
        # existing es variants from the current source — so a hand-made es edit is overwritten.
        When the command CreateWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "staging"            |
            | baseWorkspaceName  | "live"               |
            | newContentStreamId | "staging-cs-id"      |
        And the command CreateWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "staging-user"       |
            | baseWorkspaceName  | "staging"            |
            | newContentStreamId | "staging-user-cs-id" |
        When I am in workspace "staging-user"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |
        # Publish #1 → full rule creates + translates the es variant on staging.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "staging-user"         |
            | newContentStreamId | "staging-user-cs-id-2" |

        # Hand-edit the es variant directly on staging (target-dimension edit → no stale record).
        When I am in workspace "staging"
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                               |
            | nodeAggregateId           | "sir-david-nodenborough"                            |
            | originDimensionSpacePoint | {"language": "es"}                                  |
            | propertyValues            | {"inlineEditableStringProperty": "Hand Crafted ES"} |

        # A second, unrelated publish triggers the rule again. force-refresh re-translates the
        # existing (non-stale) es variant, overwriting the manual edit.
        When I am in workspace "staging-user"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId  | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                          |
            | nody-mc-nodeface | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Face Text"}  |
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                  |
            | workspaceName      | "staging-user"         |
            | newContentStreamId | "staging-user-cs-id-3" |

        When I am in workspace "staging" and dimension space point {"language":"es"}
        Then I expect node aggregate identifier "sir-david-nodenborough" to lead to node staging-cs-id;sir-david-nodenborough;{"language":"es"}
        And I expect this node to have the following properties:
            | Key                          | Value                |
            | inlineEditableStringProperty | "My Text translated" |

    Scenario: Auto-sync rule with translationStrategy=keep-existing preserves manual edits but a stale node is still refreshed
        # The `review` rule is full + keep-existing. A hand-made es edit on a non-stale node survives,
        # but a node whose source changed (→ stale) is still re-translated — the load-bearing override.
        When the command CreateWorkspace is executed with payload:
            | Key                | Value               |
            | workspaceName      | "review"            |
            | baseWorkspaceName  | "live"              |
            | newContentStreamId | "review-cs-id"      |
        And the command CreateWorkspace is executed with payload:
            | Key                | Value               |
            | workspaceName      | "review-user"       |
            | baseWorkspaceName  | "review"            |
            | newContentStreamId | "review-user-cs-id" |
        When I am in workspace "review-user"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                          |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text"}    |
            | nody-mc-nodeface       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Face Text"}  |
        # Publish #1 → es variants created + translated for both nodes.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                 |
            | workspaceName      | "review-user"         |
            | newContentStreamId | "review-user-cs-id-2" |

        # Hand-edit sir-david's es directly on review (no stale). Leave nody-mc's es alone.
        When I am in workspace "review"
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                               |
            | nodeAggregateId           | "sir-david-nodenborough"                            |
            | originDimensionSpacePoint | {"language": "es"}                                  |
            | propertyValues            | {"inlineEditableStringProperty": "Hand Crafted ES"} |

        # Change nody-mc's EN source (→ stale for its es) and publish.
        When I am in workspace "review-user"
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                              |
            | nodeAggregateId           | "nody-mc-nodeface"                                 |
            | originDimensionSpacePoint | {"language": "en"}                                 |
            | propertyValues            | {"inlineEditableStringProperty": "Face Changed"}   |
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                 |
            | workspaceName      | "review-user"         |
            | newContentStreamId | "review-user-cs-id-3" |

        When I am in workspace "review" and dimension space point {"language":"es"}
        # keep-existing preserved the hand-made, non-stale translation …
        Then I expect node aggregate identifier "sir-david-nodenborough" to lead to node review-cs-id;sir-david-nodenborough;{"language":"es"}
        And I expect this node to have the following properties:
            | Key                          | Value               |
            | inlineEditableStringProperty | "Hand Crafted ES"   |
        # … but the stale node was refreshed from its new source anyway.
        And I expect node aggregate identifier "nody-mc-nodeface" to lead to node review-cs-id;nody-mc-nodeface;{"language":"es"}
        And I expect this node to have the following properties:
            | Key                          | Value                    |
            | inlineEditableStringProperty | "Face Changed translated" |

    Scenario: Full sync re-translates a stale tethered child whose target variant already exists
        # A property change confined to a TETHERED child (whose target variant already exists) must
        # still be re-synced. The tethered child cannot be created independently, but once its
        # variant exists a SetNodeProperties can refresh it directly.
        When the command CreateWorkspace is executed with payload:
            | Key                | Value               |
            | workspaceName      | "review"            |
            | baseWorkspaceName  | "live"              |
            | newContentStreamId | "review-cs-id"      |
        And the command CreateWorkspace is executed with payload:
            | Key                | Value               |
            | workspaceName      | "review-user"       |
            | baseWorkspaceName  | "review"            |
            | newContentStreamId | "review-user-cs-id" |
        When I am in workspace "review-user"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                                                                          | tetheredDescendantNodeAggregateIds |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} | {"tethered": "nodewyn-tetherton"}  |
        # Publish #1 → full rule creates + translates the es variants of sir-david AND its tethered child.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                 |
            | workspaceName      | "review-user"         |
            | newContentStreamId | "review-user-cs-id-2" |

        # Change ONLY the tethered child's en source (→ stale for the tethered child's es), then publish.
        When I am in workspace "review-user"
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                          |
            | nodeAggregateId           | "nodewyn-tetherton"                                            |
            | originDimensionSpacePoint | {"language": "en"}                                             |
            | propertyValues            | {"autoTranslatableStringProperty": "Tethered Changed"}         |
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                 |
            | workspaceName      | "review-user"         |
            | newContentStreamId | "review-user-cs-id-3" |

        # The tethered child's existing es variant is refreshed from its new source (stale forces it,
        # even under keep-existing).
        When I am in workspace "review" and dimension space point {"language":"es"}
        Then I expect node aggregate identifier "nodewyn-tetherton" to lead to node review-cs-id;nodewyn-tetherton;{"language":"es"}
        And I expect this node to have the following properties:
            | Key                            | Value                         |
            | autoTranslatableStringProperty | "Tethered Changed translated" |

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
