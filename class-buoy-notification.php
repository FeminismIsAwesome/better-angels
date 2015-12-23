<?php
if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.
/**
 * Class responsible for sending notifications triggered by the right
 * events via the right mechanisms.
 *
 * @author maymay <bitetheappleback@gmail.com>
 * @copyright Copyright (c) 2015-2016 by Meitar "maymay" Moscovitz
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 * @package WordPress\Plugin\WP_Buoy_Plugin\Notifications
 */
class WP_Buoy_Notification extends WP_Buoy_Plugin {

    public function __construct () {
    }

    public static function register () {
        add_action(parent::$prefix . '_team_member_added', array(__CLASS__, 'addedToTeam'), 10, 2);
    }

    /**
     * Notifies a user that they have been added to a team.
     *
     * Sends a notification (by email) asking a user for confirmation
     * to join a response team.
     *
     * @todo Support sending notices via mechanisms other than email.
     *
     * @param int $user_id
     * @param WP_Buoy_Team $team
     */
    public static function addedToTeam ($user_id, $team) {
        // TODO: Where should this function that gets a pronoun go?
        $pronoun = get_user_meta($team->author->ID, parent::$prefix . '_pronoun', true);
        if (!$pronoun) {
            $pronoun = __('their', 'buoy');
        }

        $subject = sprintf(
            __('%1$s wants you to join %2$s crisis response team.', 'buoy'),
            $team->author->display_name, $pronoun
        );
        // TODO: Write a better message.
        $msg = admin_url(
            'edit.php?post_type=' . parent::$prefix . '_team&page=' . parent::$prefix . '_team_membership'
        );
        $user = get_userdata($user_id);
        wp_mail($user->user_email, $subject, $msg);
    }

    /**
     * Sends a notification.
     */
    public function send () {
    }

}
