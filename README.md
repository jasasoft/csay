# CleverSay Knowledge Base

A modern, AI-powered knowledge base and FAQ chatbot system for WordPress.

## Description

CleverSay transforms your WordPress site into an intelligent FAQ system with a floating chatbot widget. Originally developed as a legacy PHP 5 application, this version has been completely rewritten using modern PHP 8+ standards, WordPress best practices, and clean architecture patterns.

### Features

- **Smart Search Engine**: Pattern matching with wildcards, spell correction using Levenshtein distance, synonym replacement, word stemming, and stopword filtering
- **Floating Chatbot Widget**: Customizable position and colors, accessible via CSS variables
- **Knowledge Base Management**: Full CRUD operations with categories, bulk actions, and alphabet navigation
- **Analytics Dashboard**: Track questions, match rates, popular keywords, and visitor patterns with Chart.js visualizations
- **Ask Question (Admin)**: Test search without logging, see detailed matching process with scores
- **Inquiry System**: Capture unanswered questions for follow-up with email notifications
- **Rating System**: Collect helpful/not helpful feedback with optional comments
- **REST API**: Full API for external integrations with authentication support
- **Import/Export**: Migrate from legacy systems or backup your data
- **Shortcodes**: Embed search forms, chatbot, or FAQ lists anywhere

## Requirements

- WordPress 5.8 or higher
- PHP 8.0 or higher
- MySQL 5.7 or higher (or MariaDB 10.3+)

## Installation

1. Upload the `cleversay` folder to `/wp-content/plugins/`
2. Rename to `cleversay` (optional)
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to CleverSay → Settings to configure
5. Add knowledge base entries via CleverSay → Knowledge Base

## Usage

### Shortcodes

```
[cleversay]
```
Displays an inline search form.

```
[cleversay_chatbot]
```
Embeds the full chatbot interface.

```
[cleversay_faq category="general" limit="10"]
```
Displays FAQ entries as an accordion list.

### REST API

The plugin provides a REST API at `/wp-json/cleversay/v1/`:

- `GET /search?q=query` - Search the knowledge base
- `GET /knowledge` - List all entries
- `POST /knowledge` - Create an entry
- `GET /knowledge/{id}` - Get single entry
- `PUT /knowledge/{id}` - Update entry
- `DELETE /knowledge/{id}` - Delete entry
- `GET /categories` - List categories
- `POST /rate` - Submit a rating
- `POST /inquiry` - Submit an inquiry
- `GET /stats` - Get analytics (admin only)

### Importing from Legacy System

1. Go to CleverSay → Import/Export
2. Enter your legacy database credentials
3. Click "Import from Legacy Database"
4. The system will migrate:
   - Knowledge base entries
   - Synonyms/spell check dictionary
   - Stopwords
   - Recent questions (last 90 days)

## Configuration

### Widget Settings

- Enable/disable floating widget
- Position (bottom-right, bottom-left, top-right, top-left)
- Custom title and placeholder text
- Welcome message

### Appearance

- Primary and secondary colors
- Text and background colors
- All colors are applied via CSS custom properties

### Search Settings

- Enable spell checking
- Spell check threshold (similarity %)
- Minimum match score
- Maximum results per query
- Show suggestions for unmatched queries

### Inquiry Settings

- Enable inquiry form
- Notification email address
- Require email from users
- Custom messages

### Analytics

- Enable/disable tracking
- Track visitors by IP
- Anonymize IP addresses (GDPR)

## File Structure

