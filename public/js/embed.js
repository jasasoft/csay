/**
 * CleverSay Embed Script — Shadow DOM edition
 *
 * Drop on any external site with:
 *   <script src="https://yoursite.com/.../embed.js"
 *           data-site="https://yoursite.com"
 *           data-token="YOUR_TOKEN"></script>
 *
 * The widget lives inside a Shadow DOM so the host site's CSS cannot touch it.
 * @package CleverSay
 */
(function () {
    'use strict';

    // ── Read data-site / data-token from the script tag ──────────────────────
    var scripts  = document.querySelectorAll('script[data-site]');
    var scriptEl = scripts[scripts.length - 1];
    if (!scriptEl) return;

    var SITE        = scriptEl.getAttribute('data-site').replace(/\/$/, '');
    var EMBED_TOKEN = scriptEl.getAttribute('data-token') || '';
    var CONFIG_URL  = SITE + '/wp-json/cleversay/v1/embed-config';
    var WIDGET_ID   = 'cs-embed-widget';

    // ── Rate limiter ─────────────────────────────────────────────────────────
    var rl = { count: 0, reset: Date.now() + 60000 };
    function rateOk() {
        var now = Date.now();
        if (now > rl.reset) { rl.count = 0; rl.reset = now + 60000; }
        if (rl.count >= 20) return false;
        rl.count++;
        return true;
    }


    // ── Font helper ───────────────────────────────────────────────────────────
    var FONT_MAP = {
        'system':  { family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif', url: '' },
        'inter':   { family: '"Inter", sans-serif',   url: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap' },
        'dm-sans': { family: '"DM Sans", sans-serif', url: 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap' },
        'nunito':  { family: '"Nunito", sans-serif',  url: 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600&display=swap' },
        'poppins': { family: '"Poppins", sans-serif', url: 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap' },
        'lato':    { family: '"Lato", sans-serif',    url: 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap' },
    };

    function getFontConfig(cfg) {
        if (cfg.widgetFont === 'custom') {
            return { family: cfg.widgetFontFamily || 'sans-serif', url: cfg.widgetFontUrl || '' };
        }
        return FONT_MAP[cfg.widgetFont] || FONT_MAP['system'];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function esc(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function post(url, data, cb) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.withCredentials = false;
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            try { cb(null, JSON.parse(xhr.responseText)); }
            catch(e) { cb(e); }
        };
        xhr.onerror = function () { cb(new Error('Network error')); };
        var params = Object.keys(data).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
        }).join('&');
        xhr.send(params);
    }

    // Minimal markdown → HTML (for plain-text AI answers)
    function md(text) {
        if (!text) return '';
        var h = text
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>')
            .replace(/__([^_]+?)__/g, '<strong>$1</strong>')
            // v4.37.84+: markdown links [label](url) — for contact info
            // emitted by the polish prompt
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g,
                     '<a href="$2" target="_blank" rel="noopener">$1</a>')
            // Bare URLs: linkify if not already inside a tag.
            // Match URL liberally then preserve trailing punctuation
            // outside the link.
            .replace(/(^|[^"'>])(https?:\/\/[^\s<>]+?)([.,!?;)\]]?)(?=\s|$|<)/g,
                     '$1<a href="$2" target="_blank" rel="noopener">$2</a>$3')
            .replace(/^[ \t]*[-*]\s+(.+)$/gm, '<li>$1</li>')
            .replace(/^[ \t]*\d+\.[ \t]+(.+)$/gm, '<li>$1</li>');
        h = h.replace(/(<li>[\s\S]+?<\/li>)(?!\s*<li>)/g, '<ul>$1</ul>');
        // v4.40.4: collapse whitespace between adjacent <li> tags so the
        // following \n→<br> conversion doesn't insert <br> between list
        // items. Without this, list output renders as
        // "<li>A</li><br><li>B</li>" instead of "<li>A</li><li>B</li>".
        h = h.replace(/(<\/li>)\s+(<li>)/g, '$1$2');
        h = h.replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>');
        return '<p>' + h + '</p>';
    }

    // ── All widget CSS — self-contained, no external dependencies ────────────
    function buildCSS(cfg, fontCfg) {
        return [
            ':host { all: initial; }',
            '*, *::before, *::after { box-sizing: border-box; }',

            /* ── Variables ── */
            ':host {',
            '  --cs-primary:      ' + cfg.primaryColor   + ';',
            '  --cs-header-bg:    ' + cfg.headerBgColor  + ';',
            '  --cs-header-text:  ' + cfg.headerTextColor + ';',
            '  --cs-toggle-bg:    ' + (cfg.toggleBgColor || cfg.primaryColor) + ';',
            '  --cs-user-bubble:  ' + (cfg.userBubbleColor || cfg.primaryColor) + ';',
            '  --cs-user-text:    ' + (cfg.userBubbleText  || '#fff') + ';',
            '  --cs-bot-bubble:   ' + (cfg.botBubbleColor  || '#fff') + ';',
            '  --cs-bot-text:     ' + (cfg.botBubbleText   || '#1d2327') + ';',
            '  --cs-chat-bg:      ' + (cfg.chatBgColor     || '#f5f5f7') + ';',
            '  --cs-text:         #1e1e1e;',
            '  --cs-text-light:   #6c757d;',
            '  --cs-border:       #e2e4e7;',
            '  --cs-radius:       16px;',
            '  --cs-radius-sm:    10px;',
            '  --cs-shadow:       0 4px 20px rgba(0,0,0,0.15);',
            '  font-family: ' + fontCfg.family + ';',
            '  font-size: ' + (cfg.widgetFontSize || 15) + 'px;',
            '  line-height: 1.5;',
            '  color: var(--cs-text);',
            '}',

            /* ── Widget wrapper ── */
            '.cs-widget { position: fixed; z-index: 2147483647; }',
            '.cs-widget.position-bottom-right { bottom: 24px; right: 24px; }',
            '.cs-widget.position-bottom-left  { bottom: 24px; left:  24px; }',
            '.cs-widget.position-top-right    { top: 24px;    right: 24px; }',
            '.cs-widget.position-top-left     { top: 24px;    left:  24px; }',

            /* ── Toggle button ── */
            '.cs-toggle {',
            '  width: 60px; height: 60px; border-radius: 50%;',
            '  background: var(--cs-toggle-bg);',
            '  border: 3px solid var(--cs-toggle-bg); outline: 3px solid #fff;',
            '  cursor: pointer;',
            '  box-shadow: 0 4px 16px rgba(0,0,0,0.2);',
            '  display: flex; align-items: center; justify-content: center;',
            '  transition: transform 0.2s, box-shadow 0.2s;',
            '  position: relative; overflow: hidden; padding: 0;',
            '}',
            '.cs-toggle:hover { transform: scale(1.08); box-shadow: 0 6px 20px rgba(0,0,0,0.25); }',
            '.cs-toggle svg { width: 28px; height: 28px; fill: #fff; display: block; flex-shrink: 0; }',
            '.cs-toggle .cs-close-icon { display: none; }',
            '.cs-widget.active .cs-toggle .cs-chat-icon,',
            '.cs-widget.active .cs-toggle-avatar { display: none !important; }',
            '.cs-widget.active .cs-toggle .cs-close-icon {',
            '  display: flex !important; width: 26px; height: 26px; fill: #fff; position: relative; z-index: 1;',
            '}',
            '.cs-widget.active .cs-toggle { display: none; }',
            '.cs-toggle-avatar {',
            '  position: absolute; inset: 0; width: 100%; height: 100%;',
            '  object-fit: cover; border-radius: 50%; display: block;',
            '}',

            /* ── Chat container ── */
            '.cs-container {',
            '  position: absolute; width: 370px; max-width: calc(100vw - 40px);',
            '  background: #fff; border-radius: var(--cs-radius);',
            '  box-shadow: var(--cs-shadow);',
            '  display: flex; flex-direction: column;',
            '  overflow: hidden;',
            '  transition: opacity 0.2s, transform 0.2s;',
            '  opacity: 0; transform: scale(0.95) translateY(8px); pointer-events: none;',
            /* Target height 680px but never taller than viewport minus toggle+padding */
            '  height: min(680px, calc(100vh - 40px));',
            '}',
            '.cs-widget.position-bottom-right .cs-container,',
            '.cs-widget.position-bottom-left  .cs-container { bottom: 72px; }',
            '.cs-widget.active.position-bottom-right .cs-container,',
            '.cs-widget.active.position-bottom-left  .cs-container { bottom: 16px; }',
            '.cs-widget.position-top-right    .cs-container,',
            '.cs-widget.position-top-left     .cs-container { top: 72px; }',
            '.cs-widget.position-bottom-right .cs-container,',
            '.cs-widget.position-top-right    .cs-container { right: 0; }',
            '.cs-widget.position-bottom-left  .cs-container,',
            '.cs-widget.position-top-left     .cs-container { left: 0; }',
            '.cs-widget.active .cs-container {',
            '  opacity: 1; transform: scale(1) translateY(0); pointer-events: auto;',
            '}',

            /* ── Header ── */
            '.cs-header {',
            '  background: var(--cs-header-bg); color: var(--cs-header-text);',
            '  display: flex; align-items: center; gap: 10px; padding: 12px 16px; flex-shrink: 0;',
            '}',
            '.cs-header-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }',
            '.cs-header-title { margin: 0; font-size: 16px; font-weight: 600; flex: 1; color: var(--cs-header-text); }',
            '.cs-close {',
            '  background: none; border: none; cursor: pointer; padding: 4px;',
            '  color: var(--cs-header-text); opacity: 0.8; border-radius: 6px;',
            '  display: flex; align-items: center; justify-content: center;',
            '}',
            '.cs-close:hover { opacity: 1; background: rgba(255,255,255,0.15); }',
            '.cs-close svg { width: 18px; height: 18px; fill: currentColor; display: block; }',

            /* ── Messages ── */
            '.cs-messages {',
            '  flex: 1; overflow-y: auto; padding: 16px; min-height: 0;',
            '  background: var(--cs-chat-bg); display: flex; flex-direction: column; gap: 16px;',
            '  scroll-behavior: smooth;',
            '}',

            /* ── Message rows ── */
            '@keyframes cs-slide-in { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }',
            '.cs-message {',
            '  display: flex; align-items: flex-start; gap: 8px;',
            '  animation: cs-slide-in 0.25s ease;',
            '}',
            '.cs-message.user { flex-direction: row-reverse; }',

            /* ── Avatars ── */
            '.cs-avatar {',
            '  width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;',
            '  object-fit: cover;',
            '}',
            '.cs-avatar-placeholder {',
            '  width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;',
            '  background: var(--cs-primary); display: flex; align-items: center; justify-content: center;',
            '}',
            '.cs-avatar-placeholder svg { width: 16px; height: 16px; fill: #fff; display: block; }',

            /* ── Message body ── */
            '.cs-msg-body { display: flex; flex-direction: column; gap: 4px; max-width: 85%; }',
            '.cs-msg-label { font-size: 11px; font-weight: 600; color: var(--cs-text-light); text-transform: uppercase; letter-spacing: 0.04em; padding-left: 2px; }',

            /* ── Bubbles ── */
            '.cs-bubble {',
            '  padding: 10px 14px; border-radius: 0 14px 14px 14px;',
            '  font-size: inherit; line-height: 1.55; word-break: break-word;',
            '  background: var(--cs-bot-bubble); color: var(--cs-bot-text);',
            '  box-shadow: 0 1px 3px rgba(0,0,0,0.08);',
            '}',
            '.cs-message.user .cs-bubble {',
            '  border-radius: 14px 14px 0 14px;',
            '  background: var(--cs-user-bubble); color: var(--cs-user-text);',
            '  box-shadow: 0 1px 3px rgba(0,0,0,0.12);',
            '}',
            '.cs-bubble p { margin: 0 0 8px; }',
            '.cs-bubble p:last-child { margin-bottom: 0; }',
            /* Follow-up suggestion — visual treatment for the trailing paragraph
             * the AI adds with an adjacent-topic question. We can\'t reliably
             * identify it from markup alone, so we style the LAST paragraph in
             * AI bubbles slightly muted + a top border, only when it ends with
             * a question mark (the format the prompt enforces). The paragraph-
             * count check (>1) prevents single-paragraph answers from getting
             * styled as if they were follow-ups. */
            '.cs-msg.ai-answer .cs-bubble p:last-child:not(:only-child) { color: var(--cs-text-light); font-style: italic; padding-top: 6px; margin-top: 8px; border-top: 1px solid var(--cs-border); font-size: 0.95em; }',
            '.cs-bubble a { color: var(--cs-primary); text-decoration: underline; text-underline-offset:2px; cursor: pointer; }',
            '.cs-bubble a:hover { opacity: 0.8; }',
            '.cs-bubble ul, .cs-bubble ol { margin: 4px 0; padding-left: 20px; }',
            '.cs-bubble li { margin-bottom: 2px; }',

            /* ── Typing indicator ── */
            '@keyframes cs-bounce { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(-6px)} }',
            '.cs-typing { display: flex; align-items: center; gap: 4px; padding: 12px 14px; }',
            '.cs-typing span { width: 7px; height: 7px; border-radius: 50%; background: var(--cs-text-light); display: inline-block; animation: cs-bounce 1.2s infinite; }',
            '.cs-typing span:nth-child(2) { animation-delay: 0.15s; }',
            '.cs-typing span:nth-child(3) { animation-delay: 0.3s; }',

            /* ── Rating ── */
            '.cs-rating { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; }',
            '.cs-rating-label { font-size: 12px; color: var(--cs-text-light); width: 100%; }',
            '.cs-rating-buttons { display: flex; gap: 6px; }',
            '.cs-rate {',
            '  display: inline-flex; align-items: center; gap: 4px;',
            '  padding: 4px 10px; font-size: 13px;',
            '  border: 1px solid var(--cs-border); border-radius: 20px;',
            '  background: #fff; cursor: pointer; color: var(--cs-text-light);',
            '  transition: all 0.15s;',
            '}',
            '.cs-rate:hover { background: var(--cs-primary); color: #fff; border-color: var(--cs-primary); }',
            '.cs-rate svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; display: inline-block; vertical-align: middle; flex-shrink: 0; }',

            /* ── Input area ── */
            '.cs-input-area {',
            '  display: flex; align-items: center; gap: 8px; padding: 10px 12px;',
            '  border-top: 1px solid var(--cs-border); background: #fff; flex-shrink: 0;',
            '}',
            '.cs-input {',
            '  flex: 1; border: 1px solid var(--cs-border); border-radius: 22px;',
            '  padding: 9px 14px; font-size: inherit; outline: none;',
            '  font-family: inherit; transition: border-color 0.15s; background: #fff; color: var(--cs-text);',
            '}',
            '.cs-input:focus { border-color: var(--cs-primary); }',
            '.cs-submit {',
            '  width: 38px; height: 38px; border-radius: 50%;',
            '  background: var(--cs-primary); border: none; cursor: pointer;',
            '  display: flex; align-items: center; justify-content: center; flex-shrink: 0;',
            '  transition: opacity 0.15s;',
            '}',
            '.cs-submit:hover { opacity: 0.85; }',
            '.cs-submit svg { width: 18px; height: 18px; fill: #fff; display: block; }',

            /* ── AI badge ── */
            '.cs-ai-badge {',
            '  font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;',
            '  color: var(--cs-primary); margin-bottom: 2px; padding-left: 2px;',
            '}',

            /* ── Teaser bubble ── */
            '@keyframes cs-teaser-in { from { opacity:0; } to { opacity:1; } }',
            '.cs-teaser {',
            '  position:absolute;',
            '  bottom:50%; transform:translateY(50%);',
            '  right:72px;',
            '  background:var(--cs-header-bg); color:var(--cs-header-text);',
            '  padding:12px 32px 12px 16px; border-radius:14px 14px 14px 14px;',
            '  box-shadow:0 4px 16px rgba(0,0,0,0.18);',
            '  font-size:14px; line-height:1.45; width:220px; white-space:normal;',
            '  animation:cs-teaser-in 0.3s ease;',
            '  cursor:pointer;',
            '}',
            /* Tail pointing right toward the toggle button */
            '.cs-teaser::after {',
            '  content:""; position:absolute;',
            '  top:50%; right:-8px; transform:translateY(-50%);',
            '  border:8px solid transparent;',
            '  border-left-color:var(--cs-header-bg); border-right:0;',
            '}',
            '.cs-teaser-close {',
            '  position:absolute; top:6px; right:8px;',
            '  background:none; border:none; cursor:pointer; font-size:13px;',
            '  color:var(--cs-header-text); opacity:0.7; line-height:1; padding:2px 4px;',
            '}',
            '.cs-teaser-close:hover { opacity:1; }',

            /* ── Screen reader utility ── */
            '.sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); border:0; }',

            /* ── Still need help / inquiry ── */
            '.cs-still-help { margin-top:10px; }',
            '.cs-still-help-link { display:inline-flex; align-items:center; gap:6px; background:rgba(0,0,0,0.05); border:none; cursor:pointer; font-size:13px; color:var(--cs-text-light); text-decoration:none; padding:5px 12px; border-radius:50px; transition:background 0.15s, color 0.15s; font-family:inherit; white-space:nowrap; }',
            '.cs-still-help-link:hover { background:var(--cs-primary); color:#fff; }',
            '.cs-still-help-link svg { flex-shrink:0; }',
            '.cs-inquiry-form { margin-top:10px; padding:12px 14px; background:var(--cs-bot-bubble); border:1px solid var(--cs-border); border-radius:10px; display:flex; flex-direction:column; gap:8px; }',
            '.cs-inquiry-form input, .cs-inquiry-form textarea { border:1px solid var(--cs-border); border-radius:8px; padding:8px 10px; font-size:13px; font-family:inherit; outline:none; width:100%; background:#fff; color:var(--cs-text); transition:border-color 0.15s; resize:none; }',
            '.cs-inquiry-form input:focus, .cs-inquiry-form textarea:focus { border-color:var(--cs-primary); }',
            '.cs-inquiry-submit { align-self:flex-end; padding:7px 16px; border-radius:20px; background:var(--cs-primary); color:#fff; border:none; font-size:13px; font-family:inherit; font-weight:600; cursor:pointer; transition:opacity 0.15s; }',
            '.cs-inquiry-submit:hover { opacity:0.85; }',
            '.cs-inquiry-success { font-size:13px; color:#00a32a; padding:6px 0; }',
            '.cs-yesno { display:flex; gap:8px; margin-top:8px; flex-wrap:wrap; }',
            '.cs-yesno-btn { padding:6px 16px; border-radius:20px; border:1.5px solid var(--cs-primary); font-size:13px; font-family:inherit; cursor:pointer; transition:all 0.15s; background:#fff; }',
            '.cs-yesno-yes { background:var(--cs-primary); color:#fff; }',
            '.cs-yesno-yes:hover { opacity:0.85; }',
            '.cs-yesno-no { color:var(--cs-primary); background:#fff; }',
            '.cs-yesno-no:hover { background:var(--cs-primary); color:#fff; }',

            /* ── Conversation CSAT prompt ── */
            '.cs-csat { margin:12px 0; padding:14px; background:var(--cs-bot-bubble); border:1px solid var(--cs-border); border-radius:12px; }',
            '.cs-csat-q { font-size:13px; font-weight:600; color:var(--cs-text); margin-bottom:10px; }',
            '.cs-csat-buttons { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }',
            '.cs-csat-btn { flex:1; min-width:80px; padding:10px 6px; border:1.5px solid var(--cs-border); background:#fff; border-radius:10px; cursor:pointer; font-family:inherit; font-size:22px; transition:all 0.15s; display:flex; flex-direction:column; align-items:center; gap:3px; }',
            '.cs-csat-btn:hover { border-color:var(--cs-primary); transform:translateY(-1px); }',
            '.cs-csat-btn.selected { border-color:var(--cs-primary); background:var(--cs-primary); color:#fff; }',
            '.cs-csat-btn-label { font-size:11px; font-weight:600; }',
            '.cs-csat-comment { margin-top:10px; width:100%; border:1px solid var(--cs-border); border-radius:8px; padding:8px; font-family:inherit; font-size:13px; resize:none; outline:none; background:#fff; color:var(--cs-text); }',
            '.cs-csat-comment:focus { border-color:var(--cs-primary); }',
            '.cs-csat-submit { margin-top:8px; width:100%; padding:8px; border:none; border-radius:8px; background:var(--cs-primary); color:#fff; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; }',
            '.cs-csat-submit:hover { opacity:0.9; }',
            '.cs-csat-submit:disabled { opacity:0.4; cursor:not-allowed; }',
            '.cs-csat-thanks { text-align:center; font-size:13px; color:var(--cs-text-light); padding:8px 0; }',
            '.cs-csat-dismiss { background:none; border:none; color:var(--cs-text-light); font-size:11px; cursor:pointer; text-decoration:underline; margin-top:8px; display:block; margin-left:auto; font-family:inherit; }',
            '.cs-csat-dismiss:hover { color:var(--cs-text); }',

            /* ── Human handoff ── */
            '.cs-handoff-offer .cs-msg-body { background:var(--cs-bot-bubble); border:1px solid var(--cs-border); border-radius:12px; padding:12px; }',
            '.cs-handoff-btn { display:inline-block; margin-right:6px; padding:8px 14px; border:none; border-radius:8px; background:var(--cs-primary); color:#fff; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; transition:opacity 0.15s; }',
            '.cs-handoff-btn:hover { opacity:0.9; }',
            '.cs-handoff-skip { display:inline-block; padding:8px 14px; border:1.5px solid var(--cs-border); border-radius:8px; background:#fff; color:var(--cs-text-light); font-family:inherit; font-size:13px; font-weight:500; cursor:pointer; transition:all 0.15s; }',
            '.cs-handoff-skip:hover { border-color:var(--cs-text-light); color:var(--cs-text); }',

            /* ── v4.37.92+: Source citations ── */
            /* v4.37.94+: Bubble meta row — flex container that holds
             * pairings of (badge|sources) or (rating|sources). Sources
             * always floats right via space-between. The row itself
             * owns the top margin; child elements should not stack
             * margins. The solo variant is for sources-without-partner. */
            '.cs-bubble-meta { display: flex; align-items: center; justify-content: space-between; margin-top: 6px; gap: 8px; }',
            '.cs-bubble-meta.cs-bubble-meta-solo { justify-content: flex-end; }',
            '.cs-bubble-meta > .cs-ai-badge { margin: 0; }',
            '.cs-bubble-meta > .cs-rating { margin: 0; }',
            '.cs-bubble-meta > .cs-sources-wrap { margin: 0; margin-left: auto; flex-shrink: 0; }',
            /* v4.37.96+: When .cs-rating sits inside .cs-bubble-meta, switch
             * its inner layout from "flex row with label width:100%" to a
             * proper flex column. The label-width:100% trick was poisoning
             * the rating's intrinsic-width calculation — the row was
             * wrapping sources to a new line because the rating thought
             * it needed more horizontal space than it actually did. In
             * column mode the label naturally sits above the buttons,
             * the rating claims only content-width, and sources fits
             * comfortably to its right. */
            '.cs-bubble-meta > .cs-rating { flex-direction: column; align-items: flex-start; flex-wrap: nowrap; }',
            '.cs-bubble-meta > .cs-rating .cs-rating-label { width: auto; }',
            '.cs-sources-wrap { display: block; margin-top: 6px; }',
            '.cs-sources-link { background: none; border: none; padding: 2px 6px; font-size: 12px; color: #6b7280; cursor: pointer; border-radius: 4px; transition: background 0.15s, color 0.15s; display: inline-flex; align-items: center; font-family: inherit; }',
            '.cs-sources-link:hover, .cs-sources-link:focus { background: #f3f4f6; color: var(--cs-primary); outline: none; }',
            '.cs-sources-link svg { vertical-align: -2px; margin-left: 2px; }',
            /* Panel: real slide-out-from-under animation. The panel starts
             * tucked behind the chat container (same horizontal position
             * as the container, but with z-index BELOW it so the
             * container visually covers it). Sliding to the final
             * position transforms the panel laterally; the part that
             * moves out from behind the container becomes visible. The
             * container has no explicit z-index but is later in DOM
             * order, naturally rendering above the panel. We force
             * panel z-index to a low value to make the layering explicit. */
            '.cs-sources-panel { position: absolute; z-index: 1; background: #fff; border: 1px solid var(--cs-border); border-radius: var(--cs-radius); box-shadow: var(--cs-shadow); overflow: hidden; display: none; flex-direction: column; width: 370px; max-height: 600px; height: auto; transition: transform 0.32s cubic-bezier(0.22, 0.61, 0.36, 1); }',
            /* Container needs a higher z-index than the panel so it
             * visually covers the panel during the slide. */
            '.cs-container { z-index: 2; }',
            /* Bottom-right / top-right widgets: panel slides LEFT from
             * behind the chat container. Final position is right:382px
             * (12px gap to the left of the 370px container). Closed
             * state: translateX(382px) puts it fully behind container
             * at right:0. */
            '.cs-widget.position-bottom-right .cs-sources-panel, .cs-widget.position-top-right .cs-sources-panel { right: 382px; transform: translateX(382px); }',
            '.cs-widget.position-bottom-right .cs-sources-panel.is-open, .cs-widget.position-top-right .cs-sources-panel.is-open { transform: translateX(0); }',
            /* Bottom-left / top-left: mirror image — panel slides RIGHT
             * from behind container. Final left:382px, closed state
             * translateX(-382px) puts it at left:0 (covered by container). */
            '.cs-widget.position-bottom-left .cs-sources-panel, .cs-widget.position-top-left .cs-sources-panel { left: 382px; right: auto; transform: translateX(-382px); }',
            '.cs-widget.position-bottom-left .cs-sources-panel.is-open, .cs-widget.position-top-left .cs-sources-panel.is-open { transform: translateX(0); }',
            '.cs-widget.position-bottom-right .cs-sources-panel, .cs-widget.position-bottom-left .cs-sources-panel { bottom: 16px; }',
            '.cs-widget.position-top-right .cs-sources-panel, .cs-widget.position-top-left .cs-sources-panel { top: 16px; }',
            '.cs-sources-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px 8px; border-bottom: 1px solid #f3f4f6; background: #fff; flex-shrink: 0; }',
            '.cs-sources-panel-header h4 { margin: 0; font-size: 14px; font-weight: 600; color: #1f2937; }',
            '.cs-sources-close { background: none; border: none; padding: 4px; cursor: pointer; color: #6b7280; border-radius: 4px; line-height: 0; }',
            '.cs-sources-close:hover, .cs-sources-close:focus { background: #f3f4f6; color: #1f2937; outline: none; }',
            '.cs-sources-list { margin: 0; padding: 6px 0 12px; list-style: none; flex: 1; overflow-y: auto; min-height: 0; }',
            '.cs-source-item { list-style: none; margin: 0; }',
            '.cs-source-link { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; color: #1f2937; text-decoration: none; transition: background 0.15s; }',
            '.cs-source-link:hover, .cs-source-link:focus { background: #f9fafb; outline: none; }',
            '.cs-source-icon { font-size: 18px; flex-shrink: 0; line-height: 1; padding-top: 1px; }',
            '.cs-source-text { flex: 1; min-width: 0; }',
            '.cs-source-title { display: block; font-size: 13px; font-weight: 500; color: #1f2937; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
            '.cs-source-meta { display: block; font-size: 11px; color: #6b7280; margin-top: 2px; }',
            /* v4.37.97+: snippet shown under each source title — gives
             * student the "why was this cited" context. White-space
             * normal so it wraps to ~2 visual lines naturally. */
            '.cs-source-snippet { display: block; font-size: 12px; color: #4b5563; line-height: 1.45; margin-top: 4px; word-wrap: break-word; }',
            /* Highlight query terms within the snippet. <mark> by default
             * has yellow bg in most browsers; styled here for consistency. */
            '.cs-source-snippet mark { background: #fef3c7; color: inherit; padding: 0 2px; border-radius: 2px; }',
            '.cs-source-arrow { flex-shrink: 0; color: #9ca3af; }',
            /* Mobile fallback: slide-up from below over the entire widget.
             * Panel becomes fixed-position at viewport edges. */
            '@media (max-width: 600px) {',
            '  .cs-sources-panel, .cs-widget.position-bottom-right .cs-sources-panel, .cs-widget.position-bottom-left .cs-sources-panel, .cs-widget.position-top-right .cs-sources-panel, .cs-widget.position-top-left .cs-sources-panel { position: fixed; left: 12px; right: 12px; bottom: 12px; top: auto; width: auto; max-height: 60vh; z-index: 2147483647; transform: translateY(110%); border-radius: 16px; }',
            '  .cs-sources-panel.is-open, .cs-widget.position-bottom-right .cs-sources-panel.is-open, .cs-widget.position-bottom-left .cs-sources-panel.is-open, .cs-widget.position-top-right .cs-sources-panel.is-open, .cs-widget.position-top-left .cs-sources-panel.is-open { transform: translateY(0); }',
            '}',
        ].join('\n');
    }

    // ── Build widget HTML ─────────────────────────────────────────────────────
    function buildHTML(cfg) {
        var pos = cfg.position || 'bottom-right';

        var avatar = cfg.mascotUrl
            ? '<img src="' + cfg.mascotUrl + '" alt="" class="cs-toggle-avatar">'
            : '<svg class="cs-chat-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>';

        var closeIcon = '<svg class="cs-close-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';

        var headerAvatar = cfg.mascotUrl
            ? '<img src="' + cfg.mascotUrl + '" alt="" class="cs-header-avatar">'
            : '';

        var welcomeAvatar = cfg.mascotUrl
            ? '<img src="' + cfg.mascotUrl + '" alt="" class="cs-avatar">'
            : '<div class="cs-avatar-placeholder"><svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg></div>';

        return [
            '<div class="cs-widget position-' + pos + '">',
            '  <button type="button" class="cs-toggle" aria-expanded="false" aria-label="Open help chat">',
            '    ' + avatar + closeIcon,
            '  </button>',
            '  <div class="cs-container" role="dialog" aria-modal="false" aria-hidden="true">',
            '    <div class="cs-header">',
            '      ' + headerAvatar,
            '      <h3 class="cs-header-title">' + esc(cfg.botName) + '</h3>',
            '      <button type="button" class="cs-close" aria-label="Close chat">',
            '        <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
            '      </button>',
            '    </div>',
            '    <div class="cs-messages" role="log" aria-live="polite" tabindex="0">',
            '      <div class="cs-message bot">',
            '        ' + welcomeAvatar,
            '        <div class="cs-msg-body">',
            '          <span class="cs-msg-label">' + esc(cfg.botLabel) + '</span>',
            '          <div class="cs-bubble">' + esc(cfg.welcomeMessage) + '</div>',
            '        </div>',
            '      </div>',
            '    </div>',
            '    <div class="cs-input-area">',
            '      <span class="sr-only">Type your question</span>',
            '      <input type="text" class="cs-input" placeholder="' + esc(cfg.placeholder) + '" autocomplete="off" maxlength="500">',
            '      <button type="button" class="cs-submit" aria-label="Send">',
            '        <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
            '      </button>',
            '    </div>',
            '  </div>',
            '</div>',
        ].join('\n');
    }

    // ── Wire up interactions ──────────────────────────────────────────────────
    function bindEvents(cfg, root) {
        var widget    = root.querySelector('.cs-widget');
        var toggle    = root.querySelector('.cs-toggle');
        var closeBtn  = root.querySelector('.cs-close');
        var container = root.querySelector('.cs-container');
        var messages  = root.querySelector('.cs-messages');
        var input     = root.querySelector('.cs-input');
        var submit    = root.querySelector('.cs-submit');

        // Conversation history for this session — sent with each request
        // so AI can resolve follow-up questions like "what about part-time?"
        var conversationHistory = [];

        // Conversation ID for analytics correlation
        var conversationId = 'c_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);

        // ── Lead capture (pre-chat gate) ───────────────────────────────────
        // Show a form before allowing chat IF:
        //   - Lead capture is enabled in settings
        //   - Visitor hasn't submitted within the cooldown window (per browser)
        // After submit (or skip on soft gate), the form is replaced with the
        // configured welcome message and chat input is unlocked.
        var LEAD_STORAGE_KEY = 'cleversay_lead_last_submitted';
        function leadRecentlySubmitted() {
            try {
                var lc = cfg.leadCapture || {};
                var cooldownDays = parseInt(lc.cooldownDays, 10);
                if (isNaN(cooldownDays) || cooldownDays <= 0) return false; // 0 = always show
                var ts = parseInt(localStorage.getItem(LEAD_STORAGE_KEY) || '0', 10);
                if (!ts) return false;
                var ageMs = Date.now() - ts;
                return ageMs < (cooldownDays * 86400 * 1000);
            } catch (_) { return false; }
        }

        function maybeShowLeadGate() {
            var lc = cfg.leadCapture || {};
            if (!lc.enabled) return false;
            if (leadRecentlySubmitted()) return false;

            // Replace welcome bubble with the form
            var welcomeBubble = root.querySelector('.cs-bubble');
            if (!welcomeBubble) return false;

            var fields = lc.fields || {};
            var idOpts = (lc.identityOptions || []).filter(function (s) { return s && s.trim(); });

            function fieldRow(name, label, type, isRequired, isShown) {
                if (!isShown) return '';
                type = type || 'text';
                var requiredAttr = isRequired ? ' required' : '';
                var requiredMark = isRequired ? ' <span style="color:#d63638;">*</span>' : '';
                return [
                    '<label class="cs-lead-row">',
                    '  <span class="cs-lead-label">' + esc(label) + requiredMark + '</span>',
                    '  <input type="' + type + '" name="' + name + '" class="cs-lead-input"' + requiredAttr + ' autocomplete="' + name + '">',
                    '</label>',
                ].join('');
            }

            var idShown    = !!(fields.identity && fields.identity.enabled);
            var idRequired = !!(fields.identity && fields.identity.required);
            var idHtml = '';
            if (idShown && idOpts.length > 0) {
                var idLabel = lc.identityLabel || 'I am a…';
                var idReqMark = idRequired ? ' <span style="color:#d63638;">*</span>' : '';
                var optsHtml = '<option value="" disabled selected>Select…</option>';
                idOpts.forEach(function (opt) {
                    optsHtml += '<option value="' + esc(opt) + '">' + esc(opt) + '</option>';
                });
                idHtml = [
                    '<label class="cs-lead-row">',
                    '  <span class="cs-lead-label">' + esc(idLabel) + idReqMark + '</span>',
                    '  <select name="identity" class="cs-lead-input"' + (idRequired ? ' required' : '') + '>',
                       optsHtml,
                    '  </select>',
                    '</label>',
                ].join('');
            }

            var formHtml = [
                '<div class="cs-lead-welcome">' + esc(lc.welcomeMessage || '') + '</div>',
                '<form class="cs-lead-form" novalidate>',
                  fieldRow('first_name', 'First name', 'text',  !!(fields.first_name && fields.first_name.required), !!(fields.first_name && fields.first_name.enabled)),
                  fieldRow('last_name',  'Last name',  'text',  !!(fields.last_name  && fields.last_name.required),  !!(fields.last_name  && fields.last_name.enabled)),
                  fieldRow('email',      'Email',      'email', !!(fields.email      && fields.email.required),      !!(fields.email      && fields.email.enabled)),
                  idHtml,
                  fieldRow('phone',      'Phone',      'tel',   !!(fields.phone      && fields.phone.required),      !!(fields.phone      && fields.phone.enabled)),
                  fieldRow('date_of_birth', 'Date of birth (optional)', 'date', !!(fields.date_of_birth && fields.date_of_birth.required), !!(fields.date_of_birth && fields.date_of_birth.enabled)),
                '  <button type="submit" class="cs-lead-submit">' + esc(lc.submitLabel || 'Continue') + '</button>',
                  (!lc.hardGate ? '  <a href="#" class="cs-lead-skip">' + esc(lc.skipLabel || 'Skip and start chatting') + '</a>' : ''),
                '  <div class="cs-lead-error" style="display:none;color:#d63638;font-size:12px;margin-top:8px;"></div>',
                (lc.consentText ? '  <div class="cs-lead-consent" style="font-size:11px;color:#646970;margin-top:10px;line-height:1.5;">' + esc(lc.consentText) + '</div>' : ''),
                '</form>',
            ].join('');

            welcomeBubble.outerHTML = '<div class="cs-bubble cs-lead-bubble" style="padding:14px 16px;">' + formHtml + '</div>';

            // Inject lead-capture styles into the shadow root
            var leadStyles = document.createElement('style');
            leadStyles.textContent = [
                '.cs-lead-welcome { font-size:13px;line-height:1.5;margin-bottom:14px; }',
                '.cs-lead-form { display:flex;flex-direction:column;gap:10px; }',
                '.cs-lead-row { display:flex;flex-direction:column;gap:4px; }',
                '.cs-lead-label { font-size:11px;font-weight:500;color:#3c434a; }',
                '.cs-lead-input { padding:8px 10px;border:1px solid #c3c4c7;border-radius:4px;font-size:13px;font-family:inherit; }',
                '.cs-lead-input:focus { outline:none;border-color:var(--cs-primary);box-shadow:0 0 0 2px color-mix(in srgb, var(--cs-primary) 25%, transparent); }',
                '.cs-lead-submit { background:var(--cs-primary);color:#fff;border:none;padding:10px 14px;border-radius:4px;font-size:13px;font-weight:500;cursor:pointer;margin-top:6px; }',
                '.cs-lead-submit:disabled { opacity:0.6;cursor:not-allowed; }',
                '.cs-lead-skip { font-size:12px;color:#646970;text-decoration:underline;text-align:center;margin-top:4px;display:block; }',
            ].join('\n');
            root.appendChild(leadStyles);

            // Lock chat input until form is dealt with
            input.disabled  = true;
            submit.disabled = true;
            input.placeholder = ''; // visually quiet

            var formEl  = root.querySelector('.cs-lead-form');
            var errorEl = root.querySelector('.cs-lead-error');
            var skipEl  = root.querySelector('.cs-lead-skip');

            function unlockChatAndSwap(welcomeText) {
                var bubble = root.querySelector('.cs-lead-bubble');
                if (bubble) {
                    bubble.classList.remove('cs-lead-bubble');
                    bubble.style.padding = '';
                    bubble.innerHTML = esc(welcomeText || cfg.welcomeMessage || '');
                }
                input.disabled    = false;
                submit.disabled   = false;
                input.placeholder = cfg.placeholder || 'Type your question';
                input.focus();
            }

            if (skipEl) {
                skipEl.addEventListener('click', function (e) {
                    e.preventDefault();
                    unlockChatAndSwap(cfg.welcomeMessage || 'Hi! How can I help?');
                });
            }

            formEl.addEventListener('submit', function (e) {
                e.preventDefault();
                errorEl.style.display = 'none';

                var data = {};
                ['first_name', 'last_name', 'email', 'identity', 'phone', 'date_of_birth'].forEach(function (k) {
                    var f = formEl.querySelector('[name="' + k + '"]');
                    if (f) data[k] = (f.value || '').trim();
                });

                var btn = formEl.querySelector('.cs-lead-submit');
                btn.disabled = true;
                btn.textContent = 'Sending…';

                var payload = Object.assign({
                    action:          'cleversay_submit_lead',
                    nonce:           cfg.nonce,
                    embed_token:     EMBED_TOKEN,
                    conversation_id: conversationId,
                }, data);

                post(cfg.ajaxUrl, payload, function (resp) {
                    btn.disabled = false;
                    btn.textContent = (cfg.leadCapture && cfg.leadCapture.submitLabel) || 'Continue';
                    if (resp && resp.success) {
                        try { localStorage.setItem(LEAD_STORAGE_KEY, String(Date.now())); } catch (_) {}
                        unlockChatAndSwap(cfg.welcomeMessage || 'Thanks! How can I help you today?');
                    } else {
                        var msg = (resp && resp.data && resp.data.message)
                                  || 'Could not submit. Please check your entries and try again.';
                        errorEl.textContent = msg;
                        errorEl.style.display = 'block';
                    }
                });
            });

            return true;
        }

        // Show the lead gate before any other interaction wiring depends on it
        maybeShowLeadGate();

        // ── Conversation CSAT ──────────────────────────────────────────────
        // Best-practice behavior: ask once, at the natural end of conversation.
        // "Natural end" = 90 seconds of idle after the last bot answer, OR the
        // user closes the widget. We never block the close — the prompt slides
        // into the chat; the user can close right over it if they want.
        // Also: we suppress repeat asks for 30 days per visitor (localStorage).

        var csatPrompted   = false;  // this session only — idle or close has triggered
        var csatSubmitted  = false;  // they submitted or explicitly dismissed
        var csatIdleTimer  = null;
        var CSAT_IDLE_MS   = 90 * 1000;  // 90s of quiet after last bot msg
        var CSAT_MIN_MSGS  = 2;          // user must have asked ≥2 questions
        var CSAT_COOLDOWN_DAYS = 30;
        var CSAT_STORAGE_KEY = 'cleversay_csat_last_rated';

        // ── Human handoff state ──────────────────────────────────────────
        var failureStreak  = 0;        // consecutive no-match responses
        var handoffOffered = false;    // avoid re-offering every turn

        // Keywords/phrases that signal the user wants a real person
        var HANDOFF_PATTERNS = [
            /\b(real\s+)?(human|person|agent|representative|rep)\b/i,
            /\btalk\s+(to|with)\s+(a|someone|real)/i,
            /\bspeak\s+(to|with)\s+(a|someone|real)/i,
            /\b(live|real)\s+(chat|support|help|agent)\b/i,
            /\bconnect\s+me\s+(to|with)/i,
            /\b(customer\s+)?support\s+(team|person|agent)\b/i,
        ];
        function isHandoffRequest(text) {
            if (!text || text.length < 3) return false;
            for (var i = 0; i < HANDOFF_PATTERNS.length; i++) {
                if (HANDOFF_PATTERNS[i].test(text)) return true;
            }
            return false;
        }

        // Build a plain-text transcript from conversationHistory for the form
        function buildTranscript() {
            if (!conversationHistory || !conversationHistory.length) return '';
            var lines = conversationHistory.map(function (m) {
                var who = m.type === 'user' ? 'Visitor' : 'Bot';
                return who + ': ' + (m.content || '').replace(/\s+/g, ' ').trim();
            });
            return lines.join('\n');
        }

        // Inline offer to connect with a human. Shows a friendly message and
        // a button; clicking shows the inquiry form with transcript pre-filled.
        function offerHandoff(lastQuestion, handoffType) {
            if (handoffOffered) return;
            handoffOffered = true;

            var messageText = (handoffType === 'keyword_request')
                ? "Sure — I'll connect you with someone from our team. Please share your contact info below and they'll follow up:"
                : "It seems I'm not finding the right answer. Would you like to send this to someone on our team?";

            var box = document.createElement('div');
            box.className = 'cs-msg cs-msg-bot cs-handoff-offer';
            box.innerHTML =
                '<div class="cs-msg-body">' +
                    '<div style="margin-bottom:10px;">' + esc(messageText) + '</div>' +
                    '<button type="button" class="cs-handoff-btn">Contact the team</button>' +
                    '<button type="button" class="cs-handoff-skip">Keep chatting</button>' +
                '</div>';
            messages.appendChild(box);
            messages.scrollTop = messages.scrollHeight;

            box.querySelector('.cs-handoff-btn').addEventListener('click', function () {
                // Replace buttons with the pre-filled form
                var formWrap = box.querySelector('.cs-msg-body');
                formWrap.innerHTML = '<div style="margin-bottom:10px;font-size:13px;color:var(--cs-text);">Your recent chat will be included so the team has context.</div>';
                showHandoffForm(formWrap, lastQuestion, handoffType);
            });
            box.querySelector('.cs-handoff-skip').addEventListener('click', function () {
                box.remove();
                // Allow re-offer later if they fail again
                handoffOffered = false;
            });
        }

        function showHandoffForm(container, lastQuestion, handoffType) {
            var form = document.createElement('div');
            form.className = 'cs-inquiry-form';
            var emailLabel    = cfg.requireEmail
                ? (cfg.strings.inquiryEmail || 'Your email').replace(' (optional)', '') + ' *'
                : (cfg.strings.inquiryEmail || 'Your email (optional)');
            var emailRequired = cfg.requireEmail ? ' required' : '';
            form.innerHTML =
                '<input type="text" class="cs-inq-name" placeholder="' + esc(cfg.strings.inquiryName || 'Your name (optional)') + '" maxlength="100">' +
                '<input type="email" class="cs-inq-email" placeholder="' + esc(emailLabel) + '" maxlength="200"' + emailRequired + '>' +
                '<textarea class="cs-inq-msg" rows="3" placeholder="Anything else you\'d like them to know? (optional)" maxlength="1000"></textarea>' +
                '<button type="button" class="cs-inquiry-submit">Send to the team</button>';
            container.appendChild(form);

            form.querySelector('.cs-inquiry-submit').addEventListener('click', function () {
                var name  = form.querySelector('.cs-inq-name').value.trim();
                var email = form.querySelector('.cs-inq-email').value.trim();
                var extra = form.querySelector('.cs-inq-msg').value.trim();

                if (cfg.requireEmail && !email) {
                    var emailInput = form.querySelector('.cs-inq-email');
                    emailInput.style.borderColor = '#d63638';
                    emailInput.focus();
                    return;
                }

                var transcript = buildTranscript();
                this.disabled   = true;
                this.textContent = 'Sending…';

                var payload = {
                    question:     lastQuestion || 'Handoff request',
                    details:      extra,
                    email:        email,
                    name:         name,
                    transcript:   transcript,
                    handoff_type: handoffType || 'user_initiated'
                };

                var xhr = new XMLHttpRequest();
                xhr.open('POST', SITE + '/wp-json/cleversay/v1/inquiry', true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.onload = function () {
                    form.innerHTML = '<div class="cs-inquiry-success">Thanks! We\'ll get back to you soon.</div>';
                };
                xhr.onerror = function () {
                    form.innerHTML = '<div class="cs-inquiry-success">Thanks! We\'ll get back to you soon.</div>';
                };
                xhr.send(JSON.stringify(payload));
            });
        }


        function csatRecentlyRated() {
            try {
                var last = localStorage.getItem(CSAT_STORAGE_KEY);
                if (!last) return false;
                var ageMs = Date.now() - parseInt(last, 10);
                return ageMs < (CSAT_COOLDOWN_DAYS * 24 * 60 * 60 * 1000);
            } catch (e) {
                // Storage blocked (private mode, etc.) — fall back to per-session only
                return false;
            }
        }

        function csatMarkRated() {
            try { localStorage.setItem(CSAT_STORAGE_KEY, String(Date.now())); }
            catch (e) { /* ignore */ }
        }

        function csatEligible() {
            if (csatPrompted || csatSubmitted) return false;
            if (csatRecentlyRated()) return false;
            var userMsgCount = conversationHistory.filter(function (m) {
                return m.type === 'user';
            }).length;
            return userMsgCount >= CSAT_MIN_MSGS;
        }

        // Start/reset the idle timer every time a bot message arrives
        function scheduleCsatIdleCheck() {
            if (csatIdleTimer) clearTimeout(csatIdleTimer);
            csatIdleTimer = setTimeout(function () {
                if (!csatEligible()) return;
                if (!widget.classList.contains('active')) return; // don't pop while closed
                csatPrompted = true;
                showCsatPrompt(false);
            }, CSAT_IDLE_MS);
        }

        function cancelCsatIdleCheck() {
            if (csatIdleTimer) { clearTimeout(csatIdleTimer); csatIdleTimer = null; }
        }

        function openWidget() {
            container.setAttribute('aria-hidden', 'false');
            toggle.setAttribute('aria-expanded', 'true');
            widget.classList.add('active');
            input.focus();
        }

        function closeWidget() {
            if (window.cleversayDebug) {
                console.log('[CleverSay] closeWidget', {
                    eligible: csatEligible(),
                    recentlyRated: csatRecentlyRated(),
                    csatPrompted: csatPrompted,
                    csatSubmitted: csatSubmitted
                });
            }
            cancelCsatIdleCheck();
            // Gentle close-time intercept — ONCE. If they qualify for CSAT and
            // haven't been prompted yet, we show the prompt but keep the widget
            // open so they can rate. A SECOND close click always closes for
            // real — we never trap them.
            if (csatEligible() && !messages.querySelector('.cs-csat')) {
                csatPrompted = true;
                showCsatPrompt(true); // closing=true → uses "Before you go…" headline
                messages.scrollTop = messages.scrollHeight;
                return; // stay open this one time
            }
            container.setAttribute('aria-hidden', 'true');
            toggle.setAttribute('aria-expanded', 'false');
            widget.classList.remove('active');
            // v4.37.92+: paired Sources panel — close when widget closes.
            closeSourcesPanel();
        }

        function showCsatPrompt(closing) {
            if (messages.querySelector('.cs-csat')) return;
            var headline = closing
                ? 'Before you go — was this conversation helpful?'
                : 'Was this conversation helpful?';
            var dismissLabel = closing ? 'No thanks, close' : 'No thanks';
            var box = document.createElement('div');
            box.className = 'cs-csat';
            box.innerHTML = ''
                + '<div class="cs-csat-q">' + headline + '</div>'
                + '<div class="cs-csat-buttons">'
                +   '<button type="button" class="cs-csat-btn" data-rating="helpful">'
                +     '<span>👍</span><span class="cs-csat-btn-label">Helpful</span>'
                +   '</button>'
                +   '<button type="button" class="cs-csat-btn" data-rating="somewhat">'
                +     '<span>🤔</span><span class="cs-csat-btn-label">Somewhat</span>'
                +   '</button>'
                +   '<button type="button" class="cs-csat-btn" data-rating="not_helpful">'
                +     '<span>👎</span><span class="cs-csat-btn-label">Not helpful</span>'
                +   '</button>'
                + '</div>'
                + '<textarea class="cs-csat-comment" rows="2" placeholder="Optional: tell us more…" style="display:none;"></textarea>'
                + '<button type="button" class="cs-csat-submit" style="display:none;">Send feedback</button>'
                + '<button type="button" class="cs-csat-dismiss">' + dismissLabel + '</button>';
            messages.appendChild(box);
            messages.scrollTop = messages.scrollHeight;

            var selectedRating = null;
            var ratingBtns  = box.querySelectorAll('.cs-csat-btn');
            var commentEl   = box.querySelector('.cs-csat-comment');
            var submitBtn   = box.querySelector('.cs-csat-submit');
            var dismissBtn  = box.querySelector('.cs-csat-dismiss');

            ratingBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    selectedRating = btn.dataset.rating;
                    ratingBtns.forEach(function (b) { b.classList.remove('selected'); });
                    btn.classList.add('selected');
                    commentEl.style.display = 'block';
                    submitBtn.style.display = 'block';
                    if (selectedRating !== 'helpful') commentEl.focus();
                });
            });

            submitBtn.addEventListener('click', function () {
                if (!selectedRating) return;
                submitBtn.disabled = true;
                submitCsat(selectedRating, commentEl.value.trim(), box, closing);
            });

            dismissBtn.addEventListener('click', function () {
                csatSubmitted = true;
                csatMarkRated();  // dismissal also starts the cooldown
                box.remove();
                // If they got this prompt by clicking close, honor their intent
                // and close the widget now that they've dismissed.
                if (closing) forceCloseWidget();
            });
        }

        function submitCsat(rating, comment, box, closing) {
            csatSubmitted = true;
            csatMarkRated();
            var data = new FormData();
            data.append('action', 'cleversay_rate_conversation');
            data.append('rating', rating);
            data.append('comment', comment);
            data.append('history', JSON.stringify(conversationHistory));
            data.append('nonce', cfg.nonce || '');
            if (cfg.embedToken) data.append('embed_token', cfg.embedToken);
            fetch(cfg.ajaxUrl, {
                method:      'POST',
                credentials: 'include',
                body:        data
            }).then(function () {
                box.innerHTML = '<div class="cs-csat-thanks">Thanks for your feedback! 🙏</div>';
                // If they reached this prompt via the close button, close the
                // widget after they see the thanks message. Otherwise leave the
                // thanks briefly then remove so they can keep chatting.
                if (closing) {
                    setTimeout(forceCloseWidget, 1500);
                } else {
                    setTimeout(function () { box.remove(); }, 2500);
                }
            }).catch(function () {
                box.remove();
                if (closing) forceCloseWidget();
            });
        }

        function forceCloseWidget() {
            container.setAttribute('aria-hidden', 'true');
            toggle.setAttribute('aria-expanded', 'false');
            widget.classList.remove('active');
        }

        toggle.addEventListener('click', function () {
            removeteaser();
            widget.classList.contains('active') ? closeWidget() : openWidget();
        });
        closeBtn.addEventListener('click', closeWidget);

        // v4.37.92+: Escape key closes Sources panel when open.
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSourcesPanel();
        });

        // ── Teaser bubble ──────────────────────────────────────────────────
        var _teaserTimer = null;
        var _teaserEl    = null;

        function removeteaser() {
            if (_teaserEl) { _teaserEl.remove(); _teaserEl = null; }
            if (_teaserTimer) { clearTimeout(_teaserTimer); _teaserTimer = null; }
        }

        if (cfg.teaserEnabled) {
            _teaserTimer = setTimeout(function () {
                if (widget.classList.contains('active')) return; // already open
                _teaserEl = document.createElement('div');
                _teaserEl.className = 'cs-teaser';
                _teaserEl.textContent = cfg.teaserMessage || cfg.welcomeMessage || 'How can I help?';

                var closeBtn2 = document.createElement('button');
                closeBtn2.className = 'cs-teaser-close';
                closeBtn2.textContent = '✕';
                closeBtn2.setAttribute('aria-label', 'Dismiss');
                closeBtn2.addEventListener('click', function (e) { e.stopPropagation(); removeteaser(); });
                _teaserEl.appendChild(closeBtn2);

                _teaserEl.addEventListener('click', function () { removeteaser(); openWidget(); });
                widget.appendChild(_teaserEl);
            }, (cfg.teaserDelay || 3) * 1000);
        }

        function appendMessage(role, html) {
            var isBot = role === 'bot';
            var avatarHTML = '';
            if (isBot) {
                avatarHTML = cfg.mascotUrl
                    ? '<img src="' + cfg.mascotUrl + '" alt="" class="cs-avatar">'
                    : '<div class="cs-avatar-placeholder"><svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg></div>';
            }
            var labelHTML = isBot ? '<span class="cs-msg-label">' + esc(cfg.botLabel) + '</span>' : '';

            var div = document.createElement('div');
            div.className = 'cs-message ' + role;
            div.innerHTML = avatarHTML
                + '<div class="cs-msg-body">'
                + labelHTML
                + '<div class="cs-bubble">' + html + '</div>'
                + '</div>';
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
            return div;
        }

        function showTyping() {
            return appendMessage('bot',
                '<span class="cs-typing"><span></span><span></span><span></span></span>'
            );
        }

        // v4.37.92+: Sources citation panel for embed widget.
        // Mirrors the on-site widget behavior — slide-out panel attached
        // to the .cs-widget root, sliding from beside the chat container.
        function sourceIcon(type) {
            switch (type) {
                case 'pdf':  return '\uD83D\uDCC4'; // 📄
                case 'docx': return '\uD83D\uDCDD'; // 📝
                case 'url':  return '\uD83D\uDD17'; // 🔗
                case 'text': return '\uD83D\uDCD1'; // 📑
                default:     return '\u2022';        // •
            }
        }
        function buildSourcesPanel() {
            var panel = widget.querySelector(':scope > .cs-sources-panel');
            if (panel) return panel;
            panel = document.createElement('div');
            panel.className = 'cs-sources-panel';
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-label', 'Sources');
            panel.innerHTML = '<div class="cs-sources-panel-header">'
                + '<h4>Sources</h4>'
                + '<button type="button" class="cs-sources-close" aria-label="Close">'
                + '<svg viewBox="0 0 24 24" width="18" height="18"><path d="M18.3 5.71L12 12l6.3 6.29-1.42 1.42L10.59 13.41 4.29 19.71 2.88 18.29 9.17 12 2.88 5.71 4.29 4.29l6.3 6.3 6.3-6.3z" fill="currentColor"/></svg>'
                + '</button>'
                + '</div>'
                + '<ul class="cs-sources-list" role="list"></ul>';
            widget.appendChild(panel);
            panel.querySelector('.cs-sources-close').addEventListener('click', closeSourcesPanel);
            return panel;
        }
        function toggleSourcesPanel(sources, question) {
            var panel = widget.querySelector(':scope > .cs-sources-panel');
            if (panel && panel.classList.contains('is-open')) {
                closeSourcesPanel();
                return;
            }
            showSourcesPanel(sources, question);
        }
        // v4.37.97+: Highlight query terms in the snippet. Tokenizes
        // the user's question into words >= 4 chars (skips short
        // function words like "what", "the", "do" that would
        // highlight everywhere and look noisy). Wraps matches in
        // <mark>. Stop early — if no meaningful query terms, return
        // snippet as-is (no highlighting attempted).
        function highlightSnippet(snippet, question) {
            if (!snippet) return '';
            var safe = esc(snippet);
            if (!question) return safe;
            // Extract distinct query terms >= 4 chars; lowercase for matching
            var terms = (question.toLowerCase().match(/[a-z0-9]+/g) || [])
                .filter(function(w) { return w.length >= 4; })
                .filter(function(w, i, arr) { return arr.indexOf(w) === i; });
            if (!terms.length) return safe;
            // Build a regex that matches any of the terms (case-insensitive,
            // word-boundary-ish using \b). Escape regex specials.
            var pattern = terms.map(function(t) {
                return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }).join('|');
            var rx = new RegExp('\\b(' + pattern + ')\\b', 'gi');
            return safe.replace(rx, '<mark>$1</mark>');
        }
        function showSourcesPanel(sources, question) {
            var panel = buildSourcesPanel();
            var list  = panel.querySelector('.cs-sources-list');
            list.innerHTML = '';
            sources.forEach(function(src) {
                var icon    = sourceIcon(src.type);
                var title   = esc(src.title || '(untitled source)');
                var snippet = src.snippet || '';
                var url     = src.url || '';
                var li = document.createElement('li');
                li.className = 'cs-source-item';

                // v4.37.97+: New format — title on top, snippet below
                // with query-term highlighting. URL is no longer shown
                // (link is the whole row; click navigates). Snippet is
                // optional; older citation rows pre-snippet-column may
                // not have one.
                var snippetHtml = snippet
                    ? '<span class="cs-source-snippet">' + highlightSnippet(snippet, question) + '</span>'
                    : '';

                if (url) {
                    li.innerHTML = '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="cs-source-link">'
                        + '<span class="cs-source-icon">' + icon + '</span>'
                        + '<span class="cs-source-text">'
                        + '<span class="cs-source-title">' + title + '</span>'
                        + snippetHtml
                        + '</span>'
                        + '<svg class="cs-source-arrow" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">'
                        + '<path d="M14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3z" fill="currentColor"/>'
                        + '</svg>'
                        + '</a>';
                } else {
                    li.className += ' cs-source-item-disabled';
                    li.innerHTML = '<span class="cs-source-icon">' + icon + '</span>'
                        + '<span class="cs-source-text">'
                        + '<span class="cs-source-title">' + title + '</span>'
                        + snippetHtml
                        + '</span>';
                }
                list.appendChild(li);
            });
            panel.style.display = 'flex';
            // Force reflow so the transition triggers
            void panel.offsetHeight;
            panel.classList.add('is-open');
        }
        function closeSourcesPanel() {
            var panel = widget.querySelector(':scope > .cs-sources-panel');
            if (!panel) return;
            panel.classList.remove('is-open');
            setTimeout(function() { panel.style.display = 'none'; }, 300);
        }

        var svgUp   = '<svg width="13" height="13" viewBox="0 0 24 24"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>';
        var svgDown = '<svg width="13" height="13" viewBox="0 0 24 24"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/></svg>';

        function showStillHelp(parentDiv, question) {
            if (!cfg.enableInquiry) return;
            var body = parentDiv.querySelector ? parentDiv.querySelector('.cs-msg-body') || parentDiv : parentDiv;
            var wrap = document.createElement('div');
            wrap.className = 'cs-still-help';

            var link = document.createElement('button');
            link.type = 'button';
            link.className = 'cs-still-help-link';
            link.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'
                + ' <span>' + esc(cfg.strings.stillHelp || 'Still need help? Send us a message.') + '</span>';
            wrap.appendChild(link);

            link.addEventListener('click', function () {
                link.style.display = 'none';
                showInquiryForm(wrap, question);
            });

            body.appendChild(wrap);
        }

        function showInquiryForm(container, question) {
            var form = document.createElement('div');
            form.className = 'cs-inquiry-form';
            var emailLabel    = cfg.requireEmail
                ? (cfg.strings.inquiryEmail || 'Your email').replace(' (optional)', '') + ' *'
                : (cfg.strings.inquiryEmail || 'Your email (optional)');
            var emailRequired = cfg.requireEmail ? ' required' : '';
            var emailStyle    = cfg.requireEmail ? ' style="border-color:var(--cs-primary)"' : '';
            form.innerHTML =
                '<input type="text" class="cs-inq-name" placeholder="' + esc(cfg.strings.inquiryName || 'Your name (optional)') + '" maxlength="100">' +
                '<input type="email" class="cs-inq-email" placeholder="' + esc(emailLabel) + '" maxlength="200"' + emailRequired + emailStyle + '>' +
                '<textarea class="cs-inq-msg" rows="3" placeholder="' + esc(cfg.strings.inquiryPlaceholder || 'Enter your message (optional)') + '" maxlength="1000"></textarea>' +
                '<button type="button" class="cs-inquiry-submit">' + esc(cfg.strings.submitInquiry || 'Send Message') + '</button>';
            container.appendChild(form);

            form.querySelector('.cs-inquiry-submit').addEventListener('click', function () {
                var name  = form.querySelector('.cs-inq-name').value.trim();
                var email = form.querySelector('.cs-inq-email').value.trim();
                var extra = form.querySelector('.cs-inq-msg').value.trim();

                if (cfg.requireEmail && !email) {
                    var emailInput = form.querySelector('.cs-inq-email');
                    emailInput.style.borderColor = '#d63638';
                    emailInput.focus();
                    return;
                }

                var fullQ = question + (extra ? '\n\nAdditional details: ' + extra : '');
                this.disabled = true;
                this.textContent = '...';

                var xhr = new XMLHttpRequest();
                xhr.open('POST', SITE + '/wp-json/cleversay/v1/inquiry', true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.onload = function () {
                    form.innerHTML = '<div class="cs-inquiry-success">' + esc(cfg.strings.inquirySuccess || 'Thanks! We\'ll get back to you soon.') + '</div>';
                };
                xhr.onerror = function () {
                    form.innerHTML = '<div class="cs-inquiry-success">' + esc(cfg.strings.inquirySuccess || 'Thanks! We\'ll get back to you soon.') + '</div>';
                };
                xhr.send(JSON.stringify({ question: fullQ, email: email, name: name }));
            });
        }

        // Intercept user replies to a pending yes/no inquiry prompt
        var _pendingInquiryQuestion = null;

        function askInquiry(question) {
            if (!cfg.enableInquiry) return;
            // Guard: don't show a second inquiry prompt if one is already pending
            if (_pendingInquiryQuestion !== null) return;
            _pendingInquiryQuestion = question;

            // Bot asks yes/no as a new message with quick-reply buttons
            var msgRow = appendMessage('bot', esc(cfg.strings.stillHelp || 'Would you like to send a message to our team?'));
            var body   = msgRow.querySelector('.cs-msg-body') || msgRow;

            var btns = document.createElement('div');
            btns.className = 'cs-yesno';
            btns.innerHTML =
                '<button class="cs-yesno-btn cs-yesno-yes" type="button">' + esc(cfg.strings.inquiryYes || 'Yes, please') + '</button>' +
                '<button class="cs-yesno-btn cs-yesno-no"  type="button">' + esc(cfg.strings.inquiryNo  || 'No, thanks') + '</button>';
            body.appendChild(btns);

            btns.querySelector('.cs-yesno-yes').addEventListener('click', function (e) {
                e.stopPropagation();
                btns.remove();
                _pendingInquiryQuestion = null;
                appendMessage('user', esc(cfg.strings.inquiryYes || 'Yes, please'));
                // Pre-fill the bot bubble with a short intro so the form
                // doesn't appear in an empty speech bubble. Default text is
                // configurable per-site in Settings → Inquiries → Form Intro.
                var introText = (cfg.strings.inquiryIntro || 'Sure — fill out the form below and we\'ll get back to you.');
                var formMsg = appendMessage('bot', esc(introText));
                var formBody = formMsg.querySelector('.cs-msg-body') || formMsg;
                showInquiryForm(formBody, question);
                scrollToBottom();
            });

            btns.querySelector('.cs-yesno-no').addEventListener('click', function (e) {
                e.stopPropagation();
                btns.remove();
                _pendingInquiryQuestion = null;
                appendMessage('user', esc(cfg.strings.inquiryNo || 'No, thanks'));
                appendMessage('bot', esc(cfg.strings.inquiryDeclined || 'No problem! Feel free to ask if you have other questions.'));
                scrollToBottom();
            });

            scrollToBottom();
        }

        function doSearch(question) {
            if (!rateOk()) return;
            var typing = showTyping();

            post(cfg.ajaxUrl, {
                action:      'cleversay_search',
                nonce:       cfg.nonce,
                embed_token: EMBED_TOKEN,
                question:    question,
                history:     JSON.stringify(conversationHistory.slice(-6)),
                context:     'embed',
            }, function (err, r) {
                typing.remove();
                if (err) {
                    console.error('[CleverSay] Search error:', err);
                    appendMessage('bot', esc(cfg.strings.noAnswer || 'Sorry, I could not find an answer.'));
                    return;
                }
                if (!r.success) {
                    console.warn('[CleverSay] Search failed:', JSON.stringify(r));
                    appendMessage('bot', esc(cfg.strings.noAnswer || 'Sorry, I could not find an answer.'));
                    return;
                }

                var d = r.data;
                if (!d.found || !d.answers || !d.answers.length) {
                    var noMsg = d.no_answer_message || cfg.strings.noAnswer || 'Sorry, I could not find an answer.';
                    appendMessage('bot', esc(noMsg));
                    // Record no-answer in history so AI knows context
                    conversationHistory.push({ type: 'user', content: question });
                    conversationHistory.push({ type: 'bot',  content: noMsg });

                    // count_as_failure (server flag): greetings = false, gibberish = true,
                    // real questions = true. Determines whether streak increments.
                    var countsAsFailure = (d.count_as_failure !== false);
                    // show_inquiry tells us whether to show Yes/No buttons —
                    // only true for real-question failures, not gibberish.
                    var canShowInquiry  = (d.show_inquiry === true);

                    if (countsAsFailure) {
                        failureStreak++;
                        if (failureStreak >= 2 && !handoffOffered) {
                            // Auto-escalation — replaces the usual inquiry prompt
                            offerHandoff(question, 'auto_escalation');
                        } else if (canShowInquiry) {
                            askInquiry(question);
                        }
                    }
                    // Greetings: no failure, no inquiry prompt, no handoff
                    scheduleCsatIdleCheck();
                    return;
                }
                var firstAnswer = d.answers[0];

                // AI deflections (off-topic refusals like "Sorry, I can only
                // help with...") should count toward the failure streak even
                // though the server returns found:true. Otherwise the bot
                // never auto-escalates to a human after several refusals.
                if (firstAnswer && firstAnswer.is_deflection) {
                    failureStreak++;
                    if (failureStreak >= 2 && !handoffOffered) {
                        // We'll still render the deflection answer below,
                        // but offer handoff afterwards instead of askInquiry
                        firstAnswer._suppressInquiry = true;
                    }
                } else {
                    // Got a real answer — reset streak
                    failureStreak = 0;
                }

                var rawAnswer   = firstAnswer.answer || firstAnswer.response || '';

                if (!rawAnswer) {
                    appendMessage('bot', esc(cfg.strings.noAnswer || 'Sorry, I could not find an answer.'));
                    return;
                }

                var isHtml    = /<[a-zA-Z][\s\S]*>/.test(rawAnswer);
                var processed = isHtml ? rawAnswer : md(rawAnswer);

                // Linkify bare URLs — replace href-less URLs with anchor tags
                // Split on existing <a...>...</a> to avoid double-processing
                processed = processed.split(/(<a\b[^>]*>[\s\S]*?<\/a>)/i).map(function(part, i) {
                    // Even indexes are outside existing <a> tags — safe to linkify
                    if (i % 2 === 0) {
                        return part.replace(/(https?:\/\/[^\s<>")\]]+)/g, '<a href="$1">$1</a>');
                    }
                    return part; // odd = existing anchor, leave alone
                }).join('');

                var answerDiv = appendMessage('bot', processed);

                // Tag AI-generated answers so CSS can style the follow-up
                // suggestion paragraph (the trailing paragraph the AI adds
                // with an adjacent-topic question). Non-AI answers (KB
                // matches) don't get the class — their paragraphs aren't
                // follow-ups, just answer body.
                if (firstAnswer.ai_assisted) {
                    answerDiv.classList.add('ai-answer');
                }

                // Record this exchange in session history
                conversationHistory.push({ type: 'user', content: question });
                conversationHistory.push({ type: 'bot',  content: rawAnswer });
                // Keep last 10 turns (5 exchanges) to avoid excessive tokens
                if (conversationHistory.length > 10) {
                    conversationHistory = conversationHistory.slice(-10);
                }

                // After 3 bot answers, offer an inline CSAT prompt (once)
                scheduleCsatIdleCheck();

                // Open all links in new tab
                answerDiv.querySelectorAll('a[href]').forEach(function(a) {
                    a.setAttribute('target', '_blank');
                    a.setAttribute('rel', 'noopener noreferrer');
                });

                // v4.37.94+: Metadata layout — badge / rating / sources
                // share rows depending on what's present.
                //
                //   badge ON,  rating ON,  sources ON  → [badge|sources] / [rating]
                //   badge OFF, rating ON,  sources ON  → [rating|sources]
                //   badge OFF, rating OFF, sources ON  → [sources alone, right]
                //   badge ON,  rating OFF, sources ON  → [badge|sources]
                //   badge ON,  rating ON,  sources OFF → [badge] / [rating]
                //   ...etc.
                //
                // Sources always floats right when present. The element that
                // shares its row (badge or rating) sits left, with flex
                // space-between filling the gap. When sources is absent,
                // badge and rating each get their own row as before.
                var hasBadge   = firstAnswer.ai_assisted && cfg.showAiBadge;
                var hasRating  = cfg.showRating && firstAnswer.show_rating && firstAnswer.id;
                var hasSources = Array.isArray(firstAnswer.sources) && firstAnswer.sources.length > 0;

                var msgBody = answerDiv.querySelector('.cs-msg-body');

                // Helper: build the AI badge element
                function buildBadgeEl() {
                    var b = document.createElement('span');
                    b.className = 'cs-ai-badge';
                    b.textContent = cfg.aiLabel || 'AI-assisted answer';
                    return b;
                }
                // Helper: build the rating block
                function buildRatingEl() {
                    var r = document.createElement('div');
                    r.className = 'cs-rating';
                    r.setAttribute('data-id', firstAnswer.id);
                    r.setAttribute('data-target', firstAnswer.rating_target || 'kb');
                    r.setAttribute('data-question', question);
                    r.innerHTML = '<div class="cs-rating-label">' + esc(cfg.strings.helpful) + '</div>'
                        + '<div class="cs-rating-buttons">'
                        + '<button type="button" class="cs-rate" data-val="1">' + svgUp   + ' ' + esc(cfg.strings.yes) + '</button>'
                        + '<button type="button" class="cs-rate" data-val="0">' + svgDown + ' ' + esc(cfg.strings.no)  + '</button>'
                        + '</div>';
                    return r;
                }
                // Helper: build the sources link wrap
                function buildSourcesEl() {
                    var w = document.createElement('div');
                    w.className = 'cs-sources-wrap';
                    var label = (cfg.strings && cfg.strings.sourcesLabel) || 'Sources';
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'cs-sources-link';
                    btn.innerHTML = esc(label) + ' (' + firstAnswer.sources.length + ')'
                        + '<svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path d="M7 10l5 5 5-5z" fill="currentColor"/></svg>';
                    btn._sources = firstAnswer.sources;
                    btn._question = question; // for snippet keyword highlighting
                    btn.addEventListener('click', function() {
                        toggleSourcesPanel(this._sources, this._question);
                    });
                    w.appendChild(btn);
                    return w;
                }

                // Decide which row layout applies and build it.
                if (hasSources) {
                    // Sources is present → it always shares a row. Pick its
                    // partner: badge first if available, otherwise rating.
                    if (hasBadge) {
                        // Row 1: badge (left) + sources (right)
                        var row1 = document.createElement('div');
                        row1.className = 'cs-bubble-meta';
                        row1.appendChild(buildBadgeEl());
                        row1.appendChild(buildSourcesEl());
                        msgBody.appendChild(row1);
                        // Row 2: rating, if present, on its own line
                        if (hasRating) {
                            msgBody.appendChild(buildRatingEl());
                        }
                    } else if (hasRating) {
                        // Row 1: rating (left) + sources (right)
                        // Wrap rating in the meta row so flex layout treats
                        // them as siblings.
                        var row = document.createElement('div');
                        row.className = 'cs-bubble-meta';
                        row.appendChild(buildRatingEl());
                        row.appendChild(buildSourcesEl());
                        msgBody.appendChild(row);
                    } else {
                        // Sources alone, right-aligned
                        var soloRow = document.createElement('div');
                        soloRow.className = 'cs-bubble-meta cs-bubble-meta-solo';
                        soloRow.appendChild(buildSourcesEl());
                        msgBody.appendChild(soloRow);
                    }
                } else {
                    // No sources — each item gets its own row as before.
                    if (hasBadge)  msgBody.appendChild(buildBadgeEl());
                    if (hasRating) msgBody.appendChild(buildRatingEl());
                }

                // Handoff escalation — fires after multiple consecutive
                // deflections, regardless of rating UI. The widget tracks
                // _suppressInquiry on AI deflections to mark the streak.
                if (firstAnswer._suppressInquiry && cfg.enableInquiry) {
                    offerHandoff(question, 'auto_escalation');
                }
            });
        }

        function handleSubmit() {
            var q = input.value.trim();
            if (!q) return;
            input.value = '';

            // v4.37.95+: Close any open Sources panel when a new question
            // is asked. The previous citations belong to the previous
            // answer; keeping the panel open with stale sources beside a
            // new conversation creates confusing context drift.
            closeSourcesPanel();

            // User is active — reset the CSAT idle clock
            cancelCsatIdleCheck();

            // If an inquiry yes/no prompt is pending, intercept affirmative/negative replies
            if (_pendingInquiryQuestion !== null) {
                var lower = q.toLowerCase().replace(/[^a-z]/g, '');
                var isYes = /^(yes|yeah|yep|yup|sure|ok|okay|please|yesplease|y)$/.test(lower);
                var isNo  = /^(no|nope|nah|cancel|n)$/.test(lower);
                if (isYes || isNo) {
                    var savedQ = _pendingInquiryQuestion;
                    _pendingInquiryQuestion = null;
                    // Remove the yes/no buttons so they can't be clicked again
                    var btns = messages.querySelector('.cs-yesno');
                    if (btns) btns.remove();
                    appendMessage('user', esc(q));
                    if (isYes) {
                        // Same intro logic as the button-click path above —
                        // keeps both routes (clicking "Yes, please" vs typing
                        // "yes") behaviorally identical.
                        var introText = (cfg.strings.inquiryIntro || 'Sure — fill out the form below and we\'ll get back to you.');
                        var formMsg  = appendMessage('bot', esc(introText));
                        var formBody = formMsg.querySelector('.cs-msg-body') || formMsg;
                        showInquiryForm(formBody, savedQ);
                    } else {
                        appendMessage('bot', esc(cfg.strings.inquiryDeclined || 'No problem! Feel free to ask if you have other questions.'));
                    }
                    scrollToBottom();
                    return;
                }
                // Not a yes/no — treat as a new question and clear the pending state
                _pendingInquiryQuestion = null;
                var btns2 = messages.querySelector('.cs-yesno');
                if (btns2) btns2.remove();
            }

            appendMessage('user', esc(q));

            // ── Human handoff: keyword detection ──────────────────────────
            // If the user explicitly asks for a real person, skip KB search
            // and offer a handoff form immediately.
            if (isHandoffRequest(q)) {
                conversationHistory.push({ type: 'user', content: q });
                offerHandoff(q, 'keyword_request');
                return;
            }

            doSearch(q);
        }

        submit.addEventListener('click', handleSubmit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSubmit(); }
        });

        // Rating clicks — supports both KB ratings and AI ratings.
        // For AI ratings (data-target='ai_answer'):
        //   👍 → record positive rating, show "Thanks!"
        //   👎 → record negative rating, then immediately open the inquiry form
        //         (replaces the old "Still need help? → Yes" path).
        // For KB ratings (default):
        //   👍 → record, show thanks
        //   👎 → record, trigger AI re-answer to try harder, then offer inquiry
        messages.addEventListener('click', function (e) {
            var btn = e.target.closest('.cs-rate');
            if (!btn) return;
            var ratingDiv = btn.closest('.cs-rating');
            if (!ratingDiv) return;
            if (ratingDiv.getAttribute('data-used')) return; // already consumed

            var id     = ratingDiv.getAttribute('data-id');
            var target = ratingDiv.getAttribute('data-target') || 'kb';
            var val    = btn.getAttribute('data-val');

            // Branch by target — different endpoints for KB vs AI ratings
            var ajaxAction = (target === 'ai_answer') ? 'cleversay_rate_ai_answer' : 'cleversay_rate_answer';
            var idField    = (target === 'ai_answer') ? 'ai_answer_id' : 'id';

            var payload = {
                action:      ajaxAction,
                nonce:       cfg.nonce,
                embed_token: EMBED_TOKEN,
                rating:      val === '1' ? 'helpful' : 'not_helpful',
            };
            payload[idField] = id;
            post(cfg.ajaxUrl, payload, function () {});

            // Mark as consumed up front — prevents any re-trigger or double-handling
            ratingDiv.setAttribute('data-used', '1');

            if (val === '1') {
                // 👍 — both paths just say thanks
                ratingDiv.innerHTML = '<span style="font-size:13px;color:var(--cs-text-light)">' + esc(cfg.strings.thanks || 'Thanks!') + '</span>';
                return;
            }

            // ── 👎 path ────────────────────────────────────────────────────
            ratingDiv.innerHTML = '';

            // For AI ratings, simply open the inquiry form using the question
            // stored on the rating row (or fall back to recent conversation).
            if (target === 'ai_answer') {
                var aiQuestion = ratingDiv.getAttribute('data-question') || '';
                if (!aiQuestion) {
                    // Best-effort fallback to last user question
                    var allMsgs2  = messages.querySelectorAll('.cs-message.user .cs-bubble');
                    aiQuestion    = allMsgs2.length ? allMsgs2[allMsgs2.length - 1].textContent.trim() : '';
                }
                if (cfg.enableInquiry) {
                    askInquiry(aiQuestion);
                } else {
                    appendMessage('bot', 'Sorry that wasn\'t helpful.');
                }
                return;
            }

            // ── KB 👎 path (unchanged): try AI re-answer, then offer inquiry ──
            // Get original question: prefer last user bubble, fall back to conversation history
            var allMsgs   = messages.querySelectorAll('.cs-message.user .cs-bubble');
            var lastUserQ = allMsgs.length ? allMsgs[allMsgs.length - 1].textContent.trim() : '';
            if (!lastUserQ && conversationHistory.length) {
                for (var hi = conversationHistory.length - 1; hi >= 0; hi--) {
                    if (conversationHistory[hi].type === 'user' || conversationHistory[hi].role === 'user') {
                        lastUserQ = (conversationHistory[hi].content || '').trim();
                        break;
                    }
                }
            }

            if (!lastUserQ) {
                appendMessage('bot', 'Sorry that wasn\'t helpful. ' + (cfg.enableInquiry ? 'Please send us a message and we\'ll follow up with you.' : ''));
                if (cfg.enableInquiry) askInquiry('');
                return;
            }

            // Bridge message + typing indicator
            appendMessage('bot', 'Sorry that wasn\'t helpful! Let me try again…');
            var typing2 = showTyping();

            post(cfg.ajaxUrl, {
                action:      'cleversay_search',
                nonce:       cfg.nonce,
                embed_token: EMBED_TOKEN,
                question:    lastUserQ,
                history:     JSON.stringify(conversationHistory.slice(-6)),
                context:     'embed',
                force_ai:    '1',
            }, function (err, r) {
                typing2.remove();
                if (err) {
                    appendMessage('bot', 'I wasn\'t able to find a better answer.');
                    if (cfg.enableInquiry) askInquiry(lastUserQ);
                    return;
                }
                var aiData = (r && r.success && r.data && r.data.answers && r.data.answers[0]) || null;
                var aiText = aiData ? (aiData.answer || '') : '';

                if (aiText) {
                    var isHtml2  = /<[a-zA-Z][\s\S]*>/.test(aiText);
                    var rendered = isHtml2 ? aiText : md(aiText);
                    var aiDiv    = appendMessage('bot', rendered);
                    aiDiv.querySelectorAll('a[href]').forEach(function(a) {
                        a.setAttribute('target', '_blank');
                        a.setAttribute('rel', 'noopener noreferrer');
                    });

                    // Apply the new AI rating UI under this re-answer too,
                    // since the rerun returns a real AI answer.
                    if (cfg.showRating && aiData.show_rating && aiData.id) {
                        var ratingDiv2 = document.createElement('div');
                        ratingDiv2.className = 'cs-rating';
                        ratingDiv2.setAttribute('data-id', aiData.id);
                        ratingDiv2.setAttribute('data-target', aiData.rating_target || 'ai_answer');
                        ratingDiv2.setAttribute('data-question', lastUserQ);
                        ratingDiv2.innerHTML = '<div class="cs-rating-label">' + esc(cfg.strings.helpful) + '</div>'
                            + '<div class="cs-rating-buttons">'
                            + '<button type="button" class="cs-rate" data-val="1">' + svgUp   + ' ' + esc(cfg.strings.yes) + '</button>'
                            + '<button type="button" class="cs-rate" data-val="0">' + svgDown + ' ' + esc(cfg.strings.no)  + '</button>'
                            + '</div>';
                        aiDiv.querySelector('.cs-msg-body').appendChild(ratingDiv2);
                    }
                } else {
                    appendMessage('bot', 'I wasn\'t able to find a better answer for that.');
                    if (cfg.enableInquiry) askInquiry(lastUserQ);
                }
            });
        });
    }

    // ── Bootstrap ─────────────────────────────────────────────────────────────
    function boot() {
        console.log('[CleverSay] Booting embed from: ' + CONFIG_URL);

        // Check for Shadow DOM support (all modern browsers support it)
        if (!document.head.attachShadow && !HTMLElement.prototype.attachShadow) {
            console.warn('[CleverSay] Shadow DOM not supported — falling back to regular DOM');
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', CONFIG_URL, true);
        xhr.withCredentials = false;
        xhr.onload = function () {
            console.log('[CleverSay] Config status: ' + xhr.status);
            var cfg;
            try { cfg = JSON.parse(xhr.responseText); }
            catch(e) { console.error('[CleverSay] Config parse error: ' + e.message); return; }

            // ── Suspended-site check ─────────────────────────────────
            // When a site's trial has expired or the plan is suspended, the
            // server returns { suspended: true, message: '...' } instead of
            // the full config. Render a minimal static "unavailable" widget
            // — it's better than the toggle button just disappearing
            // (which would make the client's site look broken).
            if (cfg && cfg.suspended) {
                console.log('[CleverSay] Site is currently unavailable: ' + (cfg.reason || ''));
                var suspendedHost = document.createElement('div');
                suspendedHost.id  = 'cs-embed-host-suspended';
                suspendedHost.style.cssText = 'position:fixed;z-index:2147483647;bottom:20px;right:20px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:8px;padding:10px 14px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px;color:#646970;max-width:280px;box-shadow:0 2px 8px rgba(0,0,0,0.08);';
                suspendedHost.textContent = cfg.message || 'This chatbot is currently unavailable.';
                document.body.appendChild(suspendedHost);
                return;
            }

            if (!cfg || !cfg.ajaxUrl) { console.error('[CleverSay] Config missing ajaxUrl'); return; }

            // ── Create host element ──
            var host = document.createElement('div');
            host.id  = 'cs-embed-host';
            host.style.cssText = 'position:fixed;z-index:2147483647;bottom:0;right:0;width:0;height:0;overflow:visible;';
            document.body.appendChild(host);

            // ── Attach Shadow DOM ──
            var shadow;
            try {
                shadow = host.attachShadow({ mode: 'open' });
            } catch(e) {
                // Fallback: use regular DOM if Shadow DOM fails
                shadow = host;
            }

            // ── Inject styles ──
            var fontCfg  = getFontConfig(cfg);

            // Load Google Font into the main document <head> — fonts loaded there
            // are available inside shadow DOM, but <link> tags inside shadow roots
            // are not reliably fetched by all browsers.
            if (fontCfg.url && !document.querySelector('link[data-cs-font]')) {
                var fontLink = document.createElement('link');
                fontLink.rel             = 'stylesheet';
                fontLink.href            = fontCfg.url;
                fontLink.setAttribute('data-cs-font', '1');
                document.head.appendChild(fontLink);
            }

            var styleEl  = document.createElement('style');
            styleEl.textContent = buildCSS(cfg, fontCfg);
            shadow.appendChild(styleEl);

            // ── Inject widget HTML ──
            var wrapper = document.createElement('div');
            wrapper.innerHTML = buildHTML(cfg);
            shadow.appendChild(wrapper.firstChild);

            // ── Bind events ──
            bindEvents(cfg, shadow);
        };
        xhr.onerror = function () {
            console.error('[CleverSay] Could not load config from ' + CONFIG_URL);
        };
        xhr.send();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

}());
