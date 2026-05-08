<?php
/**
 * Network Appearance — font picker + custom CSS editor
 *
 * @package CleverSay
 */

if (!defined('ABSPATH')) exit;

$custom_css   = get_site_option('cleversay_custom_admin_css', '');
$font_family  = get_site_option('cleversay_admin_font_family', 'Outfit');
$font_size    = (int) get_site_option('cleversay_admin_font_size', 14);
$updated      = !empty($_GET['updated']);
$tab          = sanitize_key($_GET['tab'] ?? 'font');
$font_options = \CleverSay\NetworkAdmin::get_font_options();
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('sliders', 26); ?>
        <?php esc_html_e('Admin Appearance', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <?php if ($updated): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php esc_html_e('Saved. Changes are live immediately — refresh any open admin page to see them.', 'cleversay'); ?></p>
    </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper" style="margin-top:16px;">
        <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-custom-css&tab=font')); ?>"
           class="nav-tab <?php echo $tab === 'font' ? 'nav-tab-active' : ''; ?>">
            <?php echo \CleverSay\Icons::render('sparkles', 14); ?>
            <?php esc_html_e('Typography', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-custom-css&tab=css')); ?>"
           class="nav-tab <?php echo $tab === 'css' ? 'nav-tab-active' : ''; ?>">
            <?php echo \CleverSay\Icons::render('code', 14); ?>
            <?php esc_html_e('Custom CSS', 'cleversay'); ?>
        </a>
    </nav>

    <?php if ($tab === 'font'): ?>

    <!-- ── Typography Tab ────────────────────────────────────────── -->
    <div style="margin-top:20px;display:grid;grid-template-columns:1fr 320px;gap:24px;">

        <!-- Font picker -->
        <form method="post" action="">
            <?php wp_nonce_field('cleversay_admin_font', 'cleversay_admin_font_nonce'); ?>

            <div class="cleversay-table-card" style="padding:0;">
                <div style="padding:14px 18px;border-bottom:2px solid var(--cs-border);">
                    <h3 style="margin:0;font-size:14px;font-weight:700;">
                        <?php echo \CleverSay\Icons::render('book-open', 16); ?>
                        <?php esc_html_e('Font Family', 'cleversay'); ?>
                    </h3>
                    <p class="description" style="margin:4px 0 0;">
                        <?php esc_html_e('Applied to all CleverSay admin pages network-wide.', 'cleversay'); ?>
                    </p>
                </div>

                <div style="padding:18px;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;">
                    <?php foreach ($font_options as $value => $opt):
                        $is_selected = ($value === $font_family);
                        $google_url  = \CleverSay\NetworkAdmin::get_google_font_url($value);
                    ?>
                        <label style="display:block;cursor:pointer;">
                            <input type="radio" name="admin_font_family" value="<?php echo esc_attr($value); ?>"
                                   <?php checked($is_selected); ?>
                                   style="display:none;"
                                   onchange="document.getElementById('cs-font-preview').style.fontFamily = this.value + ', sans-serif'; document.querySelectorAll('.cs-font-card').forEach(el => el.classList.remove('is-selected')); this.closest('.cs-font-card').classList.add('is-selected');">
                            <link rel="stylesheet" href="<?php echo esc_url($google_url); ?>">
                            <div class="cs-font-card <?php echo $is_selected ? 'is-selected' : ''; ?>"
                                 style="padding:14px 16px;border:2px solid <?php echo $is_selected ? 'var(--cs-primary)' : 'var(--cs-border)'; ?>;border-radius:var(--cs-radius-sm);background:<?php echo $is_selected ? 'var(--cs-primary-light)' : 'var(--cs-bg)'; ?>;transition:all var(--cs-transition-fast);">
                                <div style="font-family:'<?php echo esc_attr($value); ?>', sans-serif;font-size:18px;font-weight:700;letter-spacing:-0.01em;color:var(--cs-text);line-height:1.2;">
                                    <?php echo esc_html($opt['label']); ?>
                                </div>
                                <div style="font-family:'<?php echo esc_attr($value); ?>', sans-serif;font-size:12px;color:var(--cs-text-tertiary);margin-top:4px;">
                                    <?php echo esc_html($opt['sample']); ?>
                                </div>
                                <div style="font-family:'<?php echo esc_attr($value); ?>', sans-serif;font-size:13px;color:var(--cs-text);margin-top:8px;line-height:1.5;">
                                    The quick brown fox jumps over the lazy dog 0123456789
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </div>

                <!-- Font size slider -->
                <div style="padding:18px;border-top:2px solid var(--cs-border);">
                    <h3 style="margin:0 0 12px;font-size:14px;font-weight:700;">
                        <?php echo \CleverSay\Icons::render('sliders', 16); ?>
                        <?php esc_html_e('Base Font Size', 'cleversay'); ?>
                    </h3>
                    <div style="display:flex;align-items:center;gap:16px;">
                        <input type="range" name="admin_font_size" id="admin_font_size"
                               min="12" max="18" step="1"
                               value="<?php echo esc_attr($font_size); ?>"
                               oninput="document.getElementById('cs-font-size-display').textContent = this.value + 'px';"
                               style="flex:1;">
                        <span id="cs-font-size-display" style="font-family:'SF Mono',monospace;font-size:14px;font-weight:600;color:var(--cs-primary);min-width:60px;text-align:right;">
                            <?php echo esc_html($font_size); ?>px
                        </span>
                    </div>
                    <p class="description" style="margin:8px 0 0;">
                        <?php esc_html_e('12px is compact, 16px is comfortable, 18px is large.', 'cleversay'); ?>
                    </p>
                </div>

                <div style="padding:14px 18px;border-top:2px solid var(--cs-border);background:var(--cs-muted);display:flex;justify-content:space-between;align-items:center;">
                    <p style="margin:0;font-size:12px;color:var(--cs-text-tertiary);">
                        <?php echo \CleverSay\Icons::render('info', 14); ?>
                        <?php esc_html_e('Applies across all client sites automatically.', 'cleversay'); ?>
                    </p>
                    <button type="submit" class="button button-primary">
                        <?php echo \CleverSay\Icons::render('check', 14); ?>
                        <?php esc_html_e('Save Font Settings', 'cleversay'); ?>
                    </button>
                </div>
            </div>
        </form>

        <!-- Live preview panel -->
        <div>
            <div class="cleversay-table-card" style="padding:0;">
                <div style="padding:14px 18px;border-bottom:2px solid var(--cs-border);">
                    <h3 style="margin:0;font-size:14px;font-weight:700;">
                        <?php echo \CleverSay\Icons::render('eye', 16); ?>
                        <?php esc_html_e('Live Preview', 'cleversay'); ?>
                    </h3>
                </div>
                <div id="cs-font-preview" style="padding:20px;font-family:'<?php echo esc_attr($font_family); ?>', sans-serif;">
                    <div style="font-size:22px;font-weight:800;letter-spacing:-0.02em;margin-bottom:8px;">
                        CleverSay Dashboard
                    </div>
                    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--cs-text-tertiary);margin-bottom:4px;">
                        Total Questions
                    </div>
                    <div style="font-size:36px;font-weight:800;letter-spacing:-0.03em;line-height:1;margin-bottom:16px;">
                        1,847
                    </div>
                    <p style="font-size:14px;line-height:1.5;color:var(--cs-text-secondary);">
                        How does tuition cost for next semester? Students should review the cost information at the Financial Services website.
                    </p>
                    <div style="display:flex;gap:6px;margin-top:12px;">
                        <span class="cleversay-badge cleversay-badge-active">Active</span>
                        <span class="cleversay-badge cleversay-badge-hold">Trial</span>
                    </div>
                </div>
            </div>

            <div class="cleversay-table-card" style="margin-top:16px;padding:14px 18px;background:var(--cs-accent-light);border-color:var(--cs-accent);">
                <p style="margin:0 0 6px;font-weight:700;color:#92400E;font-size:13px;">
                    <?php echo \CleverSay\Icons::render('lightbulb', 14); ?>
                    <?php esc_html_e('Tip', 'cleversay'); ?>
                </p>
                <p style="margin:0;font-size:12px;color:#92400E;line-height:1.5;">
                    <?php esc_html_e('Prefer 15-16px for better readability. Inter and Plus Jakarta Sans are especially friendly at larger sizes.', 'cleversay'); ?>
                </p>
            </div>
        </div>

    </div>

    <?php else: // CSS tab ?>

    <!-- ── Custom CSS Tab ───────────────────────────────────────── -->
    <div style="margin-top:20px;display:grid;grid-template-columns:1fr 320px;gap:24px;">

        <div class="cleversay-table-card" style="padding:0;">
            <div style="padding:14px 18px;border-bottom:2px solid var(--cs-border);display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:14px;font-weight:700;">
                    <?php echo \CleverSay\Icons::render('code', 16); ?>
                    <?php esc_html_e('Network-wide CSS Overrides', 'cleversay'); ?>
                </h3>
                <span style="font-size:12px;color:var(--cs-text-tertiary);">
                    <?php printf(esc_html__('%d characters', 'cleversay'), strlen($custom_css)); ?>
                </span>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('cleversay_custom_css', 'cleversay_custom_css_nonce'); ?>

                <textarea name="cleversay_custom_css"
                          id="cleversay-custom-css"
                          rows="28"
                          style="width:100%;border:none;border-radius:0;background:#f8f9fa;
                                 font-family:'SF Mono',Consolas,Monaco,monospace;
                                 font-size:13px;line-height:1.6;padding:16px 20px;
                                 resize:vertical;outline:none;color:#1e293b;"
                          spellcheck="false"
                          placeholder="/* Your custom CSS here */
.cleversay-stat-card {
    background: #fffbeb;
}"><?php echo esc_textarea($custom_css); ?></textarea>

                <div style="padding:14px 18px;border-top:2px solid var(--cs-border);background:var(--cs-muted);display:flex;justify-content:space-between;align-items:center;">
                    <p style="margin:0;font-size:12px;color:var(--cs-text-tertiary);">
                        <?php echo \CleverSay\Icons::render('info', 14); ?>
                        <?php esc_html_e('Loads after core styles on all CleverSay admin pages.', 'cleversay'); ?>
                    </p>
                    <button type="submit" class="button button-primary">
                        <?php echo \CleverSay\Icons::render('check', 14); ?>
                        <?php esc_html_e('Save Custom CSS', 'cleversay'); ?>
                    </button>
                </div>
            </form>
        </div>

        <div>
            <div class="cleversay-table-card" style="padding:0;margin-bottom:16px;">
                <div style="padding:14px 18px;border-bottom:2px solid var(--cs-border);">
                    <h3 style="margin:0;font-size:14px;font-weight:700;">
                        <?php echo \CleverSay\Icons::render('tag', 16); ?>
                        <?php esc_html_e('Common Selectors', 'cleversay'); ?>
                    </h3>
                </div>
                <div style="padding:14px 18px;font-family:'SF Mono',Consolas,Monaco,monospace;font-size:12px;line-height:2;">
                    <div><code style="background:transparent;border:none;padding:0;">.cleversay-admin</code></div>
                    <div><code style="background:transparent;border:none;padding:0;">.cleversay-stat-card</code></div>
                    <div><code style="background:transparent;border:none;padding:0;">.cleversay-table-card</code></div>
                    <div><code style="background:transparent;border:none;padding:0;">.cleversay-panel</code></div>
                    <div><code style="background:transparent;border:none;padding:0;">.cleversay-badge-active</code></div>
                    <div><code style="background:transparent;border:none;padding:0;">.button-primary</code></div>
                    <div><code style="background:transparent;border:none;padding:0;">.nav-tab-active</code></div>
                    <div><code style="background:transparent;border:none;padding:0;">.cs-icon</code></div>
                </div>
            </div>

            <div class="cleversay-table-card" style="padding:0;">
                <div style="padding:14px 18px;border-bottom:2px solid var(--cs-border);">
                    <h3 style="margin:0;font-size:14px;font-weight:700;">
                        <?php echo \CleverSay\Icons::render('layers', 16); ?>
                        <?php esc_html_e('Design Tokens', 'cleversay'); ?>
                    </h3>
                </div>
                <div style="padding:14px 18px;font-family:'SF Mono',Consolas,Monaco,monospace;font-size:12px;line-height:1.9;">
                    <div><code style="background:transparent;border:none;padding:0;">--cs-primary</code> <span style="color:#3B82F6;">#3B82F6</span></div>
                    <div><code style="background:transparent;border:none;padding:0;">--cs-secondary</code> <span style="color:#10B981;">#10B981</span></div>
                    <div><code style="background:transparent;border:none;padding:0;">--cs-accent</code> <span style="color:#F59E0B;">#F59E0B</span></div>
                    <div><code style="background:transparent;border:none;padding:0;">--cs-muted</code> #F3F4F6</div>
                    <div><code style="background:transparent;border:none;padding:0;">--cs-text</code> #111827</div>
                    <div><code style="background:transparent;border:none;padding:0;">--cs-border</code> #E5E7EB</div>
                    <div><code style="background:transparent;border:none;padding:0;">--cs-radius</code> 8px</div>
                </div>
            </div>
        </div>

    </div>

    <?php endif; ?>
</div>

<style>
.cs-font-card:hover {
    border-color: var(--cs-primary) !important;
    transform: translateY(-1px);
}
.cs-font-card.is-selected {
    border-color: var(--cs-primary) !important;
    background: var(--cs-primary-light) !important;
}
#cleversay-custom-css:focus {
    background: #fff !important;
    box-shadow: inset 3px 0 0 var(--cs-primary) !important;
}
input[type="range"] {
    accent-color: var(--cs-primary);
}
</style>
