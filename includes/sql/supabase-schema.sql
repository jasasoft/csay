-- =============================================================================
-- CleverSay Supabase Schema
-- =============================================================================
--
-- This file is run by Supabase::install_schema() and from the admin UI.
-- It is idempotent — safe to run multiple times. Uses CREATE ... IF NOT EXISTS
-- throughout.
--
-- Tables created:
--   cleversay_chunks  — chunk text + vector embedding + metadata
--   cleversay_cache   — query result cache with TTL
--
-- Indexes created:
--   tenant_idx          — fast tenant scoping (B-tree)
--   source_idx          — fast lookup by source (for re-indexing)
--   current_idx         — filter to is_current=true rows (for retrieval)
--   updated_idx         — recency-based queries
--   embedding_hnsw      — vector similarity search (HNSW)
--   fts_idx             — fulltext search (GIN on tsvector)
--   cache_tenant_idx    — fast tenant-scoped cache invalidation
--   cache_expires_idx   — cleanup of expired cache entries
--
-- Architecture: see ARCHITECTURE.md
-- =============================================================================

-- Activate pgvector extension. No-op if already enabled.
CREATE EXTENSION IF NOT EXISTS vector;

-- =============================================================================
-- Chunks table
-- =============================================================================
--
-- One row per indexed chunk per source. When a source is re-indexed,
-- old rows are marked is_current=FALSE rather than deleted (audit trail
-- and rollback capability). Retrieval queries always filter is_current=TRUE.
--
-- The embedding column stores the 1536-dimensional vector produced by
-- OpenAI's text-embedding-3-small. If we change embedding models in the
-- future, the embedding_model and embedding_version columns let us
-- track which model produced which row, supporting gradual migration.
--
-- The chunk_hash column is sha256(chunk_text) — used for deduplication
-- and to detect when re-indexing produced identical content (skip
-- re-embedding to save API costs).

