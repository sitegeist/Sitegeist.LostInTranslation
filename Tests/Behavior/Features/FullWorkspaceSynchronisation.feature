@contentrepository
Feature: Full workspace synchronisation (CLI `synchronise --full`)
    # The stale-driven sync only translates nodes the projection has flagged. The "full" mode
    # walks the entire source subgraph from the root downward and considers every translatable
    # node, regardless of stale state. Useful for initial migration, recovery from a corrupted
    # stale projection, or operator-triggered rebuild of a target dimension.

    Background:
        Given using the following content dimensions yaml configuration:
        """yaml
        language:
          values:
            en: {}
            de:
              referenceLanguage: en
        """
        And using the following node types:
        """yaml
        'Neos.ContentRepository:Root': []
        'Neos.Neos:Content':
          abstract: true
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

    Scenario: Full sync into an empty target dimension creates all missing variants
        When I am in workspace "live"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                  | initialPropertyValues                          |
            | node-alpha      | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations | {"inlineEditableStringProperty": "Alpha Text"} |
            | node-beta       | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations | {"inlineEditableStringProperty": "Beta Text"}  |

        # Stale rows exist for both nodes at (live, de) — the projection captured them on create.
        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames                    |
            | live          | {"language":"de"}         | node-alpha      | ["inlineEditableStringProperty"] |
            | live          | {"language":"de"}         | node-beta       | ["inlineEditableStringProperty"] |

        # Full sync from live/en to live/de: every translatable node under the root gets a variant.
        When I full-synchronise translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}

        # Both variants now exist + translated; the cascade events cleared the stale rows.
        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

        When I am in workspace "live" and dimension space point {"language":"de"}
        Then I expect node aggregate identifier "node-alpha" to lead to node cs-identifier;node-alpha;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                          | Value                   |
            | inlineEditableStringProperty | "Alpha Text translated" |
        And I expect node aggregate identifier "node-beta" to lead to node cs-identifier;node-beta;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                          | Value                  |
            | inlineEditableStringProperty | "Beta Text translated" |

    Scenario: Full sync with default skip-existing leaves already-translated variants untouched
        # Two nodes; one is pre-translated (variant exists, stale cleared via CreateNodeVariant
        # cascade); the other has no target variant yet. Default skip-existing=true means the
        # pre-translated one is skipped; only the missing variant gets created.
        When I am in workspace "live"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId   | parentNodeAggregateId  | nodeTypeName                                                  | initialPropertyValues                              |
            | node-translated   | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations | {"inlineEditableStringProperty": "Original Text"}  |
        # Pre-translate node-translated by issuing CreateNodeVariant — the TranslationCommandHook
        # cascades SetNodeProperties + the projection clears its stale row.
        When the command CreateNodeVariant is executed with payload:
            | Key             | Value               |
            | nodeAggregateId | "node-translated"   |
            | sourceOrigin    | {"language":"en"}   |
            | targetOrigin    | {"language":"de"}   |
        # Now add a second node with no variant yet.
        When the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                  | initialPropertyValues                        |
            | node-untouched  | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations | {"inlineEditableStringProperty": "New Text"} |

        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames                    |
            | live          | {"language":"de"}         | node-untouched  | ["inlineEditableStringProperty"] |

        # Full sync with default skipExisting=true: node-translated is skipped; node-untouched
        # gets a CreateNodeVariant.
        When I full-synchronise translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"}

        Then I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |
        When I am in workspace "live" and dimension space point {"language":"de"}
        Then I expect node aggregate identifier "node-translated" to lead to node cs-identifier;node-translated;{"language":"de"}
        # node-translated still has its original translation — full-sync default did not overwrite it.
        And I expect this node to have the following properties:
            | Key                          | Value                      |
            | inlineEditableStringProperty | "Original Text translated" |
        And I expect node aggregate identifier "node-untouched" to lead to node cs-identifier;node-untouched;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                          | Value                 |
            | inlineEditableStringProperty | "New Text translated" |

    Scenario: Full sync with skipExisting=false re-translates existing variants too
        # Same setup as the previous scenario, but now editor manually overrode the German text
        # AFTER the auto-translation. With skipExisting=false the full sync must re-translate
        # the existing variant from the current source — overwriting the manual edit.
        When I am in workspace "live"
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId | parentNodeAggregateId  | nodeTypeName                                                  | initialPropertyValues                             |
            | node-edited     | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:NodeWithFewerTranslations | {"inlineEditableStringProperty": "Source Text"}   |
        When the command CreateNodeVariant is executed with payload:
            | Key             | Value             |
            | nodeAggregateId | "node-edited"     |
            | sourceOrigin    | {"language":"en"} |
            | targetOrigin    | {"language":"de"} |
        # Manual override of the auto-translation: editor replaced "Source Text translated" with
        # something hand-written. This clears the de stale row for that property if any remained.
        When the command SetNodeProperties is executed with payload:
            | Key                       | Value                                                |
            | nodeAggregateId           | "node-edited"                                        |
            | originDimensionSpacePoint | {"language": "de"}                                   |
            | propertyValues            | {"inlineEditableStringProperty": "Hand Crafted DE"}  |

        And I expect exactly the following stale translations:
            | workspaceName | originDimensionSpacePoint | nodeAggregateId | propertyNames |

        # Full sync with skipExisting=false: hand-crafted text gets clobbered by re-translation
        # of the current source ("Source Text translated").
        When I full-synchronise translations from workspace "live" dimension space point {"language":"en"} to workspace "live" dimension space point {"language":"de"} including existing variants

        When I am in workspace "live" and dimension space point {"language":"de"}
        Then I expect node aggregate identifier "node-edited" to lead to node cs-identifier;node-edited;{"language":"de"}
        And I expect this node to have the following properties:
            | Key                          | Value                     |
            | inlineEditableStringProperty | "Source Text translated"  |
