/**
 * CleverSay Network — Logo Media Uploader
 * Handles the WordPress media picker for client logo upload.
 *
 * @package CleverSay
 * @since   4.0.1
 */
(function ($) {
    'use strict';

    var mediaUploader;

    $(document).on('click', '#cleversay-upload-logo-btn', function (e) {
        e.preventDefault();

        // Reuse existing uploader instance if available
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media({
            title:    'Select or Upload Client Logo',
            button:   { text: 'Use This Logo' },
            library:  { type: 'image' },
            multiple: false,
        });

        mediaUploader.on('select', function () {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#client_logo_url').val(attachment.url);
            $('#cleversay-logo-preview').attr('src', attachment.url).show();
            $('#cleversay-remove-logo-btn').show();
        });

        mediaUploader.open();
    });

    $(document).on('click', '#cleversay-remove-logo-btn', function (e) {
        e.preventDefault();
        $('#client_logo_url').val('');
        $('#cleversay-logo-preview').hide().attr('src', '');
        $(this).hide();
    });

})(jQuery);
