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

    /**
     * Constructor.
     */
    public function __construct () {
    }

    public static function register () {
        add_action('publish_' . parent::$prefix . '_team', array(__CLASS__, 'inviteMembers'), 10, 2);

        add_action(parent::$prefix . '_team_member_added', array(__CLASS__, 'addedToTeam'), 10, 2);
        add_action(parent::$prefix . '_team_member_removed', array(__CLASS__, 'removedFromTeam'), 10, 2);
    }

    /**
     * Schedules a notification to be sent to the user.
     *
     * @param int $user_id
     * @param WP_Buoy_Team $team
     */
    public static function addedToTeam ($user_id, $team) {
        add_post_meta($team->post->ID, '_' . parent::$prefix . '_notify', $user_id, false);

        // Call the equivalent of the "status_type" hook since adding
        // a member may have happened after publishing the post itself.
        // This catches any just-added members.
        do_action("{$team->post->post_status}_{$team->post->post_type}", $team->post->ID, $team->post);
    }

    /**
     * Removes any scheduled notices to be sent to the user.
     *
     * @param int $user_id
     * @param WP_Buoy_Team $team
     */
    public static function removedFromTeam ($user_id, $team) {
        delete_post_meta($team->post->ID, '_' . parent::$prefix . '_notify', $user_id);
    }

    /**
     * Invites users added to a team when it is published.
     *
     * @todo Support inviting via other means than email.
     *
     * @uses wp_mail()
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public static function inviteMembers ($post_id, $post) {
        $team = new WP_Buoy_Team($post_id);
        $to_notify = array_unique(get_post_meta($post_id, '_' . parent::$prefix . '_notify'));
        foreach ($to_notify as $user_id) {
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

            delete_post_meta($post_id, '_' . parent::$prefix . '_notify', $user_id);
        }
    }

}
