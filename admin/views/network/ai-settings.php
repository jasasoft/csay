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

    <?php
    // v4.41.5.4+: Inline diagnostic — fires a one-shot request against
    // the currently-saved synthesis model and shows the full HTTP code +
    // response body. Designed for operators without shell access who
    // need to verify their API key + model combination is working
    // before relying on it for live traffic. Result is stashed in a
    // transient by the form handler in class-network-admin.php.
    $synthesis_test = get_transient('cleversay_synthesis_test_result');
    if ($synthesis_test) {
        delete_transient('cleversay_synthesis_test_result');
    }
    ?>
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php echo \CleverSay\Icons::render('activity', 16); ?>
                <?php esc_html_e('Test Synthesis Model', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <p class="description" style="margin-top:0;margin-bottom:12px;">
                <?php esc_html_e(
                    'Sends a tiny test request ("reply with: pong") to whichever model is currently saved as Synthesis Model. Useful for verifying provider keys and model availability without using shell access. Save your settings first if you just changed them — the test uses saved values, not unsaved form values.',
                    'cleversay'
                ); ?>
            </p>
            <form method="post" action="" style="display:inline-block;">
                <?php wp_nonce_field('cleversay_test_synthesis', 'cleversay_test_synthesis_nonce'); ?>
                <button type="submit"
                        name="cleversay_ai_action" value="test_synthesis"
                        class="button button-secondary">
                    <?php esc_html_e('Test Synthesis Model Now', 'cleversay'); ?>
                </button>
            </form>

            <?php if (is_array($synthesis_test)):
                $cls = !empty($synthesis_test['success']) ? 'notice-success' : 'notice-error';
            ?>
                <div class="notice <?php echo esc_attr($cls); ?>" style="margin:14px 0 0;padding:12px 14px;">
                    <p style="margin:0 0 8px;">
                        <strong>
                            <?php
                            echo !empty($synthesis_test['success'])
                                ? esc_html__('Test PASSED', 'cleversay')
                                : esc_html__('Test FAILED', 'cleversay');
                            ?>
                        </strong>
                        — <?php echo esc_html__('latency:', 'cleversay'); ?>
                        <code><?php echo (int) ($synthesis_test['latency_ms'] ?? 0); ?> ms</code>,
                        <?php echo esc_html__('provider:', 'cleversay'); ?>
                        <code><?php echo esc_html($synthesis_test['synthesis_provider'] ?? '?'); ?></code>,
                        <?php echo esc_html__('model:', 'cleversay'); ?>
                        <code><?php echo esc_html($synthesis_test['model'] ?? '?'); ?></code>
                        <?php if (!empty($synthesis_test['http_code'])): ?>
                            , <?php echo esc_html__('HTTP:', 'cleversay'); ?>
                            <code><?php echo (int) $synthesis_test['http_code']; ?></code>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($synthesis_test['answer'])): ?>
                        <p style="margin:0 0 6px;">
                            <strong><?php esc_html_e('Answer:', 'cleversay'); ?></strong>
                            <code><?php echo esc_html($synthesis_test['answer']); ?></code>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($synthesis_test['error'])): ?>
                        <p style="margin:0;">
                            <strong><?php esc_html_e('Error:', 'cleversay'); ?></strong>
                            <code style="white-space:pre-wrap;display:block;background:#fff;padding:8px;margin-top:4px;border-radius:3px;">
                                <?php echo esc_html($synthesis_test['error']); ?>
                            </code>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($synthesis_test['raw_body_excerpt'])): ?>
                        <p style="margin:6px 0 0;">
                            <strong><?php esc_html_e('Raw response (first 500 chars):', 'cleversay'); ?></strong>
                            <code style="white-space:pre-wrap;display:block;background:#fff;padding:8px;margin-top:4px;border-radius:3px;">
                                <?php echo esc_html($synthesis_test['raw_body_excerpt']); ?>
                            </code>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

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
                        // v4.42.2+: openai_api_key added for GPT-4o mini.
                        $anthropic_key   = (string) ($settings['anthropic_api_key'] ?? '');
                        $gemini_key      = (string) ($settings['gemini_api_key']    ?? '');
                        $openai_key      = (string) ($settings['openai_api_key']    ?? '');
                        $legacy_key      = (string) ($settings['api_key']           ?? '');
                        $current_model   = (string) ($settings['model'] ?? 'claude-haiku-4-5-20251001');
                        $active_provider = \CleverSay\NetworkSettings::provider_for_model($current_model);
                        // Surface legacy key under the active provider if no
                        // per-provider key is set, so admin sees their existing
                        // setting even before saving on this version.
                        if ($anthropic_key === '' && $gemini_key === '' && $openai_key === '' && $legacy_key !== '') {
                            if ($active_provider === 'gemini') {
                                $gemini_key = $legacy_key;
                            } elseif ($active_provider === 'openai') {
                                $openai_key = $legacy_key;
                            } else {
                                $anthropic_key = $legacy_key;
                            }
                        }
                        ?>
                        <p class="description" style="margin-top:0; margin-bottom:14px;">
                            <?php esc_html_e('Save keys for all providers you want to use, so you can switch models freely. The key matching the selected default model below will be used for AI calls.', 'cleversay'); ?>
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

                        <!-- v4.42.2+: OpenAI (GPT-4o mini) -->
                        <div style="margin-bottom:8px; padding:12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                                <strong><?php esc_html_e('OpenAI (GPT)', 'cleversay'); ?></strong>
                                <?php if ($active_provider === 'openai'): ?>
                                    <span style="font-size:11px; padding:2px 8px; background:#dff6dd; color:#0a6b0a; border:1px solid #a3d9a5; border-radius:11px; font-weight:600;">
                                        <?php esc_html_e('Active', 'cleversay'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <input type="password" name="openai_api_key" id="openai_api_key"
                                   class="regular-text"
                                   value="<?php echo esc_attr($openai_key); ?>"
                                   autocomplete="new-password"
                                   placeholder="sk-...">
                            <p class="description" style="margin-top:6px;">
                                <?php esc_html_e('Get your key at', 'cleversay'); ?>
                                <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>
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
                            <?php esc_html_e('Provider is inferred from your model selection. Make sure the API key above matches: Anthropic key for Claude models, Google AI Studio key for Gemini models, OpenAI key for GPT models.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <?php
                    // v4.41.5.2+: synthesis-model selector. Synthesis is the
                    // most expensive AI step (the chat answer itself), and
                    // is the right knob for latency/cost A/B testing.
                    // The dropdown reuses the same model list as the default
                    // model selector. If the saved value isn't a member of
                    // that list (older installs may have a model that's
                    // been removed from the dropdown), prepend it as a
                    // "Currently saved" option so the user can see what
                    // they're actually running before they change it.
                    $synthesis_models = $models;
                    $current_synthesis = (string) ($settings['synthesis_model'] ?? '');
                    if ($current_synthesis !== '' && !isset($synthesis_models[$current_synthesis])) {
                        $synthesis_models = array_merge(
                            [$current_synthesis => sprintf(
                                /* translators: %s = model id like 'claude-sonnet-4-5-20250929' */
                                __('%s (currently saved — not in current dropdown options)', 'cleversay'),
                                $current_synthesis
                            )],
                            $synthesis_models
                        );
                    }
                    ?>
                    <th><label for="synthesis_model"><?php esc_html_e('Synthesis Model', 'cleversay'); ?></label></th>
                    <td>
                        <select name="synthesis_model" id="synthesis_model">
                            <?php foreach ($synthesis_models as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>"
                                    <?php selected($current_synthesis, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e(
                                'The model used to generate chat answers when AI fallback fires (the largest contributor to total request latency). Use the Latency dashboard to A/B compare: take a baseline window on your current setting, switch, take another window, then compare p50/p95 synthesis times and answer quality.',
                                'cleversay'
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <?php
                    // v4.41.5.7+: validator-model selector. Validator runs
                    // on every Layer 1 strong KB hit (when validate_kb is
                    // on) to gatekeep whether the matched answer actually
                    // addresses the question. It's a short, structured
                    // call (max_tokens=100, decision JSON output) but it
                    // fires often — potentially more than synthesis on
                    // tenants with strong KB coverage. Worth surfacing
                    // because it's currently invisible in admin UI and
                    // every save of this form was silently resetting it
                    // to the schema default before v4.41.5.2 added the
                    // preservation fix.
                    //
                    // Same "currently saved" handling as synthesis: the
                    // schema default 'claude-sonnet-4-5-20250929' isn't
                    // in get_available_models() anymore, so existing
                    // installs need to see what they have before
                    // changing it.
                    $validator_models = $models;
                    $current_validator = (string) ($settings['validator_model'] ?? '');
                    if ($current_validator !== '' && !isset($validator_models[$current_validator])) {
                        $validator_models = array_merge(
                            [$current_validator => sprintf(
                                /* translators: %s = model id like 'claude-sonnet-4-5-20250929' */
                                __('%s (currently saved — not in current dropdown options)', 'cleversay'),
                                $current_validator
                            )],
                            $validator_models
                        );
                    }
                    ?>
                    <th><label for="validator_model"><?php esc_html_e('Validator Model', 'cleversay'); ?></label></th>
                    <td>
                        <select name="validator_model" id="validator_model">
                            <?php foreach ($validator_models as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>"
                                    <?php selected($current_validator, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e(
                                'The model used to validate KB matches (decides whether a Layer 1 hit actually addresses the user\'s question — accept, refer, or reject). Runs on every strong KB hit when "Validate KB" is enabled. Smaller/cheaper models work fine here since the output is a short routing decision, not prose. Note: validator currently uses Anthropic\'s API only — picking a Gemini or OpenAI model here will fail. Use an Anthropic model.',
                                'cleversay'
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <?php
                    // v4.42.1+: polish-model selector. Polish rewrites
                    // accepted KB answers in the configured tone. It's a
                    // constrained transformation (10 explicit rules in
                    // the prompt; preserve all facts, numbers, URLs,
                    // contact info), so the smaller default model
                    // generally suffices. Operators on high-stakes
                    // tenants where polish-time fact distortion would be
                    // harmful can lift to a larger model. Same currently-
                    // saved fallback pattern as the validator selector
                    // above.
                    $polish_models = $models;
                    $current_polish = (string) ($settings['polish_model'] ?? '');
                    if ($current_polish !== '' && !isset($polish_models[$current_polish])) {
                        $polish_models = array_merge(
                            [$current_polish => sprintf(
                                /* translators: %s = model id */
                                __('%s (currently saved — not in current dropdown options)', 'cleversay'),
                                $current_polish
                            )],
                            $polish_models
                        );
                    }
                    ?>
                    <th><label for="polish_model"><?php esc_html_e('Polish Model', 'cleversay'); ?></label></th>
                    <td>
                        <select name="polish_model" id="polish_model">
                            <?php foreach ($polish_models as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>"
                                    <?php selected($current_polish, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e(
                                'The model used to AI-rewrite accepted KB answers in the configured tenant tone. Runs on every Layer 1 hit when "Polish KB" is enabled, unless the entry was admin-polished and unchanged since. The task is constrained — keep all facts, rewrite tone only — so the smaller default model (Haiku) generally suffices and is cheaper/faster. Lift to a larger model if you observe Haiku altering facts, numbers, URLs, or contact details during polish. The polish call routes to whichever provider owns the selected model — just make sure that provider\'s API key is saved above.',
                                'cleversay'
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="max_tokens"><?php esc_html_e('Max Tokens', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="max_tokens" id="max_tokens"
                               class="small-text" min="100" max="2000" step="50"
                               value="<?php echo esc_attr($settings['max_tokens'] ?? 1000); ?>">
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
