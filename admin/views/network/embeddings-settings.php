<?php
/**
 * Network Embeddings Settings View
 *
 * Configures the Supabase Postgres connection for vector storage and
 * the OpenAI API key used to generate embeddings. These settings drive
 * the semantic retrieval layer (Phase 1+ of the embeddings migration).
 *
 * Until the feature flag is enabled AND a working connection is verified,
 * production traffic continues to use FULLTEXT-only retrieval.
 *
 * @package CleverSay
 * @since   4.38.0
 * @var array $settings Current Supabase settings from Supabase::get_config()
 */

if (!defined('ABSPATH')) exit;
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('database', 18); ?>
        <?php esc_html_e('Embeddings & Vector Search', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <p class="description" style="margin-bottom:20px;max-width:780px;">
        <?php esc_html_e(
            'Configure semantic retrieval via Supabase Postgres + pgvector. When enabled, queries match KB chunks by meaning (not just keywords), which fixes vocabulary mismatch cases like users asking "finish my degree" when the KB says "graduation requirements." See ARCHITECTURE.md for the design.',
            'cleversay'
        ); ?>
    </p>

    <?php if (!empty($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Settings saved.', 'cleversay'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['test_result'])):
        $result = get_transient('cleversay_supabase_test_result');
        delete_transient('cleversay_supabase_test_result');
        if (is_array($result)):
            $cls = $result['success'] ? 'notice-success' : 'notice-error';
    ?>
        <div class="notice <?php echo esc_attr($cls); ?> is-dismissible">
            <p><strong><?php echo esc_html($result['message']); ?></strong></p>
            <?php if (!empty($result['details'])): ?>
                <ul style="margin:6px 0 6px 20px;list-style:disc;">
                    <?php foreach ($result['details'] as $k => $v): ?>
                        <li>
                            <code><?php echo esc_html($k); ?></code>:
                            <?php echo esc_html(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; endif; ?>

    <?php if (!empty($_GET['schema_result'])):
        $result = get_transient('cleversay_supabase_schema_result');
        delete_transient('cleversay_supabase_schema_result');
        if (is_array($result)):
            $cls = $result['success'] ? 'notice-success' : 'notice-error';
    ?>
        <div class="notice <?php echo esc_attr($cls); ?> is-dismissible">
            <p><strong><?php echo esc_html($result['message']); ?></strong>
            <?php if (!empty($result['statements_run'])): ?>
                (<?php echo (int) $result['statements_run']; ?>
                <?php esc_html_e('statements executed', 'cleversay'); ?>)
            <?php endif; ?>
            </p>
        </div>
    <?php endif; endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('cleversay_network_supabase', 'cleversay_network_supabase_nonce'); ?>

        <!-- Feature flag -->
        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('sliders', 16); ?>
                    <?php esc_html_e('Feature Flag', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="enabled"><?php esc_html_e('Enable Embeddings', 'cleversay'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" id="enabled" value="1"
                                   <?php checked(!empty($settings['enabled'])); ?>>
                            <?php esc_html_e('Enable vector retrieval for all sites with this network', 'cleversay'); ?>
                        </label>
                        <p class="description" style="margin-top:6px;">
                            <?php esc_html_e('Controls the indexing pipeline: when on, KB entries and source chunks are embedded into Supabase. Retrieval is controlled by the separate flag below. This flag has no effect until Supabase connection and OpenAI API key are both configured.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="use_hybrid_retrieval"><?php esc_html_e('Use Hybrid Retrieval', 'cleversay'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="use_hybrid_retrieval" id="use_hybrid_retrieval" value="1"
                                   <?php checked(!empty($settings['use_hybrid_retrieval'])); ?>>
                            <?php esc_html_e('Use hybrid (vector + FULLTEXT) retrieval for source chunks', 'cleversay'); ?>
                        </label>
                        <p class="description" style="margin-top:6px;">
                            <?php esc_html_e('When off, source-chunk retrieval uses MySQL FULLTEXT only (pre-Phase-3 behavior). When on, vector and FULLTEXT results are merged via Reciprocal Rank Fusion. KB entry matching is unaffected. Falls back to FULLTEXT automatically on Supabase outage.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Supabase connection -->
        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('database', 16); ?>
                    <?php esc_html_e('Supabase Connection', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="host"><?php esc_html_e('Host', 'cleversay'); ?></label></th>
                    <td>
                        <input type="text" name="host" id="host" class="regular-text"
                               value="<?php echo esc_attr($settings['host'] ?? ''); ?>"
                               placeholder="db.your-project-ref.supabase.co">
                        <p class="description"><?php esc_html_e('From Supabase → Project Settings → Database → Connection info.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="port"><?php esc_html_e('Port', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="port" id="port" class="small-text"
                               value="<?php echo esc_attr($settings['port'] ?? 5432); ?>">
                        <p class="description"><?php esc_html_e('5432 for direct connection (recommended). 6543 for pooled connection.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="database"><?php esc_html_e('Database', 'cleversay'); ?></label></th>
                    <td>
                        <input type="text" name="database" id="database" class="regular-text"
                               value="<?php echo esc_attr($settings['database'] ?? 'postgres'); ?>">
                        <p class="description"><?php esc_html_e('Default is "postgres" (Supabase default).', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="user"><?php esc_html_e('User', 'cleversay'); ?></label></th>
                    <td>
                        <input type="text" name="user" id="user" class="regular-text"
                               value="<?php echo esc_attr($settings['user'] ?? 'postgres'); ?>">
                        <p class="description"><?php esc_html_e('Default is "postgres" (Supabase default).', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="password"><?php esc_html_e('Password', 'cleversay'); ?></label></th>
                    <td>
                        <input type="password" name="password" id="password" class="regular-text"
                               value="<?php echo esc_attr($settings['password'] ?? ''); ?>"
                               autocomplete="new-password">
                        <p class="description"><?php esc_html_e('Database password set during Supabase project creation. Leave blank to keep existing.', 'cleversay'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- OpenAI API -->
        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('key', 16); ?>
                    <?php esc_html_e('OpenAI Embeddings API', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="openai_api_key"><?php esc_html_e('OpenAI API Key', 'cleversay'); ?></label></th>
                    <td>
                        <input type="password" name="openai_api_key" id="openai_api_key" class="regular-text"
                               value="<?php echo esc_attr($settings['openai_api_key'] ?? ''); ?>"
                               autocomplete="new-password"
                               placeholder="sk-...">
                        <p class="description">
                            <?php esc_html_e('Used only for generating embeddings. Synthesis can use any provider (Claude, Gemini) via the AI Settings page.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="embedding_model"><?php esc_html_e('Embedding Model', 'cleversay'); ?></label></th>
                    <td>
                        <select name="embedding_model" id="embedding_model">
                            <option value="text-embedding-3-small"
                                <?php selected(($settings['embedding_model'] ?? 'text-embedding-3-small') === 'text-embedding-3-small'); ?>>
                                text-embedding-3-small (1536 dim, recommended)
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Only text-embedding-3-small is supported in Phase 1. The schema is fixed at 1536 dimensions.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'cleversay'); ?></button>
        </p>
    </form>

    <!-- Diagnostic actions -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php echo \CleverSay\Icons::render('activity', 16); ?>
                <?php esc_html_e('Diagnostics', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <p>
                <?php esc_html_e('Run these after saving your settings to verify the integration.', 'cleversay'); ?>
            </p>

            <form method="post" action="" style="display:inline-block;margin-right:8px;">
                <?php wp_nonce_field('cleversay_supabase_test', 'cleversay_supabase_test_nonce'); ?>
                <button type="submit" name="cleversay_supabase_action" value="test_connection"
                        class="button">
                    <?php esc_html_e('Test Connection', 'cleversay'); ?>
                </button>
            </form>

            <form method="post" action="" style="display:inline-block;margin-right:8px;"
                  onsubmit="return confirm('<?php echo esc_js(__('This will create the cleversay_chunks and cleversay_cache tables in your Supabase database. Safe to run multiple times. Proceed?', 'cleversay')); ?>');">
                <?php wp_nonce_field('cleversay_supabase_schema', 'cleversay_supabase_schema_nonce'); ?>
                <button type="submit" name="cleversay_supabase_action" value="install_schema"
                        class="button">
                    <?php esc_html_e('Install Schema', 'cleversay'); ?>
                </button>
            </form>

            <form method="post" action="" style="display:inline-block;">
                <?php wp_nonce_field('cleversay_supabase_test_embed', 'cleversay_supabase_test_embed_nonce'); ?>
                <button type="submit" name="cleversay_supabase_action" value="test_embedding"
                        class="button">
                    <?php esc_html_e('Test Embedding API', 'cleversay'); ?>
                </button>
            </form>

            <p class="description" style="margin-top:14px;">
                <strong><?php esc_html_e('Setup order:', 'cleversay'); ?></strong>
                <?php esc_html_e('1) Save settings. 2) Test Connection. 3) Install Schema. 4) Test Embedding API. 5) Enable feature flag.', 'cleversay'); ?>
            </p>
        </div>
    </div>

    <?php
    // v4.41.0+: Multi-site overview replaces the old single-site status
    // panel and action buttons. The previous panel rendered in the
    // network admin's blog context (typically blog 1, the network main
    // site), which gave operators misleading numbers about whichever
    // tenant they intended to inspect. Per-tenant operations now live
    // on the new per-site Embeddings admin page. See Bugs 1, 2, 3 in
    // the v4.41.0 handoff brief.
    if (\CleverSay\Supabase::is_enabled()) {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/embeddings-overview.php';
    }
    ?>
</div>
