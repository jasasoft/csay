/**
 * CleverSay Admin JavaScript
 *
 * @package CleverSay
 */

(function($) {
    'use strict';

    /**
     * Knowledge Base Management
     */
    const KnowledgeManager = {
        init: function() {
            this.bindEvents();
            this.initEditor();
        },

        bindEvents: function() {
            // Save entry via AJAX
            $(document).on('submit', '#cleversay-entry-form', this.saveEntry.bind(this));
            
            // Delete entry
            $(document).on('click', '.delete-entry', this.deleteEntry.bind(this));
            
            // Bulk actions
            $(document).on('click', '#do-bulk-action', this.bulkAction.bind(this));
            
            // Quick edit
            $(document).on('click', '.quick-edit', this.quickEdit.bind(this));
            
            // Link validation
            $(document).on('click', '.validate-links', this.validateLinks.bind(this));
            
            // Select all checkbox
            $(document).on('change', '#cb-select-all', function() {
                $('input[name="entry_ids[]"]').prop('checked', this.checked);
            });

            // Alphabet filter
            $(document).on('click', '.alphabet-filter a', function(e) {
                e.preventDefault();
                var letter = $(this).data('letter');
                window.location.href = updateQueryParam('letter', letter);
            });

            // Search type toggle
            $(document).on('change', '#search_type', this.updateKeywordPreview.bind(this));
            $(document).on('input', '#keyword', this.updateKeywordPreview.bind(this));
        },

        initEditor: function() {
            // Initialize any rich text editors if needed
            if (typeof wp !== 'undefined' && wp.editor) {
                // WordPress editor is available
            }
        },

        saveEntry: function(e) {
            e.preventDefault();
            var $form = $(e.target);
            var $button = $form.find('button[type="submit"]');
            var originalText = $button.text();

            $button.prop('disabled', true).text(cleversayAdmin.i18n.saving);

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_save_entry',
                    nonce: cleversayAdmin.nonce,
                    data: $form.serialize()
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', response.data.message);
                        if (response.data.redirect) {
                            window.location.href = response.data.redirect;
                        }
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', cleversayAdmin.i18n.error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        deleteEntry: function(e) {
            e.preventDefault();
            
            if (!confirm(cleversayAdmin.i18n.confirmDelete)) {
                return;
            }

            var $link = $(e.target).closest('a');
            var entryId = $link.data('id');
            var $row = $link.closest('tr');

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_delete_entry',
                    nonce: cleversayAdmin.nonce,
                    entry_id: entryId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                        });
                        showNotice('success', response.data.message);
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', cleversayAdmin.i18n.error);
                }
            });
        },

        bulkAction: function(e) {
            e.preventDefault();
            
            var action = $('#bulk-action-selector').val();
            var selected = $('input[name="entry_ids[]"]:checked').map(function() {
                return this.value;
            }).get();

            if (!action || action === '-1') {
                showNotice('error', cleversayAdmin.i18n.selectAction);
                return;
            }

            if (selected.length === 0) {
                showNotice('error', cleversayAdmin.i18n.selectItems);
                return;
            }

            if (action === 'delete' && !confirm(cleversayAdmin.i18n.confirmBulkDelete)) {
                return;
            }

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_bulk_action',
                    nonce: cleversayAdmin.nonce,
                    bulk_action: action,
                    entry_ids: selected
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', cleversayAdmin.i18n.error);
                }
            });
        },

        quickEdit: function(e) {
            e.preventDefault();
            var $row = $(e.target).closest('tr');
            var entryId = $row.data('id');
            
            // Toggle quick edit row
            var $editRow = $row.next('.quick-edit-row');
            if ($editRow.length) {
                $editRow.toggle();
            } else {
                // Create quick edit row
                this.createQuickEditRow($row, entryId);
            }
        },

        createQuickEditRow: function($row, entryId) {
            var colspan = $row.find('td').length;
            var $editRow = $('<tr class="quick-edit-row"><td colspan="' + colspan + '"></td></tr>');
            
            $editRow.find('td').html('<div class="quick-edit-loading">' + cleversayAdmin.i18n.loading + '</div>');
            $row.after($editRow);

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_get_entry',
                    nonce: cleversayAdmin.nonce,
                    entry_id: entryId
                },
                success: function(response) {
                    if (response.success) {
                        $editRow.find('td').html(response.data.html);
                    }
                }
            });
        },

        validateLinks: function(e) {
            e.preventDefault();
            var $button = $(e.target);
            var entryId = $button.data('id');
            
            $button.prop('disabled', true).text(cleversayAdmin.i18n.checking);

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_validate_links',
                    nonce: cleversayAdmin.nonce,
                    entry_id: entryId
                },
                success: function(response) {
                    if (response.success) {
                        var results = response.data.results;
                        var message = cleversayAdmin.i18n.linksChecked + ': ' + 
                            results.valid + ' valid, ' + results.invalid + ' broken';
                        showNotice(results.invalid > 0 ? 'warning' : 'success', message);
                    }
                },
                complete: function() {
                    $button.prop('disabled', false).text(cleversayAdmin.i18n.validateLinks);
                }
            });
        },

        updateKeywordPreview: function() {
            var keyword = $('#keyword').val();
            var searchType = $('#search_type').val();
            var preview = '';

            switch (searchType) {
                case 'exact':
                    preview = '"' + keyword + '"';
                    break;
                case 'prefix':
                    preview = keyword + '*';
                    break;
                case 'suffix':
                    preview = '*' + keyword;
                    break;
                case 'contains':
                    preview = '*' + keyword + '*';
                    break;
            }

            $('#keyword-preview').text(preview);
        }
    };

    /**
     * Synonym Management
     */
    const SynonymManager = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('submit', '#add-synonym-form', this.addSynonym.bind(this));
            $(document).on('click', '.delete-synonym', this.deleteSynonym.bind(this));
            $(document).on('click', '.toggle-synonym', this.toggleSynonym.bind(this));
        },

        addSynonym: function(e) {
            e.preventDefault();
            var $form = $(e.target);

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_add_synonym',
                    nonce: cleversayAdmin.nonce,
                    term: $form.find('[name="term"]').val(),
                    replacement: $form.find('[name="replacement"]').val(),
                    is_phrase: $form.find('[name="is_phrase"]').is(':checked') ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        showNotice('error', response.data.message);
                    }
                }
            });
        },

        deleteSynonym: function(e) {
            e.preventDefault();
            
            if (!confirm(cleversayAdmin.i18n.confirmDelete)) {
                return;
            }

            var $link = $(e.target).closest('a');
            var id = $link.data('id');

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_delete_synonym',
                    nonce: cleversayAdmin.nonce,
                    synonym_id: id
                },
                success: function(response) {
                    if (response.success) {
                        $link.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                        });
                    }
                }
            });
        },

        toggleSynonym: function(e) {
            e.preventDefault();
            var $link = $(e.target).closest('a');
            var id = $link.data('id');

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_toggle_synonym',
                    nonce: cleversayAdmin.nonce,
                    synonym_id: id
                },
                success: function(response) {
                    if (response.success) {
                        $link.closest('tr').toggleClass('inactive');
                        $link.find('.dashicons').toggleClass('dashicons-yes dashicons-no');
                    }
                }
            });
        }
    };

    /**
     * Category Management
     */
    const CategoryManager = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('submit', '#add-category-form', this.addCategory.bind(this));
        },

        addCategory: function(e) {
            e.preventDefault();
            var $form = $(e.target);

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                                    }
            });
        },

        deleteCategory: function(e) {
            e.preventDefault();
            
            if (!confirm(cleversayAdmin.i18n.confirmDeleteCategory)) {
                return;
            }

            var $link = $(e.target).closest('a');
            var id = $link.data('id');

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_delete_category',
                    nonce: cleversayAdmin.nonce,
                },
                success: function(response) {
                    if (response.success) {
                        $link.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        showNotice('error', response.data.message);
                    }
                }
            });
        },

        editCategory: function(e) {
            e.preventDefault();
            // Implement inline editing or modal
        }
    };

    /**
     * Inquiry Management
     */
    const InquiryManager = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('click', '.resolve-inquiry', this.resolveInquiry.bind(this));
            $(document).on('submit', '.inquiry-response-form', this.submitResponse.bind(this));
        },

        resolveInquiry: function(e) {
            e.preventDefault();
            var $link = $(e.target).closest('a');
            var id = $link.data('id');

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_resolve_inquiry',
                    nonce: cleversayAdmin.nonce,
                    inquiry_id: id
                },
                success: function(response) {
                    if (response.success) {
                        $link.closest('tr').find('.badge-pending')
                            .removeClass('badge-pending')
                            .addClass('badge-resolved')
                            .text(cleversayAdmin.i18n.resolved);
                    }
                }
            });
        },

        submitResponse: function(e) {
            // Handle AJAX response submission if needed
        }
    };

    /**
     * Dashboard
     */
    const Dashboard = {
        init: function() {
            this.loadRecentActivity();
        },

        loadRecentActivity: function() {
            var $container = $('#recent-activity');
            if (!$container.length) return;

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_get_recent_activity',
                    nonce: cleversayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $container.html(response.data.html);
                    }
                }
            });
        }
    };

    /**
     * Search Test
     */
    const SearchTest = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('submit', '#search-test-form', this.runTest.bind(this));
        },

        runTest: function(e) {
            e.preventDefault();
            var $form = $(e.target);
            var query = $form.find('[name="test_query"]').val();
            var $results = $('#search-test-results');

            $results.html('<p class="loading">' + cleversayAdmin.i18n.searching + '</p>');

            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cleversay_test_search',
                    nonce: cleversayAdmin.nonce,
                    query: query
                },
                success: function(response) {
                    if (response.success) {
                        var html = '<h4>Results for "' + escapeHtml(query) + '"</h4>';
                        
                        if (response.data.corrections.length) {
                            html += '<p class="corrections">Corrections applied: ' + 
                                response.data.corrections.join(', ') + '</p>';
                        }

                        if (response.data.results.length) {
                            html += '<ul class="search-results">';
                            response.data.results.forEach(function(result) {
                                html += '<li>';
                                html += '<strong>' + escapeHtml(result.keyword) + '</strong>';
                                html += ' <span class="score">(' + result.score + '%)</span>';
                                html += '<br><small>' + escapeHtml(result.response.substring(0, 100)) + '...</small>';
                                html += '</li>';
                            });
                            html += '</ul>';
                        } else {
                            html += '<p class="no-results">No results found.</p>';
                        }

                        $results.html(html);
                    }
                }
            });
        }
    };

    /**
     * Utility Functions
     */
    function showNotice(type, message) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').first().after($notice);
        
        // Add dismiss button functionality
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        });

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function updateQueryParam(key, value) {
        var url = new URL(window.location.href);
        if (value) {
            url.searchParams.set(key, value);
        } else {
            url.searchParams.delete(key);
        }
        return url.toString();
    }

    /**
     * Initialize on document ready
     */
    $(function() {
        // Initialize managers based on current page
        if ($('#cleversay-entry-form').length || $('.cleversay-knowledge-list').length) {
            KnowledgeManager.init();
        }

        if ($('.cleversay-synonyms').length) {
            SynonymManager.init();
        }

        if ($('.cleversay-categories').length) {
            CategoryManager.init();
        }

        if ($('.cleversay-inquiries').length) {
            InquiryManager.init();
        }

        if ($('.cleversay-dashboard').length) {
            Dashboard.init();
        }

        if ($('#search-test-form').length) {
            SearchTest.init();
        }

        // Common functionality
        
        // Confirm before leaving with unsaved changes
        var formChanged = false;
        $('form').on('change', 'input, textarea, select', function() {
            formChanged = true;
        });

        $('form').on('submit', function() {
            formChanged = false;
        });

        $(window).on('beforeunload', function() {
            if (formChanged) {
                return cleversayAdmin.i18n.unsavedChanges;
            }
        });

        // Auto-generate slug from name
        $(document).on('input', 'input[name="name"]', function() {
            var $slug = $('input[name="slug"]');
            if (!$slug.data('manual')) {
                $slug.val($(this).val()
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '')
                );
            }
        });

        $(document).on('input', 'input[name="slug"]', function() {
            $(this).data('manual', true);
        });

        // Expandable rows
        $(document).on('click', '.row-expand', function() {
            $(this).closest('tr').next('.expanded-row').toggle();
            $(this).find('.dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
        });

        // Tooltips
        $('.cleversay-tooltip').each(function() {
            $(this).attr('title', $(this).data('tooltip'));
        });
    });

    /**
     * Keyword Edit - Pattern Builder
     */
    const PatternBuilder = {
        currentTarget: null,
        groupCounter: 0,

        init: function() {
            if (!$('.cleversay-keyword-edit').length) return;
            
            this.bindEvents();
        },

        bindEvents: function() {
            // Open pattern builder modal
            $(document).on('click', '.edit-pattern-btn', this.openModal.bind(this));
            
            // Close modal
            $(document).on('click', '.modal-close, .modal-cancel', this.closeModal.bind(this));
            $(document).on('click', '#pattern-builder-modal', function(e) {
                if (e.target === this) PatternBuilder.closeModal();
            });
            
            // Add OR group
            $(document).on('click', '#add-or-group', this.addOrGroup.bind(this));
            
            // Add word to group
            $(document).on('click', '.add-word-btn', this.addWord.bind(this));
            
            // Remove word
            $(document).on('click', '.remove-word-btn', this.removeWord.bind(this));
            
            // Remove group
            $(document).on('click', '.remove-group-btn', this.removeGroup.bind(this));
            
            // Update preview on input change
            $(document).on('input change', '#pattern-groups-builder input, #pattern-groups-builder select', 
                this.updatePreview.bind(this));
            
            // Apply pattern
            $(document).on('click', '#apply-pattern', this.applyPattern.bind(this));
            
            // Add pattern to response group
            $(document).on('click', '.add-pattern-btn', this.addPatternToGroup.bind(this));
            
            // Delete pattern
            $(document).on('click', '.delete-pattern', this.deletePattern.bind(this));
            
            // Add response group
            $(document).on('click', '#add-response-group', this.addResponseGroup.bind(this));
            
            // Delete response group
            $(document).on('click', '.delete-group', this.deleteResponseGroup.bind(this));
            
            // Delete keyword
            $(document).on('click', '#delete-keyword', this.deleteKeyword.bind(this));
        },

        openModal: function(e) {
            e.preventDefault();
            
            const $builder = $(e.currentTarget).closest('.pattern-builder');
            this.currentTarget = $builder;
            
            const currentPattern = $builder.find('.pattern-value').val();
            
            // Reset and build the modal content
            $('#pattern-groups-builder').empty();
            this.groupCounter = 0;
            
            if (currentPattern && currentPattern !== '') {
                // Parse existing pattern
                this.parsePattern(currentPattern);
            } else {
                // Start with one empty group
                this.addOrGroup();
            }
            
            this.updatePreview();
            $('#pattern-builder-modal').show();
        },

        closeModal: function() {
            $('#pattern-builder-modal').hide();
            this.currentTarget = null;
        },

        parsePattern: function(pattern) {
            // Split by | for OR groups
            const orGroups = pattern.split('|');
            
            orGroups.forEach(group => {
                if (!group.trim()) return;
                
                this.groupCounter++;
                const $group = $(this.getGroupTemplate(this.groupCounter));
                
                // Split by & or + for AND words
                const andWords = group.split(/[&+]/);
                
                andWords.forEach(word => {
                    word = word.trim();
                    if (!word) return;
                    
                    let type = 'exact';
                    let cleanWord = word;
                    
                    // Detect wildcards
                    if (word.startsWith('*') && word.endsWith('*')) {
                        type = 'contains';
                        cleanWord = word.slice(1, -1);
                    } else if (word.startsWith('*')) {
                        type = 'suffix';
                        cleanWord = word.slice(1);
                    } else if (word.endsWith('*')) {
                        type = 'prefix';
                        cleanWord = word.slice(0, -1);
                    }
                    
                    const $word = $(this.getWordTemplate(cleanWord, type));
                    $group.find('.group-words').append($word);
                });
                
                $('#pattern-groups-builder').append($group);
            });
        },

        addOrGroup: function() {
            this.groupCounter++;
            const $group = $(this.getGroupTemplate(this.groupCounter));
            
            // Add one empty word by default
            const $word = $(this.getWordTemplate('', 'exact'));
            $group.find('.group-words').append($word);
            
            $('#pattern-groups-builder').append($group);
            this.updatePreview();
        },

        addWord: function(e) {
            const $group = $(e.currentTarget).closest('.pattern-builder-group');
            const $word = $(this.getWordTemplate('', 'exact'));
            $group.find('.group-words').append($word);
            $word.find('.word-input').focus();
            this.updatePreview();
        },

        removeWord: function(e) {
            const $item = $(e.currentTarget).closest('.pattern-word-item');
            const $group = $item.closest('.pattern-builder-group');
            
            $item.remove();
            
            // If no words left, remove the group
            if ($group.find('.pattern-word-item').length === 0) {
                $group.remove();
            }
            
            this.updatePreview();
        },

        removeGroup: function(e) {
            $(e.currentTarget).closest('.pattern-builder-group').remove();
            this.updatePreview();
        },

        getGroupTemplate: function(num) {
            return $('#pattern-group-template').html()
                .replace(/{groupNum}/g, num);
        },

        getWordTemplate: function(word, type) {
            return $('#pattern-word-template').html()
                .replace(/{word}/g, word)
                .replace(/{exactSelected}/g, type === 'exact' ? 'selected' : '')
                .replace(/{prefixSelected}/g, type === 'prefix' ? 'selected' : '')
                .replace(/{suffixSelected}/g, type === 'suffix' ? 'selected' : '')
                .replace(/{containsSelected}/g, type === 'contains' ? 'selected' : '');
        },

        updatePreview: function() {
            const pattern = this.buildPattern();
            $('#generated-pattern').text(pattern || '(empty)');
        },

        buildPattern: function() {
            const orGroups = [];
            
            $('#pattern-groups-builder .pattern-builder-group').each(function() {
                const andWords = [];
                
                $(this).find('.pattern-word-item').each(function() {
                    const word = $(this).find('.word-input').val().trim();
                    const type = $(this).find('.word-type').val();
                    
                    if (!word) return;
                    
                    let formatted = word;
                    switch (type) {
                        case 'prefix':
                            formatted = word + '*';
                            break;
                        case 'suffix':
                            formatted = '*' + word;
                            break;
                        case 'contains':
                            formatted = '*' + word + '*';
                            break;
                    }
                    
                    andWords.push(formatted);
                });
                
                if (andWords.length > 0) {
                    orGroups.push(andWords.join('&'));
                }
            });
            
            return orGroups.join('|');
        },

        applyPattern: function() {
            if (!this.currentTarget) return;
            
            const pattern = this.buildPattern();
            
            this.currentTarget.find('.pattern-value').val(pattern);
            this.currentTarget.find('.pattern-preview').text(pattern || '(not set)');
            
            this.closeModal();
        },

        addPatternToGroup: function(e) {
            const $group = $(e.currentTarget).closest('.response-group');
            const groupIndex = $group.data('group-index');
            const $patternsList = $group.find('.patterns-list');
            const patternIndex = $patternsList.find('.pattern-item').length;
            
            const html = $('#new-pattern-template').html()
                .replace(/{groupIndex}/g, groupIndex)
                .replace(/{patternIndex}/g, patternIndex);
            
            $patternsList.append(html);
        },

        deletePattern: function(e) {
            if (!confirm(cleversayAdmin.strings.confirmDelete || 'Are you sure?')) return;
            
            $(e.currentTarget).closest('.pattern-item').remove();
        },

        addResponseGroup: function() {
            const $container = $('#response-groups-container');
            const groupIndex = $container.find('.response-group').length;
            
            const html = $('#new-response-group-template').html()
                .replace(/{groupIndex}/g, groupIndex);
            
            const $newGroup = $(html);
            $container.append($newGroup);
            
            // Scroll to new group
            $('html, body').animate({
                scrollTop: $newGroup.offset().top - 100
            }, 500);
        },

        deleteResponseGroup: function(e) {
            if (!confirm(cleversayAdmin.strings.confirmDeleteGroup || 'Delete this response group and all its patterns?')) return;
            
            $(e.currentTarget).closest('.response-group').remove();
        },

        deleteKeyword: function() {
            if (!confirm(cleversayAdmin.strings.confirmDeleteKeyword || 'Delete this keyword and ALL its patterns and responses? This cannot be undone.')) return;
            
            const keyword = $('input[name="keyword"]').val();
            
            $.ajax({
                url: cleversayAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'cleversay_delete_keyword',
                    nonce: cleversayAdmin.nonce,
                    keyword: keyword
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = cleversayAdmin.adminUrl + 'admin.php?page=cleversay-knowledge&message=deleted';
                    } else {
                        alert(response.data?.message || 'Error deleting keyword');
                    }
                },
                error: function() {
                    alert('Error deleting keyword');
                }
            });
        }
    };

    // Initialize Pattern Builder
    $(document).ready(function() {
        PatternBuilder.init();
    });

})(jQuery);
