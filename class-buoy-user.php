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
     * Constructor.
     */
    public function __construct () {
    }

    /**
     * Registers user-related WordPress hooks.
     *
     * @return void
     */
    public static function register () {
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
