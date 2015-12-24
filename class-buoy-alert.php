<?php
if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.
/**
 * Alerts are posts that record some incident information such as the
 * location and attached media recordings of what's going on.
 *
 * @author maymay <bitetheappleback@gmail.com>
 * @copyright Copyright (c) 2015-2016 by Meitar "maymay" Moscovitz
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 * @package WordPress\Plugin\WP_Buoy_Plugin\Teams
 */
class WP_Buoy_Alert extends WP_Buoy_Plugin {

    public static function register () {
        register_post_type(parent::$prefix . '_alert', array(
            'label' => __('Incidents', 'buoy'),
            'description' => __('A call for help.', 'buoy'),
            'supports' => array('title'),
            'has_archive' => false,
            'rewrite' => false,
            'can_export' => false,
            'delete_with_user' => true
        ));

        add_action('admin_menu', array(__CLASS__, 'registerAdminMenu'));
    }

    public static function registerAdminMenu () {
        $hooks = array();

        $hooks[] = $hook = add_dashboard_page(
            __('Activate Alert', 'buoy'),
            __('Activate Alert', 'buoy'),
            'read', // give access to all users including Subscribers role
            parent::$prefix . '_activate_alert',
            array(__CLASS__, 'renderActivateAlertPage')
        );
        add_action('load-' . $hook, array(__CLASS__, 'removeScreenOptions'));
        add_action('load-' . $hook, array(__CLASS__, 'addFrontEndScripts'));
        add_action('load-' . $hook, array(__CLASS__, 'addInstallerScripts'));
        add_action('load-' . $hook, array(__CLASS__, 'enqueueFrameworkScripts'));
    }

    public static function renderActivateAlertPage () {
        require_once 'pages/activate-alert.php';
    }

    /**
     * Utility function to remove the WordPress "Screen Options" tab.
     *
     * @todo Move to the main plugin class?
     *
     * @see https://developer.wordpress.org/reference/hooks/screen_options_show_screen/
     *
     * @uses add_filter()
     */
    public static function removeScreenOptions () {
        add_filter('screen_options_show_screen', '__return_false');
    }

    /**
     * Enqueues main alert functionality scripts and styles.
     *
     * @uses get_plugin_data()
     * @uses wp_enqueue_style
     * @uses wp_register_script
     * @uses wp_enqueue_script
     * @uses wp_localize_script
     *
     * @return void
     */
    public static function addFrontEndScripts () {
        $plugin_data = get_plugin_data(plugin_dir_path(__FILE__) . parent::$prefix . '.php');
        wp_enqueue_style(
            parent::$prefix . '-style',
            plugins_url(parent::$prefix . '.css', __FILE__),
            false,
            $plugin_data['Version']
        );
        wp_register_script(
            parent::$prefix . '-script',
            plugins_url(parent::$prefix . '.js', __FILE__),
            array('jquery'),
            $plugin_data['Version']
        );
        wp_localize_script(parent::$prefix . '-script', parent::$prefix . '_vars', self::localizeScript());
        wp_enqueue_script(parent::$prefix . '-script');
    }

    /**
     * Enqueues the "webapp/native" installer scripts if the user has
     * not previously dismissed this functionality.
     *
     * @uses WP_Buoy_User_Settings::get()
     * @uses wp_enqueue_script
     * @uses wp_enqueue_style
     *
     * @return void
     */
    public static function addInstallerScripts () {
        $usropt = new WP_Buoy_User_Settings(get_userdata(get_current_user_id()));
        if (!$usropt->get('installer_dismissed')) {
            wp_enqueue_script(
                parent::$prefix . '-install-webapp',
                plugins_url('includes/install-webapp.js', __FILE__),
                array('jquery')
            );
            wp_enqueue_style(
                parent::$prefix . '-install-webapp',
                plugins_url('includes/install-webapp.css', __FILE__)
            );
        }
    }

    /**
     * Enqueues the Bootstrap CSS and JavaScript framework resources,
     * along with jQuery library plugins used for the Alert UI.
     *
     * @todo Should this kind of utility loader be moved into its own class?
     *
     * @return void
     */
    public static function enqueueFrameworkScripts () {
        // Enqueue jQuery plugins.
        wp_enqueue_style(
            'jquery-datetime-picker',
            plugins_url('includes/jquery.datetimepicker.css', __FILE__)
        );
        wp_enqueue_script(
            'jquery-datetime-picker',
            plugins_url('includes/jquery.datetimepicker.full.min.js', __FILE__),
            array('jquery'),
            null,
            true
        );

        // Enqueue BootstrapCSS/JS framework.
        wp_enqueue_style(
            parent::$prefix . '-bootstrap',
            'https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css'
        );
        wp_enqueue_script(
            parent::$prefix . '-bootstrap',
            'https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js',
            false,
            null,
            true
        );

        // Enqueue a custom pulse loader CSS animation.
        wp_enqueue_style(
            parent::$prefix . '-pulse-loader',
            plugins_url('includes/pulse-loader.css', __FILE__)
        );
    }

    /**
     * Translate user interface strings used in JavaScript.
     *
     * @return string[] An array of translated strings suitable for wp_localize_script().
     */
    public static function localizeScript () {
        $locale_parts = explode('_', get_locale());
        return array(
            'ietf_language_tag' => array_shift($locale_parts),
            'i18n_install_btn_title' => __('Install Buoy', 'buoy'),
            'i18n_install_btn_content' => __('Install Buoy by tapping this button, then choosing "Add to home screen" from the menu.', 'buoy'),
            'i18n_dismiss' => __('Dismiss', 'buoy'),
            'i18n_map_title' => __('Incident Map', 'buoy'),
            'i18n_hide_map' => __('Hide Map', 'buoy'),
            'i18n_show_map' => __('Show Map', 'buoy'),
            'i18n_crisis_location' => __('Location of emergency alert signal', 'buoy'),
            'i18n_missing_crisis_location' => __('Emergency alert signal could not be pinpointed on a map.', 'buoy'),
            'i18n_my_location' => __('My location', 'buoy'),
            'i18n_directions' => __('Directions to here', 'buoy'),
            'i18n_call' => __('Call', 'buoy'),
            'i18n_responding_to_alert' => __('Responding to alert', 'buoy'),
            'i18n_schedule_alert' => __('Schedule alert', 'buoy'),
            'i18n_scheduling_alert' => __('Scheduling alert', 'buoy'),
            'incident_nonce' => wp_create_nonce(parent::$prefix . '-incident-nonce')
        );
    }

}
