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
        # special handling for this one
        uriPathSegment:
          type: string
          options:
            automaticTranslation: true
        inlineEditableStringProperty:
          type: string
          ui:
            inlineEditable: true
        stringProperty:
          type: string
    # A Document type whose `uriPathSegment` is opted into automatic translation, to exercise the strict-charset
    # post-processing (a translated segment must stay a valid slug).
    'Neos.Neos:Document':
      abstract: true
      options:
        automaticTranslation: true
      properties:
        uriPathSegment:
          type: string
          options:
            automaticTranslation: true
            translationPostProcessor: 'Sitegeist\LostInTranslation\Domain\PostProcessor\UriPathSegmentPostProcessor'
        title:
          type: string
          options:
            automaticTranslation: true
    'Sitegeist.LostInTranslation.Testing:Page':
      superTypes:
        'Neos.Neos:Document': true
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
      | nody-mc-nodeface | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"uriPathSegment": "my-title", "inlineEditableStringProperty": "My Text"} |
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
    And event data at index 1 is:
      | Key                       | Expected                     |
      | metadata.initiatingUserId | "initiating-user-identifier" |
    And event at index 2 is of type "NodePropertiesWereSet" with payload:
      | Key | Expected |
    And event data at index 2 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |
    When I am in dimension space point {"language": "de"}
    Then I expect node aggregate identifier "nody-mc-nodeface" to lead to node user-cs-id;nody-mc-nodeface;{"language":"de"}
    And I expect this node to have the following properties:
      | Key                          | Value                |
      | uriPathSegment | "my-title-translated" |
      | inlineEditableStringProperty | "My Text translated" |

    When the command SetNodeProperties is executed with payload:
      | Key                       | Value                                                               |
      | nodeAggregateId           | "nody-mc-nodeface"                                                  |
      | originDimensionSpacePoint | {"language":"de"}                                                   |
      | propertyValues            | {"inlineEditableStringProperty": "My Text translated and adjusted"} |
    Then I expect exactly 4 events to be published on stream "ContentStream:user-cs-id"
    And event at index 3 is of type "NodePropertiesWereSet" with payload:
      | Key | Expected |
    And event data at index 3 is:
      | Key                       | Expected                     |
      | metadata.initiatingUserId | "initiating-user-identifier" |

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
    And event data at index 4 is:
      | Key                       | Expected            |
      | metadata.initiatingUserId | "AI:dummy:my-dummy" |
    And event at index 5 is of type "NodePropertiesWereSet" with payload:
      | Key | Expected |
    And event data at index 5 is:
      | Key                       | Expected                     |
      | metadata.initiatingUserId | "initiating-user-identifier" |

  Scenario: A source property holding the string "0" is translated, not skipped as blank
    # Guards a falsy-value bug: the collection step rejected blank sources with `empty($sourceValue)`, which is also
    # true for the string "0" — so a property legitimately holding "0" was left untranslated on variant creation,
    # while the stale-driven path in StalePropertyCommandBuilder translated it. Both now skip only null and
    # whitespace-only strings, so the two drivers agree on the same node.
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                     | initialPropertyValues                 |
      | zero-nodeface   | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation | {"inlineEditableStringProperty": "0"} |
    When the command CreateNodeVariant is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "zero-nodeface"   |
      | sourceOrigin    | {"language":"en"} |
      | targetOrigin    | {"language":"de"} |

    When I am in dimension space point {"language": "de"}
    Then I expect node aggregate identifier "zero-nodeface" to lead to node user-cs-id;zero-nodeface;{"language":"de"}
    And I expect this node to have the following properties:
      | Key                          | Value          |
      | inlineEditableStringProperty | "0 translated" |

  Scenario: The uriPathSegment of a Document Node is translated and kept a valid slug
    # uriPathSegment is auto-translatable but has a strict charset ([a-z0-9-]). The dummy translation service appends
    # " translated", yielding "my-test-uri-path translated" — which violates the charset (it contains a space). The
    # value must therefore be re-slugified back into a valid segment: "my-test-uri-path-translated".
    When I am in workspace "user-workspace"
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                 | initialPropertyValues                                       |
      | my-document     | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:Page     | {"title": "My Title", "uriPathSegment": "my-test-uri-path"} |
    When the command CreateNodeVariant is executed with payload:
      | Key             | Value             |
      | nodeAggregateId | "my-document"     |
      | sourceOrigin    | {"language":"en"} |
      | targetOrigin    | {"language":"de"} |

    When I am in dimension space point {"language": "de"}
    Then I expect node aggregate identifier "my-document" to lead to node user-cs-id;my-document;{"language":"de"}
    And I expect this node to have the following properties:
      | Key            | Value                         |
      | title          | "My Title translated"         |
      | uriPathSegment | "my-test-uri-path-translated" |
