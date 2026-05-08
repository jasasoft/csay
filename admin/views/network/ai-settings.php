<?php
/**
 * Network AI Settings View
 *
 * @package CleverSay
 * @since   4.0.0
 * @var array $settings  Current AI settings from NetworkSettings::get_ai()
 */

if (!defined('ABSPATH')) exit;

// v4.37.43+: pull the model list from AI::get_available_models() so
// Claude and Gemini options stay in sync across all settings pages
// (single-site Settings, network admin, A/B test page, client sites
// admin). Single source of truth.
$models = \CleverSay\AI::get_available_models();
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('sliders', 18); ?>
        <?php esc_html_e('Network AI Settings', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <p class="description" style="margin-bottom:20px;max-width:700px;">
        <?php esc_html_e('These settings apply to all client sites. Clients cannot view or modify these values. The API key and model selection here override any per-site settings.', 'cleversay'); ?>
    </p>

    <form method="post" action="">
        <?php wp_nonce_field('cleversay_network_ai', 'cleversay_network_ai_nonce'); ?>

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('key', 16); ?>
                    <?php esc_html_e('AI Provider API', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="ai_enabled"><?php esc_html_e('Enable AI', 'cleversay'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ai_enabled" id="ai_enabled" value="1"
                                   <?php checked(!empty($settings['ai_enabled'])); ?>>
                            <?php esc_html_e('Enable AI fallback for all client sites', 'cleversay'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Provider API Keys', 'cleversay'); ?></th>
                    <td>
                        <?php
                        // v4.37.74+: separate fields per provider so admin can keep
                        // both keys saved and switch models freely. The legacy
                        // 'api_key' is kept for back-compat fallback when a
                        // provider-specific key isn't set.
                        $anthropic_key   = (string) ($settings['anthropic_api_key'] ?? '');
                        $gemini_key      = (string) ($settings['gemini_api_key']    ?? '');
                        $legacy_key      = (string) ($settings['api_key']           ?? '');
                        $current_model   = (string) ($settings['model'] ?? 'claude-haiku-4-5-20251001');
                        $active_provider = \CleverSay\NetworkSettings::provider_for_model($current_model);
                        // Surface legacy key under the active provider if neither
                        // per-provider key is set, so admin sees their existing
                        // setting even before saving on this version.
                        if ($anthropic_key === '' && $gemini_key === '' && $legacy_key !== '') {
                            if ($active_provider === 'gemini') {
                                $gemini_key = $legacy_key;
                            } else {
                                $anthropic_key = $legacy_key;
                            }
                        }
                        ?>
                        <p class="description" style="margin-top:0; margin-bottom:14px;">
                            <?php esc_html_e('Save keys for both providers so you can switch models freely. The key matching the selected default model below will be used for AI calls.', 'cleversay'); ?>
                        </p>

                        <!-- Anthropic / Claude -->
                        <div style="margin-bottom:14px; padding:12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                                <strong><?php esc_html_e('Anthropic (Claude)', 'cleversay'); ?></strong>
                                <?php if ($active_provider === 'anthropic'): ?>
                                    <span style="font-size:11px; padding:2px 8px; background:#dff6dd; color:#0a6b0a; border:1px solid #a3d9a5; border-radius:11px; font-weight:600;">
                                        <?php esc_html_e('Active', 'cleversay'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <input type="password" name="anthropic_api_key" id="anthropic_api_key"
                                   class="regular-text"
                                   value="<?php echo esc_attr($anthropic_key); ?>"
                                   autocomplete="new-password"
                                   placeholder="sk-ant-api03-...">
                            <p class="description" style="margin-top:6px;">
                                <?php esc_html_e('Get your key at', 'cleversay'); ?>
                                <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>
                            </p>
                        </div>

                        <!-- Google / Gemini -->
                        <div style="margin-bottom:8px; padding:12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                                <strong><?php esc_html_e('Google (Gemini)', 'cleversay'); ?></strong>
                                <?php if ($active_provider === 'gemini'): ?>
                                    <span style="font-size:11px; padding:2px 8px; background:#dff6dd; color:#0a6b0a; border:1px solid #a3d9a5; border-radius:11px; font-weight:600;">
                                        <?php esc_html_e('Active', 'cleversay'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <input type="password" name="gemini_api_key" id="gemini_api_key"
                                   class="regular-text"
                                   value="<?php echo esc_attr($gemini_key); ?>"
                                   autocomplete="new-password"
                                   placeholder="AIzaSy...">
                            <p class="description" style="margin-top:6px;">
                                <?php esc_html_e('Get your key at', 'cleversay'); ?>
                                <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com/app/apikey</a>
                            </p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th><label for="model"><?php esc_html_e('Default Model', 'cleversay'); ?></label></th>
                    <td>
                        <select name="model" id="model">
                            <?php foreach ($models as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>"
                                    <?php selected($settings['model'] ?? '', $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Provider is inferred from your model selection. Make sure the API key above matches: Anthropic key for Claude models, Google AI Studio key for Gemini models.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="max_tokens"><?php esc_html_e('Max Tokens', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="max_tokens" id="max_tokens"
                               class="small-text" min="100" max="2000" step="50"
                               value="<?php echo esc_attr($settings['max_tokens'] ?? 450); ?>">
                        <p class="description"><?php esc_html_e('Maximum tokens per AI response. 450 recommended.', 'cleversay'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('briefcase', 16); ?>
                    <?php esc_html_e('Budget & Limits', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="monthly_budget"><?php esc_html_e('Monthly Budget (USD)', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="monthly_budget" id="monthly_budget"
                               class="small-text" min="0" step="1"
                               value="<?php echo esc_attr($settings['monthly_budget'] ?? 0); ?>">
                        <p class="description"><?php esc_html_e('Global monthly spend cap across all sites. 0 = no limit.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fallback_threshold"><?php esc_html_e('AI Fallback Threshold', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="fallback_threshold" id="fallback_threshold"
                               class="small-text" min="0" max="100"
                               value="<?php echo esc_attr($settings['fallback_threshold'] ?? 70); ?>">
                        <p class="description"><?php esc_html_e('Minimum KB match score before triggering AI. 70 recommended.', 'cleversay'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('sparkles', 16); ?>
                    <?php esc_html_e('AI Behaviour', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><?php esc_html_e('Validate KB Answers', 'cleversay'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="validate_kb" value="1"
                                   <?php checked(!empty($settings['validate_kb'])); ?>>
                            <?php esc_html_e('Use AI to validate KB answers before showing them', 'cleversay'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Polish KB Answers', 'cleversay'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="polish_kb" value="1"
                                   <?php checked(!empty($settings['polish_kb'])); ?>>
                            <?php esc_html_e('Use AI to improve phrasing of KB answers', 'cleversay'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Validate aadefault', 'cleversay'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="aadefault_validate" value="1"
                                   <?php checked(!empty($settings['aadefault_validate'])); ?>>
                            <?php esc_html_e('Validate aadefault KB matches before returning them', 'cleversay'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>
                        <?php echo \CleverSay\Icons::render('globe', 14); ?>
                        <?php esc_html_e('Multilingual', 'cleversay'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="multilingual" value="1"
                                   <?php checked(!empty($settings['multilingual'])); ?>>
                            <?php esc_html_e('Enable multilingual support (auto-detect non-English questions and translate responses back to the visitor\'s language)', 'cleversay'); ?>
                        </label>
                        <p class="description" style="margin-top:6px;">
                            <?php esc_html_e('When enabled, non-English questions are translated to English for KB search, and answers are translated back to the visitor\'s detected language. Adds ~2 AI calls per non-English question. Leave off if your audience is English-only.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(__('Save Network AI Settings', 'cleversay')); ?>
    </form>

    <!-- Test API Key -->
    <?php if (!empty($settings['api_key'])): ?>
    <div class="cleversay-table-card" style="margin-top:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php echo \CleverSay\Icons::render('zap', 16); ?>
                <?php esc_html_e('Test API Key', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:18px;">
            <p class="description" style="margin:0 0 12px;">
                <?php esc_html_e('Verify the saved API key is valid and can reach the configured provider (Anthropic or Google).', 'cleversay'); ?>
            </p>
            <button type="button" id="cs-network-test-api" class="button">
                <?php echo \CleverSay\Icons::render('shield', 18); ?>
                <?php esc_html_e('Test Saved API Key', 'cleversay'); ?>
            </button>
            <span id="cs-network-test-result" style="margin-left:12px;font-size:13px;"></span>
        </div>
    </div>

    <script>
    (function($) {
        $('#cs-network-test-api').on('click', function() {
            const $btn = $(this);
            const $result = $('#cs-network-test-result');
            $btn.prop('disabled', true);
            $result.text('<?php echo esc_js(__('Testing…', 'cleversay')); ?>').css('color', '#646970');

            $.post(ajaxurl, {
                action: 'cleversay_test_stored_api_key',
                nonce:  '<?php echo esc_js(wp_create_nonce('cleversay_admin_nonce')); ?>'
            }).done(function(r) {
                if (r.success) {
                    $result.text('✓ ' + (r.data?.message || '<?php echo esc_js(__('API key is valid.', 'cleversay')); ?>')).css('color', '#00a32a');
                } else {
                    $result.text('✗ ' + (r.data?.message || '<?php echo esc_js(__('API key test failed.', 'cleversay')); ?>')).css('color', '#d63638');
                }
            }).fail(function() {
                $result.text('<?php echo esc_js(__('Request failed.', 'cleversay')); ?>').css('color', '#d63638');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    })(jQuery);
    </script>
    <?php endif; ?>
</div>
