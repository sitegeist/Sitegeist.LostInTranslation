# Retranslation & Synchronization — Design, Workflows & Decisions

This document describes the two backend translation features of `Sitegeist.LostInTranslation`
on Neos 9 / the new Content Repository (CR):

- **Retranslation** — bring *one node's* target-language subtree back in sync with its
  source (reference) language, on demand.
- **Synchronization** — reconcile translations *across workspaces and/or dimensions*, either
  automatically on publish or via a manual/CLI operation.

It is written as the "why" companion to the code: every non-obvious decision is recorded with
its reason and its consequences, including decisions that were tried and later reversed (see
[§7 Evolution / superseded decisions](#7-evolution--superseded-decisions)). It was reconstructed
from the full development history and verified against the current source.

> Conventions used below: `en` is the reference/source language, `de`/`es` are targets.
> "DSP" = `DimensionSpacePoint`. "Stale row" = a record in the `stale_translations` table.

---

## 1. The shared backbone: the stale-translation projection

Both features sit on top of one piece of state: a custom CR projection that records *what still
needs translating*. Understanding it is a prerequisite for understanding everything else.

### What it tracks

`StaleTranslationProjection` (`Classes/ContentRepository/StaleTranslationProjection/`) maintains the
`stale_translations` table, one row per `(workspaceName, nodeAggregateId, originDimensionSpacePointHash)`
plus a `propertyNames` JSON list of the translatable properties that are out of date.

**Key invariant — rows are keyed at the *target* origin DSP, not the source.** When an editor
changes a property at the `en` origin, `whenNodePropertiesWereSet` resolves every target dimension
of `en` via `ReferenceDimensionSpacePointResolver::findAllTargetDimensionSpacePoints()` and writes
one stale row *at each target origin* (`de`, `es`, …) listing the changed translatable properties.
The same handler also deletes any stale row sitting at the event's *own* origin — so when the
translation itself lands (a `NodePropertiesWereSet` at the `de` origin), the `de` row is cleared.
That delete-at-own-origin is the normal clearing mechanism.

Companion tables:
- `stale_translations_nodeaggregate_type` — caches `nodeAggregateId → NodeTypeName` (the type isn't
  on every event).
- `stale_translations_ws_hierarchy` — tracks the workspace base-hierarchy so the type can be resolved
  across workspaces that inherit aggregates by rebase/publish.

### Events handled

| Event | Effect |
|---|---|
| `NodeAggregateWithNodeWasCreated` | Insert a stale row at each target origin for the created node's translatable + non-empty-default properties. Defaults count when they carry content (non-empty string **or** non-empty array/object), gated by the translatable-property set. |
| `NodePropertiesWereSet` | Clear the row at the event's own origin; (re)insert/update rows at every target origin for the changed translatable properties. |
| `NodeAggregateTypeWasChanged` | Recompute the translatable property set against the new type's defaults. |
| `NodePeer/Specialization/GeneralizationVariantWasCreated` | `clearStructuralStaleRecord`: drop the row at the variant's target origin **only when it has no translatable properties** (an empty `[]` row) — see "load-bearing empty rows" below. |
| `NodeAggregateWasRemoved` | `DELETE` rows for the aggregate at each affected covered DSP **unioned with its referenceLanguage targets** (so removing the `en` variant also clears the `de`/`es` rows that hung off it). Strict-scoped to affected DSPs, not aggregate-wide. |
| `WorkspaceWasPublished` / `WasRebased` / `BaseWorkspaceWasChanged` / `WasDiscarded` / `WasRemoved` / `WasCreated` | Maintain rows across the workspace hierarchy via `replaceWorkspaceEntries(source, target)` (DELETE target rows; INSERT … SELECT source rows as target rows). |
| `SubtreeWasTagged` / `SubtreeWasUntagged` | **Explicitly not handled.** Tagging does not change what needs translating — with one wrinkle: since Neos 9.1 *deletes* by tagging `removed`, a deleted node keeps its stale rows. Nothing will ever satisfy them (a deleted node is not a translation source), so `SynchronizationStatusProvider` filters them out at read time. Deliberately not pruned in the projection: a restore from the trash bin then brings the pending translation back with it. |

### Why a projection (and why these design choices)

- **Reason for keying at the target origin:** the table answers exactly the question both features
  ask — "what is out of date *in the target dimension*?" Retranslation reads the rows for the target
  it's asked about; the publish hook reads the rows at `(targetWorkspace, targetDimension)`. The
  alternative (key by source DSP, "the en source changed") was prototyped and rejected — see
  [§7](#7-evolution--superseded-decisions).
- **Load-bearing empty rows.** A node with *no* translatable properties (most commonly a Document's
  tethered `main` `ContentCollection`) is still recorded — with an empty `propertyNames` list.
  Nothing ever issues `SetNodeProperties` for it, so the normal clearing mechanism can't fire. The
  empty row is what drives *structural* mirroring: it tells synchronization "this node must exist in
  the target," producing a `CreateNodeVariant`. The row is then cleared by the variant-creation
  event handler (`clearStructuralStaleRecord`). Hence empty rows must **not** be suppressed at write
  time; they are cleared by the variant event that satisfies them.
- **Type cache resolves across workspaces.** `NodeTypeResolver::resolveByNodeAggregateId` walks the
  `_ws_hierarchy` parent chain, because the type-populating event (`NodeAggregateWithNodeWasCreated`)
  doesn't fire for workspaces that inherit an aggregate via rebase/publish. Without this, stale rows
  in e.g. another user's workspace never cleared.

### Consequences & gotchas

- **`findBySubtree`'s `IN (…)` binding needs `ArrayParameterType::STRING`.** Without it DBAL coerces
  the id array to the literal string `"Array"` and the finder silently matches nothing — this once
  blocked the entire feature.
- **`whenNodeAggregateTypeWasChanged` serialization contract.** The `propertyNames` JSON must be a
  *list*. Use `array_values(array_unique(array_intersect(...)))` before encoding — otherwise
  `array_intersect` preserves non-contiguous keys and `json_encode` emits an object
  (`{"1":"text"}`), which diverges from the canonical `[]` shape that other writers and the
  exact-string `[]` prune rely on.
- **Per-save cost** is `1 + 2·T` queries and `T+1` transactions (T = number of target dimensions).
  Accepted as cheap (T is single-digit).
- **External access is read-only.** Neos 9 only exposes `ContentRepository::projectionState(ReadModelClass)`.
  The projection cannot be reached to mutate it directly, so the read side is
  `StaleTranslationReadModel` exposing `staleTranslationFinder` (queries) and
  `staleTranslationMaintenance` (the sanctioned write/prune escape hatch the projection's
  event-driven `apply()` can't cover).

### Shared helpers built on top

- **`StalePropertyCommandBuilder`** — the single translate→build pipeline. Given a node, its stale
  property list, and source/target DeepL languages, it extracts values (via connectors for value
  objects, flatten/deflate for strings), translates in **one batched DeepL call per node**, applies
  per-property post-processors, and returns a `SetNodeProperties`. Shared by `Retranslator`, the
  publish hook, and `FullWorkspaceSynchronizer`.
- **`ReferenceDimensionSpacePointResolver`** — maps target→source (1:1, via the target preset's
  `referenceLanguage`) and source→all-targets (`findAllTargetDimensionSpacePoints`, 1:many).
- **`DimensionValueDirectiveFactory` / `DeeplLanguagePair`** — resolve a DSP to a DeepL language id
  via the preset's `options.deeplLanguage` (supports `EN:EN-GB` asymmetric notation, or `false` to
  disable a language); returns null → translation short-circuits.
- **`AiCommandDispatcher` + `AISystemTranslationRuntimeState`** — dispatches translation commands
  with two effects: (1) attributes the resulting events to the AI service rather than the editor;
  (2) **disables authorization checks** for the duration (`securityContext->withoutAuthorizationChecks`)
  so a VIEWER/editor without write rights on `live` doesn't get a 500 — synchronization is a system
  operation. State is always reset in a `finally`. This also short-circuits the command hooks
  (recursion guard) so AI-authored events don't re-trigger translation.
- **`NodeTreeDepth::of`** — ancestor count, used to order commands ancestor-before-descendant.
- **`CrossWorkspaceSynchronizationTarget::prepare(cr, source, target, rebase=true): ?string`** —
  cross-workspace preflight: target must exist and be based on source; force-rebases it; returns
  `null` to proceed or a human-readable skip reason.
- **`StaleRecordReconciler::pruneIfUnsatisfiable`** — prunes rows that can never be cleared by an
  event (target variant exists but nothing translatable; or tethered + no properties + parent
  present). Centralizes the "prune the **target** workspace's row" rule (see the cross-workspace
  pruning gotcha in [§5](#5-synchronization)).

---

## 2. Retranslation

### What it is

A **pure, per-node** operation: given one node aggregate and a target dimension, bring that node's
subtree in the target language back in sync with its source. It translates the stale properties of
existing target variants and creates the variants that are missing. It does **not** reach outside
the node it was asked about — creating missing *ancestor* documents is synchronization's job (see
[§7](#7-evolution--superseded-decisions)).

The source language is always **derived from the target**, never passed in: the target preset's
`referenceLanguage` names its source. Retranslating into the source language itself (or a target
whose preset has `deeplLanguage: false`) is a no-op skip, not an error.

### Entry points

| Surface | Code |
|---|---|
| CLI | `lostintranslation:retranslate-node <nodeAggregateId> <target> <contentRepository> <workspace>` — `LostInTranslationCommandController::retranslateNodeCommand`. **All four args required** (see CLI gotcha). |
| Inspector "Retranslate" button | `RetranslationController::retranslateNodeAction` (POST) + `getTranslationMetadataAction` (GET, returns `{isUpToDate, referenceLanguage:{label}|null, staleNodeCount}`). Routes in `Configuration/Routes.yaml`; UI in `Neos.Ui/`. |

Both funnel into `Retranslator::retranslateNode(...)`, so a fix in one place covers both.

### Runtime workflow

1. **Resolve source DSP** from the target's `referenceLanguage` via `ReferenceDimensionSpacePointResolver`.
   If none (target *is* the source, or unconfigured) → `RetranslationResult::skipped(reason)`.
2. **Resolve DeepL language pair** via `DimensionValueDirectiveFactory`. If either side is `false`/null
   → skip.
3. **Pre-fetch stale rows** for the subtree into an in-memory map
   (`StaleTranslationFinder::findBySubtree(sourceSubtree, targetOriginDSP)`), so the walk does O(1)
   lookups.
4. **Find the source subtree** via `findSubtree`, scoped with a `NodeTypeCriteria` allow-list of
   `Neos.Neos:Content` + `Neos.Neos:ContentCollection`. Nested `Neos.Neos:Document` nodes are
   excluded from the walk (the entry node is always returned even if it's a Document). Uses
   `excludeRemoved`.
5. **One depth-first pre-order walk** of the source subtree. Per node:
   - stale row exists **and** target variant exists → `SetNodeProperties` (via
     `StalePropertyCommandBuilder`);
   - target variant missing **and** node is non-tethered → `CreateNodeVariant`;
   - stale row exists but the builder produces nothing translatable → prune the row
     (`StaleRecordReconciler`).
6. **Dispatch** via `AiCommandDispatcher`. The freshly-created variants get their translated
   `SetNodeProperties` from the existing `TranslationCommandHook` cascade (which also cascades onto
   tethered descendants) — the retranslator does **not** also emit properties for them, to avoid
   double-translation.
7. Return `RetranslationResult` (`stalePropertyCommandsDispatched`, `variantCommandsDispatched`,
   `skippedReason`, `isNoOp()`).

### Decisions & reasons

- **Source derived from target, not vice-versa.** `referenceLanguage` is target→source 1:1, so the
  resolution is unambiguous. (This is also why retranslation was immune to the multi-target fan-out
  bug that hit the projection — see [§7](#7-evolution--superseded-decisions).)
- **`CreateNodeVariant` for missing targets, not `SetNodeProperties`.** Writing properties to a
  non-existent target origin fails; instead create the variant and let the cascade translate it.
- **Tethered nodes are not given their own `CreateNodeVariant`, but the walk still recurses into
  them.** The CR auto-creates a tethered variant when its non-tethered ancestor's variant is created;
  emitting `CreateNodeVariant` for a tethered node is invalid. But a tethered subtree can contain
  non-tethered descendants that *do* need variants.
- **Document-scope filter** (exclude nested Documents). Each Document is its own translation scope;
  retranslating one page must not bleed into nested pages.
- **`RetranslationResult` value object instead of `void`.** Every failure path used to degrade to
  "log debug + return", so the CLI printed "finished" on a complete no-op. The result object makes
  skip/no-op/dispatched observable; the CLI prints a distinct line for each.
- **Empty-source handling: clear, don't translate.** A blank string source value is propagated as an
  empty value to the target (no wasted DeepL call) so the target is cleared and the stale row closes;
  a `null` source means "nothing to propagate" and is skipped. (In the `CreateNodeVariant` cascade a
  blank source is simply skipped — a brand-new variant has nothing to clear. This is a deliberate,
  correct divergence between the two write paths.)
- **DeepL is batched per node**, not per property — all of a node's translatable leaves go in one
  `translate()` call. Cross-node batching was considered and rejected as speculative on a cold path.
- **Logger injected via `?LoggerInterface = null` + `injectLogger()` setter**, not a
  `#[Flow\Inject(name: '…SystemLogger')]` attribute — the attribute on a non-nullable typed property
  crashes Flow's proxy ("Cannot access uninitialized non-nullable property by reference"). The setter
  is the codebase convention.

### Consequences & gotchas

- **Retranslation throws if the target's parent doesn't cover the target dimension.**
  `CreateNodeVariant` → `requireNodeAggregateToCoverDimensionSpacePoint($parentNodeAggregate, …)`,
  and the error names the **parent**, not the node you asked for. Creating missing ancestors is
  synchronization's responsibility.
- **CLI positional-argument trap.** Flow fills only *required* (no-default) parameters positionally;
  positional args after the last required slot are silently dropped into `$exceedingArguments`. A
  `$workspace = 'live'` default once caused `… de default ad-min` to silently run against `live`.
  Fixed by making all four args required (trade-off: you must always type the workspace).
- **Best-effort, no rollback.** Commands are dispatched as separate event-store calls; a mid-flight
  failure leaves earlier commands committed. Dispatch order (stale fix-ups before variants) is chosen
  to keep partial-failure damage smaller and more obvious.
- Inspector endpoints are reachable only from inside the Neos UI shell (session cookie +
  `X-Flow-Csrftoken` on POST). curl/other-origin/CLI/cron get 401/403 by design.

---

## 3. The two auth wirings (Retranslation & Synchronization controllers)

A controller that should be callable from the Neos backend needs **both** of these — missing either
looks like the other is broken:

1. **A request pattern** attaching the controller to `Neos.Neos:Backend` authentication. The
   authoritative patterns live in `Configuration/Settings.Authentication.yaml`, which **wins the
   Settings merge** for that key — a duplicate block in `Settings.yaml` is silently overridden.
   Entries are OR-combined, so each controller needs its own entry. The
   `controllerObjectNamePattern` uses a **single** backslash (`ControllerObjectName::matchRequest`
   doubles it internally); a YAML `\\` won't match → "no tokens which could be authenticated" → 401.
2. **A Policy.yaml grant.** A privilege target matching the controller's actions
   (`Sitegeist.LostInTranslation:Retranslation`, `:Synchronization`) GRANTed to
   `Neos.Neos:AbstractEditor` and `:Administrator`. Otherwise `Neos.Neos:AllControllerActions`
   catches the controller, nothing grants it, and a logged-in editor gets 403 (ABSTAIN).

**Gotcha — stale sessions masquerade as config bugs.** A `Neos_Session` cookie minted *before* the
request-pattern config changed carries a token whose provider scope no longer matches, so it keeps
401-ing even when the config is correct. When iterating on auth/session config, clear cookies and
re-login (a long red-herring debugging session was caused by two `Neos_Session` cookies on different
paths). Dev server note: the app ran on **8081**, logs at `Data/Logs/`.

---

## 4. Synchronization — model & configuration

### What it is

Rule-driven reconciliation of translations across `(workspace, dimension)` pairs. Its headline
capability over retranslation is **cross-workspace** flow — e.g. translate published `live/en`
content into a `de-review/de` workspace where it can be reviewed before going live.

Automatic synchronization is **always stale-driven**. Walking the whole tree regardless of stale
state is a deliberately separate, CLI-only operation (`synchronize --full`) so the heavy/expensive
full walk can never be triggered unintentionally by a publish.

### Configuration

`Sitegeist.LostInTranslation.nodeTranslation.synchronization` is a list of rules (default `[]`,
opt-in):

```yaml
nodeTranslation:
  synchronization:
    - sourceWorkspaceName: live      # publish into this workspace triggers the rule
      sourceDimension: en            # must equal the targetDimension preset's referenceLanguage
      targetWorkspaceName: de-review # where translated variants are written
      targetDimension: de
      scope: Document                # Document | Content          (REQUIRED — config validation throws if missing)
      mode: auto                     # auto | ask                  (default: auto)
```

**The governing idea: the target dimension is a projection of the source dimension.** The source language is
the single source of truth for properties, structure and subtree tags; target-side changes are overwritten
when a synchronization runs. That is intended, not a limitation — no mechanism preserves them, and none
should be added. Everything below follows from it, and it is why a rule has exactly two knobs.

- **`SynchronizationScope`** (required, no default) — governs what synchronization may **create**, and
  nothing else:
  - `Document` — mirror the whole structure: create missing Document variants **and** their content.
  - `Content` — only act on records whose closest self-or-ancestor Document already exists in the
    target dimension (`FindClosestNodeFilter::create('Neos.Neos:Document')`); **never** auto-create
    Documents. Creating a Document in the target is a deliberate manual action.

  Subtree tags and removals are **not** scope-gated: a node hidden or deleted in the source language is
  hidden or deleted in the target under either scope. All three drivers apply the creation gate — the
  publish hook, "sync now" and (as `Document`, since it has no rule) the `--full` CLI.
- **`SynchronizationMode`** (default `auto`) — governs **when the translation runs**, and nothing else:
  - `auto` — translate inline during the publish (publish blocks on DeepL).
  - `ask` — defer translation to a deliberate manual sync (Neos UI prompt / backend module "sync
    now") so the publish stays fast and atomic.

  Everything *cheap* happens inline on every publish under **both** modes: subtree-tag mirroring
  (and therefore deletions), mirrored hard removals, and the cross-workspace rebase. Only translating,
  which costs a DeepL call per node, is what `ask` defers. See
  [the mode invariant in §5](#the-mode-invariant-ask-and-auto-differ-only-in-when-never-in-what).

**Subtree tags and deletions are always synchronized** — there is no per-rule setting:

- Source-language tag changes are mirrored onto the target variant with `TagSubtree` / `UntagSubtree`: the
  `disabled` hide/show tag, the `removed` soft-removal tag, and any custom `SubtreeTag`. Sync owns the target
  dimension's whole tag space; a dimension that needs independent tag state should not have a rule pointing
  at it.
- Only **explicit** tags are mirrored — inherited ones reproduce themselves once their tagged ancestor is
  reconciled, so the source's tag *structure* is what gets reproduced, not merely its effective state.
- The emit is **idempotent**: a tag already matching the target's explicit state is skipped, so the CR never
  trips `SubtreeIsAlreadyTagged` / `SubtreeIsNotTagged` and re-runs are safe.
- **Deleting a node is a tag change.** Neos 9.1 soft-removes by tagging `removed` rather than destroying the
  node (`NeosSubtreeTag::removed()`; hard `RemoveNodeAggregate` outside `live` is explicitly undesired in
  Neos, since it destroys the hierarchy information the trash bin and conflict detection need). Deletions
  therefore ride the tag mirror, and restoring from the trash bin mirrors back the other way. When a soft
  removal becomes a *hard* one is `SoftRemovalGarbageCollector`'s business, per dimension — not
  synchronization's. A hard removal that a publish happens to carry is mirrored too, from the publish's own
  `NodeAggregateWasRemoved` events.
- Because the source owns the target's tag state, hiding or deleting **only the translation** does not stick:
  the publish hook converges a tag event that touched only the target dimension back onto the source's
  current state, and the deliberate sync's diff reaches the same result.

> **Upgrading.** The `onSourceRemoval` and `onSourceTagging` rule keys were **removed**; see
> [§7](#7-evolution--superseded-decisions). `SynchronizationRule::fromArray()` **throws** on either, rather
> than ignoring it: both defaulted to "leave the target alone", so silently accepting an old config would
> start hiding and deleting nodes in the target dimension without anyone asking for it.

A `SynchronizationRule` is an immutable readonly VO; `SynchronizationRules::forPublicationTarget()`
returns **all** rules matching a publish target (one publish may fan out to multiple targets).
The language dimension name is not per-rule — it's resolved once from
`nodeTranslation.languageDimensionName`. `swapLanguage` replaces only the language coordinate,
preserving other coordinates (e.g. `{language:en,country:us} → {language:es,country:us}`).

> **Flow gotcha:** Flow merges settings *sequences by index*. An unrelated rule at index 0 in
> another package once leaked `mode: ask` onto this package's rule. Scope test/demo rules to the
> right Flow context and to their own workspaces.

---

## 5. Synchronization — entry points & workflows

There are three drivers, all returning `WorkspaceSynchronizationResult` (an aggregate of
`PerNodeSynchronizationResult` = `NodeAggregateId` + `RetranslationResult`).

### The mode invariant: `ask` and `auto` differ only in *when*, never in *what*

`mode` selects **when** a rule's TRANSLATION runs — inline during the publish (`auto`) or on a
deliberate "sync now" / CLI run (`ask`). It must never change the **result**. Everything cheap (tag
mirroring, and therefore deletions; mirrored hard removals; the cross-workspace rebase) runs inline on
every publish under both modes, precisely so there is less left that could diverge. For one and the same
source-side change, all three drivers have to converge on the same target state. Any difference in the
*decision* a driver makes about that change — which nodes are in scope, whether a tag is mirrored,
whether a kept subtree is descended into — is a **bug**, not a mode difference. When reviewing or
extending this feature, treat a driver-specific outcome as a defect until proven otherwise; two of the
entries in [§7](#7-evolution--superseded-decisions) exist because exactly that went unnoticed.

**The one permitted difference is coverage, not semantics.** The publish hook only ever sees the delta
of the publish it is reacting to (§4: walking the whole tree on publish is deliberately not done); the
deliberate runs diff the whole target dimension against the source. So a deliberate run additionally
catches up on changes the hook could never have seen — made before the rule existed, or outside any
publish. That is a difference in what each driver can **observe**,
not in what it does with what it observes. Wherever both paths observe the same change, they must
handle it identically.

Practical rule when the two paths disagree: the publish path's "stay incremental, never walk the target
tree" contract is the harder constraint, so the fix is normally to bring the *diff* path in line — or to
let the hook act on an event it **already has in the publish delta**, which is still incremental. Making
the hook walk the target tree to catch up with the diff path is not an option.

### A. Automatic, on publish — `SynchronizationCommandHook`

Registered in `Configuration/Settings.Neos.yaml` under `commandHooks`, **after** `TranslationCommandHook`.

Flow (`onAfterHandle`, reacting to `PublishWorkspace` / `PublishIndividualNodesFromWorkspace`):
1. Read the `WorkspaceWasPublished` event to get the publish target workspace.
2. `rules->forPublicationTarget(target)` → matching rules.
3. Per rule: `rebaseTargetOntoSource(rule)` — cross-workspace preflight + force-rebase
   (`CrossWorkspaceSynchronizationTarget`). This runs **even for `ask` rules**, so the projection /
   backend module reflects what still needs syncing instead of the target lagging. Skip the rule on
   failure (target missing / not based on source).
4. If `rule.mode === Ask` → skip steps 5-8 (translation deferred); steps 9-10 still run.
5. Read stale rows at `(rule.targetWorkspaceName, targetDimension)` via the finder.
6. Per stale record, with the **scope gate**: under `Content`, skip if the record's closest Document
   doesn't exist in the target dimension.
7. Build commands: target variant missing + non-tethered → `CreateNodeVariant` (cascade translates);
   target variant missing + tethered → skip (ancestor's variant cascade creates it); target variant
   exists → translated `SetNodeProperties`. Unsatisfiable no-op rows are pruned via
   `StaleRecordReconciler`.
8. **Order commands ancestor-before-descendant** by source-tree depth (`NodeTreeDepth`).
9. **Tagging mirror** (always; both modes): scan this publish's `SubtreeWasTagged` / `SubtreeWasUntagged`
   events and normalise each into one question — should the target variant carry this tag explicitly?
   - the event's `affectedDimensionSpacePoints` include the rule's **source** DSP → mirror the event's own
     direction;
   - they include only the **target** DSP (someone tagged, hid or deleted the *translation* directly) →
     converge that tag onto the source's **current** explicit state, which reverts the target-side change.
     Reading the source rather than inverting the event is what makes this order-independent. A node with no
     source counterpart is skipped — an editor-owned target-only node is none of sync's business;
   - neither → skip (a third language).

   Then emit `TagSubtree` / `UntagSubtree` for the target variant (`allSpecializations` at the target DSP)
   unless it is already in the desired *explicit* state (idempotent, avoids `SubtreeIsAlreadyTagged` /
   `SubtreeIsNotTagged`). A node *created* in this same publish (its id is among step 7's
   `CreateNodeVariant`s) also gets its `TagSubtree`, relying on the batch being dispatched in order (create
   then tag) onto the freshly-created, untagged variant. Not scope-gated. **This is also the deletion
   mirror**, since Neos 9.1 deletes by tagging `removed`. Emitted **before** the removal mirror so a node
   tagged-and-hard-removed in one publish is tagged while it still exists.
10. **Hard-removal mirror** (always; both modes): scan this publish's `PublishedEvents` for
   `NodeAggregateWasRemoved` whose `affectedCoveredDimensionSpacePoints` include the rule's **source** DSP
   (this gate distinguishes a source-side removal from an editor deleting only the target variant). For each
   whose target variant still exists, append a `RemoveNodeAggregate` for the target variant
   (`allSpecializations` at the target DSP), keeping only subtree-root removals so the CR's own cascade is not
   fought. Not scope-gated. Cheap and precise — only the publish delta is inspected, never the whole target
   tree. Note this is *not* the editor's delete path (step 9 is); it covers a hard removal a fixture or script
   put into the publish.
11. Flag AI authorship and **return** the `Commands` — the hook does *not* dispatch inline (it can't,
   mid-publish); the CR dispatches the returned commands as separate commits after the publish.

### B. Manual "sync now" / post-publish prompt — `WorkspaceSynchronizer` + controller/module

- `WorkspaceSynchronizationController` (routes `…/synchronization/pending` GET, `…/synchronize` POST)
  and `LostInTranslationModuleController` (backend module) drive `WorkspaceSynchronizer::synchronizeRule()`.
- `SynchronizationStatusProvider::pendingCountForRule()` is the shared, side-effect-free counter used
  by both the UI prompt and the module, so they agree. It counts stale rows at the **target**
  workspace+origin whose aggregate still exists in the target graph; returns 0 if the target is
  absent. Rows whose **source node was deleted** (carries the `removed` tag) are skipped — the projection
  ignores tag events, so a deletion leaves its stale rows behind and nothing will ever satisfy them; counting
  them would report "out of sync" forever and keep re-raising the post-publish prompt. Filtered at read time
  rather than pruned, so restoring the node from the trash bin brings its pending translation back.
- `WorkspaceSynchronizer` validates `sourceWorkspace != targetWorkspace` and that `sourceDimension`
  equals the target's `referenceLanguage`; cross-workspace force-rebases the target; applies the rule's
  **scope gate** (identical to the hook's — under `Content`, skip a record whose closest Document is absent
  from the target dimension, so clicking "sync now" cannot create the Documents the scope keeps out); then
  dispatches one `Retranslator::retranslateSubtree()` per stale record, depth-ordered.
- **`--dry-run` previews; it does not translate.** Both synchronizers report what the run *would* do — per node
  and as totals — while dispatching nothing, pruning no stale row and, cross-workspace, not rebasing. The
  constraint that shapes the implementation: building a translated `SetNodeProperties` **is** the DeepL call, so
  a preview that built commands and threw them away would cost exactly what the run it is estimating costs.
  Decision and build are therefore split — `Retranslator::planSubtree()` and
  `StalePropertyCommandBuilder::wouldBuildSetNodeProperties()` answer "would this node be written?" from the
  source values alone, and the Behat suite counts what reaches the translation service to keep it that way.
  `planSubtree()` returns node **ids**, not counts, because the stale-driven run walks a subtree per record and
  relies on the projection clearing rows to avoid doing a node twice; a preview clears nothing, so it
  deduplicates by id instead. Two gaps are inherent and accepted: a cross-workspace preview reads the
  **un-rebased** target and therefore under-reports, and `--full` cannot foresee that dispatching a
  `CreateNodeVariant` materialises tethered children mid-walk which the same walk then re-translates.
- **Tag mirror (diff path).** After the stale-driven pass it converges each target node's EXPLICIT subtree
  tags onto the source by diffing the two dimensions (`TargetTagReconciler`): a `TagSubtree` per tag the
  target lacks, an `UntagSubtree` per tag it has extra — for any tag, including `removed`, which is how this
  path mirrors **deletions and restores**. Always runs; there is nothing to configure. The reconciler re-reads
  fresh subgraphs so a variant created earlier in the same run is tagged too, and reads both sides with FULL
  visibility (`VisibilityConstraints::createEmpty()`) because soft-removed nodes on either side are exactly
  what it must see. (The full reconcile is acceptable here because "sync now" is already a deliberate,
  heavier operation — unlike the publish hook, which must stay incremental. It is also what self-heals
  changes no publish carried.)
- There is no separate removal pass. `TargetOrphanCollector` — a diff of "present in target, absent in
  source" — was **deleted**: it could not tell "deleted in the source" from "never in the source", which under
  `Content` scope is the expected case, so it emptied editor-owned target-only pages. See
  [§7](#7-evolution--superseded-decisions).
- The backend module lists **all** rules (source→target, dimensions, scope, mode, live out-of-sync
  count) with "Sync now"/"Sync all", ignoring `mode` on purpose — it doubles as a manual catch-up for
  rules that errored mid-cascade.

**UI saga note:** the post-publish prompt triggers on the redux `…/Publishing/FINISHED` action, not
`SUCEEDED` (a successful no-confirmation publish dispatches `STARTED → FINISHED` with no `SUCEEDED`).
Match the **literal** action string, not an imported constant (a wrong import path makes
`takeEvery` silently match nothing). Filter to publishes (not discards) and dedupe the double
`FINISHED`.

### C. Full-workspace — `FullWorkspaceSynchronizer` (CLI `synchronize --full`)

- Walks every root aggregate's source subgraph top-down (so it never has the ancestor-ordering
  problem). Per node: skip if not translatable; `CreateNodeVariant` if target absent + non-tethered;
  otherwise a translated `SetNodeProperties` covering **all** translatable properties — including for target
  variants that already exist, since the target is a projection of the source.
- `--skip-existing` is the one deliberate exception to that: it keeps a target variant that exists and has no
  stale row, preserving target-side property edits the source has not touched. It defaults to **off** because
  the projection model says the source wins; it exists because re-asserting a *property* costs a DeepL call
  per node, unlike re-asserting a tag, so on a large workspace the cheap-but-divergent run is sometimes what
  you want. A stale row always forces a refresh regardless.
- Same `--dry-run` semantics as [B](#b-manual-sync-now--post-publish-prompt--workspacesynchronizer--controllermodule)
  — including that a preview translates nothing; for cross-workspace it force-rebases the target first (skipped
  on dry-run).
- The CLI has no rule, hence no scope: `--full` always behaves as `Document` scope.
- **Tag mirror.** The diff-based `TargetTagReconciler` pass runs after the translation walk, converging each
  target node's explicit subtree tags onto the source — including `removed`, so deletions and restores are
  mirrored here too. Unconditional; the `--remove-orphans` and `--sync-tags` flags are gone.

### Cross-workspace mechanics (the crux)

- **Read source, write target.** Source content is read from `sourceWorkspaceName`; all commands are
  dispatched into `targetWorkspaceName`. Identical to single-workspace when they're equal (backward
  compatible).
- **The target must be / be based on the source**, and is **force-rebased** onto it before reading
  (`RebaseWorkspace … STRATEGY_FORCE`). Why: `CreateNodeVariant` is *intra-workspace* (it copies
  within one content stream), so the target's source dimension must be current and must contain every
  source node, or the cascade reads stale content / throws `NodeAggregateCurrentlyDoesNotExist`. The
  rebase fixes both.
- **"Source wins" only on a *genuine* conflict.** Force-rebase replays the target's non-conflicting
  edits on top — so editing the *same property* in both workspaces is **not** a CR conflict and the
  **target (review) edit survives**. "Source wins" only fires on a structural conflict (e.g. target
  edits a node the source removed → target change dropped). This is the intended divergence behaviour
  for a review workspace.
- **Never auto-create the target workspace.** A cross-workspace rule fires on *every* publish to its
  source, so auto-creating the target would re-materialize it on every publish. Instead, skip with a
  clear reason when the target is absent; surface it (CLI `<error>` + non-zero exit; controller
  `errors[]`; dialog flash). Creating the workspace is a manual action.
- **Out-of-sync count is measured on the target, never the source.** For `live/en → de-review/de`,
  syncing clears `de-review`'s rows but never `live`'s `de` rows (those are intentionally never
  translated in the review pattern). Counting the source would report "out of sync" forever.

### Consequences & gotchas

- **Prune the *target* workspace's stale row, never the source.** `findBySubtree` keys to the source
  subgraph, so a naive no-op prune via `$stale->workspaceName` would delete `live`'s row and falsely
  mark `live/de` "in sync." `StaleRecordReconciler` centralizes the correct
  (prune-the-target) rule for all three drivers.
- **Hook ordering is load-bearing.** `TranslationCommandHook::onAfterHandle` resets AI state on its
  first line, so `SynchronizationCommandHook` must be registered *after* it for its
  `setActiveAIServiceId` to stick.
- **Factory must resolve the finder lazily.** `SynchronizationCommandHookFactory` resolves
  `StaleTranslationReadModel` from *inside* the hook, not at factory time — re-entering the CR
  registry at factory time trips the recursion guard.
- **`PublishedEvents` iterates `EventInterface`, not `EventEnvelope`** — read the raw event; `->event`
  access fails.
- **Force-rebase mints a new content stream id**, and rebase is whole-workspace (not scoped to the
  rule's dimension). A reviewer editing the target gets rebased out from under them on each `live`
  publish. Tests assert content **by workspace name**, not by hardcoded content-stream id.
- **`ask` semantic tension (accepted):** because the rebase happens at publish time for `ask` rules,
  a reviewer's conflicting edit can be dropped by *someone else's* publish, before the reviewer acts.
  Translation is still deferred; the rebase is not. Explicitly accepted.
- **Partial vs full publish.** `PublishIndividualNodesFromWorkspace` emits `WorkspaceWasPublished`
  with `partial: true`, so the hook fires on partial publishes too — but `replaceWorkspaceEntries`
  always copies the *entire* source stale set into the target (the projection doesn't distinguish
  partial). Accepted for v1. Full publish overwrites the user workspace with `live`'s post-replication
  rows; partial publish leaves the user workspace's pre-publish stale set intact.
- **Concurrency.** Two near-simultaneous publishes into the same target use optimistic locking; the
  second hits `ConcurrencyException` and the CR rethrows with no auto-retry (republish needed). A
  mid-sync abort leaves "published but only partially translated" — but it is **self-healing**: the
  stale row survives and the next publish / manual sync catches up. (Retry-on-conflict and
  "variant already exists" resilience were noted but not built.)
- **An editor workspace must be branched off `live` *after* the sync** to edit an auto-synced
  variant — the sync writes the variant to `live` after the publish already forked the editing
  workspace, so editing it in the old fork fails with "Dimension space point … is not yet occupied."
- **`@flowEntities` does not truncate workspace metadata/role tables** (they're in CR `ignoredTables`)
  — Behat needs an explicit `@BeforeScenario` prune or shared-workspace metadata collides across
  scenarios.

---

## 6. Maintenance: the reconcile command

`lostintranslation:reconcile <workspace> [<contentRepository>] [--dry-run]`
(`LostInTranslationCommandController::reconcileCommand`) walks the ContentGraph and deletes stale
rows whose aggregate no longer exists. Why it's needed: the projection clears only the
*directly-removed* aggregate on `NodeAggregateWasRemoved`; descendants (a Document's tethered content
collection, nested content) cascade away in the graph but leave orphan rows behind. The orphan
cleanup is a hybrid — read-time orphan filtering in the synchronizers **plus** this CLI for bulk
prune — rather than a live projection cascade (which would mean mirroring the whole CR hierarchy
inside the projection and handling moves; judged out of scope). `findAll()` is used here precisely
because it must scan all workspaces for orphans; the hot paths use the SQL-scoped
`findByWorkspaceAndOrigin` instead.

> Not to be confused with the deletion mirror (§4–5): `reconcile` prunes orphan **stale-tracking rows** left
> in the projection table after a node was hard-removed; the tag mirror propagates a *deletion* to the
> target-language node in the content graph. They are complementary — the former is projection housekeeping,
> the latter mirrors editor intent.
>
> Note `reconcile` only helps after a **hard** removal, since a soft-removed aggregate still exists. Stale rows
> belonging to a soft-removed (deleted) node are instead filtered at read time by
> `SynchronizationStatusProvider`, so they neither inflate the out-of-sync count nor get pruned — a restore from
> the trash bin brings the pending translation back with it.

---

## 7. Evolution / superseded decisions

Recorded because the *reasons* remain instructive; these describe earlier states, **not** current
behaviour.

- **Stale-row keying — source DSP (rejected) vs target DSP (current).** One session weighed keying
  rows by the source origin ("the en source changed; one row covers all targets") vs the target
  origin ("de needs translating"). The **target-origin** design is what shipped: the table directly
  answers "what's out of date in the target?", which is what both features query.
- **Ancestor creation moved out of `Retranslator` into synchronization.** A version had `Retranslator`
  walk the source ancestor chain and create missing ancestor documents. The user reverted it:
  "because this is a feature of synchronization, put the test and the code into synchronization." So
  `Retranslator` is pure per-node, and the synchronizers depth-sort stale records (ancestor before
  descendant) so a parent's `CreateNodeVariant` materializes before a child's. (Limitation: a parent
  that is missing from the target *and* has no stale row is unreachable by stale-driven sync — that's
  `--full`'s job.)
- **The removal-sync feature (`onSourceRemoval`) — built, then DELETED.** Mirroring source deletions went
  through three designs. (1) A projection "removal tombstone" table was rejected: the projection is
  rule-agnostic, so it would record tombstones even for rules that never consume them, and it would have to
  replicate every workspace-lifecycle handler. (2) So the consumers detected removals themselves — the publish
  hook scanned the publish's own `NodeAggregateWasRemoved` events, while the deliberate runs diffed the target
  dimension against the source (`TargetOrphanCollector`), gated by an opt-in per-rule enum
  `keep-target | remove-target` and by `scope` (`SynchronizationScope::mayRemoveNode()`). (3) That whole
  apparatus was then **deleted**, because it turned out to be solving a problem Neos does not have and could
  not solve it correctly anyway:
  - **Neos 9.1 does not hard-remove.** Deleting a node in a workspace SOFT-removes it — `TagSubtree` with
    `NeosSubtreeTag::removed()`, which is what `Neos.Workspace.Ui`'s trash bin reads; hard removals outside
    `live` are explicitly undesired (they destroy the hierarchy information the trash bin and conflict
    detection need). `SoftRemovalGarbageCollector` converts them to hard removals later, per dimension,
    *after* the publish command. So the event scan mostly never fired for a real editor deletion, and the
    GC's later hard removal was invisible to the hook. Meanwhile the *tag* mirror was already carrying the
    deletion — un-gated by `scope` — so the removal feature was a second, weaker mechanism for something the
    tag mirror does correctly by construction.
  - **A diff cannot tell "deleted" from "never there".** `TargetOrphanCollector`'s test was "present in the
    target, absent in the source", which conflates a removed source node with a target-only one. Under
    `Content` scope a target-only Document is the **expected** case (the scope exists precisely because
    adopting a Document is a deliberate manual act), and all content below such a page is target-only too — so
    a reconcile emptied editor-owned pages. An intermediate fix stopped the diff descending into a kept
    Document, which removed the data loss but left the asymmetry; deleting the pass removed the class of bug.
    The tag mirror has no such ambiguity: it only ever converges nodes present in **both** dimensions.

  **Decision:** deletions ride the tag mirror; `onSourceRemoval`, `SourceRemovalBehavior`,
  `TargetOrphanCollector`, `SynchronizationScope::mayRemoveNode()` and the `--remove-orphans` flag are gone.
  What is given up: a hard removal that never passed through a publish (a script, a fixture,
  `RemoveNodeAggregate` on `live` directly) is mirrored by nothing, and leaves a target orphan no driver will
  clean — see [§8](#8-known-gaps--future-work). What is gained beyond the bug fix: **restore** now mirrors too,
  which a hard-removal mirror could never undo.
- **The tagging opt-out (`onSourceTagging`) — also deleted; tags always sync.** The mirror was introduced as an
  opt-in enum `keep-target | sync-to-target`, on the reasoning that a reviewer might want independent
  visibility in the target dimension. Once the target dimension was stated to be a *projection* of the source
  (§4), that reasoning collapsed: "the source is the source of truth, except for tags, unless you ask" is not a
  model anyone can hold. Tag mirroring is now unconditional, and sync owns the target dimension's entire tag
  space — any `SubtreeTag`, not a fixed list. A dimension that needs independent tag state should not have a
  rule pointing at it. Retained design decisions: **not** gated by `scope` (hiding is not a structural change);
  **idempotent** (skip when the target's *explicit* state already matches, since the CR throws
  `SubtreeIsAlreadyTagged` / `SubtreeIsNotTagged`); only **explicit** tags are mirrored, so the source's tag
  *structure* is reproduced rather than its effective state. **Create-and-tag in one publish** works by
  emitting the mirrored `TagSubtree` after the node's own `CreateNodeVariant` in the returned batch — the CR
  dispatches sequentially, so the (untagged) variant exists by then, and `CreateNodeVariant` does **not** copy
  explicit subtree tags onto a peer variant (verified by test).
- **`withoutRestrictions()` is a trap.** `VisibilityConstraints::withoutRestrictions()` reads like "see
  everything" but is defined as `excludeSubtreeTags(['removed'])` — it hides soft-removed nodes, and is
  therefore *identical* to `NeosVisibilityConstraints::excludeRemoved()`. Every subgraph in this package used
  it, which is why nothing could see a soft removal to mirror it and why a soft-removed target node read as
  "absent" (inviting a `CreateNodeVariant` for a variant that exists). Current rule: **target** subgraphs and
  **both** sides of `TargetTagReconciler` use `VisibilityConstraints::createEmpty()` (genuinely everything),
  while the **source** subgraph for *translation* keeps `excludeRemoved()` — a deleted node is not a
  translation source. A review finding claiming the two synchronizers diverged here was mistaken: the two
  constraints were the same. The real defect was that both hid too much.
- **`mode: ask` deferring more than the translation.** `ask` originally skipped the tag and removal mirrors on
  publish too, leaving them to the deliberate sync. That broke the mode invariant in a way nothing surfaced:
  the out-of-sync count reads stale-translation rows, and a hide or a deletion produces none, so an `ask` rule
  could sit on pending work that no prompt ever mentioned. Mirroring is cheap (it inspects the publish's own
  events and never walks the target tree), so it now runs inline for **both** modes and `ask` defers only the
  DeepL-bound translation.
- **Auto-creating the target workspace (`ReviewWorkspaceProvisioner`) — added then reverted/deleted.**
  See [§5](#5-synchronization): a cross-workspace rule fires on every publish, so auto-create was
  wrong. The class no longer exists.
- **Trigger mechanism — `CatchUpHook` (rejected) → `CommandHookInterface::onAfterHandle` (current).**
  The CR's `SubscriptionEngine::processExclusively` lock is still held through
  `CatchUpHook::onAfterBatchCompleted`, so re-entering `cr->handle()` there throws "Subscription
  engine is already processing." `onAfterHandle` runs after the lock releases.
- **Multi-target fan-out bug (fixed).** `ReferenceDimensionSpacePointResolver` was singular
  (`return` on first match), so when `en` had two targets (`de`, `es`) only the `de` row was ever
  written. Replaced with `findAllTargetDimensionSpacePoints(): DimensionSpacePointSet` and a
  `foreach` at both projection call sites. (Target→source stays 1:1, so retranslation was unaffected.)
- **`uriPathSegment` slug coercion** moved from a hardcoded `if ($name === 'uriPathSegment')` (which
  lived in two places and could drift) to a per-NodeType-property `TranslatedPropertyPostProcessor`
  (`options.translationPostProcessor`, e.g. `UriPathSegmentPostProcessor`), read parallel to
  `options.automaticTranslation`. Default registered on `Neos.Neos:Document.uriPathSegment` but
  inactive unless the integrator also enables `automaticTranslation` on it. Rejected alternatives: a
  URI value object (Document NodeType is Neos core, out of scope) and a `TranslationConnector`
  (connectors match by value-object *type* and operate on objects; `uriPathSegment` is a string
  matched by *name*). `html_entity_decode` was deliberately left as its existing global flag.
- **Config/enum churn.** British→American spelling (`synchronis*` → `synchroniz*`), dropped the
  `Publication` prefix, settled on the command name `lostintranslation:synchronize`. Two enums
  `synchronizationStrategy`/`translationStrategy` (and a `bool $skipExisting`) were introduced, then
  the `…Strategy` classes were **deleted** when automatic sync was fixed to be stale-only and the
  rule shape was refactored; `SynchronizationScope` (Content|Document) and `SynchronizationMode`
  (auto|ask) are the surviving enums. Removed CLI flags: `--skip-existing`, `--cache`.
- **`AISystemTranslationRuntimeState` gained authorization-bypass.** Originally it only changed
  *authorship*; a VIEWER on `live` then hit "No write permissions on workspace live" (500). It now
  also disables authorization checks at the dispatch chokepoint.

---

## 8. Known gaps & future work

- **Reference properties are not synchronized.** `NodeReferencesWereSet` carries a two-level
  `(referenceName, propertyName)` structure and writing it needs `SetNodeReferences` (which *replaces*
  the whole reference set, so all targets+properties must be re-sent). Planned, not built.
- **Fork-seeding gap.** A target forked from a source that already had a backlog, with no publish
  since, reads 0 pending — `whenWorkspaceWasCreated` doesn't seed rows from the base. The one-line
  fix (`replaceWorkspaceEntries(new, base)` on creation) perturbs exact-stale assertions, so it was
  left out of scope.
- **Partial-publish stale propagation** copies the whole source stale set (see [§5](#5-synchronization)).
- **`--full` re-translates an already-cascaded tethered child once more** (it pre-fetches the stale
  set once, so a child stale at start is re-hit after its parent's cascade already translated it).
  DeepL-cached and last-write-wins, so harmless but redundant.
- **Concurrency** has no retry-on-`ConcurrencyException` and no "variant already exists" tolerance
  yet (self-healing covers the functional gap).
- Several projection handlers were specified as red TDD scenarios (Behat `todo` profile) but not all
  implemented: thinning `whenNodeAggregateTypeWasChanged`, partial publish/discard wiring,
  `whenDimensionSpacePointWasMoved`.
- **Pending tag changes and deletions are not counted by the out-of-sync status.**
  `SynchronizationStatusProvider` counts stale-translation rows, and a hide or a deletion produces none, so
  neither raises the post-publish "sync now" prompt. This no longer strands work — tag mirroring runs inline on
  every publish for both modes precisely because of this — but a change that reached the target dimension
  outside a publish still waits, unannounced, for the next deliberate sync. Counting it would require a
  target-tree diff on every status read, the expense the incremental design exists to avoid.
- **A hard removal that never passes through a publish is mirrored by nothing.** The editor path soft-removes
  and is mirrored by the tag mirror; a script or fixture issuing `RemoveNodeAggregate` on `live` directly, or
  `SoftRemovalGarbageCollector`'s own promotion of a soft removal to a hard one, produces no publish for the
  hook to react to. With `TargetOrphanCollector` gone ([§7](#7-evolution--superseded-decisions)) nothing
  reconciles the resulting target orphan. Accepted: the diff that would find it cannot distinguish a deleted
  source node from a target-only one, which is what made it dangerous.
- **Untested interaction with Neos's own soft-removal machinery.**
  `ImpendingHardRemovalConflictDetectionHook` blocks a soft→hard promotion while a workspace has pending
  changes inside the removed subtree, and the AI-authored tag commands this package dispatches *are* pending
  changes in the target workspace. A mirrored soft removal in a review workspace may therefore keep Neos from
  ever garbage-collecting the source's. Not reproduced, not tested — flagged as unknown.
- **Auto tag mirroring of create-and-tag-in-one-publish covers nodes the sync creates via a direct
  `CreateNodeVariant`** (translatable, non-tethered). The hook tags a node created in the same publish by
  ordering the mirrored `TagSubtree` after that node's `CreateNodeVariant` (the CR dispatches the batch
  sequentially, so the variant exists by then). Two narrow residuals are left to the deliberate sync's diff
  path (`TargetTagReconciler`, which has no such gap): a tagged node with **no translatable property**
  (the sync never creates it), and a **custom** tag on a **tethered** node created in the same publish.
  Note the `disabled` (hide) and `removed` tags **cannot** be applied to a tethered node at all — Neos's
  `NeosSubtreeTaggingConstraintChecks` rejects that on both source and target — so the practical "hide a
  tethered node" case does not exist; only arbitrary custom tags on tethered nodes are possible.
- The Neos 8 in-content "translation status" banner/overlay and the `ShowStatus.fusion` module view
  were **not** ported (depend on Neos 8 APIs with no Neos 9 equivalent); the inspector view covers
  the same find-stale → retranslate workflow.

---

## 9. Key files

```
Classes/
  Command/LostInTranslationCommandController.php      retranslate-node, synchronize, reconcile
  Controller/RetranslationController.php              inspector: metadata + retranslate
  Controller/WorkspaceSynchronizationController.php   UI: pending + synchronize
  Controller/LostInTranslationModuleController.php     backend module (status + glossary)

  Domain/
    Retranslator.php                                  per-node engine
    RetranslationResult.php
    WorkspaceSynchronizer.php                         stale-driven sync (manual / "sync now")
    FullWorkspaceSynchronizer.php                     CLI --full
    WorkspaceSynchronizationResult.php / PerNodeSynchronizationResult.php
    StalePropertyCommandBuilder.php                   shared translate→build pipeline
    ReferenceDimensionSpacePointResolver.php          target↔source dimension resolution
    CrossWorkspaceSynchronizationTarget.php           cross-ws preflight + force-rebase
    StaleRecordReconciler.php                         prune unsatisfiable (target) rows
    TargetTagReconciler.php                           diff-based subtree-tag mirror incl. deletions (manual / full)
    NodeTreeDepth.php                                 ancestor-before-descendant ordering
    AiCommandDispatcher.php                           AI attribution + auth bypass
    SynchronizationRule(s).php / SynchronizationScope.php / SynchronizationMode.php
    SynchronizationStatusProvider.php / RuleSynchronizationStatus.php
    Directive/ (DimensionValueDirectiveFactory, DeeplLanguagePair, …)
    PostProcessor/ (TranslatedPropertyPostProcessorInterface, UriPathSegmentPostProcessor)

  ContentRepository/
    CommandHook/SynchronizationCommandHook.php (+Factory)   auto-sync on publish
    CommandHook/TranslationCommandHook.php (+Factory)       translate-on-CreateNodeVariant cascade
    StaleTranslationProjection/                             projection, finder, maintenance, read model, type resolver
    AuthProvider/ (AISystemTranslationRuntimeState, AIAware…AuthProvider)

  Infrastructure/DeepL/ (DeepLTranslationService, cache, glossary, auth key)
  Infrastructure/Dummy/DummyTranslationService.php

Configuration/
  Settings.yaml                 nodeTranslation.* (incl. synchronization rules, commented example)
  Settings.Neos.yaml            commandHooks + projection registration (ordering matters)
  Settings.Authentication.yaml  authoritative backend request patterns (wins the merge)
  Policy.yaml                   Retranslation / Synchronization privilege grants
  Routes.yaml                   controller routes
  NodeTypes.yaml                options.automaticTranslation, options.translationPostProcessor
```
