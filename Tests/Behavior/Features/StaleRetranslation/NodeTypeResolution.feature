@contentrepository
Feature: Track resolution of node aggregate types across workspaces. This must be done since not all relevant events contain the current node type name.

    Background:
        Given using the following content dimensions:
            | Identifier | Values | Generalizations |
            | language   | en     |                 |
        And using the following node types:
    """yaml
    'Neos.ContentRepository:Root': []
    'Sitegeist.LostInTranslation.Testing:TypeA': []
    'Sitegeist.LostInTranslation.Testing:TypeB': []
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

    Scenario: Complete node type resolution cycle: Create a node, change its type and all that across workspaces. We don't care about removal since we don't track hierarchy anyway
        When I am in workspace "user-workspace"
        # Create
        And the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId        | parentNodeAggregateId  | nodeTypeName                              |
            | sir-david-nodenborough | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:TypeA |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId        | nodeTypeName                              |
            | user-workspace       | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | live                 | sir-david-nodenborough |                                           |
            | other-user-workspace | sir-david-nodenborough |                                           |
        And I expect exactly the following node type resolution entries:
            | workspaceName  | nodeAggregateId        | nodeTypeName                              |
            | user-workspace | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |

        # Publish
        When the command PublishWorkspace is executed with payload:
            | Key                | Value            |
            | workspaceName      | "user-workspace" |
            | newContentStreamId | "new-user-cs-id" |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId        | nodeTypeName                              |
            | user-workspace       | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | live                 | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            # derived from live; technically this is wrong since the node does not even exit in other-user-workspace,
            # but this is not a problem because it will never be called:
            | other-user-workspace | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
        And I expect exactly the following node type resolution entries:
            | workspaceName | nodeAggregateId        | nodeTypeName                              |
            | live          | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |

        # ChangeBaseWorkspace
        When the command ChangeBaseWorkspace is executed with payload:
            | Key               | Value                  |
            | workspaceName     | "other-user-workspace" |
            | baseWorkspaceName | "user-workspace"       |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId        | nodeTypeName                              |
            | user-workspace       | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | live                 | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | other-user-workspace | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
        And I expect exactly the following node type resolution entries:
            | workspaceName | nodeAggregateId        | nodeTypeName                              |
            | live          | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |

        When the command ChangeBaseWorkspace is executed with payload:
            | Key               | Value                  |
            | workspaceName     | "other-user-workspace" |
            | baseWorkspaceName | "live"                 |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId        | nodeTypeName                              |
            | user-workspace       | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | live                 | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | other-user-workspace | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
        And I expect exactly the following node type resolution entries:
            | workspaceName | nodeAggregateId        | nodeTypeName                              |
            | live          | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |

        # Rebase
        When the following CreateNodeAggregateWithNode commands are executed:
            | workspaceName | nodeAggregateId            | parentNodeAggregateId  | nodeTypeName                              |
            | live          | sir-nodeward-nodington-iii | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:TypeA |
        When the command RebaseWorkspace is executed with payload:
            | Key             | Value                  |
            | "workspaceName" | "other-user-workspace" |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId            | nodeTypeName                              |
            | user-workspace       | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeA |
            | live                 | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeA |
            | other-user-workspace | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeA |
            | user-workspace       | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |
            | live                 | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |
            | other-user-workspace | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |
        And I expect exactly the following node type resolution entries:
            | workspaceName | nodeAggregateId            | nodeTypeName                              |
            | live          | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeA |
            | live          | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |

        # Discard
        When the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId  | parentNodeAggregateId  | nodeTypeName                              |
            | nody-mc-nodeface | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:TypeB |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId        | nodeTypeName                              |
            | user-workspace       | nody-mc-nodeface       | Sitegeist.LostInTranslation.Testing:TypeB |
            | user-workspace       | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | live                 | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | other-user-workspace | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
        And I expect exactly the following node type resolution entries:
            | workspaceName  | nodeAggregateId            | nodeTypeName                              |
            | live           | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeA |
            | live           | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |
            | user-workspace | nody-mc-nodeface           | Sitegeist.LostInTranslation.Testing:TypeB |
        When the command DiscardWorkspace is executed with payload:
            | Key           | Value            |
            | workspaceName | "user-workspace" |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId        | nodeTypeName                              |
            | user-workspace       | nody-mc-nodeface       |                                           |
            | user-workspace       | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | live                 | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | other-user-workspace | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
        And I expect exactly the following node type resolution entries:
            | workspaceName | nodeAggregateId            | nodeTypeName                              |
            | live          | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeA |
            | live          | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |

        # Change node aggregate type
        When the command ChangeNodeAggregateType is executed with payload:
            | Key             | Value                                       |
            | nodeAggregateId | "sir-david-nodenborough"                    |
            | newNodeTypeName | "Sitegeist.LostInTranslation.Testing:TypeB" |
            | strategy        | "happypath"                                 |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId        | nodeTypeName                              |
            | user-workspace       | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeB |
            | live                 | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
            | other-user-workspace | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeA |
        And I expect exactly the following node type resolution entries:
            | workspaceName  | nodeAggregateId            | nodeTypeName                              |
            | live           | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeA |
            | live           | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |
            | user-workspace | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeB |

        When the command PublishWorkspace is executed with payload:
            | Key                | Value                   |
            | workspaceName      | "user-workspace"        |
            | newContentStreamId | "even-newer-user-cs-id" |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId        | nodeTypeName                              |
            | user-workspace       | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeB |
            | live                 | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeB |
            # other-user-workspace still does not know the node and thus falls back; no problem either
            | other-user-workspace | sir-david-nodenborough | Sitegeist.LostInTranslation.Testing:TypeB |
        And I expect exactly the following node type resolution entries:
            | workspaceName | nodeAggregateId            | nodeTypeName                              |
            | live          | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeB |
            | live          | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |

        # Delete workspace
        When the following CreateNodeAggregateWithNode commands are executed:
            | nodeAggregateId  | parentNodeAggregateId  | nodeTypeName                              |
            | nody-mc-nodeface | lady-eleonode-rootford | Sitegeist.LostInTranslation.Testing:TypeB |
        And the command DeleteWorkspace is executed with payload:
            | Key           | Value            |
            | workspaceName | "user-workspace" |
        Then I expect the following node type resolution:
            | workspaceName        | nodeAggregateId  | nodeTypeName |
            | user-workspace       | nody-mc-nodeface |              |
            | live                 | nody-mc-nodeface |              |
            | other-user-workspace | nody-mc-nodeface |              |
        And I expect exactly the following node type resolution entries:
            | workspaceName | nodeAggregateId            | nodeTypeName                              |
            | live          | sir-david-nodenborough     | Sitegeist.LostInTranslation.Testing:TypeB |
            | live          | sir-nodeward-nodington-iii | Sitegeist.LostInTranslation.Testing:TypeA |
