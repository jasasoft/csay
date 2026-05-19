<?php
/**
 * Ask Question Admin View
 * 
 * Allows admins to test the search system without logging/skewing analytics data.
 * Shows detailed process of how answers are matched.
 * 
 * @package CleverSay
 * @since 2.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap cleversay-admin cleversay-ask-question">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('search', 16); ?>
        <?php esc_html_e('Ask Question', 'cleversay'); ?>
    </h1>
    
    <p class="description" style="margin: 10px 0 20px;">
        <?php esc_html_e('Test the search system without affecting analytics. See the detailed matching process.', 'cleversay'); ?>
    </p>
    
    <hr class="wp-header-end">

    <?php
    // v4.37.51+: support prefill from URL. The phrase-edit page has
    // a "Test in Ask Question" link that includes the variation as a
    // query string. JS below auto-submits if prefill is present.
    $prefill_question = isset($_GET['prefill'])
        ? sanitize_text_field(wp_unslash((string) $_GET['prefill']))
        : '';
    ?>

    <div class="cleversay-ask-container">
        <!-- Search Form -->
        <div class="cleversay-ask-form-wrapper">
            <form id="cleversay-ask-form" class="cleversay-ask-form">
                <?php wp_nonce_field('cleversay_nonce', 'cleversay_nonce'); ?>
                <div class="cleversay-ask-input-group">
                    <label for="cleversay-question" class="screen-reader-text">
                        <?php esc_html_e('Enter your question', 'cleversay'); ?>
                    </label>
                    <input type="text" 
                           id="cleversay-question" 
                           name="question" 
                           class="cleversay-ask-input"
                           value="<?php echo esc_attr($prefill_question); ?>"
                           placeholder="<?php esc_attr_e('Type your question here...', 'cleversay'); ?>"
                           autocomplete="off"
                           autofocus>
                    <button type="submit" class="button button-primary button-large cleversay-ask-submit">
                        <?php echo \CleverSay\Icons::render('search', 16); ?>
                        <?php esc_html_e('Ask', 'cleversay'); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Results Container -->
        <div id="cleversay-ask-results" class="cleversay-ask-results" style="display: none;">
            
            <!-- Process Section -->
            <div class="cleversay-result-section cleversay-process-section">
                <h2>
                    <?php echo \CleverSay\Icons::render('settings', 16); ?>
                    <?php esc_html_e('Process', 'cleversay'); ?>
                </h2>
                <div id="cleversay-process-steps" class="cleversay-process-steps"></div>
            </div>

            <!-- v4.42.0.3+: Served Response Section.
                 Shows the actual user-facing answer after the full pipeline
                 (validator + polish + AI reroute) plus latency and cost.
                 Hidden by default; populated by JS only when the AJAX
                 response includes a served_response payload. -->
            <div class="cleversay-result-section cleversay-served-section" style="display:none;">
                <h2>
                    <?php echo \CleverSay\Icons::render('message-circle', 16); ?>
                    <?php esc_html_e('AI answer (served)', 'cleversay'); ?>
                    <span id="cleversay-served-path-badge" style="margin-left:10px;font-size:11px;font-weight:500;padding:2px 8px;border-radius:10px;background:#e8f0fb;color:#2271b1;vertical-align:middle;"></span>
                </h2>
                <div id="cleversay-served-meta" style="margin-bottom:12px;font-size:12px;color:#555;display:flex;gap:18px;flex-wrap:wrap;">
                    <span><strong><?php esc_html_e('Latency', 'cleversay'); ?>:</strong> <span id="cleversay-served-ms">—</span></span>
                    <span><strong><?php esc_html_e('Cost', 'cleversay'); ?>:</strong> <span id="cleversay-served-cost">—</span></span>
                    <span><strong><?php esc_html_e('Tokens', 'cleversay'); ?>:</strong> <span id="cleversay-served-tokens">—</span></span>
                    <span><strong><?php esc_html_e('Model', 'cleversay'); ?>:</strong> <span id="cleversay-served-model">—</span></span>
                </div>
                <div id="cleversay-served-answer" class="cleversay-response-content"></div>
            </div>

            <!-- Response Section: shows the matched KB entry verbatim
                 (pre-validator). Renamed for clarity in the UI; keeps
                 the same DOM hooks for back-compat with existing JS. -->
            <div class="cleversay-result-section cleversay-response-section">
                <h2>
                    <?php echo \CleverSay\Icons::render('database', 16); ?>
                    <?php esc_html_e('Matched KB entry (raw)', 'cleversay'); ?>
                </h2>
                <div id="cleversay-response-content" class="cleversay-response-content"></div>
            </div>

            <!-- v4.42.0.4+: "You May Also Be Interested In" / Related
                 Questions section removed per operator request — the
                 feature is no longer in use. -->

        </div>

        <!-- Loading Indicator -->
        <div id="cleversay-ask-loading" class="cleversay-ask-loading" style="display: none;">
            <span class="spinner is-active"></span>
            <?php esc_html_e('Searching...', 'cleversay'); ?>
        </div>
        
        <!-- No Results -->
        <div id="cleversay-no-results" class="cleversay-no-results" style="display: none;">
            <div class="cleversay-no-results-icon">
                <?php echo \CleverSay\Icons::render('alert-triangle', 16); ?>
            </div>
            <p><?php esc_html_e('No matching entries found.', 'cleversay'); ?></p>
            <div id="cleversay-suggestions" class="cleversay-suggestions"></div>
        </div>
        
    </div>
</div>

<style>
.cleversay-ask-question .cleversay-ask-container {
    max-width: 900px;
    margin-top: 20px;
}

.cleversay-ask-form-wrapper {
    background: #fff;
    padding: 25px;
    border: 1px solid #c3c4c7;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 25px;
}

.cleversay-ask-input-group {
    display: flex;
    gap: 12px;
}

.cleversay-ask-input {
    flex: 1;
    padding: 12px 16px !important;
    font-size: 16px !important;
    border: 2px solid #8c8f94 !important;
    border-radius: 6px !important;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.cleversay-ask-input:focus {
    border-color: #2271b1 !important;
    box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.2) !important;
    outline: none !important;
}

.cleversay-ask-submit {
    padding: 0 24px !important;
    height: auto !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px;
}

.cleversay-ask-submit .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

.cleversay-result-section {
    background: #fff;
    padding: 20px 25px;
    border: 1px solid #c3c4c7;
    border-radius: 8px;
    margin-bottom: 20px;
}

.cleversay-result-section h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 15px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e2e4e7;
    font-size: 16px;
    color: #1d2327;
}

.cleversay-result-section h2 .dashicons {
    color: #2271b1;
}

/* Process Steps */
.cleversay-process-steps {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
    line-height: 1.8;
}

