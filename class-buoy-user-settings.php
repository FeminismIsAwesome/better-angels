<?php
if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.
/**
 * Class responsible for handling plugin options, getting and setting
 * options from the rest of the plugin.
 *
 * @author maymay <bitetheappleback@gmail.com>
 * @copyright Copyright (c) 2015-2016 by Meitar "maymay" Moscovitz
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 * @package WordPress\Plugin\WP_Buoy_Plugin\User\Settings
 */
class WP_Buoy_User_Settings {

    /**
     * The user associated with these settings.
     *
     * @var WP_User
     */
    private $user;

    /**
     * User's current settings.
     *
     * @var array
     */
    private $options;

    /**
     * List of default values for user profile settings.
     *
     * @todo Possible values should not be part of default values.
     *
     * @var array
     */
    private $default = array(
        // option name              => default/possible values
        'phone_number'              => '',
        'gender_pronoun_possessive' => '',
        'crisis_message'            => '',
        'public_responder'          => false,
        'sms_provider'              => array(
            '',
            'AT&T',
            'Alltel',
            'Boost Mobile',
            'Cricket',
            'Metro PCS',
            'Nextel',
            'Ptel',
            'Qwest',
            'Sprint',
            'Suncom',
            'T-Mobile',
            'Tracfone',
            'U.S. Cellular',
            'Verizon',
            'Virgin Mobile'
        )
    );

    /**
     * Constructor.
     *
     * @param WP_User $profileuser
     *
     * @return WP_Buoy_User_Settings
     */
    public function __construct ($profileuser) {
        $this->user = $profileuser;
        $this->options = $this->get_options();
        return $this;
    }

    public function __get ($name) {
        return $this->$name;
    }

    /**
     * Retrieves user's Buoy settings from the WordPress database.
     *
     * @return array
     */
    private function get_options () {
        $opts = array();
        foreach ($this->default as $k => $v) {
            $opts[$k] = get_user_meta($this->user->ID, WP_Buoy_Plugin::$prefix . '_' . $k, true);
        }
        return $opts;
    }

    /**
     * Retrieves a user option.
     *
     * @param string $key
     * @param mixed $default The value to return if the option doesn't exist.
     *
     * @return mixed The current option value, or the $default parameter if the option doesn't exist.
     */
    public function get ($key, $default = null) {
        return ($this->has($key)) ? $this->options[$key] : $default;
    }

    /**
     * Sets a user option.
     *
     * Returns the current instance for chaining.
     *
     * @return WP_Buoy_User_Settings
     */
    public function set ($key, $value) {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * Checks whether a given option name is set.
     *
     * @param string $key The option name.
     *
     * @return bool
     */
    public function has ($key) {
        return isset($this->options[$key]);
    }

    /**
     * Saves the current options to the database in user meta fields.
     *
     * Multiple user meta fields are used so that the WP database is
     * more easily queryable to allow, for example, finding all users
     * whose phone company is Verizon. This aids in debugging issues.
     *
     * Returns the current instance for chaining.
     *
     * @uses WP_Buoy_User_Settings::$options
     *
     * @return WP_Buoy_User_Settings
     */
    public function save () {
        foreach ($this->options as $k => $v) {
            update_user_meta($this->user->ID, WP_Buoy_Plugin::$prefix . '_' . $k, $v);
        }
        return $this;
    }

    /**
     * Removes 
     *
     * Returns the current instance for chaining, so you can do stuff
     * like removing all user options upon plugin uninstallation:
     *
     * ```php
     * $useropts = new WP_Buoy_User_Settings();
     * foreach ($useropts->options as $k => $v) {
     *     $useropts->delete($k)->save();
     * }
     * ```
     *
     * @return WP_Buoy_User_Settings
     */
    public function delete ($key) {
        unset($this->options[$key]);
        return $this;
    }

}