```
cleversay/
├── cleversay.php              # Main plugin file
├── uninstall.php              # Cleanup on uninstall
├── includes/
│   ├── class-database.php     # Database schema and operations
│   ├── class-search.php       # Search engine
│   ├── class-spellcheck.php   # Spell correction
│   ├── class-analytics.php    # Analytics tracking
│   ├── class-import-export.php # Import/Export functionality
│   └── class-api.php          # REST API endpoints
├── admin/
│   ├── class-admin.php        # Admin interface
│   ├── css/admin.css          # Admin styles
│   ├── js/admin.js            # Admin scripts
│   └── views/                 # Admin templates
│       ├── dashboard.php
│       ├── knowledge-list.php
│       ├── knowledge-form.php
│       ├── categories.php
│       ├── synonyms.php
│       ├── questions.php
│       ├── inquiries.php
│       ├── reports.php
│       ├── settings.php
│       └── import-export.php
├── public/
│   ├── class-public.php       # Frontend functionality
│   ├── css/public.css         # Frontend styles
│   ├── js/public.js           # Frontend scripts
│   └── views/
│       └── chatbot-widget.php # Widget template
└── languages/
    └── cleversay.pot          # Translation template
```

## Database Tables

The plugin creates the following tables (with WordPress prefix):

- `cleversay_knowledge` - Main knowledge base entries
- `cleversay_categories` - Hierarchical categories
- `cleversay_questions` - Search query log
- `cleversay_visitors` - Visitor tracking
- `cleversay_synonyms` - Synonym/spell check dictionary
- `cleversay_ratings` - User feedback
- `cleversay_inquiries` - Unanswered questions

## Hooks and Filters

### Actions

```php
do_action('cleversay_before_search', $query);
do_action('cleversay_after_search', $query, $results);
do_action('cleversay_entry_saved', $entry_id);
do_action('cleversay_inquiry_submitted', $inquiry_id);
```

### Filters

```php
apply_filters('cleversay_search_results', $results, $query);
apply_filters('cleversay_widget_output', $html);
apply_filters('cleversay_stopwords', $stopwords);
apply_filters('cleversay_min_match_score', $score);
```

## Changelog

### 2.0.2
- Added: "Ask Question" admin feature for testing search without logging
- Added: Detailed process display showing synonym replacements, keyword matching, scores
- Added: Related questions ("You May Also Be Interested In...") in test results
- Added: `test_search()` method in Search class with full debug output
- Added: Click-to-search on related and suggested questions
- Fixed: Search now also searches in `question` column, not just keyword/sub_keyword
- Fixed: Fallback search using original question words if processed words return nothing
- Fixed: Broad search fallback if keyword search fails
- Fixed: Public search response format to match JavaScript expectations
- Fixed: Stopwords safeguard - keeps words if all would be removed
- Fixed: Default min_score lowered to 50 for better matching
- Improved: Error handling with try-catch in AJAX handlers

### 2.0.1
- Fixed: Column name consistency (`sub_keyword` instead of `subkeyword`)
- Fixed: PHP 8 strict type errors in legacy import (string type casting)
- Fixed: Dashboard using wrong column name (`created_at` instead of `asked_at`)
- Fixed: Knowledge form field names to match database schema
- Fixed: Chart.js container height issue causing infinite expansion
- Fixed: Statistics card using wrong column (`rate` → `helpful_yes/helpful_no`)
- Fixed: Missing `chatbot-embedded.php` template for shortcode
- Added: Embedded chatbot styles for `[cleversay_chatbot]` shortcode
- Improved: Dashboard layout with quick actions at top
- Improved: Stats cards redesigned as 4-column grid with icons
- Improved: Legacy import now shows debug info about found columns

### 2.0.0
- Complete rewrite using PHP 8+ and modern WordPress standards
- Added REST API
- Added Analytics dashboard with Chart.js
- Added Categories system
- Added Rating system with feedback
- Added Inquiry management
- Added Import/Export functionality
- Removed FCKeditor (use WordPress editor)
- Removed JPGraph (use Chart.js)
- Removed custom GeoIP (use external service)
- Improved search algorithm
- Mobile-responsive design
- GDPR compliance options

### 1.x
- Legacy version (not maintained)

## License

GPL v2 or later. See LICENSE file.

## Credits

Originally developed by CleverSay Team. Modernized for WordPress by the development team.
