<?php

namespace RYSE\GitHubUpdaterDemo;
/**
 * Plugin Name: D365 Popup Manager
 *  * Plugin URI: https://github.com/Ujjawal-bh/plugin-updater
 * Description: Manage popups with time-based visibility, enable/disable functionality, multilingual content, and REST API for mobile app integration.
 * Author: D365
 * Version: 1.0.1
 * Text Domain: d365popup-manager
 * Tested up to:       6.6.1
 * Requires at least:  6.5
 * Requires PHP:       8.0
 * Update URI: https://github.com/Ujjawal-bh/plugin-updater
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class D365Popup_Manager {
    public function __construct() {
        // Register custom post type for popups
        add_action('init', [$this, 'd365_register_popup_post_type']);

        // Add meta boxes for popup settings
        add_action('add_meta_boxes', [$this, 'd365_add_popup_meta_boxes']);
        add_action('save_post', [$this, 'd365_save_popup_meta']);

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'd365_enqueue_admin_assets']);

        // Frontend enque scripts
        add_action('wp_enqueue_scripts', [$this, 'd365_enqueue_assets']);
        

        // REST API endpoint for mobile app
        add_action('rest_api_init', [$this, 'd365_register_rest_api_endpoints']);

        // Frontend popup rendering
        add_action('wp_footer', [$this, 'd365_display_active_popup']);

        add_action('admin_notices', [$this, 'd365_display_admin_notice']);
    }

    /**
     * Register Custom Post Type for Popups
     */
    public function d365_register_popup_post_type() {
        $labels = [
            'name'               => __('Popups', 'd365popup-manager'),
            'singular_name'      => __('Popup', 'd365popup-manager'),
            'add_new'            => __('Add New Popup', 'd365popup-manager'),
            'add_new_item'       => __('Add New Popup', 'd365popup-manager'),
            'edit_item'          => __('Edit Popup', 'd365popup-manager'),
            'new_item'           => __('New Popup', 'd365popup-manager'),
            'view_item'          => __('View Popup', 'd365popup-manager'),
            'all_items'          => __('All Popups', 'd365popup-manager'),
            'search_items'       => __('Search Popups', 'd365popup-manager'),
            'not_found'          => __('No Popups found.', 'd365popup-manager'),
            'not_found_in_trash' => __('No Popups found in Trash.', 'd365popup-manager'),
            'featured_image'     => __( 'D365 Popup Image', 'd365popup-manager' ),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'menu_position'      => 20,
            'menu_icon'          => 'dashicons-megaphone',
            'supports'           => ['title', 'editor', 'thumbnail'],
        ];

        register_post_type('d365popup', $args);
    }

    /**
     * Add Meta Boxes for Popup Settings
     */
    public function d365_add_popup_meta_boxes() {
        add_meta_box(
            'popup_settings',
            __('Popup Settings', 'd365popup-manager'),
            [$this, 'd365_render_popup_settings_meta_box'],
            'd365popup',
            'side'
        );
    }

    /**
     * Render Meta Box for Popup Settings
     */
    public function d365_render_popup_settings_meta_box($post) {
        wp_nonce_field('popup_settings_nonce', 'popup_settings_nonce');

        $enabled = get_post_meta($post->ID, '_popup_enabled', true);
        $start_time = get_post_meta($post->ID, '_popup_start_time', true);
        $end_time = get_post_meta($post->ID, '_popup_end_time', true);

        ?>
        <p>
            <label for="popup_enabled">
                <input type="checkbox" id="popup_enabled" name="popup_enabled" value="1" <?php checked($enabled, '1'); ?> />
                <?php esc_html_e('Enable Popup', 'd365popup-manager'); ?>
            </label>
        </p>
        <p>
            <label for="popup_start_time"><?php esc_html_e('Start Time:', 'd365popup-manager'); ?></label>
            <input type="datetime-local" id="popup_start_time" name="popup_start_time" value="<?php echo esc_attr($start_time); ?>" />
        </p>
        <p>
            <label for="popup_end_time"><?php esc_html_e('End Time:', 'd365popup-manager'); ?></label>
            <input type="datetime-local" id="popup_end_time" name="popup_end_time" value="<?php echo esc_attr($end_time); ?>" />
        </p>
        <?php
    }

    /**
     * Save Popup Meta Data
     */
    public function d365_save_popup_meta($post_id) {
        // Verify nonce
        if (!isset($_POST['popup_settings_nonce']) || !wp_verify_nonce($_POST['popup_settings_nonce'], 'popup_settings_nonce')) {
            return;
        }
    
        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
    
        // Ensure it's the 'popup' post type
        if ('d365popup' !== get_post_type($post_id)) {
            return;
        }
    
        // Get submitted data
        $enabled = isset($_POST['popup_enabled']) ? '1' : '0';
        $start_time = isset($_POST['popup_start_time']) ? sanitize_text_field($_POST['popup_start_time']) : '';
        $end_time = isset($_POST['popup_end_time']) ? sanitize_text_field($_POST['popup_end_time']) : '';
    
        // Validate dates
        if ($enabled === '1' && !empty($start_time) && !empty($end_time)) {
            // Check for overlapping popups
            $args = [
                'post_type'   => 'd365popup',
                'post_status' => 'publish',
                'post__not_in' => [$post_id], // Exclude the current popup
                'meta_query'  => [
                    'relation' => 'AND',
                    [
                        'key'     => '_popup_enabled',
                        'value'   => '1',
                        'compare' => '='
                    ],
                    [
                        'relation' => 'OR',
                        // New start time is between an existing popup's range
                        [
                            'key'     => '_popup_start_time',
                            'value'   => [$start_time, $end_time],
                            'compare' => 'BETWEEN',
                            'type'    => 'DATETIME'
                        ],
                        // New end time is between an existing popup's range
                        [
                            'key'     => '_popup_end_time',
                            'value'   => [$start_time, $end_time],
                            'compare' => 'BETWEEN',
                            'type'    => 'DATETIME'
                        ],
                        // Existing popup fully overlaps the new popup's range
                        [
                            'relation' => 'AND',
                            [
                                'key'     => '_popup_start_time',
                                'value'   => $start_time,
                                'compare' => '<=',
                                'type'    => 'DATETIME'
                            ],
                            [
                                'key'     => '_popup_end_time',
                                'value'   => $end_time,
                                'compare' => '>=',
                                'type'    => 'DATETIME'
                            ],
                        ],
                    ],
                ],
            ];
    
            $query = new WP_Query($args);
    
            if ($query->have_posts()) {
                // Disable the popup
                $enabled = '0';
    
                // Set error transient
                set_transient('popup_time_error', __('A popup already exists within this time range. The popup has been disabled. Please adjust the time range.', 'd365popup-manager'), 30);
            }
        }
    
        // Save meta fields
        update_post_meta($post_id, '_popup_enabled', $enabled);
        update_post_meta($post_id, '_popup_start_time', $start_time);
        update_post_meta($post_id, '_popup_end_time', $end_time);
    
        // If there was an error, redirect back with the error message
        if (get_transient('popup_time_error')) {
            wp_redirect(add_query_arg('popup_time_error', '1', get_edit_post_link($post_id, 'url')));
            exit;
        }
    }
    
    
    
    /**
     * Display Admin Notice for Validation Errors
     */
    public function d365_display_admin_notice() {
        if (isset($_GET['popup_time_error']) && get_transient('popup_time_error')) {
            $error_message = get_transient('popup_time_error');
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($error_message); ?></p>
            </div>
            <?php
            delete_transient('popup_time_error'); // Clean up after showing the message
        }
    }


    /**
     * Enqueue Admin Assets
     */
    public function d365_enqueue_admin_assets($hook) {
        if ('post.php' === $hook || 'post-new.php' === $hook) {
            wp_enqueue_style('popup-manager-admin', plugin_dir_url(__FILE__) . 'assets/css/admin.css');
        }
    }

    public function d365_enqueue_assets() {
        
        wp_enqueue_style('d365-popup-style', plugin_dir_url(__FILE__) . 'assets/css/popup-style.css.css');
        
    }

    /**
     * Register REST API Endpoints
     */
    public function d365_register_rest_api_endpoints() {
        register_rest_route('popup-manager/v1', '/active-popups', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_active_popups'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get Active Popups for REST API
     */
    public function get_active_popups() {
        return $this->d365_fetch_active_popup();
    }

    /**
     * Fetch Active Popup
     */
    private function d365_fetch_active_popup() {
        $args = [
            'post_type'   => 'd365popup',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_query'  => [
                [
                    'key'     => '_popup_enabled',
                    'value'   => '1',
                    'compare' => '=',
                ],
                [
                    'key'     => '_popup_start_time',
                    'value'   => current_time('Y-m-d H:i:s'),
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ],
                [
                    'key'     => '_popup_end_time',
                    'value'   => current_time('Y-m-d H:i:s'),
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ],
            ],
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            $query->the_post();
            $popup = [
                'title' => get_the_title(),
                'content' => apply_filters('the_content', get_the_content()),
                'image' => get_the_post_thumbnail_url(get_the_ID(), 'full'),
                'startdate' => get_post_meta(get_the_ID(), '_popup_start_time', true),
                'enddate' => get_post_meta(get_the_ID(), '_popup_end_time', true),
                'enable' => get_post_meta(get_the_ID(), '_popup_enabled', true),
                'popup_id' => get_the_ID()

            ];

            wp_reset_postdata();
            return $popup;
        }

        wp_reset_postdata();
        return null;
    }

    /**
     * Display Active Popup in Footer
     */
    /**
 * Display Active Popup in Footer
 */
public function d365_display_active_popup() {
    $popup = $this->d365_fetch_active_popup();

    if ($popup) {
        $popup_id = 'd365_popup_' . $popup['popup_id']; // Unique popup identifier
       

        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const modal = document.getElementById('popup-modal');
                const closeButton = document.getElementById('popup-close');
                const popupId = '<?php echo esc_js($popup_id); ?>';

                // Check if the popup was already closed
                if (!document.cookie.split('; ').includes(popupId + '=closed')) {
                    modal.style.display = 'block'; // Show the popup
                }

                // Set the cookie when the popup is closed
                closeButton.addEventListener('click', function () {
                    modal.style.display = 'none';
                    // const expiryDate = new Date();
                    // expiryDate.setTime(expiryDate.getTime() + (30 * 24 * 60 * 60 * 1000)); // 30 days cookie expiry
                    // document.cookie = popupId + '=closed; expires=' + expiryDate.toUTCString() + '; path=/';
                   const endDate = new Date('<?php echo esc_js(get_post_meta($popup['popup_id'], '_popup_end_time', true)); ?>');
                document.cookie = popupId + '=closed; expires=' + endDate.toUTCString() + '; path=/';

                });
            });
        </script>
       <div id="popup-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 600px; width: 90%; padding: 20px; background: #fff; border-radius: 10px; text-align: center; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);">
    <h2 class="popup-title"><?php echo esc_html($popup['title']); ?></h2>
    <div class="popup-content">
    <div class="popup-discription"><?php echo $popup['content']; ?></div>
    <?php if ($popup['image']) : ?>
            <img src="<?php echo esc_url($popup['image']); ?>" alt="<?php echo esc_attr($popup['title']); ?>" class="popup-img" />
        <?php endif; ?>
    </div>
    <button id="popup-close" class="popup-close">×</button>
    </div>
</div>

        <?php
    }
}

}

new D365Popup_Manager();

