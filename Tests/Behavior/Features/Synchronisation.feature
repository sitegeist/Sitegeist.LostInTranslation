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
        # StalePropertyCommandBuilder.
        #
        # (Scenario title used to say "DE" but the test rule covers en → es, so we test the es
        # variant. Adding a de rule would require two rules and complicate the unrelated bits.)
        When I am in workspace "user-workspace"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                  | initialPropertyValues                       |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations | {"inlineEditableStringProperty": "My Text"} |

        # First publish: the es variant is freshly created and translated by the auto-sync.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id2" |
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                    |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |

        # Now mutate the en source and re-publish. The auto-sync hook should detect the fresh
        # `(live, sir-david, es)` stale row, see that the es variant already exists, and emit a
        # translated SetNodeProperties (no NodePeerVariantWasCreated this time).
        When I am in workspace "user-workspace"
        And the command SetNodeProperties is executed with payload:
            | Key                       | Value                                            |
            | nodeAggregateId           | "sir-david-nodenborough"                         |
            | originDimensionSpacePoint | {"language": "en"}                               |
            | propertyValues            | {"inlineEditableStringProperty": "Updated Text"} |
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id3" |

        # live/es stale cleared again by auto-sync; live/de remains (no rule covers it).
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                    |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |

        # cs-identifier event timeline:
        #   0 ContentStreamWasCreated (Background)
        #   1 RootNodeAggregateWithNodeWasCreated (Background)
        #   2 NodeAggregateWithNodeWasCreated (sir-david en, replicated by first publish)
        #   3 NodePeerVariantWasCreated   (sir-david en→es, auto-sync, first publish)
        #   4 NodePropertiesWereSet       (sir-david es "My Text translated", cascade)
        #   5 NodePropertiesWereSet       (sir-david en "Updated Text", replicated by second publish)
        #   6 NodePropertiesWereSet       (sir-david es "Updated Text translated", auto-sync builds it directly — NO NodePeerVariantWasCreated)
        And I expect exactly 7 events to be published on stream "ContentStream:cs-identifier"
        And event at index 6 is of type "NodePropertiesWereSet" with payload:
            | Key                                               | Expected                  |
            | workspaceName                                     | "live"                    |
            | contentStreamId                                   | "cs-identifier"           |
            | nodeAggregateId                                   | "sir-david-nodenborough"  |
            | originDimensionSpacePoint                         | {"language": "es"}        |
            | propertyValues.inlineEditableStringProperty.value | "Updated Text translated" |

    Scenario: Publishing from a workspace not covered by any rule does not trigger sync
        # The configured rule targets `sourceWorkspaceName: live`. Publishing onto a *different*
        # base workspace must therefore leave stale rows untouched: no auto-sync hook activity at
        # all on that workspace's content stream.
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
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                  | initialPropertyValues                       |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations | {"inlineEditableStringProperty": "My Text"} |

        # Publish onto `preview` — NOT live — so no rule matches and the auto-sync hook short-circuits.
        When the command PublishWorkspace is executed with payload:
            | Key                | Value            |
            | workspaceName      | "user-workspace" |
            | newContentStreamId | "user-cs-id-new" |

        # Both de and es stale rows survive on preview AND user-workspace (replaceWorkspaceEntries
        # copies preview→user-workspace after publish).
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                    |
            | preview        | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |
            | preview        | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty"] |

        # preview-cs-id timeline (no auto-sync cascade):
        #   0 ContentStreamWasForked (CreateWorkspace preview)
        #   1 NodeAggregateWithNodeWasCreated (sir-david en, replicated by publish)
        # No NodePeerVariantWasCreated, no translated NodePropertiesWereSet — proving the hook
        # did not fire for the uncovered workspace.
        And I expect exactly 2 events to be published on stream "ContentStream:preview-cs-id"