CREATE TABLE IF NOT EXISTS cleversay_chunks (

    id                BIGSERIAL PRIMARY KEY,

    -- Multi-tenant isolation. Filter EVERY query by this column.
    tenant_id         VARCHAR(191) NOT NULL,

    -- What kind of content this came from.
    --   'chunk'    — a row from cleversay_chunks (derived from a source)
    --   'kb_entry' — a row from cleversay_knowledge (manually authored Q&A)
    content_type      VARCHAR(32) NOT NULL,

    -- The MySQL primary key of the source content. For content_type='chunk'
    -- this is cleversay_chunks.id; for 'kb_entry' it's cleversay_knowledge.id.
    -- (tenant_id, content_type, content_id) is the natural unique key.
    content_id        BIGINT NOT NULL,

    -- Maps back to the original "source" in MySQL. For chunks this is the
    -- cleversay_sources row; for KB entries it equals content_id.
    -- Used for re-indexing operations that need to clear all rows tied
    -- to a particular source.
    source_id         BIGINT NOT NULL,

    -- sha256 hex of chunk_text. Used for dedup and unchanged-content detection.
    chunk_hash        VARCHAR(64) NOT NULL,

    -- Position of this chunk within its source (0-indexed).
    chunk_index       INT NOT NULL DEFAULT 0,

    -- The actual text content. Used for FULLTEXT search and synthesis context.
    chunk_text        TEXT NOT NULL,

    -- Flexible per-chunk metadata. Suggested keys:
    --   source_type:   'kb_entry' | 'crawled_page' | 'document'
    --   source_url:    full URL if applicable
    --   source_title:  human-readable source title
    --   authority:     'high' | 'medium' | 'low'
    --   page_number:   for PDF chunks
    metadata          JSONB,

    -- Vector embedding from OpenAI text-embedding-3-small (1536 dims).
    -- Other models (e.g. text-embedding-3-large at 3072) would need a
    -- different column type, hence the embedding_model tracking.
    embedding         vector(1536),

    -- Track which embedding model produced this row.
    embedding_model   VARCHAR(64) NOT NULL DEFAULT 'text-embedding-3-small',
    embedding_version INT NOT NULL DEFAULT 1,

    -- Soft delete flag. When a source is re-indexed, old rows get
    -- is_current=FALSE. Retrieval filters WHERE is_current=TRUE.
    is_current        BOOLEAN NOT NULL DEFAULT TRUE,

    -- Timestamps for recency boosts and cleanup queries.
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- Cache table
-- =============================================================================
--
-- Stores query results so identical queries from the same tenant can
-- skip retrieval and synthesis entirely. Invalidated when the tenant's
-- KB content changes (handled in the indexing pipeline).
--
-- The response is stored as JSON containing both the answer text and
-- citation metadata so cache hits return fully-rendered responses.
-- The context_hash lets us detect when retrieved chunks change even
-- if the query is the same — useful for diagnostics.

CREATE TABLE IF NOT EXISTS cleversay_cache (

    cache_key     VARCHAR(64) PRIMARY KEY,

    tenant_id     VARCHAR(191) NOT NULL,

    -- JSON-encoded payload: {answer: string, citations: array}
    response      TEXT NOT NULL,

    -- Stored separately for direct queries on citation source_ids.
    citations     JSONB,

    -- sha256 of the chunks used to generate the answer. Diagnostic only.
    context_hash  VARCHAR(64),

    expires_at    TIMESTAMP NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- Indexes — chunks table
-- =============================================================================

-- Multi-tenant isolation index. Every retrieval query filters by tenant_id.
CREATE INDEX IF NOT EXISTS cleversay_chunks_tenant_idx
    ON cleversay_chunks (tenant_id);

-- Source lookup index. Used during re-indexing to mark old rows stale.
CREATE INDEX IF NOT EXISTS cleversay_chunks_source_idx
    ON cleversay_chunks (tenant_id, content_type, source_id);

-- Content lookup index for the unique-by-content key. Used to detect
-- whether a piece of content already has an embedding row.
CREATE INDEX IF NOT EXISTS cleversay_chunks_content_idx
    ON cleversay_chunks (tenant_id, content_type, content_id);

-- is_current index. Retrieval queries always filter on is_current=TRUE.
-- Combined with tenant_id this is the hot path.
CREATE INDEX IF NOT EXISTS cleversay_chunks_current_idx
    ON cleversay_chunks (tenant_id, is_current)
    WHERE is_current = TRUE;

-- Recency index. Used for cleanup of old soft-deleted rows and recency boosts.
CREATE INDEX IF NOT EXISTS cleversay_chunks_updated_idx
    ON cleversay_chunks (updated_at);

-- HNSW vector similarity index. Cosine distance is the right metric for
-- OpenAI embeddings (which are L2-normalized — cosine == dot product up
-- to a constant). m=16 and ef_construction=64 are pgvector defaults; can
-- be tuned later if recall/latency tradeoffs warrant.
CREATE INDEX IF NOT EXISTS cleversay_chunks_embedding_hnsw
    ON cleversay_chunks
    USING hnsw (embedding vector_cosine_ops);

-- Postgres full-text search index. Used as the keyword-matching arm of
-- hybrid retrieval, alongside vector similarity. GIN on tsvector is the
-- standard approach.
CREATE INDEX IF NOT EXISTS cleversay_chunks_fts_idx
    ON cleversay_chunks
    USING GIN (to_tsvector('english', chunk_text));

-- =============================================================================
-- Indexes — cache table
-- =============================================================================

-- Fast tenant-scoped cache invalidation (DELETE WHERE tenant_id = X).
CREATE INDEX IF NOT EXISTS cleversay_cache_tenant_idx
    ON cleversay_cache (tenant_id);

-- Cleanup of expired entries (DELETE WHERE expires_at < NOW()).
CREATE INDEX IF NOT EXISTS cleversay_cache_expires_idx
    ON cleversay_cache (expires_at);

-- =============================================================================
-- End of schema
-- =============================================================================
