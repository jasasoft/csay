# Third-Party Libraries

This directory holds third-party PHP libraries vendored into CleverSay.
Each library is a single file with a clear purpose and license.

---

## Parsedown.php

**What:** [Parsedown](https://github.com/erusev/parsedown) — a fast, single-file
markdown parser for PHP.

**Why:** The widget renders bot responses via `innerHTML`. Without
server-side markdown conversion, asterisks, dashes, and brackets appear
literally on screen instead of as bold text, bullet points, and links.

**Used by:** `\CleverSay\AI::convert_minimal_markdown_to_html()` in
`includes/class-ai.php`.

**Version:** 1.7.4 (the last stable 1.7.x release; 1.8 is in beta upstream)

**License:** MIT

---

### Status: shipped with the plugin (v4.42.29+)

Earlier versions (v4.42.22–v4.42.28) treated Parsedown as an optional
drop-in: ship without it, instruct admins to download `Parsedown.php` and
place it here, fall back to a narrow regex converter when missing. That
approach had a real failure mode — the updater's atomic-swap deploy
replaces the entire plugin directory, so admin-installed Parsedown got
deleted on every upgrade.

From v4.42.29 onward, Parsedown ships inside the plugin zip. No separate
installation step. Survives upgrades automatically because every upgrade
restores this file alongside the rest of the plugin.

The `AI::convert_minimal_markdown_to_html()` fallback path for "Parsedown
missing" still exists in code — it's the safety net for the unlikely
case this file is accidentally removed from the distribution. The
**CleverSay Tools → Parsedown Status** admin page reports whether the
file is present, the class loads, the version, and a live render test.

### Why we vendor (rather than use Composer)

CleverSay is a WordPress plugin distributed as a zip. Composer's
autoloader adds setup overhead and conflicts with WordPress sites that
use their own Composer setup. A single dropped-in file with a
`require_once` is simpler, auditable, and adds no infrastructure
dependencies.
