<?php
/**
 * Plugin Name: Buoy (a Better Angels crisis response system)
 * Plugin URI: https://github.com/meitar/better-angels
 * Description: A community-based crisis response system. <strong>Like this plugin? Please <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=TJLPJYXHSRBEE&amp;lc=US&amp;item_name=Better%20Angels&amp;item_number=better-angels&amp;currency_code=USD&amp;bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted" title="Send a donation to the developer of Better Angels Buoy">donate</a>. &hearts; Thank you!</strong>
 * Version: 0.1
 * Author: Maymay <bitetheappleback@gmail.com>
 * Author URI: https://maymay.net/
 * Text Domain: buoy
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

if (!defined('WP_BUOY_MIN_PHP_VERSION')) {
    /**
     * The minimum version of PHP needed to run the plugin.
     *
     * This is explicit because WordPress supports even older versions
     * of PHP, so we check the running version on plugin activation.
     *
     * We need PHP 5.3 or later since WP_Buoy_Plugin::error_msg() uses
     * late static binding to get caller information in child classes.
     *
     * @see https://secure.php.net/manual/en/language.oop5.late-static-bindings.php
     */
    define('WP_BUOY_MIN_PHP_VERSION', '5.3');
}

/**
 * Base class that WordPress uses to register and initialize plugin.
 *
 * @author maymay <bitetheappleback@gmail.com>
 * @copyright Copyright (c) 2015-2016 by Meitar "maymay" Moscovitz
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 * @package WordPress\Plugin\WP_Buoy_Plugin
 */
class WP_Buoy_Plugin {

    /**
     * @var string $prefix String to prefix option names, settings, etc.
     *
     * @access public
     */
    public static $prefix = 'buoy';

    /**
     * Entry point for the WordPress framework into plugin code, that
     * registers various hooks.
     *
     * @return void
     */
    public static function register () {
        add_action('plugins_loaded', array(__CLASS__, 'registerL10n'));
        add_action('init', array(__CLASS__, 'initialize'));
        add_action('admin_head', array(__CLASS__, 'addHelpSidebar'));

        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
    }

    /**
     * Loads localization files from plugin's `languages` directory.
     *
     * @return void
     */
    public static function registerL10n () {
        load_plugin_textdomain('buoy', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Loads plugin componentry and calls that component's register()
     * method. Called at the WordPress `init` hook.
     *
     * @uses WP_Buoy_Settings::register();
     * @uses WP_Buoy_Team::register();
     * @uses WP_Buoy_Notification::register();
     * @uses WP_Buoy_User::register();
     * @uses WP_Buoy_Alert::register();
     *
     * @return void
     */
    public static function initialize () {
        require_once 'class-buoy-settings.php';
        require_once 'class-buoy-user-settings.php';
        require_once 'class-buoy-team.php';
        require_once 'class-buoy-notification.php';
        require_once 'class-buoy-user.php';
        require_once 'class-buoy-alert.php';

        require_once 'includes/class-wp-screen-help-loader.php';

        WP_Buoy_Settings::register();
        WP_Buoy_Team::register();
        WP_Buoy_Notification::register();
        WP_Buoy_User::register();
        WP_Buoy_Alert::register();
    }

    /**
     * Method to run when the plugin is activated by a user in the
     * WordPress Dashboard admin screen.
     *
     * This first checks to ensure minimum WordPress and PHP versions
     * have been satisfied. If not, the plugin deactivates and exits.
     *
     * @global $wp_version
     *
     * @uses $wp_version
     * @uses WP_BUOY_MIN_PHP_VERSION
     * @uses WP_Buoy_Plugin::get_minimum_wordpress_version()
     * @uses deactivate_plugins()
     * @uses plugin_basename()
     * @uses WP_Buoy_Settings::activate()
     *
     * @return void
     */
    public static function activate () {
        global $wp_version;
        $min_wp_version = self::get_minimum_wordpress_version();

        if (version_compare(WP_BUOY_MIN_PHP_VERSION, PHP_VERSION) > 0) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(sprintf(
                __('Buoy requires at least PHP version %1$s. You have PHP version %2$s.', 'buoy'),
                WP_BUOY_MIN_PHP_VERSION, PHP_VERSION
            ));
        }
        if (version_compare($min_wp_version, $wp_version) > 0) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(sprintf(
                __('Buoy requires at least WordPress version %1$s. You have WordPress version %2$s.', 'buoy'),
                $min_wp_version, $wp_version
            ));
        }

        require_once 'class-buoy-settings.php';
        $options = WP_Buoy_Settings::get_instance();
        $options->activate();
    }

    /**
     * Returns the "Requires at least" value from plugin's readme.txt.
     *
     * @see https://wordpress.org/plugins/about/readme.txt
     *
     * @return string
     */
    public static function get_minimum_wordpress_version () {
        $lines = @file(plugin_dir_path(__FILE__) . 'readme.txt');
        foreach ($lines as $line) {
            preg_match('/^Requires at least: ([0-9.]+)$/', $line, $m);
            if ($m) {
                return $m[1];
            }
        }
    }

    /**
     * Method to run when the plugin is deactivated by a user in the
     * WordPress Dashboard admin screen.
     */
    public static function deactivate () {
    }

    /**
     * Loads the appropriate document from the localized `help` folder
     * and inserts it as a help tab on the current screen.
     *
     * @uses WP_Screen_Help_loader::applyTabs()
     *
     * @return void
     */
    public static function addHelpTab () {
        $help = new WP_Screen_Help_Loader(plugin_dir_path(__FILE__) . 'help');
        $help->applyTabs();
    }

    /**
     * Appends appropriate sidebar content based on current screen.
     *
     * @uses WP_Screen_Help_Loader::applySidebar()
     *
     * @return void
     */
    public static function addHelpSidebar () {
        $help = new WP_Screen_Help_Loader(plugin_dir_path(__FILE__) . 'help');
        $help->applySidebar();
    }

    /**
     * Prepares an error message for logging.
     *
     * @param string $message
     *
     * @return string
     */
    private static function error_msg ($message) {
        // the "2" is so we get the name of the function that originally called debug_log()
        // This works so long as error_msg() is always called by debug_log()
        return '[' . get_called_class() . '::' . debug_backtrace()[2]['function'] . '()]: ' . $message;
    }

    /**
     * Prints a message to the WordPress `wp-content/debug.log` file
     * if the plugin's "detailed debugging" setting is enabled.
     *
     * @uses WP_Buoy_Settings::get()
     *
     * @param string $message
     *
     * @return void
     */
    protected static function debug_log ($message) {
        if (WP_Buoy_Settings::get_instance()->get('debug')) {
            error_log(static::error_msg($message));
        }
    }

}

WP_Buoy_Plugin::register();
