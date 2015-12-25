<?php
if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.
/**
 * Class that manages interaction between WordPress API and Buoy user
 * settings.
 *
 * @author maymay <bitetheappleback@gmail.com>
 * @copyright Copyright (c) 2015-2016 by Meitar "maymay" Moscovitz
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 * @package WordPress\Plugin\WP_Buoy_Plugin\User
 */
class WP_Buoy_User extends WP_Buoy_Plugin {

    /**
     * The WordPress user.
     *
     * @var WP_User
     */
    private $_user;

    /**
     * The user's plugin settings.
     *
     * @var WP_Buoy_User_Settings
     */
    private $_options;

    /**
     * Constructor.
     *
     * @param int $user_id
     *
     * @return WP_Buoy_User
     */
    public function __construct ($user_id) {
        $this->_user = get_userdata($user_id);
        $this->_options = new WP_Buoy_User_Settings($this->_user);
        return $this;
    }

    /**
     * Alias of WP_Buoy_User::get_gender_pronoun_possessive()
     *
     * @uses WP_Buoy_User::get_gender_pronoun_possessive()
     *
     * @return string
     */
    public function get_pronoun () {
        return $this->get_gender_pronoun_possessive();
    }

    /**
     * Gets the possessive gender pronoun of a user.
     *
     * @uses WP_Buoy_User::get_option()
     *
     * @return string
     */
    public function get_gender_pronoun_possessive () {
        return $this->get_option('gender_pronoun_possessive', __('their', 'buoy'));
    }

    /**
     * Gets the value of a user option they have set.
     *
     * @uses WP_Buoy_User_Settings::get()
     *
     * @param string $name
     * @param mixed $default
     *
     * @return mixed
     *
     * @access private
     */
    private function get_option ($name, $default = null) {
        return $this->_options->get($name, $default);
    }

    /**
     * Registers user-related WordPress hooks.
     *
     * @uses WP_Buoy_Plugin::addHelpTab()
     *
     * @return void
     */
    public static function register () {
        add_action('load-profile.php', array('WP_Buoy_Plugin', 'addHelpTab'));
        add_action('show_user_profile', array(__CLASS__, 'renderProfile'));
        add_action('personal_options_update', array(__CLASS__, 'saveProfile'));
    }

    /**
     * Prints the HTML for the custom profile fields.
     *
     * @param WP_User $profileuser
     *
     * @uses WP_Buoy_User_Settings::get()
     *
     * @return void
     */
    public static function renderProfile ($profileuser) {
        $options = new WP_Buoy_User_Settings($profileuser);
        require_once 'pages/profile.php';
    }

    /**
     * Saves profile field values to the database on profile update.
     *
     * @global $_POST Used to access values submitted by profile form.
     *
     * @param int $user_id
     *
     * @uses WP_Buoy_User_Settings::set()
     * @uses WP_Buoy_User_Settings::save()
     *
     * @return void
     */
    public static function saveProfile ($user_id) {
        $options = new WP_Buoy_User_Settings(get_userdata($user_id));
        $options
            ->set('gender_pronoun_possessive', sanitize_text_field($_POST[WP_Buoy_Plugin::$prefix.'_gender_pronoun_possessive']))
            ->set('phone_number', sanitize_text_field($_POST[WP_Buoy_Plugin::$prefix . '_phone_number']))
            ->set('sms_provider', sanitize_text_field($_POST[WP_Buoy_Plugin::$prefix . '_sms_provider']))
            ->set('crisis_message', sanitize_text_field($_POST[WP_Buoy_Plugin::$prefix . '_crisis_message']))
            ->set('public_responder', (isset($_POST[WP_Buoy_Plugin::$prefix . '_public_responder'])) ? true : false)
            ->save();
    }

}
