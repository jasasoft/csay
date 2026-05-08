/**
 * CleverSay Public JavaScript
 * Handles chatbot interactions, search, FAQ accordion, session memory, and accessibility
 *
 * @package CleverSay
 * @since 2.1.0
 */

(function($) {
    'use strict';

    // =========================================================================
    // Rate limiter — max 20 requests per 60 seconds per page session
    // =========================================================================
    const RateLimiter = {
        _count: 0,
        _reset: Date.now() + 60000,
        check() {
            const now = Date.now();
            if (now > this._reset) {
                this._count = 0;
                this._reset = now + 60000;
            }
            if (this._count >= 20) return false;
            this._count++;
            return true;
        }
    };

    // =========================================================================
    // Lightweight markdown → HTML converter (for AI answers)
    // =========================================================================
    const SimpleMarkdown = {
        parse(text) {
            if (!text) return '';
            let html = text;

            // v4.37.70+/v4.37.73+/v4.37.78+: three content shapes need
            // different handling:
            //
            //   1. Structured HTML — has block-level tags (<p>, <ul>,
            //      <li>, <div>, headings, tables, blockquote). These
            //      provide their own layout, so inter-tag whitespace
            //      is just presentation and bare newlines inside text
            //      should collapse to spaces. KB responses authored
            //      in TinyMCE land here.
            //
            //   2. Inline HTML in prose — has only inline tags
            //      (<strong>, <em>, <a>, <code>) and no block-level
            //      structure. Newlines ARE the layout (paragraph
            //      breaks). Treat as prose and run the markdown
            //      paragraph-break logic. AI-fallback responses
            //      with inline emphasis land here.
            //
            //   3. Plain text/markdown — no HTML at all. Standard
            //      markdown parsing for lists, breaks, paragraphs.
            //
            // The earlier "any HTML tag → HTML mode" detection
            // collapsed shape 2 into shape 1, which flattened
            // multi-paragraph AI responses into a single run.
            const hasBlockHtml  = /<\s*(p|ul|ol|li|div|h[1-6]|table|tr|td|blockquote|pre|br|hr)\b/i.test(html);
            const hasInlineHtml = /<\s*(strong|em|a|b|i|code|span)\b/i.test(html);

            // Bold: **text** and __text__
            html = html.replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/__([^_]+?)__/g, '<strong>$1</strong>');

            // v4.37.84+: markdown links [label](url). The polish prompt
            // emits these for contact info (e.g., emails as
            // [user@uwsp.edu](mailto:user@uwsp.edu)). Without parsing,
            // they show as literal text in the widget. We parse them
            // here in BOTH branches (block-HTML and inline/plain) so
            // contact links render correctly regardless of which shape
            // the response is. Safe to run unconditionally — the
            // bracket-paren pattern doesn't appear in valid HTML.
            html = html.replace(
                /\[([^\]]+)\]\(([^)]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener">$1</a>'
            );

            // Bare URLs: http(s)://... — also linkify, but only when
            // not already inside an <a> tag. Match URL chars liberally,
            // then strip a single trailing sentence punctuation char
            // if present (period, comma, etc. at end of URL is almost
            // always sentence punctuation, not part of the URL).
            html = html.replace(
                /(^|[^"'>])(https?:\/\/[^\s<>]+?)([.,!?;)\]]?)(?=\s|$|<)/g,
                '$1<a href="$2" target="_blank" rel="noopener">$2</a>$3'
            );

            if (hasBlockHtml) {
                // Shape 1: structured HTML. Strip whitespace adjacent
                // to block tags (TinyMCE-style indentation) and
                // collapse remaining bare newlines to spaces — the
                // block tags handle layout.
                html = html.replace(/\s*\n\s*(?=<\/?(p|ul|ol|li|div|h[1-6]|table|tr|td|blockquote|pre|br|hr)\b)/gi, '');
                html = html.replace(/(<\/?(p|ul|ol|li|div|h[1-6]|table|tr|td|blockquote|pre|br|hr)\b[^>]*>)\s*\n\s*/gi, '$1');
                html = html.replace(/\n+/g, ' ');
                return html;
            }

            // Shapes 2 and 3 share the markdown-style paragraph/list
            // handling. The bold replacement above already handled
            // **text** for both; for shape 2 (inline HTML in prose)
            // the existing inline tags pass through unchanged.

            // Unordered list items: lines starting with "- " or "* "
            html = html.replace(/^[ \t]*[-*]\s+(.+)$/gm, '<li>$1</li>');

            // Numbered list items: lines starting with "1. " "2. " etc
            html = html.replace(/^[ \t]*\d+\.[ \t]+(.+)$/gm, '<li>$1</li>');

            // Wrap runs of <li> in a <ul>
            html = html.replace(/(<li>[\s\S]+?<\/li>)(?!\s*<li>)/g, '<ul>$1</ul>');

            // v4.40.4: collapse whitespace between adjacent <li> tags so
            // the following \n→<br> conversion doesn't insert <br> between
            // list items (which renders as visible blank lines in the bubble).
            html = html.replace(/(<\/li>)\s+(<li>)/g, '$1$2');

            // Double newline → paragraph break
            html = html.replace(/\n{2,}/g, '</p><p>');

            // Single newline → line break
            html = html.replace(/\n/g, '<br>');

            // Wrap in a paragraph if not already block-level
            if (!html.startsWith('<')) {
                html = '<p>' + html + '</p>';
            }

            return html;
        }
    };

    // =========================================================================
    // Session Storage helper — key per widget instance
    // =========================================================================
    const SessionMemory = {
        _key(id) { return 'cleversay_history_' + id; },
        save(id, messages) {
            try {
                // Keep only the last 20 messages to stay lean
                const trimmed = messages.slice(-20);
                sessionStorage.setItem(this._key(id), JSON.stringify(trimmed));
            } catch (e) { /* storage unavailable — fail silently */ }
        },
        load(id) {
            try {
                const raw = sessionStorage.getItem(this._key(id));
                return raw ? JSON.parse(raw) : [];
            } catch (e) { return []; }
        },
        clear(id) {
            try { sessionStorage.removeItem(this._key(id)); } catch (e) {}
        }
    };

    // =========================================================================
    // CleverSayChatbot
    // =========================================================================
    class CleverSayChatbot {
        constructor(container) {
            this.container       = $(container);
            this.messagesEl      = this.container.find('.cleversay-messages');
            this.input           = this.container.find('.cleversay-input');
            this.submitBtn       = this.container.find('.cleversay-submit');
            this.isLoading       = false;
            this.currentAnswerId = null;

            // Derive a stable ID from the container's id or generate one
            this.instanceId = this.container.attr('id') ||
                              ('cs-' + Math.random().toString(36).slice(2, 8));

            // In-memory transcript for this session
            this._history = [];

            this.init();
        }

        init() {
            // Restore history from sessionStorage
            this._restoreHistory();

            // Submit on button click
            this.submitBtn.on('click', () => this.handleSubmit());

            // Submit on Enter (not Shift+Enter)
            this.input.on('keypress', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.handleSubmit();
                }
            });

            // Suggestion / related question clicks
            this.messagesEl.on('click', '.cleversay-suggestion', (e) => {
                this.input.val($(e.target).text()).trigger('focus');
                this.handleSubmit();
            });

            this.messagesEl.on('click', '.cleversay-related-item', (e) => {
                e.preventDefault();
                const question = $(e.currentTarget).data('question');
                if (question) {
                    this.input.val(question).trigger('focus');
                    this.handleSubmit();
                }
            });

            // Rating
            this.messagesEl.on('click', '.cleversay-rating-btn', (e) => {
                this.handleRating($(e.currentTarget));
            });

            // Inquiry form
            this.messagesEl.on('submit', '.cleversay-inquiry-form', (e) => {
                e.preventDefault();
                this.handleInquiry($(e.target));
            });

            // v4.37.89+: Sources link click — toggles the slide-up panel
            // showing citations for the bubble's RAG answer. Click again
            // (or click X / outside / press Esc) to close.
            this.messagesEl.on('click', '.cleversay-sources-link', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                const payload = $btn.data('sources');
                if (!payload) return;
                let sources = [];
                try {
                    sources = JSON.parse(decodeURIComponent(escape(atob(payload))));
                } catch (err) { return; }
                this._toggleSourcesPanel(sources);
            });

            // Clear history button (if present)
            this.container.on('click', '.cleversay-clear-history', () => {
                SessionMemory.clear(this.instanceId);
                this._history = [];
                this.messagesEl.find('.cleversay-message').not(':first').remove();
            });

            // v4.37.89+: Escape key closes Sources panel when open.
            $(document).on('keydown.cleversay-sources-' + this.instanceId, (e) => {
                if (e.key === 'Escape') this._closeSourcesPanel();
            });
        }

        // ── History ──────────────────────────────────────────────────────────

        _restoreHistory() {
            const saved = SessionMemory.load(this.instanceId);
            if (!saved.length) return;

            saved.forEach(item => {
                // Re-render each saved message without persisting again
                this._renderMessage(item.content, item.type, item.options || {}, false);
            });
        }

        _saveToHistory(content, type, options = {}) {
            // Only store lightweight data — no full HTML, just text/type
            this._history.push({ content, type, options: this._serializableOptions(options) });
            SessionMemory.save(this.instanceId, this._history);
        }

        _serializableOptions(options) {
            // Strip non-serializable data; keep only what we need to re-render.
            // v4.37.85+: also persist showAiBadge so the badge re-appears
            // when chat history reloads.
            // v4.37.89+: also persist sources for the citation feature.
            const { rating, answerId, suggestions, related, showInquiry, question, showAiBadge, sources } = options;
            return { rating, answerId, suggestions, related, showInquiry, question, showAiBadge, sources };
        }

        // ── Messaging ────────────────────────────────────────────────────────

        addMessage(content, type, options = {}) {
            this._renderMessage(content, type, options, true);
        }

        _renderMessage(content, type, options = {}, persist = true) {
            // Related questions feature removed in v4.29.1 — replaced by AI
            // follow-up suggestions baked into answer text. The related-
            // questions render code stays for backward compat with any
            // theme/integration that calls it directly.
            const showRelated = false;

            // For bot messages, parse any markdown. User messages are always escaped.
            const renderedContent = (type === 'bot') ? SimpleMarkdown.parse(content) : this.escapeHtml(content);
            const isBotMsg = (type === 'bot');

            // Avatar HTML (bot messages only)
            const avatarHtml = isBotMsg
                ? (cleversay.mascotUrl
                    ? `<img src="${cleversay.mascotUrl}" alt="" class="cleversay-msg-avatar" aria-hidden="true">`
                    : `<div class="cleversay-msg-avatar cleversay-msg-avatar-placeholder" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg></div>`)
                : '';

            // Label above bubble (bot messages only)
            const labelHtml = isBotMsg
                ? `<span class="cleversay-msg-label">${this.escapeHtml(cleversay.botLabel || 'Assistant')}</span>`
                : '';

            // v4.37.90+: AI badge and Sources link share a row with
            // space-between justification — badge floats left, Sources
            // floats right. If only one is rendered, it sits at its
            // own edge (the row layout still applies).
            //
            // Sources panel is intentionally NOT shown in inline
            // shortcode contexts (the [cleversay] embed). The
            // slide-out panel needs viewport-edge space that isn't
            // available when the widget is embedded mid-page.
            const isShortcode = this.container.hasClass('cleversay-embedded');
            const badgeHtml   = options.showAiBadge ? this.getAIBadgeHtml() : '';
            const sourcesHtml = (!isShortcode && options.sources && options.sources.length)
                ? this.getSourcesLinkHtml(options.sources)
                : '';
            const metaRowHtml = (badgeHtml || sourcesHtml)
                ? `<div class="cleversay-bubble-meta">${badgeHtml}${sourcesHtml}</div>`
                : '';

            const bubbleAndExtras = `
                <div class="cleversay-bubble">${renderedContent}</div>
                ${metaRowHtml}
                ${showRelated ? this.getRelatedQuestionsHtml(options.related) : ''}
                ${options.rating ? this.getRatingHtml(options.answerId, options.ratingTarget || 'kb') : ''}
                ${options.suggestions ? this.getSuggestionsHtml(options.suggestions) : ''}
                ${options.showInquiry ? this.getInquiryFormHtml(options.question) : ''}
            `;

            const messageHtml = isBotMsg
                ? `<div class="cleversay-message bot" role="listitem">
                    ${avatarHtml}
                    <div class="cleversay-msg-body">
                        ${labelHtml}
                        ${bubbleAndExtras}
                    </div>
                   </div>`
                : `<div class="cleversay-message user" role="listitem">
                    <div class="cleversay-bubble">${renderedContent}</div>
                   </div>`;

            this.messagesEl.append(messageHtml);

            if (type === 'user') {
                this.scrollToBottom();
            } else {
                this.scrollToLastMessage();
            }

            if (persist) {
                this._saveToHistory(content, type, options);
            }
        }

        // ── Loading indicator ─────────────────────────────────────────────────

        showLoading() {
            this.isLoading = true;
            this.submitBtn.prop('disabled', true).attr('aria-disabled', 'true');

            // Build avatar HTML matching regular bot messages
            const avatarHtml = cleversay.mascotUrl
                ? `<img src="${cleversay.mascotUrl}" alt="" class="cleversay-msg-avatar" aria-hidden="true">`
                : `<div class="cleversay-msg-avatar cleversay-msg-avatar-placeholder" aria-hidden="true">
                       <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
                   </div>`;

            const labelHtml = `<span class="cleversay-msg-label">${this.escapeHtml(cleversay.botLabel || 'Assistant')}</span>`;

            this.messagesEl.append(`
                <div class="cleversay-message bot cleversay-loading-message" aria-live="off">
                    ${avatarHtml}
                    <div class="cleversay-msg-body">
                        ${labelHtml}
                        <div class="cleversay-bubble cleversay-bubble-loading">
                            <div class="cleversay-loading" role="status" aria-label="${this.escapeHtml(cleversay.strings.searching)}">
                                <span aria-hidden="true"></span>
                                <span aria-hidden="true"></span>
                                <span aria-hidden="true"></span>
                            </div>
                        </div>
                    </div>
                </div>
            `);
            this.scrollToBottom();
        }

        hideLoading() {
            this.isLoading = false;
            this.submitBtn.prop('disabled', false).attr('aria-disabled', 'false');
            this.messagesEl.find('.cleversay-loading-message').remove();
        }

        // ── Scroll helpers ────────────────────────────────────────────────────

        scrollToBottom() {
            this.messagesEl.scrollTop(this.messagesEl[0].scrollHeight);
        }

        scrollToLastMessage() {
            const last = this.messagesEl.find('.cleversay-message').last();
            if (!last.length) return;
            const offset = last.position().top + this.messagesEl.scrollTop() - 10;
            this.messagesEl.animate({ scrollTop: offset }, 200);
        }

        // ── Search ────────────────────────────────────────────────────────────

        handleSubmit() {
            const question = this.input.val().trim();
            if (!question || this.isLoading) return;

            if (!RateLimiter.check()) {
                this.addMessage(
                    '<em>' + this.escapeHtml(
                        cleversay.strings.rateLimitMessage ||
                        'Too many requests. Please wait a moment before asking again.'
                    ) + '</em>',
                    'bot'
                );
                return;
            }

            this.addMessage(this.escapeHtml(question), 'user');
            this.input.val('');
            this.showLoading();
            this.search(question);
        }

        search(question) {
            $.ajax({
                url:    cleversay.ajaxUrl,
                method: 'POST',
                data: {
                    action:   'cleversay_search',
                    nonce:    cleversay.nonce,
                    question: question,
                    history:  JSON.stringify(this._history.slice(-6))
                },
                success: (response) => {
                    this.hideLoading();

                    if (response.success && response.data.found && response.data.answers.length > 0) {
                        const answer     = response.data.answers[0];
                        const related    = response.data.related || [];
                        const aiAssisted = response.data.ai_assisted || answer.ai_assisted || false;
                        this.currentAnswerId = answer.id;

                        // v4.37.85+: pass aiAssisted as an option, do
                        // NOT concatenate the badge HTML into the answer
                        // content. Concatenating tricked SimpleMarkdown's
                        // HTML detection into block-HTML mode, which
                        // skipped paragraph wrapping for AI-fallback
                        // responses. The badge is now rendered as a
                        // sibling element after the bubble.
                        this.addMessage(answer.answer, 'bot', {
                            rating:    cleversay.showRating && answer.show_rating,
                            answerId:  answer.id,
                            related:   related,
                            aiAssisted: aiAssisted,
                            showAiBadge: aiAssisted && cleversay.showAiBadge,
                            ratingTarget: answer.rating_target || (aiAssisted ? 'ai_answer' : 'kb'),
                            // v4.37.89+: source citations (server returns
                            // empty array when toggle off or no chunks).
                            sources: Array.isArray(answer.sources) ? answer.sources : [],
                        });
                    } else {
                        const suggestions = response.data?.suggestions || [];
                        this.addMessage(
                            this.escapeHtml(cleversay.strings.noAnswer || ''),
                            'bot',
                            {
                                suggestions: suggestions,
                                showInquiry: cleversay.enableInquiry,
                                question:    question
                            }
                        );
                    }

                    // Return focus to input after receiving answer
                    this.input.trigger('focus');
                },
                error: () => {
                    this.hideLoading();
                    this.addMessage(
                        this.escapeHtml(cleversay.strings.noAnswer || ''),
                        'bot',
                        { showInquiry: cleversay.enableInquiry }
                    );
                    this.input.trigger('focus');
                }
            });
        }

        // ── HTML builders ─────────────────────────────────────────────────────

        getRatingHtml(answerId, ratingTarget) {
            const target = ratingTarget || 'kb';
            return `
                <div class="cleversay-rating" data-answer-id="${answerId}" data-target="${target}" role="group"
                     aria-label="${this.escapeHtml(cleversay.strings.helpful)}">
                    <div class="cleversay-rating-label">${this.escapeHtml(cleversay.strings.helpful)}</div>
                    <div class="cleversay-rating-buttons">
                        <button type="button" class="cleversay-rating-btn" data-rating="helpful"
                                aria-label="${this.escapeHtml(cleversay.strings.yes)} — ${this.escapeHtml(cleversay.strings.helpful)}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" aria-hidden="true" focusable="false">
                                <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>
                            </svg>
                            ${this.escapeHtml(cleversay.strings.yes)}
                        </button>
                        <button type="button" class="cleversay-rating-btn" data-rating="not_helpful"
                                aria-label="${this.escapeHtml(cleversay.strings.no)} — ${this.escapeHtml(cleversay.strings.helpful)}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" aria-hidden="true" focusable="false">
                                <path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/>
                            </svg>
                            ${this.escapeHtml(cleversay.strings.no)}
                        </button>
                    </div>
                </div>
            `;
        }

        getSuggestionsHtml(suggestions) {
            if (!suggestions || !suggestions.length) return '';
            const label = (cleversay.strings && cleversay.strings.relatedQuestions) || 'Suggestions';
            const items = suggestions.map(s =>
                `<button type="button" class="cleversay-suggestion">${this.escapeHtml(s)}</button>`
            ).join('');
            return `
                <div class="cleversay-suggestions" role="navigation" aria-label="${this.escapeHtml(label)}">
                    <div class="cleversay-suggestions-label">${this.escapeHtml(label)}</div>
                    ${items}
                </div>
            `;
        }

        getRelatedQuestionsHtml(related) {
            if (!related || !related.length) return '';
            const label = (cleversay.strings && cleversay.strings.relatedQuestions) || 'Related Questions';
            const items = related.map(r =>
                `<li><a href="#" class="cleversay-related-item"
                        data-question="${this.escapeHtml(r.question)}"
                        aria-label="${this.escapeHtml(label)}: ${this.escapeHtml(r.question)}">
                    ${this.escapeHtml(r.question)}
                </a></li>`
            ).join('');
            return `
                <nav class="cleversay-related-questions" aria-label="${this.escapeHtml(label)}">
                    <div class="cleversay-related-label" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" aria-hidden="true" focusable="false">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                        </svg>
                        ${this.escapeHtml(label)}
                    </div>
                    <ul class="cleversay-related-list" role="list">${items}</ul>
                </nav>
            `;
        }

        getInquiryFormHtml(question) {
            return `
                <div class="cleversay-inquiry">
                    <h4>${this.escapeHtml(cleversay.strings.submitInquiry)}</h4>
                    <form class="cleversay-inquiry-form" aria-label="${this.escapeHtml(cleversay.strings.submitInquiry)}" novalidate>
                        <input type="hidden" name="question" value="${this.escapeHtml(question || '')}">
                        <label class="screen-reader-text" for="cs-details-${this.instanceId}">${this.escapeHtml(cleversay.strings.detailsPlaceholder)}</label>
                        <textarea id="cs-details-${this.instanceId}" name="details"
                                  placeholder="${this.escapeHtml(cleversay.strings.detailsPlaceholder)}" rows="3"></textarea>
                        <label class="screen-reader-text" for="cs-email-${this.instanceId}">${this.escapeHtml(cleversay.strings.inquiryPlaceholder)}</label>
                        <input id="cs-email-${this.instanceId}" type="email" name="email"
                               placeholder="${this.escapeHtml(cleversay.strings.inquiryPlaceholder)}"
                               autocomplete="email">
                        <button type="submit">${this.escapeHtml(cleversay.strings.submitInquiry)}</button>
                    </form>
                </div>
            `;
        }

        // ── Interactions ──────────────────────────────────────────────────────

        handleRating(btn) {
            const rating          = btn.data('rating');
            const ratingContainer = btn.closest('.cleversay-rating');
            const answerId        = ratingContainer.data('answer-id');
            const target          = ratingContainer.data('target') || 'kb';

            ratingContainer.find('.cleversay-rating-btn').prop('disabled', true).attr('aria-disabled', 'true');
            btn.addClass('selected').attr('aria-pressed', 'true');

            // Route to the right endpoint based on target. AI answers go to
            // cleversay_rate_ai_answer (writes to cleversay_ai_answers table);
            // KB answers go to cleversay_rate_answer (writes to KB ratings table).
            // Note: the two endpoints expect different field names for the ID:
            //   - KB endpoint:  $_POST['id']
            //   - AI endpoint:  $_POST['ai_answer_id']
            const isAi   = target === 'ai_answer';
            const action = isAi ? 'cleversay_rate_ai_answer' : 'cleversay_rate_answer';
            const data   = {
                action: action,
                nonce:  cleversay.nonce,
                rating: rating
            };
            if (isAi) {
                data.ai_answer_id = answerId;
            } else {
                data.id = answerId;
            }

            $.ajax({
                url:    cleversay.ajaxUrl,
                method: 'POST',
                data:   data,
                success: () => {
                    // Branch by what was rated and whether thumbs-up/down.
                    if (rating === 'helpful') {
                        // 👍 — both KB and AI just say thanks.
                        ratingContainer.html(
                            `<div class="cleversay-rating-thanks" role="status">${this.escapeHtml(cleversay.strings.thanks)}</div>`
                        );
                        return;
                    }

                    // 👎 path. Replace buttons with a brief acknowledgment,
                    // then escalate to the inquiry form via a follow-up
                    // bot message — matches the floating widget flow.
                    ratingContainer.html(
                        `<div class="cleversay-rating-thanks" role="status">${this.escapeHtml(cleversay.strings.thanks)}</div>`
                    );

                    if (cleversay.enableInquiry) {
                        // Try to find the question that produced this answer.
                        // The rating div sits under a message bubble; the most
                        // recent user message before it is the question asked.
                        const userMsgs = this.messagesEl.find('.cleversay-message.user .cleversay-bubble');
                        const lastUserQuestion = userMsgs.length
                            ? userMsgs.last().text().trim()
                            : '';
                        this.askInquiry(lastUserQuestion);
                    }
                }
            });
        }

        /**
         * Show a "Still need help? Send us a message." prompt as a new bot
         * message with Yes/No buttons. Mirrors the floating-widget askInquiry
         * pattern so both widgets behave consistently.
         *
         * @param {string} question  The question to pre-fill in the inquiry form
         *                           if the visitor clicks Yes.
         */
        askInquiry(question) {
            if (!cleversay.enableInquiry) return;
            // Don't show a second prompt if one is already pending.
            if (this._pendingInquiryQuestion) return;
            this._pendingInquiryQuestion = question || '';

            const stillHelp = cleversay.strings.stillHelp || 'Still need help? Send us a message.';
            const yesLabel  = cleversay.strings.inquiryYes || 'Yes, please';
            const noLabel   = cleversay.strings.inquiryNo  || 'No, thanks';

            // Build the bot message with Yes/No buttons appended.
            const yesnoHtml = `
                <div class="cleversay-yesno">
                    <button type="button" class="cleversay-yesno-btn cleversay-yesno-yes">${this.escapeHtml(yesLabel)}</button>
                    <button type="button" class="cleversay-yesno-btn cleversay-yesno-no">${this.escapeHtml(noLabel)}</button>
                </div>
            `;

            const messageHtml = `
                <div class="cleversay-message bot" role="listitem">
                    ${cleversay.mascotUrl
                        ? `<img src="${cleversay.mascotUrl}" alt="" class="cleversay-msg-avatar" aria-hidden="true">`
                        : ''}
                    <div class="cleversay-msg-body">
                        <span class="cleversay-msg-label">${this.escapeHtml(cleversay.botLabel || 'Assistant')}</span>
                        <div class="cleversay-bubble">${this.escapeHtml(stillHelp)}</div>
                        ${yesnoHtml}
                    </div>
                </div>
            `;
            this.messagesEl.append(messageHtml);
            this.scrollToLastMessage();

            // Wire the Yes/No buttons. Use one-off bindings on the just-appended
            // element so we don't accidentally attach handlers to all yesno
            // groups in the message log.
            const lastMsg = this.messagesEl.children().last();
            lastMsg.find('.cleversay-yesno-yes').one('click', (e) => {
                e.stopPropagation();
                lastMsg.find('.cleversay-yesno').remove();
                this.addMessage(yesLabel, 'user');
                const intro = cleversay.strings.inquiryIntro || 'Sure — fill out the form below and we\'ll get back to you.';
                this.addMessage(intro, 'bot', {
                    showInquiry: true,
                    question:    this._pendingInquiryQuestion || '',
                });
                this._pendingInquiryQuestion = null;
            });

            lastMsg.find('.cleversay-yesno-no').one('click', (e) => {
                e.stopPropagation();
                lastMsg.find('.cleversay-yesno').remove();
                this.addMessage(noLabel, 'user');
                const declined = cleversay.strings.inquiryDeclined || 'No problem! Feel free to ask if you have other questions.';
                this.addMessage(declined, 'bot');
                this._pendingInquiryQuestion = null;
            });
        }

        handleInquiry(form) {
            const question = form.find('input[name="question"]').val();
            const details  = form.find('textarea[name="details"]').val();
            const email    = form.find('input[name="email"]').val();

            const submitBtn = form.find('button[type="submit"]');
            submitBtn.prop('disabled', true).text('…');

            $.ajax({
                url:    cleversay.ajaxUrl,
                method: 'POST',
                data: {
                    action:   'cleversay_submit_inquiry',
                    nonce:    cleversay.nonce,
                    question: question,
                    details:  details,
                    email:    email
                },
                success: (response) => {
                    if (response.success) {
                        const msg = `<div class="cleversay-inquiry-success" role="status">${this.escapeHtml(cleversay.strings.inquirySuccess)}</div>`;
                        form.replaceWith(msg);
                    } else {
                        submitBtn.prop('disabled', false).text(cleversay.strings.submitInquiry);
                        const errMsg = response.data?.message || cleversay.strings.inquiryError;
                        this._announceError(errMsg);
                    }
                },
                error: () => {
                    submitBtn.prop('disabled', false).text(cleversay.strings.submitInquiry);
                    this._announceError(cleversay.strings.inquiryError);
                }
            });
        }

        _announceError(message) {
            // Inject an ARIA live region for errors instead of alert()
            let liveRegion = $('#cleversay-error-live');
            if (!liveRegion.length) {
                liveRegion = $('<div id="cleversay-error-live" role="alert" aria-live="assertive" class="screen-reader-text"></div>');
                $('body').append(liveRegion);
            }
            liveRegion.text('').text(message);
        }

        // v4.37.89+: Sources panel — slides up from the bottom of the
        // chat container to show citations for an AI-fallback answer.
        // Lazily-created on first call (single panel reused across all
        // bubbles). Closes via X button, Escape key, click outside, or
        // tapping Sources link again.
        _toggleSourcesPanel(sources) {
            // v4.37.90+: panel lives at .cleversay-widget level (when in
            // floating widget) or .cleversay-chatbot level (defensive
            // fallback). Look in both places.
            const $widget = this.container.closest('.cleversay-widget');
            const $host   = $widget.length ? $widget : this.container;
            const $existing = $host.find('> .cleversay-sources-panel');
            if ($existing.length && $existing.hasClass('is-open')) {
                this._closeSourcesPanel();
                return;
            }
            this._showSourcesPanel(sources);
        }

        _showSourcesPanel(sources) {
            // Build (or reuse) the panel element, refresh its contents,
            // then animate in.
            //
            // v4.37.90+: panel attaches to the parent .cleversay-widget
            // when present (floating widget case), so it can position
            // OUTSIDE the chat container — sliding out from the side
            // instead of covering chat content. Falls back to attaching
            // to the chatbot container if no widget parent is found
            // (defensive only — shortcode mode doesn't render Sources).
            const $widget = this.container.closest('.cleversay-widget');
            const $host   = $widget.length ? $widget : this.container;

            let $panel = $host.find('> .cleversay-sources-panel');
            if (!$panel.length) {
                $panel = $(`
                    <div class="cleversay-sources-panel" role="dialog" aria-label="Sources">
                        <div class="cleversay-sources-panel-header">
                            <h4>Sources</h4>
                            <button type="button" class="cleversay-sources-close" aria-label="Close">
                                <svg viewBox="0 0 24 24" width="18" height="18"><path d="M18.3 5.71L12 12l6.3 6.29-1.42 1.42L10.59 13.41 4.29 19.71 2.88 18.29 9.17 12 2.88 5.71 4.29 4.29l6.3 6.3 6.3-6.3z" fill="currentColor"/></svg>
                            </button>
                        </div>
                        <ul class="cleversay-sources-list" role="list"></ul>
                    </div>
                `);
                $host.append($panel);

                $panel.on('click', '.cleversay-sources-close', () => this._closeSourcesPanel());
            }

            const $list = $panel.find('.cleversay-sources-list').empty();
            sources.forEach((src) => {
                const icon = this._sourceIcon(src.type);
                const safeTitle = this.escapeHtml(src.title || '(untitled source)');
                const safeFile  = this.escapeHtml(src.file_name || '');
                const url = src.url || '';

                const item = url
                    ? `<li class="cleversay-source-item">
                         <a href="${this.escapeHtml(url)}" target="_blank" rel="noopener" class="cleversay-source-link">
                           <span class="cleversay-source-icon">${icon}</span>
                           <span class="cleversay-source-text">
                             <span class="cleversay-source-title">${safeTitle}</span>
                             ${safeFile ? `<span class="cleversay-source-meta">${safeFile}</span>` : ''}
                           </span>
                           <svg class="cleversay-source-arrow" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                             <path d="M14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3z" fill="currentColor"/>
                           </svg>
                         </a>
                       </li>`
                    : `<li class="cleversay-source-item cleversay-source-item-disabled">
                         <span class="cleversay-source-icon">${icon}</span>
                         <span class="cleversay-source-text">
                           <span class="cleversay-source-title">${safeTitle}</span>
                         </span>
                       </li>`;
                $list.append(item);
            });

            // Force reflow so the CSS transition triggers. Use
            // display:flex to match the open-state layout (column with
            // sticky header + scrolling list); jQuery's show() would
            // set display:block and we'd see a layout flash.
            $panel.css('display', 'flex')[0].offsetHeight;
            $panel.addClass('is-open');
        }

        _closeSourcesPanel() {
            const $widget = this.container.closest('.cleversay-widget');
            const $host   = $widget.length ? $widget : this.container;
            const $panel  = $host.find('> .cleversay-sources-panel');
            if (!$panel.length) return;
            $panel.removeClass('is-open');
            // Match the CSS transition duration before fully hiding
            setTimeout(() => $panel.css('display', 'none'), 300);
        }

        _sourceIcon(type) {
            switch (type) {
                case 'pdf':  return '📄';
                case 'docx': return '📝';
                case 'url':  return '🔗';
                case 'text': return '📑';
                default:     return '•';
            }
        }

        getAIBadgeHtml() {
            const label = (cleversay.strings && cleversay.strings.aiLabel) || 'AI-assisted answer';
            return `<div class="cleversay-ai-badge-wrap"><span class="cleversay-ai-badge" title="${label}">${label}</span></div>`;
        }

        // v4.37.89+: Sources link below AI-fallback bubbles. Renders only
        // when the answer has citation rows. Stores the source list as
        // a JSON-encoded data attribute so the click handler can show
        // the panel without needing a server round-trip.
        getSourcesLinkHtml(sources) {
            if (!sources || !sources.length) return '';
            const label = (cleversay.strings && cleversay.strings.sourcesLabel) || 'Sources';
            const count = sources.length;
            // Encode as base64 to keep HTML attributes clean (titles can
            // contain quotes that would break a naive JSON.stringify).
            const payload = btoa(unescape(encodeURIComponent(JSON.stringify(sources))));
            return `
                <div class="cleversay-sources-wrap">
                    <button type="button" class="cleversay-sources-link" data-sources="${payload}">
                        ${this.escapeHtml(label)} (${count})
                        <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" style="vertical-align:-2px;margin-left:2px;">
                            <path d="M7 10l5 5 5-5z" fill="currentColor"/>
                        </svg>
                    </button>
                </div>
            `;
        }

        escapeHtml(text) {
            if (typeof text !== 'string') return '';
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }
    }

    // =========================================================================
    // CleverSayWidget (floating button + panel)
    // =========================================================================
    class CleverSayWidget {
        constructor(el) {
            this.widget    = $(el);
            this.toggle    = this.widget.find('.cleversay-toggle');
            this.container = this.widget.find('.cleversay-container');
            this.chatbot   = null;
            this.init();
        }

        init() {
            this.chatbot = new CleverSayChatbot(this.container);

            this.toggle.on('click', () => this.toggleWidget());
            this.widget.find('.cleversay-close').on('click', () => this.closeWidget());

            // Escape key closes widget and returns focus
            $(document).on('keydown.cleversay', (e) => {
                if (e.key === 'Escape' && this.widget.hasClass('active')) {
                    this.closeWidget();
                }
            });

            // Trap focus inside the container when open
            this.container.on('keydown', (e) => {
                if (e.key === 'Tab') {
                    this._trapFocus(e);
                }
            });
        }

        toggleWidget() {
            if (this.widget.hasClass('active')) {
                this.closeWidget();
            } else {
                this.openWidget();
            }
        }

        openWidget() {
            this.widget.addClass('active');
            this.toggle.attr('aria-expanded', 'true')
                       .attr('aria-label', cleversay.strings.close || 'Close chat');
            this.container.attr('aria-hidden', 'false');
            // Move focus to the input
            setTimeout(() => this.chatbot.input.trigger('focus'), 50);
        }

        closeWidget() {
            this.widget.removeClass('active');
            this.toggle.attr('aria-expanded', 'false')
                       .attr('aria-label', 'Open help chat');
            this.container.attr('aria-hidden', 'true');
            // v4.37.90+: paired panel — close the Sources citation panel
            // when the widget closes. Conceptually they're a pair; an
            // open panel after the widget closes would be orphaned UI.
            if (this.chatbot && typeof this.chatbot._closeSourcesPanel === 'function') {
                this.chatbot._closeSourcesPanel();
            }
            // Return focus to the toggle button
            this.toggle.trigger('focus');
        }

        _trapFocus(e) {
            const focusable = this.container.find(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
            ).filter(':visible');
            if (!focusable.length) return;

            const first = focusable.first()[0];
            const last  = focusable.last()[0];

            if (e.shiftKey) {
                if (document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }
    }

    // =========================================================================
    // CleverSaySearchForm
    // =========================================================================
    class CleverSaySearchForm {
        constructor(container) {
            this.container        = $(container);
            this.input            = this.container.find('input[type="text"]');
            this.submitBtn        = this.container.find('button[type="submit"]');
            this.resultsContainer = this.container.find('.cleversay-results');
            this.init();
        }

        init() {
            this.container.on('submit', (e) => {
                e.preventDefault();
                this.handleSearch();
            });
        }

        handleSearch() {
            const question = this.input.val().trim();
            if (!question) return;

            if (!RateLimiter.check()) return;

            this.submitBtn.prop('disabled', true).text(cleversay.strings.searching);
            this.resultsContainer.html(
                `<div class="cleversay-loading" role="status" aria-label="${this.escapeHtml(cleversay.strings.searching)}">
                    <span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span>
                </div>`
            );

            $.ajax({
                url:    cleversay.ajaxUrl,
                method: 'POST',
                data: {
                    action:   'cleversay_search',
                    nonce:    cleversay.nonce,
                    question: question
                },
                success: (response) => {
                    this.submitBtn.prop('disabled', false).text(cleversay.strings.askButton);
                    if (response.success && response.data.found) {
                        this.displayResults(response.data.answers);
                    } else {
                        this.resultsContainer.html(
                            `<div class="cleversay-no-results" role="status">${this.escapeHtml(cleversay.strings.noAnswer)}</div>`
                        );
                    }
                },
                error: () => {
                    this.submitBtn.prop('disabled', false).text(cleversay.strings.askButton);
                    this.resultsContainer.html(
                        `<div class="cleversay-error" role="alert">${this.escapeHtml(cleversay.strings.noAnswer)}</div>`
                    );
                }
            });
        }

        displayResults(answers) {
            let html = '<ul class="cleversay-results-list" role="list">';
            answers.forEach(answer => {
                html += `
                    <li class="cleversay-result-item" role="listitem">
                        <div class="cleversay-result-question">${this.escapeHtml(answer.question)}</div>
                        <div class="cleversay-result-answer">${answer.answer}</div>
                    </li>
                `;
            });
            html += '</ul>';
            this.resultsContainer.html(html);
        }

        escapeHtml(text) {
            if (typeof text !== 'string') return '';
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }
    }

    // =========================================================================
    // CleverSayFAQ
    // =========================================================================
    class CleverSayFAQ {
        constructor(container) {
            this.container = $(container);
            this.init();
        }

        init() {
            this.container.on('click keypress', '.cleversay-faq-question', (e) => {
                if (e.type === 'keypress' && e.which !== 13 && e.which !== 32) return;
                e.preventDefault();

                const item     = $(e.currentTarget).closest('.cleversay-faq-item');
                const isActive = item.hasClass('active');
                const btn      = item.find('.cleversay-faq-question');

                // Close all
                this.container.find('.cleversay-faq-item').removeClass('active');
                this.container.find('.cleversay-faq-question')
                    .attr('aria-expanded', 'false');

                // Open clicked if it was closed
                if (!isActive) {
                    item.addClass('active');
                    btn.attr('aria-expanded', 'true');
                }
            });

            // Initialise aria-expanded on all buttons
            this.container.find('.cleversay-faq-question').each(function() {
                $(this).attr({
                    'aria-expanded': $(this).closest('.cleversay-faq-item').hasClass('active')
                        ? 'true' : 'false',
                    'role': 'button',
                    'tabindex': '0'
                });
            });
        }
    }

    // =========================================================================
    // Boot
    // =========================================================================
    $(document).ready(function() {

        // Floating widget
        $('.cleversay-widget').each(function() {
            new CleverSayWidget(this);
        });

        // Embedded chatbots
        $('.cleversay-embedded').each(function() {
            new CleverSayChatbot(this);
        });

        // Inline search forms
        $('.cleversay-search-form').each(function() {
            new CleverSaySearchForm(this);
        });

        // FAQ accordions
        $('.cleversay-faq').each(function() {
            new CleverSayFAQ(this);
        });

        // Top Questions click-to-ask
        $(document).on('click', '.cleversay-top-question', function(e) {
            e.preventDefault();
            const question = $(this).data('question');
            if (!question) return;

            const wrapper = $(this).closest('.cleversay-embedded-wrapper');
            const chatbot = wrapper.find('.cleversay-embedded');

            if (chatbot.length) {
                chatbot.find('.cleversay-input').val(question);
                chatbot.find('.cleversay-submit').trigger('click');

                if ($(window).width() < 768) {
                    $('html, body').animate({ scrollTop: chatbot.offset().top - 20 }, 300);
                }
            }
        });
    });

})(jQuery);
