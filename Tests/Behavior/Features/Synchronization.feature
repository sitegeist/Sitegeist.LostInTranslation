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
              options:
                referenceLanguage: en
            # auto-sync from en_live to es_live
            es:
              options:
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
        # A Document whose `uriPathSegment` is opted into automatic translation, to exercise the strict-charset
        # post-processing on the sync path (StalePropertyCommandBuilder). Only used by the uriPathSegment scenario.
        'Sitegeist.LostInTranslation.Testing:PageWithUriPathSegment':
          superTypes:
            'Neos.Neos:Document': true
          properties:
            uriPathSegment:
              type: string
              options:
                automaticTranslation: true
                translationPostProcessor: 'Sitegeist\LostInTranslation\Domain\PostProcessor\UriPathSegmentPostProcessor'
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

  Scenario: The backend module reports when the stale-translation projection is not set up
    # A set-up content repository reports the projection as ready; once its schema is gone (a fresh install before
    # `./flow cr:setup`) the backend module must surface that status instead of faulting on the missing tables.
    Then the stale-translation projection is reported as "ready"
    When the stale-translation projection schema is removed
    Then the stale-translation projection is reported as "not set up"

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

  Scenario: Synchronization creates a missing ancestor document before a descendant document
    # Creating a node variant requires its parent to already cover the target dimension
    # (CR `requireNodeAggregateToCoverDimensionSpacePoint`). When a freshly-created child document sits under a parent
    # document that has not been varied into the target dimension yet, synchronizing the child before the parent would
    # abort with `Node aggregate "parent-doc" does currently not cover dimension space point {"language":"de"}`. The
    # WorkspaceSynchronizer processes stale records ancestor-before-descendant (by source-tree depth) so the parent's
    # variant is created first. The child id ("child-doc") deliberately sorts BEFORE the parent id ("parent-doc"),
    # proving the ordering is hierarchical and not by stale-record id.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                             |
      | parent-doc      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Parent Text"} |
      | child-doc       | parent-doc             | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Child Text"}  |
    And I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                      |
      | user-workspace | {"language":"de"}         | child-doc       | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"es"}         | child-doc       | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | parent-doc      | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"es"}         | parent-doc      | ["autoTranslatableStringProperty"] |

        # 1x ContentStreamWasForked (CreateWorkspace user-workspace) + 2x NodeAggregateWithNodeWasCreated
    And I expect exactly 3 events to be published on stream "ContentStream:user-cs-id"

    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"de"}

        # Both de rows cleared (parent created+translated first, then child); es rows survive (sync targeted de only).
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames                      |
      | user-workspace | {"language":"es"}         | child-doc       | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"es"}         | parent-doc      | ["autoTranslatableStringProperty"] |

        # +4 events: parent-doc variant + its translation (ancestor, created first), then child-doc variant + its
        # translation. Ancestor-before-descendant ordering keeps the parent-covers-target invariant at every step.
    And I expect exactly 7 events to be published on stream "ContentStream:user-cs-id"
    And event at index 3 is of type "NodePeerVariantWasCreated" with payload:
      | Key             | Expected           |
      | nodeAggregateId | "parent-doc"       |
      | sourceOrigin    | {"language": "en"} |
      | peerOrigin      | {"language": "de"} |
    And event at index 4 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                 |
      | nodeAggregateId                                     | "parent-doc"             |
      | originDimensionSpacePoint                           | {"language": "de"}       |
      | propertyValues.autoTranslatableStringProperty.value | "Parent Text translated" |
    And event at index 5 is of type "NodePeerVariantWasCreated" with payload:
      | Key             | Expected           |
      | nodeAggregateId | "child-doc"        |
      | sourceOrigin    | {"language": "en"} |
      | peerOrigin      | {"language": "de"} |
    And event at index 6 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                |
      | nodeAggregateId                                     | "child-doc"             |
      | originDimensionSpacePoint                           | {"language": "de"}      |
      | propertyValues.autoTranslatableStringProperty.value | "Child Text translated" |

  Scenario: Stale-driven sync skips content whose ancestor document has no stale row, hinting at a full sync
    # Reproduces a live data hole: the stale-translation projection was installed AFTER an ancestor document was
    # created, so the document has no stale row, while content added later (under its tethered collection) does. The
    # depth-sort can only order ancestors that HAVE a stale row, so it cannot bootstrap the document — its tethered
    # `main` collection never materialises in the target and the content below cannot be varied. Instead of letting the
    # CR abort the whole run with `Node aggregate "page-home-main" does currently not cover dimension space point`, the
    # stale-driven sync skips the affected nodes and flags that a full sync is needed (the CLI / backend module turn
    # that flag into a "run synchronize --full" hint).
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                  | tetheredDescendantNodeAggregateIds |
      | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                      | {"main": "page-home-main"}         |
      | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |                                    |
    # Punch the hole: drop the document's own stale rows, as if it predated the projection. Its tethered collection and
    # the content below keep their rows, so they are the records that drive (and now stall) the stale-driven run.
    And I remove the recorded stale translations for node "page-home" in workspace "user-workspace"

    # The run proceeds (no CR abort) but skips page-home-main and intro-text: their nearest non-tethered ancestor
    # (page-home) is missing in de and has no pending translation, so this run cannot create it.
    Then synchronizing translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"de"} skips 2 node(s) pending a full sync

    # Nothing was created in de — the skip is graceful, not a partial write.
    And I expect node "page-home" to be absent in workspace "user-workspace" dimension space point {"language":"de"}
    And I expect node "page-home-main" to be absent in workspace "user-workspace" dimension space point {"language":"de"}
    And I expect node "intro-text" to be absent in workspace "user-workspace" dimension space point {"language":"de"}

  Scenario: Synchronization translates a stale uriPathSegment and keeps it a valid slug
    # The stale-driven sync path (WorkspaceSynchronizer -> Retranslator -> StalePropertyCommandBuilder) must apply the
    # same strict-charset post-processing to uriPathSegment as the create-variant cascade: a translated segment
    # ("my-test-uri-path translated", containing a space) is re-slugified to "my-test-uri-path-translated".
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                               | initialPropertyValues                  |
      | my-document     | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:PageWithUriPathSegment | {"uriPathSegment": "initial-path"}     |
    # Create + translate the de variant first, so the later sync exercises the SetNodeProperties (existing-variant) path
    # rather than CreateNodeVariant.
    When the command CreateNodeVariant is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "my-document"     |
      | sourceOrigin    | {"language":"en"} |
      | targetOrigin    | {"language":"de"} |
    # Change the source uriPathSegment so the de translation goes stale again.
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                  |
      | nodeAggregateId           | "my-document"                          |
      | originDimensionSpacePoint | {"language": "en"}                     |
      | propertyValues            | {"uriPathSegment": "my-test-uri-path"} |
    And I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames      |
      | user-workspace | {"language":"de"}         | my-document     | ["uriPathSegment"] |
      | user-workspace | {"language":"es"}         | my-document     | ["uriPathSegment"] |

    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"de"}

    # The de variant's uriPathSegment is the re-slugified translation; the de stale row is cleared, es survives.
    Then I expect node "my-document" in workspace "user-workspace" dimension space point {"language":"de"} to have property "uriPathSegment" with value "my-test-uri-path-translated"
    And I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId | propertyNames      |
      | user-workspace | {"language":"es"}         | my-document     | ["uriPathSegment"] |

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
    #   10    NodePropertiesWereSet     (nodewyn-tetherton de re-translated — the cascade above materialised its de
    #                                    variant mid-walk, so when the walk reaches it, it is an existing variant
    #                                    and `--full` refreshes every existing variant. A second DeepL call for the
    #                                    same source value.)
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

  Scenario: Full sync with --skip-existing leaves already-translated subtrees untouched
    # One subtree is pre-translated (variant exists, de stale already cleared); another is fresh. `--skip-existing` is
    # the opt-in escape hatch: it skips the already-translated one and only builds the missing variant, preserving any
    # target-side edit on it. Without the flag (the default, next scenario) both subtrees are re-translated.
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

    When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"} keeping existing variants

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
    #   14    NodePropertiesWereSet     (nodenberg de re-translated — same as event 10 above: the cascade created
    #                                    its de variant mid-walk, and `--full` refreshes every existing variant)
    # The already-translated sir-david subtree is skipped: no further events for it.
    Then I expect exactly 15 events to be published on stream "ContentStream:cs-identifier"
    And event at index 12 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                |
      | nodeAggregateId                                     | "nody-mc-nodeface"      |
      | originDimensionSpacePoint                           | {"language": "de"}      |
      | propertyValues.inlineEditableStringProperty.value   | "Face Text translated"  |
      | propertyValues.autoTranslatableStringProperty.value | "Face Other translated" |

  Scenario: Full sync re-translates existing variants by default
    # A manually-overridden de variant gets clobbered by re-translation of the current en source. That is the intended
    # model — the target dimension is a projection of the source, so target-side edits are overwritten — and it is why
    # this is the default rather than something you opt into.
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

    When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}

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

  Scenario: Content-scope cross-workspace sync never creates documents and skips content whose document is missing in the target
    # The `content-review` rule is cross-workspace Content scope (live/en → content-review/es): on publish to live it
    # force-rebases content-review onto live and only fills content whose containing Document already exists in
    # content-review/es — it never creates Document variants. Here content-review/es has no page-home yet (the document
    # rule's live/es translation is dispatched only after this hook returns, so the rebase cannot have mirrored it in
    # yet), so publishing a page with content to live mirrors nothing into content-review/es.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value                  |
      | workspaceName      | "content-review"       |
      | baseWorkspaceName  | "live"                 |
      | newContentStreamId | "content-review-cs-id" |
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                  | tetheredDescendantNodeAggregateIds |
      | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                                                                      | {"main": "page-home-main"}         |
      | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |                                    |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |

    # Content scope never creates the document in content-review/es, and the content below a (still) missing document
    # is skipped too.
    Then I expect node "page-home" to be absent in workspace "content-review" dimension space point {"language":"es"}
    And I expect node "intro-text" to be absent in workspace "content-review" dimension space point {"language":"es"}

  Scenario: Content-scope cross-workspace sync fills content under a document already present in the target
    # Cross-workspace Content scope. Once content-review/es contains a Document (mirrored in from an earlier live
    # publish via the force-rebase), publishing fresh content under that document to live fills the content into
    # content-review/es on the next publish — while content scope itself never creates the Document.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value                  |
      | workspaceName      | "content-review"       |
      | baseWorkspaceName  | "live"                 |
      | newContentStreamId | "content-review-cs-id" |
    # First publish the document to live. The Document-scope live→live/es rule translates page-home into live/es.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                              | initialPropertyValues | tetheredDescendantNodeAggregateIds |
      | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page | {"title": "Home"}     | {"main": "page-home-main"}         |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |

    # Now add content under the document and publish again. By this publish live/es already has page-home, so the
    # force-rebase mirrors the document into content-review/es; content scope then fills intro-text/es because its
    # containing document now exists there.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId | nodeTypeName                                                         | initialPropertyValues                                                                  |
      | intro-text      | page-home-main        | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "Welcome", "autoTranslatableStringProperty": "Intro"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |

    # The content was filled into content-review/es because its document exists there …
    Then I expect node "intro-text" in workspace "content-review" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "Intro translated"
    And I expect node "intro-text" in workspace "content-review" dimension space point {"language":"es"} to have property "inlineEditableStringProperty" with value "Welcome translated"
    # … and the document itself is present (mirrored by the rebase) with its translated title.
    And I expect node "page-home" in workspace "content-review" dimension space point {"language":"es"} to have property "title" with value "Home translated"

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
    # AI authorship across the full cascade — events 5-9 are all hook-emitted and must be attributed to the AI
    # service, not the publishing editor. The first publish replicates events 2-4 under the editor's id; everything
    # after that is the auto-sync cascade. Event 8 (intro-text CreateNodeVariant) is the load-bearing assertion:
    # SynchronizationCommandHook returned [CV(page-home), CV(intro-text)], and TranslationCommandHook resets the AI
    # state at the start of every onAfterHandle — including between event 7's SetNodeProperties (the page-home
    # translation cascade) and event 8. Without re-setting in onBeforeHandle, event 8 would carry the editor's id.
    And event data at index 5 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |
    And event data at index 6 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |
    And event data at index 7 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |
    And event data at index 8 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |
    And event data at index 9 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |

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

  Scenario: Synchronize propagates a cleared source property to the target dimension
    # When an editor clears a translatable property to "" in the source dimension, synchronize must
    # propagate the clearing verbatim to the target — the StalePropertyCommandBuilder routes empty source
    # values around DeepL and emits SetNodeProperties with "" so the target variant matches the source.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                                                        |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} |
    And I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # First sync en→de: variant doesn't exist yet, so the Retranslator emits CreateNodeVariant which the
    # TranslationCommandHook cascades into a translated SetNodeProperties. Clears the de stale row.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Clear autoTranslatableStringProperty in the en source — projection records a new de stale row.
    When I am in workspace "user-workspace"
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                  |
      | nodeAggregateId           | "sir-david-nodenborough"               |
      | originDimensionSpacePoint | {"language": "en"}                     |
      | propertyValues            | {"autoTranslatableStringProperty": ""} |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"]                                |
      | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Sync again en→de — the de variant already exists, so the Retranslator goes through
    # StalePropertyCommandBuilder. The empty source value is propagated verbatim (no DeepL call) and the
    # stale row is cleared.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # user-cs-id event timeline:
    #   0   ContentStreamWasForked     (Background)
    #   1   NodeAggregateWithNodeWasCreated (sir-david)
    #   2   NodePeerVariantWasCreated  (sir-david en→de, first sync)
    #   3   NodePropertiesWereSet      (sir-david de translated, TranslationCommandHook cascade)
    #   4   NodePropertiesWereSet      (sir-david en, autoTranslatableStringProperty cleared)
    #   5   NodePropertiesWereSet      (sir-david de, autoTranslatableStringProperty cleared by second sync)
    And I expect exactly 6 events to be published on stream "ContentStream:user-cs-id"
    And event at index 5 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                 |
      | workspaceName                                       | "user-workspace"         |
      | contentStreamId                                     | "user-cs-id"             |
      | nodeAggregateId                                     | "sir-david-nodenborough" |
      | originDimensionSpacePoint                           | {"language": "de"}       |
      | propertyValues.autoTranslatableStringProperty.value | ""                       |

  Scenario: Full sync propagates a cleared source property to an existing target variant
    # Same clearing contract as the stale-driven scenario above, but exercised through the `synchronize --full`
    # path (FullWorkspaceSynchronizer). Full sync re-translates every translatable property of a node via
    # StalePropertyCommandBuilder, which must route the blank source value around DeepL and emit "" so the
    # target variant mirrors the source. Regression guard: if the full walk filtered the blanked property out
    # before reaching the builder, the stale "My Other Text translated" would survive on de.
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                                                        |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text", "stringProperty": "Whatever"} |
    And I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # First full sync en→de: the de variant doesn't exist yet, so the walk emits CreateNodeVariant which the
    # TranslationCommandHook cascades into a translated SetNodeProperties. Clears the de stale rows.
    When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Clear autoTranslatableStringProperty in the en source — projection records a new de stale row.
    When I am in workspace "live"
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                  |
      | nodeAggregateId           | "sir-david-nodenborough"               |
      | originDimensionSpacePoint | {"language": "en"}                     |
      | propertyValues            | {"autoTranslatableStringProperty": ""} |
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"]                                |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Full sync again en→de — the de variant already exists and is stale, so the walk forces a refresh through
    # StalePropertyCommandBuilder. The empty source value is propagated verbatim (no DeepL call) and the stale
    # row is cleared.
    When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # cs-identifier event timeline:
    #   0   ContentStreamWasCreated             (Background)
    #   1   RootNodeAggregateWithNodeWasCreated (Background)
    #   2   NodeAggregateWithNodeWasCreated     (sir-david)
    #   3   NodePeerVariantWasCreated           (sir-david en→de, first full sync)
    #   4   NodePropertiesWereSet               (sir-david de translated, CreateNodeVariant cascade)
    #   5   NodePropertiesWereSet               (sir-david en, autoTranslatableStringProperty cleared)
    #   6   NodePropertiesWereSet               (sir-david de refreshed by the second full sync: inline
    #                                            re-translated, autoTranslatableStringProperty cleared verbatim)
    Then I expect exactly 7 events to be published on stream "ContentStream:cs-identifier"
    And event at index 6 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                 |
      | workspaceName                                       | "live"                   |
      | contentStreamId                                     | "cs-identifier"          |
      | nodeAggregateId                                     | "sir-david-nodenborough" |
      | originDimensionSpacePoint                           | {"language": "de"}       |
      | propertyValues.inlineEditableStringProperty.value   | "My Text translated"     |
      | propertyValues.autoTranslatableStringProperty.value | ""                       |

  Scenario: Auto-synced and translated events are attributed to the AI, a manual target edit to the editor
    # Attribution contract for the publish-driven auto-sync path. The AIAwareFakeAuthProvider reports the AI service id
    # (`AI:dummy:my-dummy`, from DummyTranslationService) for any write made while the SynchronizationCommandHook /
    # TranslationCommandHook have flagged the AISystemTranslationRuntimeState, and the publishing editor
    # (`initiating-user-identifier`) for everything else.
    #
    # Steps: create the source node in en, publish it to live so the `live` (en → es) rule auto-creates AND translates
    # the es variant, then hand-edit a property on that es variant and publish again. The es edit is NOT a source
    # change (nothing references es), so it produces no stale rows and the second publish triggers no sync — the edit
    # must therefore be attributed to the editor, while the auto-created variant and its translation stay attributed to
    # the AI.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                |
      | parent-doc      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Source Text"}    |

    # Publish #1 → the live (en → es) rule fires: CreateNodeVariant + cascaded translated SetNodeProperties, both
    # flagged as AI authorship.
    When the command PublishWorkspace is executed with payload:
      | Key                | Value                |
      | workspaceName      | "user-workspace"     |
      | newContentStreamId | "sync-source-cs-id2" |

    # The auto-sync writes the es variant to live *after* the publish forked user-workspace, so it lives only on live
    # now. Branch a fresh editor workspace off the synced live state to hand-edit it. es is a target dimension
    # (referenceLanguage en), so editing it records no stale rows and the publish triggers no auto-sync.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value                 |
      | workspaceName      | "es-editor-workspace" |
      | baseWorkspaceName  | "live"                |
      | newContentStreamId | "es-editor-cs-id"     |
    When I am in workspace "es-editor-workspace"
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                              |
      | nodeAggregateId           | "parent-doc"                                       |
      | originDimensionSpacePoint | {"language": "es"}                                 |
      | propertyValues            | {"autoTranslatableStringProperty": "Hand Crafted"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value                 |
      | workspaceName      | "es-editor-workspace" |
      | newContentStreamId | "es-editor-cs-id2"    |

    # cs-identifier event timeline:
    #   0   ContentStreamWasCreated (Background)
    #   1   RootNodeAggregateWithNodeWasCreated (Background)
    #   2   NodeAggregateWithNodeWasCreated (parent-doc en, replicated by publish #1) — editor
    #   3   NodePeerVariantWasCreated       (parent-doc en→es, auto-sync) — AI
    #   4   NodePropertiesWereSet           (parent-doc es translated, cascade) — AI
    #   5   NodePropertiesWereSet           (parent-doc es hand-edit, replicated by the es-editor-workspace publish) —
    #                                        editor
    Then I expect exactly 6 events to be published on stream "ContentStream:cs-identifier"

    # The replicated source creation is the editor's.
    And event at index 2 is of type "NodeAggregateWithNodeWasCreated" with payload:
      | Key             | Expected     |
      | nodeAggregateId | "parent-doc" |
    And event data at index 2 is:
      | Key                       | Expected                     |
      | metadata.initiatingUserId | "initiating-user-identifier" |

    # The auto-created es variant is attributed to the AI.
    And event at index 3 is of type "NodePeerVariantWasCreated" with payload:
      | Key             | Expected     |
      | nodeAggregateId | "parent-doc" |
    And event data at index 3 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |

    # The translation cascaded onto the es variant is attributed to the AI.
    And event at index 4 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected                 |
      | nodeAggregateId                                     | "parent-doc"             |
      | originDimensionSpacePoint                           | {"language": "es"}       |
      | propertyValues.autoTranslatableStringProperty.value | "Source Text translated" |
    And event data at index 4 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |

    # The manual es edit is attributed to the editor, not the AI.
    And event at index 5 is of type "NodePropertiesWereSet" with payload:
      | Key                                                 | Expected           |
      | nodeAggregateId                                     | "parent-doc"       |
      | originDimensionSpacePoint                           | {"language": "es"} |
      | propertyValues.autoTranslatableStringProperty.value | "Hand Crafted"     |
    And event data at index 5 is:
      | Key                       | Expected                     |
      | metadata.initiatingUserId | "initiating-user-identifier" |

  Scenario: Full sync translates the CURRENT live source into a forked review workspace, even when the target lagged (cross-workspace)
    # Cross-workspace full sync. `live` holds the en source; a `de-review` workspace forked from live is where the de
    # translation is prepared WITHOUT publishing de back to live. The target's en lagged behind live (forked, then live
    # changed). The synchronizer force-rebases de-review onto live first, so the CreateNodeVariant cascade — which reads
    # the target workspace's own en — translates live's CURRENT source, not the stale fork-time copy. `live` itself is
    # never modified.
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                          |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} |

    # Fork de-review while en == "My Text", THEN change live's en. de-review now lags behind live.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value             |
      | workspaceName      | "de-review"       |
      | baseWorkspaceName  | "live"            |
      | newContentStreamId | "de-review-cs-id" |
    When I am in workspace "live"
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                                                               |
      | nodeAggregateId           | "sir-david-nodenborough"                                                                            |
      | originDimensionSpacePoint | {"language": "en"}                                                                                  |
      | propertyValues            | {"inlineEditableStringProperty": "Updated Text", "autoTranslatableStringProperty": "Updated Other"} |

    When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"}

    # The de variant in de-review is translated from live's CURRENT en ("Updated …"), not the fork-time "My Text".
    Then I expect node "sir-david-nodenborough" in workspace "de-review" dimension space point {"language":"de"} to have property "inlineEditableStringProperty" with value "Updated Text translated"
    And I expect node "sir-david-nodenborough" in workspace "de-review" dimension space point {"language":"de"} to have property "autoTranslatableStringProperty" with value "Updated Other translated"

    # `live` is untouched: its en still reads "Updated …" and it has no de variant of its own.
    And I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"en"} to have property "inlineEditableStringProperty" with value "Updated Text"
    And I expect node "sir-david-nodenborough" to be absent in workspace "live" dimension space point {"language":"de"}

    # Stale state: live keeps its own de/es rows (the sync wrote into de-review, not live). de-review picked up copies
    # of live's rows during the force-rebase; the synced de row was then cleared by the translation, leaving only its
    # es row.
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | de-review     | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

  Scenario: Stale-driven sync creates a review-workspace variant for a node the target had never seen (cross-workspace)
    # Cross-workspace stale-driven sync where the source node did not exist in the target at all: de-review was forked
    # BEFORE the node was created in live. Without the force-rebase, dispatching CreateNodeVariant into de-review would
    # abort with "node aggregate does currently not exist". The synchronizer rebases de-review onto live first, which
    # materialises the node, so the variant can be created and translated.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value             |
      | workspaceName      | "de-review"       |
      | baseWorkspaceName  | "live"            |
      | newContentStreamId | "de-review-cs-id" |
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                          |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} |

    And I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    When I synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"}

    # The node now exists in de-review (rebased in) and its de variant is created + translated — no crash.
    Then I expect node "sir-david-nodenborough" in workspace "de-review" dimension space point {"language":"de"} to have property "inlineEditableStringProperty" with value "My Text translated"
    And I expect node "sir-david-nodenborough" in workspace "de-review" dimension space point {"language":"de"} to have property "autoTranslatableStringProperty" with value "My Other Text translated"

    # live keeps its own de/es stale rows; de-review keeps the rebase-copied es row (only de was synced).
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | de-review     | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

  Scenario: A cross-workspace dry run does not rebase the target
    # The rebase is a destructive, non-reversible mutation (new content stream, conflicting target edits dropped), so a
    # `--dry-run` — which promises to report only — must skip it. The other dry-run scenario is same-workspace, where
    # there is no rebase to skip, so this is the one that pins it.
    #
    # It also pins the mechanism the whole cross-workspace stale slice rests on. Every driver reads
    # `findByWorkspaceAndOrigin($target, …)`, and creating a workspace copies NO stale rows — de-review is forked before
    # the node exists in live, so it owns none. What fills them is the rebase: `WorkspaceWasRebased` makes the
    # projection replace the rebased workspace's rows with a copy of its base's. Hence the shape below — nothing while
    # un-rebased, the full translation once the real run rebases. Break the rebase or that projection handler and
    # cross-workspace sync silently stops seeing work.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value             |
      | workspaceName      | "de-review"       |
      | baseWorkspaceName  | "live"            |
      | newContentStreamId | "de-review-cs-id" |
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |

    # Only `live` has rows — creating a workspace copies none.
    And I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                       |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"]  |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"]  |

    When I dry-run synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"}
    # No rebase happened: de-review still owns no rows, and live's are untouched. The reported translation count is 0
    # for exactly that reason — a preview reads the target's slice, and un-rebased the target has nothing in it. This
    # is the documented cost of not rebasing: a cross-workspace preview under-reports against the real run below.
    Then the last synchronization reported 0 variant(s) and 0 property update(s)
    And I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                       |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"]  |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"]  |
    And I expect node "sir-david-nodenborough" to be absent in workspace "de-review" dimension space point {"language":"de"}

    # The contrast: the real run DOES rebase, which both materialises the node in de-review and copies live's rows
    # across — so the same slice, read the same way, now yields the variant.
    When I synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"}
    Then the last synchronization reported 1 variant(s) and 0 property update(s)
    And I expect node "sir-david-nodenborough" in workspace "de-review" dimension space point {"language":"de"} to have property "autoTranslatableStringProperty" with value "My Text translated"

  Scenario: Cross-workspace stale-driven sync must not prune the SOURCE workspace's stale rows
    # Cross-workspace sync (live/en → de-review/de) of a Document.Page with a tethered `main` collection. The
    # collection has no translatable properties, so its stale row is structural (empty property list). The document's
    # CreateNodeVariant cascade materialises the collection in de-review/de, after which the collection's own sync pass
    # is a no-op (nothing translatable to set). That no-op prune must remove the row from the TARGET workspace
    # (de-review) it is reconciling — NOT from `live`, which was never translated and still owes a de translation.
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                              | initialPropertyValues | tetheredDescendantNodeAggregateIds |
      | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page | {"title": "Home"}     | {"main": "page-home-main"}         |
    When the command CreateWorkspace is executed with payload:
      | Key                | Value             |
      | workspaceName      | "de-review"       |
      | baseWorkspaceName  | "live"            |
      | newContentStreamId | "de-review-cs-id" |
    And I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |
      | live          | {"language":"de"}         | page-home       | ["title"]     |
      | live          | {"language":"es"}         | page-home       | ["title"]     |
      | live          | {"language":"de"}         | page-home-main  | []            |
      | live          | {"language":"es"}         | page-home-main  | []            |

    When I synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"}

    # de-review got the translated de variant; its de rows are cleared, leaving the rebase-copied es rows.
    # CRUCIAL: `live` must retain ALL FOUR of its own rows — the sync wrote into de-review, not live.
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |
      | de-review     | {"language":"es"}         | page-home       | ["title"]     |
      | de-review     | {"language":"es"}         | page-home-main  | []            |
      | live          | {"language":"de"}         | page-home       | ["title"]     |
      | live          | {"language":"es"}         | page-home       | ["title"]     |
      | live          | {"language":"de"}         | page-home-main  | []            |
      | live          | {"language":"es"}         | page-home-main  | []            |

  Scenario: Full sync prunes a no-op stale row whose source properties were all unset
    # FullWorkspaceSynchronizer counterpart of the Retranslator no-op pruning: a stale node whose every translatable
    # source property is unset yields no SetNodeProperties (StalePropertyCommandBuilder returns null), so no
    # NodePropertiesWereSet ever clears its row. The full walk must prune the now-unsatisfiable row directly instead
    # of leaving it to re-no-op on every run.
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                          |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} |
    And I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # First full sync en→de creates + translates the de variant, clearing the de stale row.
    When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Unset every translatable source property on en — the de stale row reappears, but nothing is left to translate.
    When I am in workspace "live"
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                                          |
      | nodeAggregateId           | "sir-david-nodenborough"                                                       |
      | originDimensionSpacePoint | {"language": "en"}                                                             |
      | propertyValues            | {"inlineEditableStringProperty": null, "autoTranslatableStringProperty": null} |
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Full sync again en→de — the de variant exists and is stale, but every source property is unset, so the builder
    # produces no command. The de stale row must be pruned rather than lingering.
    When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    Then I expect exactly the following stale translations:
      | workspaceName | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live          | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

  Scenario: Auto-sync on publish prunes a no-op stale row whose source properties were all unset
    # SynchronizationCommandHook counterpart of the same no-op pruning: when the publish-driven auto-sync finds a
    # stale row whose target variant exists but whose source has nothing translatable left to set, no command is
    # emitted and no NodePropertiesWereSet clears the row. The hook must prune it directly so it does not re-no-op on
    # every subsequent publish.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                          |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} |

    # First publish: the live (en → es) rule creates + translates the es variant; live/es clears, the rest survive.
    When the command PublishWorkspace is executed with payload:
      | Key                | Value                |
      | workspaceName      | "user-workspace"     |
      | newContentStreamId | "sync-source-cs-id2" |
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    # Unset every translatable source property on en, then re-publish. replaceWorkspaceEntries copies the (still
    # listed) es row back onto live; the auto-sync hook then finds the existing es variant but nothing translatable
    # to set, so it must prune the live/es row rather than leave it dangling.
    When I am in workspace "user-workspace"
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                                          |
      | nodeAggregateId           | "sir-david-nodenborough"                                                       |
      | originDimensionSpacePoint | {"language": "en"}                                                             |
      | propertyValues            | {"inlineEditableStringProperty": null, "autoTranslatableStringProperty": null} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value                |
      | workspaceName      | "user-workspace"     |
      | newContentStreamId | "sync-source-cs-id3" |

    # live/es pruned by the hook; live/de survives (no rule covers de); user-workspace rows copied back as usual.
    Then I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
      | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
      | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

  Scenario: An `ask`-mode rule defers translation to a manual synchronization instead of running on publish
    # The (live, en) → (live, de) rule is configured with `mode: ask`, so publishing to `live` must NOT create or
    # translate the de variant; that is left to a manual synchronization — the same `WorkspaceSynchronizer` path the
    # post-publish "sync now" prompt and the backend module invoke. (Assertions are deliberately scoped to the de
    # variant so the scenario does not depend on what any other rule does on publish.)
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                          |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"} |

    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "ask-cs-id"      |

    # `ask` deferred it: publishing did not create the de variant.
    Then I expect node "sir-david-nodenborough" to be absent in workspace "live" dimension space point {"language":"de"}

    # A manual synchronization (what the post-publish "sync now" prompt and the backend module trigger) now creates and
    # translates the deferred de variant — proving the deferred path completes what the `ask` publish skipped.
    When I synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"de"} to have property "inlineEditableStringProperty" with value "My Text translated"
    And I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"de"} to have property "autoTranslatableStringProperty" with value "My Other Text translated"

  Scenario: Stale-mode sync into a not-yet-existing target is skipped instead of auto-creating it
    # WorkspaceSynchronizer (stale-driven) targeting a workspace that does not exist yet — e.g. a config rule whose
    # `targetWorkspaceName` "de-review" has never been created — must NOT materialise it. Creating workspaces is a
    # deliberate editor/admin action, so the sync short-circuits gracefully and the caller (CLI / Neos UI) surfaces an
    # error notification. The workspace stays absent.
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                          |
      | parent-doc      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Doc Text"} |

    # de-review does not exist yet — synchronizing must be skipped, not auto-create it.
    Then synchronizing translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"} is skipped because of "does not exist"
    And I expect workspace "de-review" to not exist

  Scenario: Full sync into a not-yet-existing target is skipped instead of auto-creating it
    # Same guarantee for FullWorkspaceSynchronizer (`synchronize --full`): a missing target workspace is reported as a
    # graceful skip rather than being created.
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                          |
      | parent-doc      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Doc Text"} |

    Then full-synchronizing translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review-full" dimension space point {"language":"de"} is skipped because of "does not exist"
    And I expect workspace "de-review-full" to not exist

  Scenario: Cross-workspace sync into a target not based on the source workspace is skipped
    # A cross-workspace synchronization requires the target workspace to be based on the source workspace (so the rebase
    # can bring it current with the source). `other-user-workspace` exists but is based on `live`, not on the requested
    # source `user-workspace`, so the sync short-circuits with a clear error instead of dispatching against a target it
    # cannot reconcile with the source.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                          |
      | parent-doc      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Doc Text"} |

    Then synchronizing translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "other-user-workspace" dimension space point {"language":"de"} is skipped because of "must be based on source workspace"

  Scenario: Publishing the source force-rebases the target onto it, conflicting target edits are dropped (source wins)
    # When the source workspace (live) is published, the cross-workspace rule force-rebases the target (content-review)
    # onto it. Non-conflicting target review edits are preserved by the replay, but a genuine conflict drops the target
    # change — the published source wins. Here the target edited a node that the source removed: the target's edit
    # cannot replay onto a base without that node, so it is dropped and the source's removal wins.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                          |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Original"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    # content-review forks from live with sir-david present.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value                  |
      | workspaceName      | "content-review"       |
      | baseWorkspaceName  | "live"                 |
      | newContentStreamId | "content-review-cs-id" |
    # Target edits the node ...
    When I am in workspace "content-review"
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                             |
      | nodeAggregateId           | "sir-david-nodenborough"                          |
      | originDimensionSpacePoint | {"language": "en"}                                |
      | propertyValues            | {"autoTranslatableStringProperty": "Review edit"} |
    # ... while the source removes it, then publishes.
    When I am in workspace "user-workspace"
    And the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allVariants"            |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |
    # The force-rebase cannot replay the target's edit onto a base where the node is gone — the conflicting target
    # change is dropped, the source's removal wins.
    Then I expect node "sir-david-nodenborough" to be absent in workspace "content-review" dimension space point {"language":"en"}

  Scenario: Publishing the source refreshes a cross-workspace rule's status, measured on the target
    # The backend module's "out of sync" count for a cross-workspace rule is measured on the TARGET (de-review), not the
    # source (live). Publishing the source force-rebases de-review onto live — even for this `ask` rule — so de-review's
    # status immediately reflects the pending translation (rebase-on-publish), without auto-translating it. A manual
    # sync then clears de-review's own rows and the count drops to zero; counting live's own (never-translated) de rows
    # would instead report the rule as out of sync forever.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value             |
      | workspaceName      | "de-review"       |
      | baseWorkspaceName  | "live"            |
      | newContentStreamId | "de-review-cs-id" |
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    # Publishing rebased de-review onto live, so its de translation shows as pending — the `ask` rule did not translate.
    Then the out-of-sync count from workspace "live" dimension "en" to workspace "de-review" dimension "de" is 1
    # Synchronizing into de-review translates the node and clears its row; the count drops to zero (live's own lingering
    # de row is never counted).
    When I synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"}
    Then I expect node "sir-david-nodenborough" in workspace "de-review" dimension space point {"language":"de"} to have property "autoTranslatableStringProperty" with value "My Text translated"
    And the out-of-sync count from workspace "live" dimension "en" to workspace "de-review" dimension "de" is 0

  Scenario: Hard-removing a content node in the source language removes its target-language variant on publish
    # A HARD removal (`RemoveNodeAggregate`) is not the editor's delete path — Neos 9.1 soft-removes, see the
    # soft-removal scenarios below — but a fixture or a script can issue one, and a publish carries it. Publishing
    # auto-creates the es variant; hard-removing the EN (source) variant and re-publishing makes the hook mirror the
    # deletion into es from this publish's own NodeAggregateWasRemoved event. Mirroring is unconditional (no rule flag).
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "My Text translated"
    # Remove only the EN (source) variant, then publish.
    When I am in workspace "user-workspace"
    And the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |
    Then I expect node "sir-david-nodenborough" to be absent in workspace "live" dimension space point {"language":"en"}
    And I expect node "sir-david-nodenborough" to be absent in workspace "live" dimension space point {"language":"es"}

  Scenario: Hard-removing a Document in the source language removes the target-language Document on publish
    # Not gated by `scope`: the scope governs what synchronization CREATES, so a Document removed in the source is
    # removed in the target under either scope.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                      |
      | home            | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:DocumentWithAutomaticTranslation | {"autoTranslatableStringProperty": "Home"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    Then I expect node "home" in workspace "live" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "Home translated"
    When I am in workspace "user-workspace"
    And the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                |
      | workspaceName                | "user-workspace"     |
      | nodeAggregateId              | "home"               |
      | coveredDimensionSpacePoint   | {"language":"en"}    |
      | nodeVariantSelectionStrategy | "allSpecializations" |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |
    # Document scope mirrors the Document deletion into es.
    Then I expect node "home" to be absent in workspace "live" dimension space point {"language":"es"}

  Scenario: Deleting a node in the source language mirrors the soft removal into the target on publish
    # THE editor delete path. Deleting a node in a workspace soft-removes it (a `removed` subtree tag) rather than
    # issuing a hard RemoveNodeAggregate, which Neos considers undesirable outside `live`. Publishing carries the
    # SubtreeWasTagged event, so the deletion reaches the target dimension through the TAG mirror — no separate removal
    # feature involved. Neos's own SoftRemovalGarbageCollector later turns each dimension's soft removal into a hard one.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "My Text translated"
    And I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to not be tagged "removed"
    # Delete the EN (source) variant the way an editor does, then publish.
    When I am in workspace "user-workspace"
    And the node "sir-david-nodenborough" is deleted in workspace "user-workspace" dimension space point {"language":"en"}
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |
    # The es variant is soft-removed too.
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to be tagged "removed"
    # Restoring the source from the trash bin mirrors back the other way.
    When I am in workspace "user-workspace"
    And the node "sir-david-nodenborough" is restored in workspace "user-workspace" dimension space point {"language":"en"}
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-4"   |
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to not be tagged "removed"

  Scenario: An `ask` rule mirrors a deletion on publish and defers only the translation
    # `mode` decides WHEN the translation runs, never what synchronization does. The `live → live/de` rule is `ask`:
    # publishing does not translate into de, but tag mirroring — and therefore deletion — is cheap and happens inline for
    # both modes. Deferring it would leave an `ask` rule with pending work nothing prompts for, since the out-of-sync
    # status counts stale-translation rows and a deletion produces none.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    # The `ask` rule did NOT translate into de on publish — that is the one thing the mode defers.
    Then I expect node "sir-david-nodenborough" to be absent in workspace "live" dimension space point {"language":"de"}
    # Translate the de variant via the deliberate "sync now".
    When I synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"de"} to have property "autoTranslatableStringProperty" with value "My Text translated"
    # Now delete the source and publish. Same-workspace, so no force-rebase can drop the de peer behind the scenes: the
    # tag mirror is the only thing that can act, and it does — inline, despite `mode: ask`.
    When I am in workspace "user-workspace"
    And the node "sir-david-nodenborough" is deleted in workspace "user-workspace" dimension space point {"language":"en"}
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"de"} to be tagged "removed"
    And I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to be tagged "removed"

  Scenario: A deleted source node stops counting as out of sync
    # The projection deliberately ignores tag events, so deleting a node leaves its stale rows behind — and no driver will
    # ever satisfy them, because a deleted node is not a translation source. If the status still counted them the backend
    # module would read "out of sync" forever and the post-publish prompt would keep asking for work that cannot complete.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                        |
      | doomed          | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Doomed"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    # The `ask` rule into de left a stale row behind, so de is out of sync by one node.
    Then the out-of-sync count from workspace "live" dimension "en" to workspace "live" dimension "de" is 1
    # Delete the source node and publish. The row survives (the projection does not react to tag events) but is no longer
    # counted, so nothing keeps prompting for it.
    When I am in workspace "user-workspace"
    And the node "doomed" is deleted in workspace "user-workspace" dimension space point {"language":"en"}
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |
    Then the out-of-sync count from workspace "live" dimension "en" to workspace "live" dimension "de" is 0
    # Restoring it from the trash bin brings the pending translation back — which is why the row is filtered at read time
    # rather than pruned from the projection.
    When I am in workspace "user-workspace"
    And the node "doomed" is restored in workspace "user-workspace" dimension space point {"language":"en"}
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-4"   |
    Then the out-of-sync count from workspace "live" dimension "en" to workspace "live" dimension "de" is 1

  Scenario: A soft-removed source node is not translated — on any driver
    # A soft-removed source node is a DELETED node, so it must never be a translation source. All three drivers read the
    # source subgraph with `excludeRemoved()` for exactly this reason; if one of them did not, an editor's deletion would
    # keep producing translations.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                       |
      | doomed          | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Doomed"} |
    # Delete it before anything ever translated it.
    And the node "doomed" is deleted in workspace "user-workspace" dimension space point {"language":"en"}
    # Neither the stale-driven "sync now" nor the full walk creates an es variant for it.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then I expect node "doomed" to be absent in workspace "user-workspace" dimension space point {"language":"es"}
    When I full-synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then I expect node "doomed" to be absent in workspace "user-workspace" dimension space point {"language":"es"}

  Scenario: "Sync now" honours Content scope and never creates a Document variant
    # `scope` has exactly one job — whether synchronization may CREATE Document variants — and every driver has to apply
    # it. The publish hook always did; the deliberate "sync now" run did not, so clicking it created the very Documents
    # `Content` scope exists to keep out.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                       | tetheredDescendantNodeAggregateIds |
      | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                           | {"main": "page-home-main"}         |
      | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Intro"} |                                    |
    # Content scope: the Document is absent from the target dimension, so neither it nor the content inside it is created.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"} with scope "Content"
    Then I expect node "page-home" to be absent in workspace "user-workspace" dimension space point {"language":"es"}
    And I expect node "intro-text" to be absent in workspace "user-workspace" dimension space point {"language":"es"}
    # Document scope creates the whole structure, which is what makes the contrast deliberate.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"} with scope "Document"
    Then I expect node "page-home" in workspace "user-workspace" dimension space point {"language":"es"} to have property "title" with value "Home translated"
    And I expect node "intro-text" in workspace "user-workspace" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "Intro translated"

  Scenario: Hard-removing a Document and a node inside it in the same publish mirrors as a single subtree removal
    # Guards the auto-path removal dedup. The CR emits a standalone NodeAggregateWasRemoved for EACH explicitly removed
    # aggregate (never for cascade-removed children), so a parent + descendant removed in one publish would otherwise
    # emit two RemoveNodeAggregate commands — and the descendant's command would target a node the parent's cascade
    # already deleted (NodeAggregateCurrentlyDoesNotExist) once the parent removal is dispatched first (e.g. when the
    # target hierarchy diverges from the source). Only the subtree-root removal must be emitted; the CR cascades the rest.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                       | tetheredDescendantNodeAggregateIds |
      | page-home       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Document.Page                            | {"title": "Home"}                           | {"main": "page-home-main"}         |
      | intro-text      | page-home-main         | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Intro"} |                                    |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    # es variants created by the Document-scope rule.
    Then I expect node "page-home" in workspace "live" dimension space point {"language":"es"} to have property "title" with value "Home translated"
    And I expect node "intro-text" in workspace "live" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "Intro translated"
    # Remove the child first then the parent (the only order the SOURCE permits — a parent removal cascades the child,
    # so the child cannot be removed afterwards), then publish both removals together.
    When I am in workspace "user-workspace"
    And the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                |
      | workspaceName                | "user-workspace"     |
      | nodeAggregateId              | "intro-text"         |
      | coveredDimensionSpacePoint   | {"language":"en"}    |
      | nodeVariantSelectionStrategy | "allSpecializations" |
    And the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                |
      | workspaceName                | "user-workspace"     |
      | nodeAggregateId              | "page-home"          |
      | coveredDimensionSpacePoint   | {"language":"en"}    |
      | nodeVariantSelectionStrategy | "allSpecializations" |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |
    # The publish completes without a NodeAggregateCurrentlyDoesNotExist abort and the whole es subtree is gone.
    Then I expect node "page-home" to be absent in workspace "live" dimension space point {"language":"es"}
    And I expect node "intro-text" to be absent in workspace "live" dimension space point {"language":"es"}

  Scenario: Hiding then showing a node in the source language mirrors the subtree tag into the target (auto, sync-to-target)
    # The `live → live/es` rule is `onSourceTagging: sync-to-target`. Publishing auto-creates the es variant; tagging the
    # EN variant "disabled" (the hide/show tag) and re-publishing mirrors the tag onto the es variant from this publish's
    # own SubtreeWasTagged event. Untagging mirrors back the other way via SubtreeWasUntagged.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    # es variant created and initially NOT tagged.
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to not be tagged "disabled"
    # Hide the EN (source) variant, then publish.
    When I am in workspace "user-workspace"
    And the command TagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "disabled"               |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-3"   |
    # The hide is mirrored onto the es variant.
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to be tagged "disabled"
    # Show the EN (source) variant again, then publish.
    When I am in workspace "user-workspace"
    And the command UntagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "disabled"               |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-4"   |
    # The show is mirrored back onto the es variant.
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to not be tagged "disabled"

  Scenario: Manual sync reconciles subtree tags by diffing source vs target — for the disabled tag AND arbitrary tags
    # The deliberate "sync now" diff path (WorkspaceSynchronizer) converges each target node's explicit subtree tags
    # onto the source. Mirrors ANY tag, not just `disabled`. Tag the EN source BEFORE the es variant even exists, so the
    # tags are applied to the variant the same run creates it (the create-and-tag case the publish hook cannot cover).
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    And the command TagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "disabled"               |
    And the command TagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "needs-review"           |
    # Manual sync creates the es variant AND reconciles BOTH tags onto it.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to be tagged "disabled"
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to be tagged "needs-review"
    # Remove only the `disabled` tag on the source, then re-sync: the diff drops `disabled` on the target but keeps the
    # custom `needs-review` tag.
    When the command UntagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "disabled"               |
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to not be tagged "disabled"
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to be tagged "needs-review"

  Scenario: A node created AND hidden in the same publish is hidden in the target (auto, sync-to-target)
    # Create + tag the EN source, then publish both in ONE go. The es variant does not exist when the hook builds its
    # batch, but its CreateNodeVariant is dispatched before the mirrored TagSubtree, so the es variant ends up hidden.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    And the command TagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "disabled"               |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    # The es variant is created, translated, AND hidden — all in this single publish.
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "My Text translated"
    And I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to be tagged "disabled"

  Scenario: A manual sync counts a mirrored deletion as a removal, not as a tag change
    # Guards the counts the "sync now" UI / backend module surface (WorkspaceSynchronizationResult totals). Both are tag
    # commands underneath, but the `removed` tag IS the deletion, so reporting it as "1 tag change" would tell an editor
    # who deleted a page something they would not recognise.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                       |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Gone"}  |
      | nody-mc-nodeface       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Stays"} |
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    # Delete one source node and hide the other.
    When the node "sir-david-nodenborough" is deleted in workspace "user-workspace" dimension space point {"language":"en"}
    And the command TagSubtree is executed with payload:
      | Key                          | Value                |
      | workspaceName                | "user-workspace"     |
      | nodeAggregateId              | "nody-mc-nodeface"   |
      | coveredDimensionSpacePoint   | {"language":"en"}    |
      | nodeVariantSelectionStrategy | "allSpecializations" |
      | tag                          | "disabled"           |
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then the last synchronization reported 1 removal(s) and 1 tag change(s)
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to be tagged "removed"
    And I expect node "nody-mc-nodeface" in workspace "user-workspace" dimension space point {"language":"es"} to be tagged "disabled"

  Scenario: A dry-run manual sync previews removals and tag changes without dispatching them
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                       |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Gone"}  |
      | nody-mc-nodeface       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Stays"} |
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    When the node "sir-david-nodenborough" is deleted in workspace "user-workspace" dimension space point {"language":"en"}
    And the command TagSubtree is executed with payload:
      | Key                          | Value                |
      | workspaceName                | "user-workspace"     |
      | nodeAggregateId              | "nody-mc-nodeface"   |
      | coveredDimensionSpacePoint   | {"language":"en"}    |
      | nodeVariantSelectionStrategy | "allSpecializations" |
      | tag                          | "disabled"           |
    When I dry-run synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    # The preview reports what WOULD happen ...
    Then the last synchronization reported 1 removal(s) and 1 tag change(s)
    # ... but nothing was dispatched: neither the deletion nor the hide reached the target.
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to not be tagged "removed"
    And I expect node "nody-mc-nodeface" in workspace "user-workspace" dimension space point {"language":"es"} to not be tagged "disabled"

  Scenario: A dry run previews the variants it would create
    # `--dry-run` exists to answer "how much work — and how many DeepL calls — is pending" before committing to it.
    # It used to answer 0 to everything translation-related, because both synchronizers replaced the per-node result
    # with a "dry-run" skip before deciding anything, while still reporting removals and tag changes correctly.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                         | tetheredDescendantNodeAggregateIds |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} | {"tethered": "nodewyn-tetherton"}  |

    When I reset the translation counter
    And I dry-run synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    # One variant: the tethered child has a stale row of its own but is never counted, because it materialises with
    # its ancestor's CreateNodeVariant rather than through a command of its own.
    Then the last synchronization reported 1 variant(s) and 0 property update(s)
    # Nothing was dispatched, nothing was pruned, and — the point of the flag — nothing was sent to DeepL.
    And the translation service translated 0 text(s)
    And I expect node "sir-david-nodenborough" to be absent in workspace "user-workspace" dimension space point {"language":"es"}
    And I expect exactly the following stale translations:
      | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                      |
      | user-workspace | {"language":"de"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"es"}         | nodewyn-tetherton      | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |
      | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["autoTranslatableStringProperty"] |

    # The contrast that makes the preview worth trusting: the real run reports the same counts.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then the last synchronization reported 1 variant(s) and 0 property update(s)
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "My Text translated"

  Scenario: A dry run counts each pending node once, not once per stale record above it
    # The stale-driven run walks a subtree per stale record, and a record nested inside an already-translated subtree
    # comes back a no-op because dispatching cleared its row. A preview dispatches nothing, so it clears nothing —
    # without deduplicating, the tethered child below would be counted twice: once inside its parent's walk and once
    # for its own record.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                         | tetheredDescendantNodeAggregateIds |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} | {"tethered": "nodewyn-tetherton"}  |
    And I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}

    # Both es variants now exist. Make both nodes stale again by editing the source.
    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                              |
      | nodeAggregateId           | "sir-david-nodenborough"                           |
      | originDimensionSpacePoint | {"language": "en"}                                 |
      | propertyValues            | {"autoTranslatableStringProperty": "Updated Text"} |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                |
      | nodeAggregateId           | "nodewyn-tetherton"                                  |
      | originDimensionSpacePoint | {"language": "en"}                                   |
      | propertyValues            | {"autoTranslatableStringProperty": "Updated Nested"} |

    When I reset the translation counter
    And I dry-run synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    # Two nodes are pending, so two property updates — not three.
    Then the last synchronization reported 0 variant(s) and 2 property update(s)
    And the translation service translated 0 text(s)
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "My Text translated"

    # And the real run agrees, node for node.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then the last synchronization reported 0 variant(s) and 2 property update(s)
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "Updated Text translated"
    And I expect node "nodewyn-tetherton" in workspace "user-workspace" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "Updated Nested translated"

  Scenario: A full-sync dry run previews every node it would touch without translating any of them
    # `--full --dry-run` is the expensive one to get wrong: it decides a command per node in the whole source
    # dimension, and building a translated SetNodeProperties IS the DeepL call. Deciding and then discarding would
    # cost exactly as much as the run it is supposed to estimate.
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                | tetheredDescendantNodeAggregateIds |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation     | {"autoTranslatableStringProperty": "My Text"}        | {"tethered": "nodewyn-tetherton"}  |
      | nody-mc-nodeface       | nodewyn-tetherton      | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "Nested Text"}    |                                    |

    When I reset the translation counter
    And I dry-run full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    # Two non-tethered nodes get a variant; the tethered child rides along with its ancestor's cascade.
    Then the last synchronization reported 2 variant(s) and 0 property update(s)
    And the translation service translated 0 text(s)
    And I expect node "sir-david-nodenborough" to be absent in workspace "live" dimension space point {"language":"de"}

    # The real run agrees on the variants but reports one property update the preview cannot foresee: dispatching
    # sir-david's CreateNodeVariant cascades the tethered `nodewyn-tetherton` into de mid-walk, and the walk then
    # reaches it as an existing variant and re-translates it. A preview dispatches nothing, so that node is still
    # absent (and tethered) when it comes past. The gap is inherent to previewing a run that reacts to its own
    # writes, and it is bounded by the cascade — which the variant count already stands for, since cascaded
    # translations are never counted individually either.
    When I full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    Then the last synchronization reported 2 variant(s) and 1 property update(s)
    And I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"de"} to have property "autoTranslatableStringProperty" with value "My Text translated"

    # Now every de variant exists, so a second full sync re-translates them all instead of creating anything. The
    # preview must say so — and still not translate.
    When I reset the translation counter
    And I dry-run full-synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}
    Then the last synchronization reported 0 variant(s) and 3 property update(s)
    And the translation service translated 0 text(s)

  Scenario: Re-running a tag sync is an idempotent no-op
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    And the command TagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "user-workspace"         |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "disabled"               |
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then the last synchronization reported 0 removal(s) and 1 tag change(s)
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to be tagged "disabled"
    # Second run: the target already matches the source, so the diff yields nothing — no error, no commands.
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then the last synchronization reported 0 removal(s) and 0 tag change(s)
    And I expect node "sir-david-nodenborough" in workspace "user-workspace" dimension space point {"language":"es"} to be tagged "disabled"

  Scenario: The tag reconcile never touches a node that exists only in the target dimension
    # The tag reconcile only converges nodes present in BOTH dimensions, which is what keeps an editor-owned target-only
    # page safe. This is structural, not a scope rule: a target-only node has no source tag state to converge onto, so
    # there is nothing to mirror. It is also why replacing the old orphan-diff with the tag mirror removed a whole class
    # of accidental deletion — a diff of "present in target, absent in source" cannot tell "deleted in the source" from
    # "never in the source", and under Content scope the latter is the expected case.
    When I am in workspace "user-workspace"
    # An es-only page with es-only content: no en source anywhere.
    And the command CreateNodeAggregateWithNode is executed with payload:
      | Key                                | Value                                       |
      | workspaceName                      | "user-workspace"                            |
      | nodeAggregateId                    | "es-only-page"                              |
      | originDimensionSpacePoint          | {"language":"es"}                           |
      | parentNodeAggregateId              | "lady-eleonode-rootford"                    |
      | nodeTypeName                       | "Sitegeist.LostInTranslation.Document.Page" |
      | initialPropertyValues              | {"title": "Sólo en español"}                |
      | tetheredDescendantNodeAggregateIds | {"main": "es-only-page-main"}               |
    And the command CreateNodeAggregateWithNode is executed with payload:
      | Key                       | Value                                                                  |
      | workspaceName             | "user-workspace"                                                       |
      | nodeAggregateId           | "es-only-text"                                                         |
      | originDimensionSpacePoint | {"language":"es"}                                                      |
      | parentNodeAggregateId     | "es-only-page-main"                                                    |
      | nodeTypeName              | "Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation" |
      | initialPropertyValues     | {"autoTranslatableStringProperty": "Contenido propio"}                 |
    # Even hiding the page in the target survives: without a source counterpart there is nothing to converge onto.
    And the command TagSubtree is executed with payload:
      | Key                          | Value                |
      | workspaceName                | "user-workspace"     |
      | nodeAggregateId              | "es-only-page"       |
      | coveredDimensionSpacePoint   | {"language":"es"}    |
      | nodeVariantSelectionStrategy | "allSpecializations" |
      | tag                          | "disabled"           |
    When I synchronize translations from workspace "user-workspace" dimension space point {"language":"en"} to workspace "user-workspace" dimension space point {"language":"es"}
    Then the last synchronization reported 0 removal(s) and 0 tag change(s)
    And I expect node "es-only-page" in workspace "user-workspace" dimension space point {"language":"es"} to have property "title" with value "Sólo en español"
    And I expect node "es-only-text" in workspace "user-workspace" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "Contenido propio"
    And I expect node "es-only-page" in workspace "user-workspace" dimension space point {"language":"es"} to be tagged "disabled"

  Scenario: A tag applied directly to the translation is reverted — the source owns the target's tags
    # The source language is the single source of truth for the target's tag state, so hiding (or deleting) only the
    # translation is a target-side change and gets overwritten. Both drivers do it: the publish hook converges a tag event
    # that touched only the TARGET dimension — the event is already in the publish delta, so no tree walk is needed — and
    # the deliberate "sync now" diff reaches the same state. That agreement is the point: `ask` and `auto` must not differ
    # in the result. Independent target-side visibility is not supported; there is no per-rule opt-out any more.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "user-cs-id-2"   |
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to have property "autoTranslatableStringProperty" with value "My Text translated"
    # To edit the auto-synced es variant an editor needs a workspace branched off `live` AFTER the sync wrote it —
    # `user-workspace` forked before, so the es variant does not exist there at all.
    When the command CreateWorkspace is executed with payload:
      | Key                | Value              |
      | workspaceName      | "es-editor"        |
      | baseWorkspaceName  | "live"             |
      | newContentStreamId | "es-editor-cs-id"  |
    # Hide ONLY the es translation, then publish. The publish carries a tag event whose affected dimension is es, not en.
    When the command TagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "es-editor"              |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"es"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "disabled"               |
    When the command PublishWorkspace is executed with payload:
      | Key                | Value             |
      | workspaceName      | "es-editor"       |
      | newContentStreamId | "es-editor-cs-id-2" |
    # The source has no `disabled` tag, so the target is converged back onto it.
    Then I expect node "sir-david-nodenborough" in workspace "live" dimension space point {"language":"es"} to not be tagged "disabled"

  Scenario: Cross-workspace manual sync mirrors a subtree tag into the review workspace
    When the command CreateWorkspace is executed with payload:
      | Key                | Value             |
      | workspaceName      | "de-review"       |
      | baseWorkspaceName  | "live"            |
      | newContentStreamId | "de-review-cs-id" |
    When I am in workspace "live"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                         |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"autoTranslatableStringProperty": "My Text"} |
    # Translate the de variant into de-review first.
    When I synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"}
    Then I expect node "sir-david-nodenborough" in workspace "de-review" dimension space point {"language":"de"} to have property "autoTranslatableStringProperty" with value "My Text translated"
    # Hide the source in live, then reconcile tags cross-workspace into de-review.
    When the command TagSubtree is executed with payload:
      | Key                          | Value                    |
      | workspaceName                | "live"                   |
      | nodeAggregateId              | "sir-david-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"en"}        |
      | nodeVariantSelectionStrategy | "allSpecializations"     |
      | tag                          | "disabled"               |
    When I synchronize translations from workspace "live" dimension space point {"language":"en"} to workspace "de-review" dimension space point {"language":"de"}
    Then I expect node "sir-david-nodenborough" in workspace "de-review" dimension space point {"language":"de"} to be tagged "disabled"
