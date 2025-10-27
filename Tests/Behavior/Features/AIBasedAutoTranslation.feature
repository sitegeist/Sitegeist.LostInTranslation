@contentrepository
Feature: Create node variant and let the AI translate the properties

  Background:
    Given using the following content dimensions:
      | Identifier | Values | Generalizations |
      | language   | en, de |                 |
    And using the following node types:
    """yaml
    'Neos.ContentRepository:Root': []
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

  Scenario: Translate the document and check the translated properties and their initiating user
    When the command CreateNodeVariant is executed with payload:
      | Key             | Value              |
      | workspaceName   | "live"             |
      | nodeAggregateId | "nody-mc-nodeface" |
      | sourceOrigin    | {"language":"en"}  |
      | targetOrigin    | {"language":"de"}  |

    Then I expect exactly 5 events to be published on stream "ContentStream:cs-identifier"
    And event at index 3 is of type "NodePeerVariantWasCreated" with payload:
      | Key | Expected |
    And event metadata at index 3 is:
      | Key              | Expected                     |
      | initiatingUserId | "initiating-user-identifier" |
    And event at index 4 is of type "NodePropertiesWereSet" with payload:
      | Key | Expected |
    And event metadata at index 4 is:
      | Key              | Expected            |
      | initiatingUserId | "AI:dummy:my-dummy" |

    And I am in dimension space point {"language": "de"}
    Then I expect node aggregate identifier "nody-mc-nodeface" to lead to node cs-identifier;nody-mc-nodeface;{"language":"de"}
    And I expect this node to have the following properties:
      | Key                          | Value                |
      | inlineEditableStringProperty | "My Text translated" |