.cleversay-step {
    padding: 8px 0;
    border-bottom: 1px dashed #e2e4e7;
}

.cleversay-step:last-child {
    border-bottom: none;
}

.cleversay-step-number {
    display: inline-block;
    background: #2271b1;
    color: #fff;
    width: 24px;
    height: 24px;
    line-height: 24px;
    text-align: center;
    border-radius: 50%;
    font-size: 12px;
    font-weight: bold;
    margin-right: 10px;
}

.cleversay-step-description {
    color: #50575e;
    margin-right: 8px;
}

.cleversay-step-result {
    color: #1d2327;
    font-weight: 500;
}

.cleversay-step-arrow {
    color: #2271b1;
    margin: 0 8px;
}

.cleversay-step-replacement {
    display: block;
    margin-left: 34px;
    color: #00a32a;
}

.cleversay-step-replacement::before {
    content: "» ";
}

/* Match entries */
.cleversay-match-entry {
    display: block;
    margin: 5px 0 5px 34px;
    padding: 8px 12px;
    background: #f6f7f7;
    border-left: 3px solid #2271b1;
    border-radius: 0 4px 4px 0;
}

.cleversay-match-score {
    display: inline-block;
    background: #2271b1;
    color: #fff;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    margin-right: 8px;
}

.cleversay-match-keyword {
    color: #1d2327;
    font-weight: 600;
}

.cleversay-match-question {
    color: #50575e;
    font-style: italic;
    margin-left: 8px;
}

/* Response Content */
.cleversay-response-content {
    line-height: 1.7;
}

.cleversay-response-question {
    font-size: 18px;
    font-weight: 600;
    color: #1d2327;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #2271b1;
}

.cleversay-response-answer {
    color: #3c434a;
}

.cleversay-response-answer p {
    margin: 0 0 12px;
}

.cleversay-response-answer a {
    color: #2271b1;
}

.cleversay-response-meta {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #e2e4e7;
    font-size: 12px;
    color: #787c82;
}

.cleversay-response-meta .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
    vertical-align: text-bottom;
}

/* v4.42.0.4+: Related Questions CSS removed — feature retired. */

/* Loading & No Results */
.cleversay-ask-loading {
    text-align: center;
    padding: 40px;
    color: #50575e;
}

.cleversay-ask-loading .spinner {
    float: none;
    margin-right: 10px;
}

.cleversay-no-results {
    text-align: center;
    padding: 40px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 8px;
}

