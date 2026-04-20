@contentrepository
Feature: Track the staleness state of translations and run retranslation on stale translations

  Background:
    Given using the following content dimensions:
      | Identifier | Values | Generalizations |
      | language   | en, de |                 |
    And using the following reference languages:
      | Language | ReferenceLanguage |
      | de       | en                |
    And using the following node types:
    """yaml
    'Neos.ContentRepository:Root': []
    'Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation':
      superTypes:
        'Neos.Neos:Node': true
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
    'Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation':
      superTypes:
        'Neos.Neos:Node': true
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
      | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                                                         | initialPropertyValues                                                                                                | nodeAggregateIdsByNodePaths       |
      | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation     | {"inlineEditableStringProperty": "My Text", "autoTranslatableStringProperty": "My Other Text"}                       | {"tethered": "nodewyn-tetherton"} |
      | nody-mc-nodeface       | nodewyn-tetherton      | Sitegeist.LostInTranslation.Testing:LeafNodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Grandchild Text", "autoTranslatableStringProperty": "My Other Grandchild Text"} | {}                                |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                                                                      |
      | nodeAggregateId           | "nody-mc-nodeface"                                                                                         |
      | originDimensionSpacePoint | {"language": "en"}                                                                                         |
      | propertyValues            | {"inlineEditableStringProperty": "My Child Text", "autoTranslatableStringProperty": "My Other Child Text"} |

    When the command CreateNodeVariant is executed with payload:
      | Key             | Value              |
      | nodeAggregateId | "nody-mc-nodeface" |
      | sourceOrigin    | {"language":"en"}  |
      | targetOrigin    | {"language":"de"}  |

    Then I expect exactly 3 events to be published on stream "ContentStream:user-cs-id"
    And event at index 1 is of type "NodePeerVariantWasCreated" with payload:
      | Key | Expected |
    And event metadata at index 1 is:
      | Key              | Expected                     |
      | initiatingUserId | "initiating-user-identifier" |
    And event at index 2 is of type "NodePropertiesWereSet" with payload:
      | Key | Expected |
    And event metadata at index 2 is:
      | Key              | Expected            |
      | initiatingUserId | "AI:dummy:my-dummy" |
    When I am in dimension space point {"language": "de"}
    Then I expect node aggregate identifier "nody-mc-nodeface" to lead to node user-cs-id;nody-mc-nodeface;{"language":"de"}
    And I expect this node to have the following properties:
      | Key                          | Value                |
      | inlineEditableStringProperty | "My Text translated" |

    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                               |
      | nodeAggregateId           | "nody-mc-nodeface"                                                  |
      | originDimensionSpacePoint | {"language":"de"}                                                   |
      | propertyValues            | {"inlineEditableStringProperty": "My Text translated and adjusted"} |
    Then I expect exactly 4 events to be published on stream "ContentStream:user-cs-id"
    And event at index 3 is of type "NodePropertiesWereSet" with payload:
      | Key | Expected |
    And event metadata at index 3 is:
      | Key              | Expected                     |
      | initiatingUserId | "initiating-user-identifier" |

    When I am in dimension space point {"language": "de"}
    Then I expect node aggregate identifier "nody-mc-nodeface" to lead to node user-cs-id;nody-mc-nodeface;{"language":"de"}
    And I expect this node to have the following properties:
      | Key                          | Value                             |
      | inlineEditableStringProperty | "My Text translated and adjusted" |

    When the command PublishWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "new-user-cs-id" |

    Then I expect exactly 6 events to be published on stream "ContentStream:cs-identifier"
    And event at index 4 is of type "NodePropertiesWereSet" with payload:
      | Key | Expected |
    And event metadata at index 4 is:
      | Key              | Expected            |
      | initiatingUserId | "AI:dummy:my-dummy" |
    And event at index 5 is of type "NodePropertiesWereSet" with payload:
      | Key | Expected |
    And event metadata at index 5 is:
      | Key              | Expected                     |
      | initiatingUserId | "initiating-user-identifier" |
