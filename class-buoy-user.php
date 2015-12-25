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
     * The user's teams.
     *
     * @var WP_Buoy_Team[]
     */
    private $_teams;

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
     * Gets the user's teams.
     *
     * @return WP_Buoy_Team[]
     */
    public function get_teams () {
        $ids = get_posts(array(
            'post_type' => parent::$prefix . '_team',
            'author' => $this->_user->ID,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));

        $teams = array();
        foreach ($ids as $id) {
            $teams[] = new WP_Buoy_Team($id);
        }
        $this->_teams = $teams;

        return $this->_teams;
    }

    /**
     * Checks whether or not the user has at least one responder.
     *
     * A "responder" in this context is a "confirmed" team member.
     * At least one responder is needed before the "Activate Alert"
     * screen will be of any use, obviously. This looks for confirmed
     * members on any of the user's teams and returns as soon as it
     * can find one.
     *
     * @uses WP_Buoy_Team::has_responder()
     *
     * @return bool
     */
    public function has_responder () {
        if (null === $this->_teams) {
            $this->get_teams();
        }
        // We need a loop here because, unless we use straight SQL,
        // we can't do a REGEXP compare on the `meta_key`, only the
        // `meta_value` itself. There's an experimental way to do it
        // over on Stack Exchange but this is more standard for now.
        //
        // See https://wordpress.stackexchange.com/a/193841/66139
        foreach ($this->_teams as $team) {
            if ($team->has_responder()) {
                return true;
            }
        }
        return false;
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

        add_action(parent::$prefix . '_team_emptied', array(__CLASS__, 'warnIfNoResponder'));
    }

    /**
     * Sends a warning to a user if they no longer have responders.
     *
     * @uses WP_Buoy_User::hasResponders()
     *
     * @param WP_Buoy_Team $team The team that has been emptied.
     *
     * @return bool
     */
    public static function warnIfNoResponder ($team) {
        $buoy_user = new self($team->author->ID);
        if (false === $buoy_user->has_responder()) {
            // TODO: This should be a bit cleaner. Maybe part of the WP_Buoy_Notification class?
            $subject = __('You no longer have crisis responders.', 'buoy');
            $msg = __('Either you have removed the last of your Buoy crisis response team members, or they have all left your teams. You will not be able to send a Buoy alert to anyone until you add more people to your team(s).', 'buoy');
            wp_mail($buoy_user->_user->user_email, $subject, $msg);
        }
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
