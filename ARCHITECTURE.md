# CleverSay Search Pipeline Architecture

This document describes the design of the search-and-answer pipeline.
It is the source of truth for layer boundaries, LLM placement, and the
principles that govern when to use generative reasoning vs. deterministic
operations.

When changing this code, test proposed changes against this document.
If a change violates a principle here, either the change is wrong, or
the principle needs updating — never silently break the design.

---

## Pipeline

```
0. User input

1. Query resolution (rewriter)
   - reference-only resolution
   - no semantic expansion or intent modification

2. Normalization (deterministic)
   - stemming / lemmatization / token cleanup
   - no LLM involvement

3. Retrieval
   - FULLTEXT on normalized query only
   - broad fallback if needed
   - no rewritten or expanded queries

4. Routing layer (LLM reasoning boundary)
   4a. KB validation (temperature = 0)
       IF KB match exists:
           ACCEPT   → serve KB answer directly
           REFERRAL → serve KB answer with referral framing
           REJECT   → fall through to 4b
   4b. Synthesis (only if no KB acceptance)
       temperature ~0.2
       generates answer from retrieved chunks

5. Response contract (conceptual, not a call)
   - persona / tone / formatting rules
   - embedded inside synthesis prompt — NOT a separate LLM pass
```

---

## LLM Placement Principle

**LLM reasoning appears only where it has context to reason with.**

Three legitimate LLM appearances in this pipeline:

| Layer | Operation | Justification |
|-------|-----------|---------------|
| 1 | Rewriter | Has conversation history; resolves references in dialogue |
| 4a | Validator | Has retrieved KB content; judges fit |
| 4b | Synthesis | Has chunks + query + history; reasons over content |

Each LLM call is grounded — it has the information it needs to do its job.

**Where LLM reasoning was deliberately removed:**

- **Pre-retrieval query expansion** (removed): the model was being asked
  to guess KB vocabulary it could not see. The associative expansion it
  produced introduced semantic drift, dragging tangentially-related chunks
  into retrieval results. This caused both inconsistent answers (different
  chunks across runs) and misleading citations (Remedial Coursework page
  cited as a source for graduation questions).

  Retrieval is now mechanical: FULLTEXT on the user's actual query, with
  broad LIKE fallback when FULLTEXT returns weak results. Vocabulary
  bridging is handled by FULLTEXT's natural language mode and the broad
  fallback, not by predictive LLM expansion.

---

## Layer Responsibilities

### 0. User input
Whatever the user typed. Untouched until the rewriter sees it.

### 1. Query resolution (rewriter)
**Job:** Resolve references that depend on conversation history. Pronouns,
continuation phrases, fragmentary follow-ups.

**Allowed transformations:**
- Pronoun resolution: "what about it?" → "what about [referent]?"
- Continuation compression: "and GPA?" → "GPA requirement for [topic]"
- Conversation stitching when the referent is unambiguous from history

**NOT allowed:**
- Semantic expansion (adding synonyms or related concepts)
- Intent reinterpretation
- Adding context the user did not include
- Rewriting standalone, well-formed questions

**Triggers (current):** third-person pronouns (it, that, those, they)
or explicit continuation phrases (what about, and, also, tell me more).
Standalone questions pass through unchanged regardless of length.

### 2. Normalization (deterministic)
**Job:** Mechanical text cleanup so retrieval can match against indexed
content reliably.

**Operations:** stemming (registers → register), lemmatization (running
→ run), case normalization, punctuation handling, light stopword filtering.

**Implementation:** existing word-processing in `Search` class. No LLM.

### 3. Retrieval
**Job:** Find chunks that match the user's normalized query.

**Path:**
1. FULLTEXT NATURAL LANGUAGE MODE on the normalized query
2. If FULLTEXT returns weak/no results → broad LIKE search as fallback

Both paths are deterministic. Same query produces same chunks every time.

**No expansion. No re-ranking. No LLM.**

### 4. Routing layer (LLM reasoning boundary)

This is a decision layer, not a single step. Two branches, mutually
exclusive in serving terms but sequentially related when validator rejects.

#### 4a. KB validation
Runs only when retrieval found a confident KB match (`layer1_strong`).

**Temperature: 0** (the routing decision must be deterministic — same
KB entry against same query phrasing should always produce the same
routing).

**Output schema:** `{"decision": "ACCEPT|REFERRAL|REJECT", "reason": "..."}`

**Routing:**
- ACCEPT → serve KB answer directly
- REFERRAL → serve KB answer with referral framing (still a successful path)
- REJECT → fall through to 4b (synthesis takes over)

