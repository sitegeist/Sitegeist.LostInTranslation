@contentrepository
Feature: Create node variant and let the AI translate the properties

  Background:
    Given using the following content dimensions:
      | Identifier | Values | Generalizations |
      | language   | en, de |                 |
    And using the following node types:
    """yaml
    'Neos.ContentRepository:Root': []
    # Because we build our own test CR from scratch we also need to define this NodeType because we do not read any NodeType definitions
    'Neos.Neos:Node':
      abstract: true
      options:
       automaticTranslation: true
    'Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation':
      superTypes:
        'Neos.Neos:Node': true
      properties:
        inlineEditableStringProperty:
          type: string
          ui:
            inlineEditable: true
        stringProperty:
          type: string
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
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId  | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                       |
      | nody-mc-nodeface | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "My Text"} |
    And the command CreateWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | baseWorkspaceName  | "live"           |
      | newContentStreamId | "user-cs-id"     |

  Scenario: Translate the document and check the translated properties and their initiating user
    When I am in workspace "user-workspace"
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
