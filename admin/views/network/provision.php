<?php
/**
 * Network admin view — Provision New Client wizard
 *
 * Expected in scope:
 *   $notice       — ['type' => 'success'|'error', 'msg' => string] or null
 *   $form_values  — array (sticky values after failed submission)
 *   $kb_packs     — array from StarterKB::packs()
 *
 * @package CleverSay
 */
defined('ABSPATH') || exit;

$main_domain = defined('DOMAIN_CURRENT_SITE') ? DOMAIN_CURRENT_SITE : $_SERVER['HTTP_HOST'];
$fv = fn($k, $d = '') => esc_attr($form_values[$k] ?? $d);
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('user-plus', 18); ?>
        <?php esc_html_e('Provision New Client', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <?php if (!empty($notice)): ?>
        <div class="notice notice-<?php echo $notice['type'] === 'success' ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo wp_kses_post($notice['msg']); ?></p>
        </div>
    <?php endif; ?>

    <p style="max-width:780px;color:#50575e;">
        <?php esc_html_e('Spin up a fully-configured CleverSay site for a new client in one step. This creates the subsite, installs their persona and branding, seeds a starter knowledge base, and (optionally) emails them a welcome with login instructions.', 'cleversay'); ?>
    </p>

    <!-- ── Before you start: manual hosting prerequisites ────────────── -->
    <details class="cleversay-panel" style="margin-bottom:16px;" open>
        <summary style="cursor:pointer;padding:14px 16px;font-weight:600;font-size:14px;color:#1d2327;list-style:none;display:flex;align-items:center;gap:8px;">
            <?php echo \CleverSay\Icons::render('alert-triangle', 16); ?>
            <?php esc_html_e('Before you start', 'cleversay'); ?>
            <span style="margin-left:auto;font-weight:400;color:#646970;font-size:12px;"><?php esc_html_e('click to toggle', 'cleversay'); ?></span>
        </summary>
        <div style="padding:0 16px 16px 16px;border-top:1px solid #e0e0e0;font-size:13px;color:#3c434a;line-height:1.6;">
            <p style="margin-top:14px;">
                <?php esc_html_e('WordPress can register a new subsite, but it can\'t create the actual subdomain on your server. Before running this wizard:', 'cleversay'); ?>
            </p>
            <ol style="margin-left:20px;">
                <li>
                    <strong><?php esc_html_e('Add the subdomain in cPanel.', 'cleversay'); ?></strong>
                    <?php esc_html_e('Go to cPanel → Domains → Create a new domain, point it to the same document root as your main site.', 'cleversay'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Wait for DNS to propagate.', 'cleversay'); ?></strong>
                    <?php esc_html_e('Usually a few seconds on shared hosting, but can take up to 30 minutes.', 'cleversay'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Issue an SSL certificate.', 'cleversay'); ?></strong>
                    <?php esc_html_e('Most hosts run AutoSSL automatically. In cPanel go to SSL/TLS Status and click Run AutoSSL if it hasn\'t picked up the new subdomain yet.', 'cleversay'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Use the Check button below.', 'cleversay'); ?></strong>
                    <?php esc_html_e('The wizard verifies the subdomain is reachable over HTTPS before creating the WordPress site.', 'cleversay'); ?>
                </li>
            </ol>
        </div>
    </details>

    <form method="post" class="cleversay-provision-form">
        <?php wp_nonce_field('cleversay_provision_new_client', '_cs_nonce'); ?>

        <!-- ── Site identity ─────────────────────────────────────────── -->
        <div class="cleversay-panel">
            <div class="cleversay-panel-header">
                <h2 style="margin:0;">
                    <?php echo \CleverSay\Icons::render('globe', 16); ?>
                    <?php esc_html_e('Site', 'cleversay'); ?>
                </h2>
            </div>
            <div class="cleversay-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="subdomain"><?php esc_html_e('Subdomain', 'cleversay'); ?> <span style="color:#d63638;">*</span></label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <input type="text" id="subdomain" name="subdomain" value="<?php echo $fv('subdomain'); ?>" required class="regular-text"
                                       pattern="[a-z0-9-]+" placeholder="client-slug"
                                       style="font-family:monospace;max-width:220px;">
                                <span style="color:#50575e;font-family:monospace;">.<?php echo esc_html($main_domain); ?></span>
                                <button type="button" id="cs-check-subdomain" class="button">
                                    <?php echo \CleverSay\Icons::render('refresh-cw', 14); ?>
                                    <?php esc_html_e('Check reachability', 'cleversay'); ?>
                                </button>
                            </div>
                            <div id="cs-subdomain-result" style="display:none;margin-top:10px;padding:10px 12px;border-radius:6px;font-size:13px;line-height:1.5;"></div>
                            <p class="description">
                                <?php esc_html_e('Lowercase letters, numbers, and hyphens only. This becomes the URL.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="title"><?php esc_html_e('Site Title', 'cleversay'); ?> <span style="color:#d63638;">*</span></label></th>
                        <td>
                            <input type="text" id="title" name="title" value="<?php echo $fv('title'); ?>" required class="regular-text"
                                   placeholder="<?php esc_attr_e('e.g. UWW Financial Aid Chatbot', 'cleversay'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_name"><?php esc_html_e('Organization Name', 'cleversay'); ?> <span style="color:#d63638;">*</span></label></th>
                        <td>
                            <input type="text" id="client_name" name="client_name" value="<?php echo $fv('client_name'); ?>" required class="regular-text"
                                   placeholder="<?php esc_attr_e('e.g. University of Wisconsin–Whitewater', 'cleversay'); ?>">
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- ── Bot persona ───────────────────────────────────────────── -->
        <div class="cleversay-panel">
            <div class="cleversay-panel-header">
                <h2 style="margin:0;">
                    <?php echo \CleverSay\Icons::render('message-circle', 16); ?>
                    <?php esc_html_e('Chatbot Persona', 'cleversay'); ?>
                </h2>
            </div>
            <div class="cleversay-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="persona_short"><?php esc_html_e('Short Name', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" id="persona_short" name="persona_short" value="<?php echo $fv('persona_short'); ?>" class="regular-text"
                                   placeholder="<?php esc_attr_e('e.g. UWW', 'cleversay'); ?>">
                            <p class="description">
                                <?php esc_html_e('Short form used by the bot in conversation. Defaults to organization name if blank.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mascot"><?php esc_html_e('Mascot / Bot Name', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" id="mascot" name="mascot" value="<?php echo $fv('mascot'); ?>" class="regular-text"
                                   placeholder="<?php esc_attr_e('e.g. Warhawk', 'cleversay'); ?>">
                            <p class="description">
                                <?php esc_html_e('Optional. The bot can introduce itself by name.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="topics"><?php esc_html_e('Main Topics', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" id="topics" name="topics" value="<?php echo $fv('topics'); ?>" class="regular-text"
                                   placeholder="<?php esc_attr_e('e.g. financial aid, scholarships, FAFSA', 'cleversay'); ?>">
                            <p class="description">
                                <?php esc_html_e('Comma-separated. The bot mentions these when greeting visitors.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="audience"><?php esc_html_e('Primary Audience', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" id="audience" name="audience" value="<?php echo $fv('audience'); ?>" class="regular-text"
                                   placeholder="<?php esc_attr_e('e.g. prospective students and their families', 'cleversay'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tone"><?php esc_html_e('Tone', 'cleversay'); ?></label></th>
                        <td>
                            <?php $t = $form_values['tone'] ?? 'friendly'; ?>
                            <select id="tone" name="tone">
                                <option value="friendly" <?php selected($t, 'friendly'); ?>><?php esc_html_e('Friendly', 'cleversay'); ?></option>
                                <option value="formal"   <?php selected($t, 'formal'); ?>><?php esc_html_e('Formal', 'cleversay'); ?></option>
                                <option value="casual"   <?php selected($t, 'casual'); ?>><?php esc_html_e('Casual', 'cleversay'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="primary_color"><?php esc_html_e('Primary Color', 'cleversay'); ?></label></th>
                        <td>
                            <input type="color" id="primary_color" name="primary_color" value="<?php echo $fv('primary_color', '#3B82F6'); ?>">
                            <p class="description">
                                <?php esc_html_e('Accent color used in the widget.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- ── Starter KB ────────────────────────────────────────────── -->
        <div class="cleversay-panel">
            <div class="cleversay-panel-header">
                <h2 style="margin:0;">
                    <?php echo \CleverSay\Icons::render('book-open', 16); ?>
                    <?php esc_html_e('Starter Knowledge Base', 'cleversay'); ?>
                </h2>
            </div>
            <div class="cleversay-panel-body">
                <p class="description" style="margin-bottom:12px;">
                    <?php esc_html_e('Pick a KB pack that matches this client\'s office or industry. Clients can edit or delete these entries after setup — they\'re just a starting point.', 'cleversay'); ?>
                </p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;">
                    <?php
                    $selected_pack = $form_values['starter_kb'] ?? 'admissions';
                    foreach ($kb_packs as $slug => $pack):
                        $count = count($pack['entries']);
                    ?>
                        <label style="display:block;border:2px solid <?php echo $selected_pack === $slug ? '#3B82F6' : '#dcdcde'; ?>;border-radius:8px;padding:12px;cursor:pointer;transition:all 0.15s;background:<?php echo $selected_pack === $slug ? '#eff6ff' : '#fff'; ?>;"
                               class="cs-kb-pack-label">
                            <input type="radio" name="starter_kb" value="<?php echo esc_attr($slug); ?>"
                                   <?php checked($selected_pack, $slug); ?>
                                   style="margin-right:8px;vertical-align:middle;">
                            <strong style="vertical-align:middle;"><?php echo esc_html($pack['label']); ?></strong>
                            <?php if ($count > 0): ?>
                                <span style="float:right;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">
                                    <?php printf(esc_html(_n('%d entry', '%d entries', $count, 'cleversay')), $count); ?>
                                </span>
                            <?php endif; ?>
                            <div style="margin-top:6px;font-size:12px;color:#50575e;line-height:1.5;">
                                <?php echo esc_html($pack['description']); ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Plan & access ─────────────────────────────────────────── -->
        <div class="cleversay-panel">
            <div class="cleversay-panel-header">
                <h2 style="margin:0;">
                    <?php echo \CleverSay\Icons::render('briefcase', 16); ?>
                    <?php esc_html_e('Plan &amp; Access', 'cleversay'); ?>
                </h2>
            </div>
            <div class="cleversay-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="plan"><?php esc_html_e('Plan', 'cleversay'); ?></label></th>
                        <td>
                            <?php $pv = $form_values['plan'] ?? 'trial'; ?>
                            <select id="plan" name="plan">
                                <option value="trial" <?php selected($pv, 'trial'); ?>><?php esc_html_e('Trial (30 days)', 'cleversay'); ?></option>
                                <option value="basic" <?php selected($pv, 'basic'); ?>><?php esc_html_e('Basic', 'cleversay'); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Trials auto-expire 30 days from activation. You can upgrade or extend later from Client Sites.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_email"><?php esc_html_e('Client Email', 'cleversay'); ?></label></th>
                        <td>
                            <input type="email" id="client_email" name="client_email" value="<?php echo $fv('client_email'); ?>" class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Used for the client record and (optionally) login credentials.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Send Login Credentials?', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="send_credentials" value="1" <?php checked(!empty($form_values['send_credentials'])); ?>>
                                <?php esc_html_e('Create a WordPress admin user and email the client a welcome message with login instructions', 'cleversay'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Leave unchecked if you want to handle setup yourself and share access later.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- ── Submit ─────────────────────────────────────────────────── -->
        <div id="cs-force-proceed-wrap" style="<?php echo !empty($notice) && $notice['type'] === 'error' && strpos($notice['msg'], 'Proceed anyway') !== false ? '' : 'display:none;'; ?>margin:12px 0;padding:10px 14px;background:#fef9e7;border:1px solid #f5c74c;border-radius:6px;">
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                <input type="checkbox" name="force_proceed" value="1" style="margin-top:3px;" <?php echo !empty($_POST['force_proceed']) ? 'checked' : ''; ?>>
                <span style="font-size:13px;">
                    <strong><?php esc_html_e('Proceed anyway.', 'cleversay'); ?></strong>
                    <?php esc_html_e('I understand the subdomain isn\'t fully reachable yet, but I want to create the WordPress site now. Visitors won\'t be able to reach it until DNS and SSL are working.', 'cleversay'); ?>
                </span>
            </label>
        </div>
        <p class="submit">
            <input type="submit" name="cleversay_provision_submit" class="button button-primary button-large"
                   value="<?php esc_attr_e('Create Client Site', 'cleversay'); ?>">
            <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-sites')); ?>" class="button button-large">
                <?php esc_html_e('Cancel', 'cleversay'); ?>
            </a>
        </p>
    </form>
</div>

<script>
// Check-subdomain nonce is separate from the form nonce
var cs_check_nonce = <?php echo wp_json_encode(wp_create_nonce('cleversay_provision_check')); ?>;
var cs_ajax_url   = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

// ── Reachability check button ─────────────────────────────────────────
(function () {
    var btn    = document.getElementById('cs-check-subdomain');
    var input  = document.getElementById('subdomain');
    var result = document.getElementById('cs-subdomain-result');
    var forceWrap = document.getElementById('cs-force-proceed-wrap');
    if (!btn || !input || !result) return;

    function showResult(state, message, fullDomain, detail) {
        var bg, border, color, icon;
        switch (state) {
            case 'ssl_ok':
                bg = '#ecfdf5'; border = '#10B981'; color = '#065F46'; icon = '✅';
                if (forceWrap) forceWrap.style.display = 'none';
                break;
            case 'http_only':
                bg = '#fef9e7'; border = '#f5c74c'; color = '#92400E'; icon = '⚠️';
                if (forceWrap) forceWrap.style.display = 'block';
                break;
            case 'dns_fail':
                bg = '#fee2e2'; border = '#EF4444'; color = '#991B1B'; icon = '❌';
                if (forceWrap) forceWrap.style.display = 'block';
                break;
            case 'collision':
                bg = '#ede9fe'; border = '#8B5CF6'; color = '#5B21B6'; icon = '⛔';
                if (forceWrap) forceWrap.style.display = 'none';
                break;
            default:
                bg = '#f6f7f7'; border = '#c3c4c7'; color = '#1d2327'; icon = 'ℹ️';
        }
        var detailHtml = detail ? ('<div style="margin-top:4px;font-size:11px;color:' + color + ';opacity:0.75;">' + detail + '</div>') : '';
        result.style.display = 'block';
        result.style.background   = bg;
        result.style.borderLeft   = '4px solid ' + border;
        result.style.color        = color;
        result.innerHTML = '<strong>' + icon + ' ' + (fullDomain || '') + '</strong><div style="margin-top:4px;">' + message + '</div>' + detailHtml;
    }

    btn.addEventListener('click', function () {
        var sub = (input.value || '').trim();
        if (!sub) {
            showResult('dns_fail', 'Enter a subdomain first.', '');
            return;
        }
        btn.disabled = true;
        var originalText = btn.innerHTML;
        btn.innerHTML = 'Checking…';
        result.style.display = 'block';
        result.style.background = '#f6f7f7';
        result.style.borderLeft = '4px solid #c3c4c7';
        result.style.color = '#1d2327';
        result.innerHTML = 'Checking ' + sub + '…';

        var data = new FormData();
        data.append('action', 'cleversay_check_subdomain');
        data.append('nonce', cs_check_nonce);
        data.append('subdomain', sub);

        fetch(cs_ajax_url, { method: 'POST', credentials: 'include', body: data })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                btn.disabled = false;
                btn.innerHTML = originalText;
                if (json && json.success && json.data) {
                    showResult(json.data.state, json.data.message, json.data.full_domain, json.data.detail || '');
                } else {
                    showResult('dns_fail', (json && json.data && json.data.message) || 'Check failed.', '');
                }
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = originalText;
                showResult('dns_fail', 'Network error: ' + err.message, '');
            });
    });
})();

// Visual selection on KB pack radio cards
document.querySelectorAll('input[name="starter_kb"]').forEach(function (r) {
    r.addEventListener('change', function () {
        document.querySelectorAll('.cs-kb-pack-label').forEach(function (lbl) {
            lbl.style.borderColor = '#dcdcde';
            lbl.style.background  = '#fff';
        });
        var parent = r.closest('.cs-kb-pack-label');
        if (parent) {
            parent.style.borderColor = '#3B82F6';
            parent.style.background  = '#eff6ff';
        }
    });
});

// Auto-suggest subdomain from site title (only if subdomain field is empty)
document.getElementById('title')?.addEventListener('input', function () {
    var sub = document.getElementById('subdomain');
    if (!sub || sub.dataset.manuallyEdited) return;
    sub.value = this.value.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .trim()
        .replace(/\s+/g, '-')
        .substring(0, 40);
});
document.getElementById('subdomain')?.addEventListener('input', function () {
    this.dataset.manuallyEdited = '1';
});
</script>