#### 4b. Synthesis
Runs when no KB match exists OR when validator rejected the KB match.

**Temperature: ~0.2** (controlled — enough determinism for consistency,
enough variation for natural prose).

**Inputs:** retrieved chunks + user query + conversation history.

**Inside the synthesis call (NOT separate LLM passes):**
- ANSWER PLANNING RULE — model identifies user goal and important
  components before writing
- TONE AND VOICE — peer-helper voice constraints
- CONTACT INFORMATION RULE — two-form decision-support model
- FOLLOW-UP SUGGESTION RULE — single follow-up question after main answer
- CRITICAL FORMATTING RULES — concise, no bullets, no filler

These are all sections of the same prompt, applied in one call.

### 5. Response contract (conceptual)

Persona, tone, and formatting are **concerns**, not pipeline steps.
They are applied within the synthesis prompt. There is no separate
persona-rewrite LLM pass. Adding one would re-introduce the kind of
multi-stage complexity this architecture deliberately avoids.

---

## Behavioral Configuration

| Operation | Temperature | Why |
|-----------|------------|-----|
| Validator | 0 | Routing decisions must be deterministic |
| Synthesis | ~0.2 | Controlled variation in prose |
| Rewriter | (provider default) | Reference resolution is bounded enough |

If you find yourself wanting to add an LLM step somewhere new, ask:

1. Does it have the context it needs to reason well? If no, the call
   probably belongs at a different stage of the pipeline.
2. What temperature is appropriate for what it produces? Routing decisions
   want 0; prose generation wants modest stochasticity.
3. Does it duplicate what an existing layer is doing? Synthesis already
   has access to query, chunks, and history — most "extra reasoning"
   ideas can be incorporated as another section of the synthesis prompt
   instead of a separate call.

---

## What Changed Recently

This architecture was finalized in v4.37.142, which:

1. **Disabled pre-retrieval LLM query expansion** (root fix for retrieval
   drift). The setting `cleversay_ai_expand_queries` now defaults to
   false. Admins can re-enable for testing but production should leave
   it off.

2. **Set validator temperature to 0** (was provider default).
   Same KB entry against same query phrasing now produces the same
   routing decision every time.

3. **Added regression-guard comments** at sensitive layer boundaries
   (rewriter, retrieval) to prevent future drift back into removed
   behaviors.

4. **Created this document** as the source of truth for the design.

In v4.38.0, Phase 1 of the embeddings migration (see Scaling Trajectory below):

5. **Added Supabase Postgres + pgvector infrastructure** for vector
   storage. The connection layer (`Supabase` class) and embedding API
   client (`Embeddings` class) are in place but inert until the feature
   flag is enabled. Production retrieval still uses FULLTEXT-only.

6. **Added Embeddings admin page** at Network → Embeddings for
   configuring the Supabase connection, OpenAI API key, and feature
   flag. Includes diagnostic actions (Test Connection, Install Schema,
   Test Embedding API).

---

## Scaling Trajectory

The current FULLTEXT-based retrieval architecture (Layer 3) is appropriate
for early-stage development but has known limits at multi-tenant production
scale. This section documents the planned evolution.

### Known Limitations of Current Architecture

**Vocabulary mismatch is unhandled at the retrieval layer.** FULLTEXT
ranks chunks by word overlap. When a user uses different vocabulary
than the KB authors did ("finish my degree" vs "graduation requirements"),
FULLTEXT cannot bridge — it returns chunks with high keyword overlap
even when those chunks aren't semantically relevant.

**Per-tenant content tuning would not scale.** The product positioning
("accurate, credible, minimal hands-on") does not allow per-tenant
manual alias tables, expansion prompt tuning, or KB content patches.
Each of those approaches violates the maintenance constraint.

**Citation accuracy degrades on vocabulary-mismatch queries.** When
FULLTEXT promotes tangentially-relevant pages, those pages also
appear as cited sources, undermining the credibility of the system.

### Planned Migration: Embeddings + Hybrid Retrieval

Vector retrieval via OpenAI's text-embedding-3-small + pgvector on
Supabase. Hybrid with the existing FULLTEXT layer using Reciprocal
Rank Fusion (RRF) for merge.

**Architecture change is local to Layer 3.** Rewriter, normalization,
validator, and synthesis are unchanged. Only the Retrieval layer
becomes hybrid.

```
Current Layer 3:
  FULLTEXT primary → broad fallback

Future Layer 3:
  ├── Vector search (Supabase pgvector)
  ├── FULLTEXT search (MySQL, current)
  ↓
  RRF merge + small boosts (recency, source authority)
  ↓
  Distance gate: refuse if best score < threshold
  ↓
  Top K to routing layer (validator/synthesis)
```

