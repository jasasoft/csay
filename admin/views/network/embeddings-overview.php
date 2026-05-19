<?php
/**
 * Network Admin — Multi-Site Embeddings Overview component.
 *
 * v4.41.0+: Added as part of the embeddings admin refactor. Replaces
 * the per-site status panel and action buttons that previously lived
 * on this network admin page (and which always ran in blog 1's context,
 * giving misleading numbers — see the v4.41.0 handoff brief).
 *
 * Read-only overview: one row per active tenant with KB counts, chunk
 * counts, queue snapshot, and a link to the per-site Embeddings admin
 * for that tenant. All actions and per-tenant settings live on the
 * per-site page.
 *
 * Iterates only blogs where TenantHelper::is_tenant_active() is true,
 * which excludes the network main site (blog 1 on jasa-server.com is
 * the network landing page, not a CleverSay tenant).
 *
 * @package CleverSay
 * @since   4.41.0
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('\\CleverSay\\TenantHelper')) {
    return;
}

$tenant_ids = \CleverSay\TenantHelper::active_tenant_ids();
?>
<div class="cleversay-table-card" style="margin-bottom:20px;">
    <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
        <h3 style="margin:0;font-size:14px;font-weight:600;">
            <?php echo \CleverSay\Icons::render('database', 16); ?>
            <?php esc_html_e('Multi-Site Embedding Status', 'cleversay'); ?>
        </h3>
    </div>
    <div style="padding:14px 18px;">
        <p class="description" style="margin-top:0;margin-bottom:14px;">
            <?php esc_html_e(
                'Read-only overview of every active CleverSay tenant. Counts of "embedded" rows are matched against current MySQL rows, so stale Supabase rows that no longer correspond to live MySQL content do not inflate the totals. To run actions for a specific tenant (Backfill, Process Queue, Retry Failed) or change a tenant\'s context-chunks setting, click through to its per-site Embeddings page.',
                'cleversay'
            ); ?>
        </p>

        <?php if (empty($tenant_ids)): ?>
            <p style="margin:0;">
                <em><?php esc_html_e('No active tenants found. A tenant is "active" when its CleverSay knowledge base or sources contain at least one row.', 'cleversay'); ?></em>
            </p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Site', 'cleversay'); ?></th>
                        <th><?php esc_html_e('KB Entries', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Source Chunks', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Queue: Pending', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Queue: Failed', 'cleversay'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tenant_ids as $blog_id):
                        $blog_id = (int) $blog_id;
                        switch_to_blog($blog_id);
                        try {
                            $stats = (new \CleverSay\Embedder())->get_queue_stats();
                        } catch (\Throwable $e) {
                            $stats = ['error' => $e->getMessage()];
                        }
                        $blog_name    = get_bloginfo('name');
                        $site_link    = admin_url('admin.php?page=cleversay-embeddings');
                        restore_current_blog();
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($blog_name ?: ('Site ' . $blog_id)); ?></strong><br>
                                <span class="description">ID <?php echo (int) $blog_id; ?></span>
                            </td>
                            <?php if (isset($stats['error'])): ?>
                                <td colspan="4">
                                    <em style="color:#b32d2e;">
                                        <?php esc_html_e('Could not load stats:', 'cleversay'); ?>
                                        <code><?php echo esc_html($stats['error']); ?></code>
                                    </em>
                                </td>
                            <?php else: ?>
                                <td>
                                    <?php echo (int) ($stats['embedded_kb_entries'] ?? 0); ?>
                                    /
                                    <?php echo (int) ($stats['total_kb_entries'] ?? 0); ?>
                                </td>
                                <td>
                                    <?php echo (int) ($stats['embedded_chunks'] ?? 0); ?>
                                    /
                                    <?php echo (int) ($stats['total_chunks'] ?? 0); ?>
                                </td>
                                <td><?php echo (int) ($stats['pending']    ?? 0); ?></td>
                                <td>
                                    <?php $failed = (int) ($stats['failed'] ?? 0); ?>
                                    <?php if ($failed > 0): ?>
                                        <strong style="color:#b32d2e;"><?php echo $failed; ?></strong>
                                    <?php else: ?>
                                        0
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <a class="button button-secondary"
                                   href="<?php echo esc_url($site_link); ?>">
                                    <?php esc_html_e('Manage →', 'cleversay'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
