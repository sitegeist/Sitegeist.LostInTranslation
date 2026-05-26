@contentrepository
Feature: Automatic retranslation on workspace publish
    # This test needs the `synchronization` config in

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

        # Stale rows recorded for both de and es
        And I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"es"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

        # Publish
        When the command PublishWorkspace is executed with payload:
            | Key                | Value                |
            | workspaceName      | "user-workspace"     |
            | newContentStreamId | "sync-source-cs-id2" |

        # ES should be auto-synced to es_live while de should record stale translations for user-workspace and live.
        # TODO: what happens to es_user-workspace? for now it should be untouched
        Then I expect exactly the following stale translations:
            | workspaceName  | originDimensionSpacePoint | nodeAggregateId        | propertyNames                                                     |
            | user-workspace | {"language":"en"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | user-workspace | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |
            | live           | {"language":"de"}         | sir-david-nodenborough | ["inlineEditableStringProperty","autoTranslatableStringProperty"] |

    Scenario: Publishing a property update to en,live auto-syncs the existing DE variant
        # TODO
    Scenario: Publishing from a workspace not covered by any rule does not trigger sync
        # TODO
