<?php
if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.
/**
 * Class responsible for handling plugin options, getting and setting
 * options from the rest of the plugin.
 *
 * @author maymay <bitetheappleback@gmail.com>
 * @copyright Copyright (c) 2015-2016 by Meitar "maymay" Moscovitz
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 * @package WordPress\Plugin\WP_Buoy_Plugin\Settings
 */
class WP_Buoy_Settings {

    /**
     * Singleton.
     *
     * @var WP_Buoy_Settings
     */
    private static $instance;

    /**
     * Database meta key name.
     *
     * @var string
     */
    private $meta_key;

    /**
     * Current settings.
     *
     * @var array
     */
    private $options;

    /**
     * List of default values for settings.
     *
     * @var array
     */
    private $default;

    /**
     * @return WP_Buoy_Settings
     */
    private function __construct () {
        $this->meta_key = WP_Buoy_Plugin::$prefix . '_settings';
        $this->options  = $this->get_options();
        $this->default  = array(
            'alert_ttl_num' => 2,
            'alert_ttl_multiplier' => DAY_IN_SECONDS,
            'safety_info' => file_get_contents(plugin_dir_path(__FILE__) . 'includes/default-safety-information.html'),
            'future_alerts' => false,
            'delete_old_incident_media' => false,
            'debug' => false
        );
        return $this;
    }

    /**
     * @return mixed
     */
    private function get_options () {
        return get_option($this->meta_key, null);
    }

    public function __get ($name) {
        return $this->$name;
    }

    /**
     * Saves default plugin options to the database when the plugin
     * is activated by a user without overwriting existing values.
     * 
     * @used-by WP_Buoy_Plugin::activate()
     *
     * @return void
     */
    public function activate () {
        foreach ($this->default as $k => $v) {
            if (!$this->has($k)) { $this->set($k, $v); }
        }
        $this->save();
    }

    /**
     * @return void
     */
    public static function register () {
        add_action('admin_init', array(__CLASS__, 'registerSettings'));
        add_action('admin_menu', array(__CLASS__, 'registerAdminMenu'));
    }

    /**
     * Gets the instance of this object.
     *
     * @return WP_Buoy_Settings
     */
    public static function get_instance () {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get ($key, $default = null) {
        return ($this->has($key)) ? $this->options[$key] : $default;
    }

    /**
     * @return WP_Buoy_Settings
     */
    public function set ($key, $value) {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * @return bool
     */
    public function has ($key) {
        return isset($this->options[$key]);
    }

    /**
     * @return WP_Buoy_Settings
     */
    public function save () {
        update_option($this->meta_key, $this->options);
        return $this;
    }

    /**
     * @return WP_Buoy_Settings
     */
    public function delete ($key) {
        unset($this->options[$key]);
        return $this;
    }

    /**
     * Registers the plugin setting with WordPress. All the plugin's
     * options are stored in a serialized array. This means there is
     * only one record in the WordPress options table in the database
     * to record all the plugin's settings.
     *
     * @see https://codex.wordpress.org/Settings_API
     *
     * @return void
     */
    public static function registerSettings () {
        register_setting(
            WP_Buoy_Plugin::$prefix . '_settings',
            WP_Buoy_Plugin::$prefix . '_settings',
            array(__CLASS__, 'validateSettings')
        );
    }

    /**
     * WordPress validation callback for the Settings API hook.
     *
     * @see https://codex.wordpress.org/Settings_API
     *
     * @return array
     */
    public function validateSettings ($input) {
        // TODO: Refactor this, maybe can do better since the array
        //       of valid options are all in the self::default var.
        $options = self::get_instance();
        $safe_input = array();
        foreach ($input as $k => $v) {
            switch ($k) {
                case 'safety_info':
                    $safe_input[$k] = force_balance_tags($v);
                    break;
                case 'alert_ttl_num':
                    if ($v > 0) {
                        $safe_input[$k] = intval($v);
                    } else {
                        $safe_input[$k] = $options->default['alert_ttl_num'];
                    }
                    break;
                case 'alert_ttl_multiplier':
                case 'future_alerts':
                case 'delete_old_incident_media':
                case 'debug':
                    $safe_input[$k] = intval($v);
                    break;
            }
        }
        return $safe_input;
    }

    /**
     * @see https://developer.wordpress.org/reference/hooks/menu_order/
     *
     * @return void
     */
    public static function registerAdminMenu () {
        $hooks = array();

        $hooks[] = add_options_page(
            __('Buoy Settings', 'buoy'),
            __('Buoy', 'buoy'),
            'manage_options',
            WP_Buoy_Plugin::$prefix . '_settings',
            array(__CLASS__, 'renderOptionsPage')
        );

        $hooks[] = add_submenu_page(
            'edit.php?post_type=' . WP_Buoy_Plugin::$prefix . '_team',
            __('Safety information', 'buoy'),
            __('Safety information', 'buoy'),
            'read',
            WP_Buoy_Plugin::$prefix . '_safety_info',
            array(__CLASS__, 'renderSafetyInfoPage')
        );

        add_filter('custom_menu_order', '__return_true');
        add_filter('menu_order', array(__CLASS__, 'reorderSubmenu'));
    }

    /**
     * Ensure "Safety information" is last in "My Teams" submenu list.
     *
     * @global array $submenu
     *
     * @param array $menu_order The top-level admin menu order. Returned unchanged.
     *
     * @return array
     */
    public static function reorderSubmenu ($menu_order) {
        global $submenu;
        $find = 'edit.php?post_type=' . WP_Buoy_Plugin::$prefix . '_team';
        if (isset($submenu[$find])) {
            foreach ($submenu[$find] as $k => $v) {
                if (in_array(WP_Buoy_Plugin::$prefix . '_safety_info', $v)) {
                    unset($submenu[$find][$k]);
                    $submenu[$find][9999] = $v;
                }
            }
        }
        return $menu_order;
    }

    /**
     * @return void
     */
    public static function renderOptionsPage () {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'buoy'));
        }
        require 'pages/options.php';
    }

    /**
     * @return void
     */
    public static function renderSafetyInfoPage () {
        if (!current_user_can('read')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'better-angels'));
        }
        $options = self::get_instance();
        print $options->get('safety_info'); // TODO: Can we harden this against XSS more?
    }

}
