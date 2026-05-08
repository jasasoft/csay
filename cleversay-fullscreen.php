<?php
/**
 * Template Name: CleverSay Chatbot (Full Page)
 * Template Post Type: page
 *
 * A bare-bones page template — no theme header, footer, or sidebar.
 * Assign this template to any WordPress page to display the chatbot
 * filling the full browser window without any theme chrome.
 *
 * Compatible with Underscore (_s) and most block/classic themes.
 *
 * @package CleverSay
 * @since   2.5.6
 */

if (!defined('ABSPATH')) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php wp_title('|', true, 'right'); bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <style>
        html, body {
            margin:   0;
            padding:  0;
            height:   100%;
            overflow: hidden;
        }
        #cleversay-fullpage-wrap {
            display:        flex;
            flex-direction: column;
            height:         100vh;
            width:          100%;
            box-sizing:     border-box;
        }
        /* Chatbot fills all available height */
        #cleversay-fullpage-wrap .cleversay-embedded-wrapper {
            flex:       1;
            min-height: 0;
            height:     100%;
        }
        #cleversay-fullpage-wrap .cleversay-embedded {
            height: 100%;
        }
        #cleversay-fullpage-wrap .cleversay-embedded-container {
            display:        flex;
            flex-direction: column;
            height:         100%;
            border-radius:  0;
            box-shadow:     none;
        }
        #cleversay-fullpage-wrap .cleversay-messages {
            flex:       1;
            min-height: 0;
        }
        #cleversay-fullpage-wrap .cleversay-two-column {
            height: 100%;
        }
        /* Remove WP admin bar space */
        html { margin-top: 0 !important; }
        #wpadminbar { display: none; }
    </style>
</head>
<body <?php body_class('cleversay-fullpage'); ?>>
<?php wp_body_open(); ?>

<div id="cleversay-fullpage-wrap">
    <?php echo do_shortcode('[cleversay_chatbot]'); ?>
</div>

<?php wp_footer(); ?>
</body>
</html>
