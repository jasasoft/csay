<?php
/**
 * CleverSay Full-Page Chatbot Template
 * No theme header or footer — just the chatbot filling the viewport.
 *
 * @package CleverSay
 * @since   2.5.8
 */

if (!defined('ABSPATH')) {
    exit;
}

// Enqueue plugin scripts/styles for this page
do_action('wp_enqueue_scripts');

$blog_name = get_bloginfo('name');
$charset   = get_bloginfo('charset');
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr($charset); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($blog_name); ?></title>
<?php
// Print registered styles and scripts
wp_print_styles();
wp_print_scripts();
?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body {
    margin: 0; padding: 0;
    height: 100%; overflow: hidden;
    font-family: sans-serif;
}
#cs-fullpage {
    display: flex;
    flex-direction: column;
    height: 100vh;
    width: 100%;
}
#cs-fullpage .cleversay-embedded-wrapper {
    flex: 1; min-height: 0; height: 100%;
}
#cs-fullpage .cleversay-embedded,
#cs-fullpage .cleversay-embedded-container {
    height: 100%;
}
#cs-fullpage .cleversay-embedded-container {
    display: flex;
    flex-direction: column;
    border-radius: 0;
    box-shadow: none;
}
#cs-fullpage .cleversay-messages {
    flex: 1; min-height: 0;
}
#cs-fullpage .cleversay-two-column { height: 100%; }
#wpadminbar { display: none; }
html { margin-top: 0 !important; }
</style>
</head>
<body class="cleversay-fullpage">

<div id="cs-fullpage">
<?php echo do_shortcode('[cleversay_chatbot]'); ?>
</div>

<?php
// Print any footer scripts registered by the plugin
wp_print_footer_scripts();
?>
</body>
</html>
