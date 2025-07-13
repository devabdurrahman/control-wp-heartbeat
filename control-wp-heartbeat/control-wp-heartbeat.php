<?php
/**
 * Plugin Name:                     Control WP Heartbeat 
 * Plugin URI:                      https://github.com/devabdurrahman/control-wp-heartbeat
 * Description:                     Control WordPress Heartbeat API behavior for Dashboard, Post Editor, and Frontend.
 * Version:                         1.0
 * Requires at Least:               5.2
 * Requires PHP:                    7.2
 * Author:                          Abdur Rahman
 * Author URI:                      https://devabdurrahman.com/
 * License:                         GPL2
 * License URI:                     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:                     control-wp-heartbeat
 * Domain Path:                     /languages
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Add a "Settings" link under the plugin name on the Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cwh_settings_link');
function cwh_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=heartbeat-control') . '">' . esc_html__('Settings', 'control-wp-heartbeat') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

class CWP_Control_Wp_Heartbeat {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('heartbeat_settings', [$this, 'modify_heartbeat_frequency']);
        add_filter('heartbeat_send', [$this, 'maybe_disable_heartbeat']);
    }

    public function add_settings_page() {
        add_options_page(
            __('Heartbeat Control', 'control-wp-heartbeat'),
            __('Heartbeat Control', 'control-wp-heartbeat'),
            'manage_options',
            'heartbeat-control',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Heartbeat Control Settings', 'control-wp-heartbeat'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('heartbeat_control_group');
                do_settings_sections('heartbeat-control');
                submit_button();
                ?>
            </form>
        </div>
        <style>
            input[type="number"] {
                width: 100px;
            }
        </style>
        <?php
    }

    public function register_settings() {
        register_setting('heartbeat_control_group', 'heartbeat_control_settings', [$this, 'sanitize_settings']);

        add_settings_section(
            'heartbeat_section',
            __('Control Options', 'control-wp-heartbeat'),
            null,
            'heartbeat-control'
        );

        $contexts = [
            'dashboard'    => __('Dashboard', 'control-wp-heartbeat'),
            'post_editor'  => __('Post Editor', 'control-wp-heartbeat'),
            'frontend'     => __('Frontend', 'control-wp-heartbeat'),
        ];

        foreach ($contexts as $context => $label) {
            add_settings_field(
                "heartbeat_{$context}",
                "$label Heartbeat",
                [$this, 'render_heartbeat_option'],
                'heartbeat-control',
                'heartbeat_section',
                ['context' => $context, 'label' => $label]
            );
        }
    }

    public function sanitize_settings($input) {
        $contexts = ['dashboard', 'post_editor', 'frontend'];
        $sanitized = [];

        foreach ($contexts as $context) {
            $mode = isset($input[$context]['mode']) ? sanitize_text_field($input[$context]['mode']) : 'allow';

            // Only accept expected values
            if (!in_array($mode, ['allow', 'disallow', 'modify'], true)) {
                $mode = 'allow';
            }

            $frequency = isset($input[$context]['frequency']) ? intval($input[$context]['frequency']) : 15;
            $frequency = max(15, min(300, $frequency)); // enforce valid range

            $sanitized[$context] = [
                'mode' => $mode,
                'frequency' => $frequency
            ];
        }

        return $sanitized;
    }

    public function render_heartbeat_option($args) {
        $options = get_option('heartbeat_control_settings');
        $context = $args['context'];
        $current = isset($options[$context]['mode']) ? $options[$context]['mode'] : 'allow';
        $frequency = isset($options[$context]['frequency']) ? intval($options[$context]['frequency']) : 15;
        ?>

        <label>
            <input type="radio" name="heartbeat_control_settings[<?php echo esc_attr($context); ?>][mode]" value="allow" <?php checked($current, 'allow'); ?>>
            <?php echo esc_html__('Allow', 'control-wp-heartbeat'); ?>
        </label><br>

        <label>
            <input type="radio" name="heartbeat_control_settings[<?php echo esc_attr($context); ?>][mode]" value="disallow" <?php checked($current, 'disallow'); ?>>
            <?php echo esc_html__('Disallow', 'control-wp-heartbeat'); ?>
        </label><br>

        <label>
            <input type="radio" name="heartbeat_control_settings[<?php echo esc_attr($context); ?>][mode]" value="modify" <?php checked($current, 'modify'); ?>>
            <?php echo esc_html__('Modify Frequency', 'control-wp-heartbeat'); ?>
        </label>

        &nbsp;
        <input type="number" name="heartbeat_control_settings[<?php echo esc_attr($context); ?>][frequency]" value="<?php echo esc_attr($frequency); ?>" min="15" max="300">
        <?php echo esc_html__('seconds', 'control-wp-heartbeat'); ?>

        <?php
    }

    public function maybe_disable_heartbeat($response) {
        if (is_admin()) {
            global $pagenow;

            if ($pagenow === 'index.php') {
                $mode = $this->get_mode('dashboard');
            } elseif ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
                $mode = $this->get_mode('post_editor');
            } else {
                $mode = 'allow';
            }
        } else {
            $mode = $this->get_mode('frontend');
        }

        if ($mode === 'disallow') {
            return false;
        }

        return $response;
    }

    public function modify_heartbeat_frequency($settings) {
        if (is_admin()) {
            global $pagenow;

            if ($pagenow === 'index.php') {
                $mode = $this->get_mode('dashboard');
                $freq = $this->get_frequency('dashboard');
            } elseif ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
                $mode = $this->get_mode('post_editor');
                $freq = $this->get_frequency('post_editor');
            } else {
                $mode = 'allow';
            }
        } else {
            $mode = $this->get_mode('frontend');
            $freq = $this->get_frequency('frontend');
        }

        if ($mode === 'modify') {
            $settings['interval'] = max(15, intval($freq));
        }

        return $settings;
    }

    private function get_mode($context) {
        $options = get_option('heartbeat_control_settings');
        return isset($options[$context]['mode']) ? $options[$context]['mode'] : 'allow';
    }

    private function get_frequency($context) {
        $options = get_option('heartbeat_control_settings');
        return isset($options[$context]['frequency']) ? intval($options[$context]['frequency']) : 15;
    }
}

new CWP_Control_Wp_Heartbeat();