.cleversay-no-results-icon .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #dba617;
}

.cleversay-no-results p {
    font-size: 16px;
    color: #50575e;
    margin: 15px 0;
}

.cleversay-suggestions {
    margin-top: 15px;
}

.cleversay-suggestions-title {
    font-weight: 600;
    margin-bottom: 10px;
    color: #1d2327;
}

.cleversay-suggestion-link {
    display: inline-block;
    margin: 5px;
    padding: 6px 12px;
    background: #f0f0f1;
    border-radius: 4px;
    color: #2271b1;
    text-decoration: none;
    font-size: 13px;
}

.cleversay-suggestion-link:hover {
    background: #2271b1;
    color: #fff;
}

/* Edit Link */
.cleversay-edit-link {
    display: inline-block;
    margin-left: 15px;
    font-size: 12px;
}

/* No Match Message */
.cleversay-no-match-message {
    padding: 20px;
    text-align: center;
    color: #826200;
    background: #fcf9e8;
    border: 1px solid #dba617;
    border-radius: 4px;
}

.cleversay-no-match-message .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    margin-right: 8px;
    vertical-align: middle;
}
</style>

<script>
jQuery(document).ready(function($) {
    const form = $('#cleversay-ask-form');
    const input = $('#cleversay-question');
    const resultsContainer = $('#cleversay-ask-results');
    const loadingIndicator = $('#cleversay-ask-loading');
    const noResults = $('#cleversay-no-results');

    // v4.37.51+: auto-submit when arriving with a prefilled question
    // (from the phrase editor's "Test in Ask Question" link). Saves
    // the admin a click in the most common debug workflow.
    if (input.val().trim() !== '') {
        setTimeout(function() { form.trigger('submit'); }, 100);
    }
    
    form.on('submit', function(e) {
        e.preventDefault();
        
        const question = input.val().trim();
        if (!question) {
            input.focus();
            return;
        }
        
        // Hide previous results
        resultsContainer.hide();
        noResults.hide();
        loadingIndicator.show();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cleversay_test_search',
                nonce: $('#cleversay_nonce').val(),
                question: question
            },
            success: function(response) {
                loadingIndicator.hide();
                
                // Debug: log the full response
                console.log('CleverSay Response:', response);
                
                // Always display results to show process steps
                if (response.success && response.data) {
                    displayResults(response.data);
                } else if (response.success === false) {
                    // Error case - still try to show any data
                    console.log('Error response:', response);
                    if (response.data && response.data.process) {
                        displayResults(response.data);
                    } else {
                        noResults.find('p').text(response.data?.message || '<?php echo esc_js(__('An error occurred.', 'cleversay')); ?>');
                        noResults.show();
                    }
                } else {
                    noResults.show();
                }
            },
            error: function() {
                loadingIndicator.hide();
                noResults.find('p').text('<?php echo esc_js(__('An error occurred. Please try again.', 'cleversay')); ?>');
                noResults.show();
            }
        });
    });
    
    function displayResults(data) {
        // Debug: log the data
        console.log('displayResults data:', data);
        
        // Always show the results container first
        resultsContainer.show();
        noResults.hide();
        
        // Process Steps
        const stepsContainer = $('#cleversay-process-steps');
        stepsContainer.empty();
        
        console.log('Process steps:', data.process);
        
        if (data.process && data.process.length > 0) {
            data.process.forEach(function(step) {
                let stepHtml = '<div class="cleversay-step">';
                stepHtml += '<span class="cleversay-step-number">' + step.step + '</span>';
                stepHtml += '<span class="cleversay-step-description">' + escapeHtml(step.description) + '</span>';
                
                if (step.result) {
                    // AI Fallback Status gets a coloured badge
                    if (step.ai_status) {
                        const colours = {
                            'would_fire':    {bg:'#e8f8ee', border:'#b3dfbf', text:'#00a32a', icon:'🤖'},
                            'not_needed':    {bg:'#e8f0fb', border:'#c5d0f5', text:'#2271b1', icon:'✅'},
                            'no_chunks':     {bg:'#fff3cd', border:'#ffc107', text:'#856404', icon:'⚠️'},
                            'disabled':      {bg:'#f0f0f1', border:'#c3c4c7', text:'#666',    icon:'⏸️'},
                            'not_configured':{bg:'#fce4e4', border:'#f5aca6', text:'#d63638', icon:'❌'},
                        };
                        const c = colours[step.ai_status] || {bg:'#f9f9f9', border:'#ddd', text:'#333', icon:'ℹ️'};
                        stepHtml += '<div style="margin-top:6px;padding:10px 14px;background:' + c.bg + ';border:1px solid ' + c.border + ';border-radius:6px;color:' + c.text + ';font-size:13px;">'
                                  + '<strong>' + c.icon + ' ' + escapeHtml(step.result) + '</strong>'
                                  + '</div>';
                    } else {
                        stepHtml += '<span class="cleversay-step-result">' + escapeHtml(step.result) + '</span>';
                    }
                }

                // Show replacements
                if (step.replacements && step.replacements.length > 0) {
                    step.replacements.forEach(function(rep) {
                        stepHtml += '<span class="cleversay-step-replacement">replaced <strong>' +
                                    escapeHtml(rep.from) + '</strong> with <strong>' +
                                    escapeHtml(rep.to) + '</strong> (' + rep.type + ')</span>';
                    });
                }
                
                // Show matches
                if (step.matches && step.matches.length > 0) {
                    step.matches.forEach(function(match) {
                        stepHtml += '<div class="cleversay-match-entry">';
                        stepHtml += '<span class="cleversay-match-score">' + match.score + ' pts</span>';
                        stepHtml += '<span class="cleversay-match-keyword">' + escapeHtml(match.keyword) + '</span>';
                        if (match.sub_keyword) {
                            stepHtml += ' / <span class="cleversay-match-keyword">' + escapeHtml(match.sub_keyword) + '</span>';
                        }
                        stepHtml += '<span class="cleversay-match-question">' + escapeHtml(match.question) + '</span>';
                        stepHtml += '</div>';
                    });
                }
                
                stepHtml += '</div>';
                stepsContainer.append(stepHtml);
            });
        } else {
            // No process steps - show raw data for debugging
            stepsContainer.append('<div class="cleversay-step"><span class="cleversay-step-description">No process steps returned. Raw data:</span><pre style="background:#f5f5f5;padding:10px;overflow:auto;max-height:300px;font-size:11px;">' + JSON.stringify(data, null, 2) + '</pre></div>');
        }
        
        // Response Content
        const responseContainer = $('#cleversay-response-content');
        responseContainer.empty();

        // v4.42.0.3+: Served Response section — shows the actual answer
        // the user would see (post-validator, post-polish, post-reroute).
        // Populated only when the server included a served_response
        // payload; hidden otherwise so older responses don't show stale
        // sections.
        const servedSection = $('.cleversay-served-section');
        if (data.served_response && (data.served_response.final_answer_html || data.served_response.path === 'no_answer')) {
            const sr = data.served_response;
            const pathLabels = {
                'kb_polished':     {label: 'Polished KB',         color: '#1f5e9c', bg: '#e8f0fb'},
                'kb_raw':          {label: 'Raw KB',              color: '#1f5e9c', bg: '#e8f0fb'},
                'kb_ai_rerouted':  {label: 'KB rejected → AI',    color: '#856404', bg: '#fff3cd'},
                'kb_weak_with_ai': {label: 'Weak KB + AI synth',  color: '#856404', bg: '#fff3cd'},
                'ai_only':         {label: 'AI synthesis only',   color: '#856404', bg: '#fff3cd'},
                'no_answer':       {label: 'No answer',           color: '#666',    bg: '#f0f0f1'}
            };
            const meta = pathLabels[sr.path] || {label: sr.path || '', color:'#333', bg:'#f9f9f9'};
            const $badge = $('#cleversay-served-path-badge');
            $badge.text(meta.label).css({color: meta.color, background: meta.bg});

            // Latency
            let msText = '—';
            if (sr.total_ms !== null && sr.total_ms !== undefined) {
                msText = sr.total_ms < 1000
                    ? Math.round(sr.total_ms) + ' ms'
                    : (sr.total_ms / 1000).toFixed(2) + ' s';
            }
            $('#cleversay-served-ms').text(msText);

            // Cost
            $('#cleversay-served-cost').text(
                (sr.cost !== null && sr.cost !== undefined && !isNaN(sr.cost))
                    ? '$' + Number(sr.cost).toFixed(4)
                    : '—'
            );

            // Tokens
            const tIn  = (sr.tokens_in  !== null && sr.tokens_in  !== undefined) ? sr.tokens_in  : '—';
            const tOut = (sr.tokens_out !== null && sr.tokens_out !== undefined) ? sr.tokens_out : '—';
            $('#cleversay-served-tokens').text(tIn + ' in / ' + tOut + ' out');

            // Model
            $('#cleversay-served-model').text(sr.synthesis_model || '—');

            // Answer body. The pipeline returns HTML for KB paths and
            // mostly-HTML (with some markdown) for AI paths. We render
            // it as-is — same as the live widget does — to preserve
            // links, lists, and formatting from KB entries.
            const answerEl = $('#cleversay-served-answer');
            if (sr.path === 'no_answer' || !sr.final_answer_html) {
                answerEl.html('<em style="color:#666;">No answer would be served. ' +
                              'The widget would show the configured no-answer message.</em>');
            } else if (sr.error) {
                answerEl.html('<div style="color:#d63638;background:#fce4e4;border:1px solid #f5aca6;padding:10px;border-radius:4px;">' +
                              '<strong>Pipeline error:</strong> ' + escapeHtml(sr.error) +
                              '</div>');
            } else {
                answerEl.html(sr.final_answer_html);
            }
            servedSection.show();
        } else {
            servedSection.hide();
        }

        if (data.primary_match) {
            const match = data.primary_match;
            let responseHtml = '<div class="cleversay-response-question">' + escapeHtml(match.question);
            responseHtml += '<a href="<?php echo admin_url('admin.php?page=cleversay-knowledge&action=edit&id='); ?>' + match.id + '" class="cleversay-edit-link">';
            responseHtml += '<?php echo \CleverSay\Icons::render('edit', 16); ?> <?php echo esc_js(__('Edit', 'cleversay')); ?></a>';
            responseHtml += '</div>';
            responseHtml += '<div class="cleversay-response-answer">' + match.response + '</div>';

            if (match.updated_at) {
                responseHtml += '<div class="cleversay-response-meta">';
                responseHtml += '<?php echo \CleverSay\Icons::render('clock', 16); ?> ';
                responseHtml += '<?php echo esc_js(__('Last updated on', 'cleversay')); ?> ' + formatDate(match.updated_at);
                responseHtml += '</div>';
            }

            responseContainer.html(responseHtml);
            $('.cleversay-response-section').show();
        } else if (data.kb_not_used) {
            // v4.42.0.5+: when no KB entry was used to produce the served
            // answer (AI synthesized from chunks instead), show a note
            // explaining why the section is empty rather than displaying
            // a misleading broad-search artifact. The actual answer is
            // visible in the "AI answer (served)" section above.
            responseContainer.html(
                '<div style="padding:14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;color:#50575e;font-style:italic;">' +
                'No KB entry was used to produce the answer above — it was synthesized by AI from indexed source chunks. ' +
                'Any KB entries listed under "Matched..." in the Process trace are broad-search fallback hits, ' +
                'not the source of the served answer.' +
                '</div>'
            );
            $('.cleversay-response-section').show();
        } else {
            $('.cleversay-response-section').hide();
        }
        
        // v4.42.0.4+: Related Questions / "You May Also Be Interested In..."
        // section removed per operator request. The feature is no longer
        // in use in the live widget, so showing it on the inspector page
        // is misleading.
        
        // Show "no results" feedback only when nothing useful was produced
        // at all (Layer 1 found nothing AND AI synthesis produced nothing).
        // v4.42.0.5+: previously this block fired whenever primary_match
        // was empty, which overwrote the kb_not_used explanation note
        // for AI-synthesized answers — making the page incorrectly say
        // "no matching entries" even when the user got a real AI answer.
        const aiServedSomething = data.served_response
            && data.served_response.final_answer_html
            && data.served_response.path !== 'no_answer';
        if ((!data.success || !data.primary_match) && !aiServedSomething && !data.kb_not_used) {
            const suggestionsContainer = $('#cleversay-suggestions');
            suggestionsContainer.empty();

            if (data.suggested && data.suggested.length > 0) {
                suggestionsContainer.append('<div class="cleversay-suggestions-title"><?php echo esc_js(__('Did you mean:', 'cleversay')); ?></div>');
                data.suggested.forEach(function(suggestion) {
                    suggestionsContainer.append(
                        '<a href="#" class="cleversay-suggestion-link" data-question="' + escapeHtml(suggestion) + '">' +
                        escapeHtml(suggestion) + '</a>'
                    );
                });
            }

            // Show "no match" message in response section
            const responseContainer = $('#cleversay-response-content');
            responseContainer.html('<div class="cleversay-no-match-message"><?php echo \CleverSay\Icons::render('alert-triangle', 16); ?> <?php echo esc_js(__('No matching entries found based on the search criteria above.', 'cleversay')); ?></div>');
            $('.cleversay-response-section').show();
        }
    }
    
    // Handle clicking on related/suggested questions
    $(document).on('click', '.cleversay-suggestion-link', function(e) {
        e.preventDefault();
        const question = $(this).data('question');
        input.val(question);
        form.submit();
    });
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }
});
</script>