### Phase 1 Status (v4.38.0)

**In place:**
- Supabase connection layer (`includes/class-supabase.php`)
- OpenAI embeddings API client (`includes/class-embeddings.php`)
- Schema (`includes/sql/supabase-schema.sql`): chunks table with
  vector(1536), HNSW index for vector similarity, GIN index for
  FULLTEXT, B-tree indexes for tenant_id and is_current; cache table
  with TTL
- Network admin page at Network → Embeddings
- Feature flag: `cleversay_network_supabase.enabled`

**Not yet in place (future phases):**
- Phase 2: indexing pipeline integration (writes embeddings to
  Supabase when MySQL chunks change)
- Phase 3: hybrid retrieval implementation in `find_relevant_chunks()`
- Phase 4: cutover from FULLTEXT-only to hybrid as default

Phase 1 is inert in production. Enabling the feature flag without
completing Phase 2-4 has no effect — the retrieval code still uses
FULLTEXT only.

### Phase 2 Status (v4.39.0)

**In place:**
- Embedder class (`includes/class-embedder.php`) — owns the
  embedding lifecycle
- Embedding queue table (`cleversay_embedding_queue`) — tracks
  jobs in pending/processing/done/failed states with retry counts
- Hooks into existing code:
  - `Indexer::store_chunks()` queues source chunks for async embedding
  - `Sources::delete()` triggers embedding cleanup on source removal
  - KB entry save (in `admin/class-admin.php`) embeds synchronously
  - KB entry delete triggers embedding cleanup
- WP-Cron processor: `cleversay_process_embeddings` runs every 5 minutes
- Custom cron schedule: `cleversay_5min` (300 second interval)
- Admin UI extensions on the Embeddings settings page:
  - Queue status panel showing pending/processing/failed counts
  - "Backfill All Existing Content" button (one-time bulk operation)
  - "Process Queue Now" button (manual trigger for testing)
  - "Retry Failed Jobs" button (resets exhausted retries)

**Hybrid sync mode** — admin-driven KB saves embed synchronously
(immediate feedback, ~1-3 sec admin save latency); source crawls and
backfill operations queue async (no admin latency, processed by cron).

**Failure handling** — embedding failures NEVER block MySQL writes.
The original save/index always succeeds. Failed embedding jobs retry
up to 3 times before being marked permanently failed for admin
review via the "Retry Failed Jobs" button.

**Not yet in place:**
- Phase 3: hybrid retrieval implementation in `find_relevant_chunks()`
- Phase 4: cutover from FULLTEXT-only to hybrid as default

Phase 2 is **active** but still doesn't affect retrieval. Embeddings
flow into Supabase whenever content is created/updated/deleted with
the feature flag on. The retrieval layer continues using FULLTEXT
only until Phase 3 ships.

**System cron recommendation**: For reliable processing, configure
your hosting (cPanel) to hit wp-cron.php every 5 minutes:

```
*/5 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null
```

Without system cron, embedding processing depends on incidental site
traffic to fire WP-Cron checks. Acceptable for Phase 2 since retrieval
isn't using embeddings yet, but recommended before Phase 4 cutover.

### Operational Costs

Estimated monthly cost at moderate multi-tenant scale (10-30 tenants):

| Component | Cost |
|-----------|------|
| Supabase Pro plan | $25/month |
| Supabase IPv4 add-on | $4/month |
| OpenAI embedding API (indexing + queries) | ~$5-20/month |
| **Total** | **~$35-50/month** |

This cost is essentially fixed regardless of tenant count up to several
hundred tenants — the vector storage and query costs scale sub-linearly
with content volume.

### Reading Order for the Migration

1. This document (the design)
2. ARCHITECTURE.md → "Phase 1 Status" (current implementation)
3. `includes/class-supabase.php` (connection layer)
4. `includes/class-embeddings.php` (embeddings API)
5. `includes/sql/supabase-schema.sql` (schema)
6. `admin/views/network/embeddings-settings.php` (admin UI)

Each phase will be added to this section as it completes, so future
maintainers can see the migration history in one place.

---

## Reading Order for New Maintainers

1. Read this document (the design)
2. Read the regression-guard comment blocks in:
   - `public/class-public.php` → `resolve_followup()` (rewriter)
   - `includes/class-indexer.php` → `find_relevant_chunks()` (retrieval)
   - `includes/class-ai.php` → `validate_kb_answer()` (validator)
   - `includes/class-ai.php` → `answer_with_context()` (synthesis)
3. Then read the code

The comments at each layer enforce the principles in this document.
The principles in this document explain why the comments exist.
