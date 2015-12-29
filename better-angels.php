<?php
/**
 * This is the old original code, now deprecated, will be removed in next merge.
 *
 * @deprecated
 */

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

class BetterAngelsPlugin {

    private $default_alert_ttl_num = 2;
    private $default_alert_ttl_multiplier = DAY_IN_SECONDS;

    private $Error; //< WP_Error object

    public function __construct () {
        add_action('wp_ajax_' . $this->prefix . 'upload-media', array($this, 'handleMediaUpload'));

        add_action('update_option_' . $this->prefix . 'settings', array($this, 'updatedSettings'), 10, 2);

        add_action($this->prefix . 'delete_old_alerts', array($this, 'deleteOldAlerts'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function activate () {
        $options = get_option($this->prefix . 'settings');
        if (false === $options || empty($options['safety_info'])) {
            $options['safety_info'] = file_get_contents(dirname(__FILE__) . '/includes/default-safety-information.html');
        }
        if (!isset($options['alert_ttl_num']) || is_null($options['alert_ttl_num']) || 0 === $options['alert_ttl_num']) {
            $options['alert_ttl_num'] = $this->default_alert_ttl_num;
        }
        if (!isset($options['alert_ttl_multiplier']) || is_null($options['alert_ttl_multiplier']) || 0 === $options['alert_ttl_multiplier']) {
            $options['alert_ttl_multiplier'] = DAY_IN_SECONDS;
        }
        update_option($this->prefix . 'settings', $options);

        $this->updateSchedules(
            '',
            $this->get_alert_ttl_string($options['alert_ttl_num'], $options['alert_ttl_multiplier'])
        );
    }

    private function time_multiplier_to_unit ($num) {
        switch ($num) {
            case HOUR_IN_SECONDS:
                return 'hours';
            case WEEK_IN_SECONDS:
                return 'weeks';
            case DAY_IN_SECONDS:
            default:
                return 'days';
        }
    }

    public function deactivate () {
        $options = get_option($this->prefix . 'settings');
        do_action($this->prefix . 'delete_old_alerts');
        wp_clear_scheduled_hook($this->prefix . 'delete_old_alerts');       // clear hook with no args
        wp_clear_scheduled_hook($this->prefix . 'delete_old_alerts', array( // and also with explicit args
            $this->get_alert_ttl_string($options['alert_ttl_num'], $options['alert_ttl_multiplier'])
        ));
    }

    /**
     * Deletes posts older than a certain threshold and possibly their children
     * (media attachments) from the database.
     *
     * @param string $threshold A strtotime()-compatible string indicating some time in the past.
     * @uses get_option() to check the value of this plugin's `delete_old_incident_media` setting for whether to delete attachments (child posts), too.
     * @return void
     */
    public function deleteOldAlerts ($threshold) {
        $options = get_option($this->prefix . 'settings');
        $threshold = empty($threshold)
            ? $this->get_alert_ttl_string($options['alert_ttl_num'], $options['alert_ttl_multiplier'])
            : $threshold;
        $wp_query_args = array(
            'post_type' => str_replace('-', '_', $this->prefix) . 'alert',
            'date_query' => array(
                'column' => 'post_date',
                'before' => $threshold,
                'inclusive' => true
            ),
            'fields' => 'ids'
        );
        $query = new WP_Query($wp_query_args);
        foreach ($query->posts as $post_id) {
            $attached_posts_by_type = array();
            $types = array('image', 'audio', 'video');
            if (!empty($options['delete_old_incident_media'])) {
                foreach ($types as $type) {
                    $attached_posts_by_type[$type] = get_attached_media($type, $post_id);
                }
                foreach ($attached_posts_by_type as $type => $posts) {
                    foreach ($posts as $post) {
                        if (!wp_delete_post($post->ID, true)) {
                            $this->debug_log(sprintf(
                                __('Failed to delete attachment post %1$s (child of %2$s) during %3$s', 'better-angels'),
                                $post->ID,
                                $post_id,
                                __FUNCTION__ . '()'
                            ));
                        }
                    }
                }
            }
            if (!wp_delete_post($post_id, true)) {
                $this->debug_log(sprintf(
                    __('Failed to delete post with ID %1$s during %2$s', 'better-angels'),
                    $post_id,
                    __FUNCTION__ . '()'
                ));
            }
        }
    }

    /**
     * Handles modifying various WordPress settings based on a plugin settings update.
     *
     * @param array $old
     * @param array $new
     * @return void
     */
    public function updatedSettings ($old, $new) {
        $this->updateSchedules(
            $this->get_alert_ttl_string($old['alert_ttl_num'], $old['alert_ttl_multiplier']),
            $this->get_alert_ttl_string($new['alert_ttl_num'], $new['alert_ttl_multiplier'])
        );
    }

    private function get_alert_ttl_string ($num, $multiplier, $past = true) {
        $str = intval($num) . ' ' . $this->time_multiplier_to_unit($multiplier);
        return ($past) ? '-' . $str : $str;
    }

    private function updateSchedules ($old_str, $new_str) {
        if (wp_next_scheduled($this->prefix . 'delete_old_alerts', array($old_str))) {
            wp_clear_scheduled_hook($this->prefix . 'delete_old_alerts', array($old_str));
        }
        wp_schedule_event(
            time() + HOUR_IN_SECONDS,
            'hourly',
            $this->prefix . 'delete_old_alerts',
            array($new_str)
        );
    }

    public function handleMediaUpload () {
        check_ajax_referer($this->prefix . 'incident-nonce', $this->prefix . 'nonce');

        $post = $this->getAlert($_GET[$this->prefix . 'incident_hash']);
        $keys = array_keys($_FILES);
        $k  = array_shift($keys);
        $id = media_handle_upload($k, $post->ID);
        $m = wp_get_attachment_metadata($id);
        $this->debug_log(sprintf(
            __('Uploaded media metadata: %s', 'better-angels'),
            print_r($m, true)
        ));
        if (is_wp_error($id)) {
            wp_send_json_error($id);
        } else {
            $mime_type = null;
            if (isset($m['mime_type'])) {
                $mime_type = $m['mime_type'];
            } else if (isset($m['sizes'])) {
                $mime_type = $m['sizes']['thumbnail']['mime-type'];
            } else {
                $mime_type = 'audio/*';
            }
            $media_type = substr($mime_type, 0, strpos($mime_type, '/'));
            $html = $this->getIncidentMediaHtml($media_type, $id);
            $resp = array(
                'id' => $id,
                'media_type' => ('application' === $media_type) ? 'audio' : $media_type,
                'html' => $html
            );
            $this->debug_log(sprintf(
                __('Sending JSON success message: %s', 'better-angels'),
                print_r($resp, true)
            ));
            wp_send_json_success($resp);
        }
    }

    /**
     * Get the guardians (resonse team members who receive alerts) for a given user.
     *
     * @return array An array of WP_User objects.
     */
    public function getGuardians ($user_id) {
        $team = $this->getResponseTeam($user_id);
        $guardians = array();
        foreach ($team as $u) {
            $info = $u->{$this->prefix . 'guardian_info'};
            if (!empty($info['receive_alerts']) && !empty($info['confirmed'])) {
                $guardians[] = $u;
            }
        }
        return $guardians;
    }

    /**
     * Checks to see if a user account is on the response team of a user.
     *
     * @param string $guardian_ID The WP user ID of the account to check.
     * @param string $user_id The WP user ID of the user whose team to check.
     * @return bool True if $guardian_login is the username of a team member for the current user.
     */
    public function isGuardian ($guardian_id, $user_id) {
        $team = $this->getResponseTeam($user_id);
        foreach ($team as $user) {
            if ($guardian_id === $user->ID) {
                return true;
            }
        }
        return false;
    }

    /**
     * Adds a user as a guardian for another user.
     *
     * @param int $guardian_id The WP user ID of the user who is set as a guardian.
     * @param int $user_id The WP user ID of the user being guarded.
     * @return mixed
     */
    public function addGuardian ($guardian_id, $user_id) {
        $err = new WP_Error();
        if (in_array($guardian_id, get_user_meta($user_id, $this->prefix . 'guardians'))) {
            $err->add(
                'duplicate-guardian',
                __('Cannot add the same user as a guardian twice.', 'better-angels'),
                $guardian_id
            );
        }
        if (false === get_userdata($guardian_id)) {
            $err->add(
                'no-such-user',
                __('No such user account.', 'better-angels'),
                $guardian_id
            );
        }
        if (get_current_user_id() == $guardian_id || $user_id === $guardian_id) {
            $err->add(
                'cannot-guard-self',
                __('Cannot add yourself as your own guardian.', 'better-angels'),
                $guardian_id
            );
        }

        if (empty($err->errors)) {
            add_user_meta($user_id, $this->prefix . 'guardians', $guardian_id);
            $gk = $this->prefix . 'guardian_' . $guardian_id . '_info';
            add_user_meta($user_id, $gk, array('confirmed' => false));
        } else {
            return $err;
        }
    }

    public function getGuardianInfo ($guardian_id, $user_id) {
        $info = get_user_meta($user_id, $this->prefix . 'guardian_' . $guardian_id . '_info', true);
        if (is_array($info) && !array_key_exists('confirmed', $info)) {
            $info['confirmed'] = false;
        }
        return $info;
    }

    public function setGuardianInfo ($guardian_id, $user_id, $info_arr) {
        $cur_info = $this->getGuardianInfo($guardian_id, $user_id);
        $new_info = array_replace($cur_info, $info_arr);
        return update_user_meta($user_id, $this->prefix . 'guardian_' . $guardian_id . '_info', $new_info);
    }

    /**
     * Removes a user from being the guardian of another user.
     *
     * @param string $guardian_id WP user name of the account to remove.
     * @param int $user_id WP user ID number of the team owner.
     * @return void
     */
    public function removeGuardian ($guardian_id, $user_id) {
        delete_user_meta($user_id, $this->prefix . 'guardians', $guardian_id);
        delete_user_meta($user_id, $this->prefix . 'guardian_' . $guardian_id . '_info');
    }

    /**
     * Get an array of users who are on a particular user's team.
     *
     * @param int $user_id User ID of the team owner.
     * @return array List of WP_User objects comprising all users.
     */
    public function getResponseTeam ($user_id) {
        $full_team = array_map(
            'get_userdata', get_user_meta($user_id, $this->prefix . 'guardians')
        );
        foreach ($full_team as $k => $g) {
            $info = $this->getGuardianInfo($g->ID, $user_id);
            $prop = $this->prefix . 'guardian_info';
            $full_team[$k]->$prop = $info;
        }
        return $full_team;
    }

    private function updateChooseAngels ($request) {
        $user_id = get_current_user_id();
        $wp_user = get_userdata($user_id);

        // Anything to edit/toggle type?
        if (!empty($request[$this->prefix . 'guardian'])) {
            foreach ($request[$this->prefix . 'guardian'] as $id => $data) {
                $this->setGuardianInfo(
                    $id,
                    $user_id,
                    array('receive_alerts' => (bool)$data['receive_alerts'])
                );
            }
        }

        // Anything to add?
        if (!empty($request[$this->prefix . 'add_guardian'])) {
            $ginfo = (isset($request[$this->prefix . 'is_fake_guardian']))
                ? array('receive_alerts' => false) : array('receive_alerts' => true);
            $guardian_id = username_exists($request[$this->prefix . 'add_guardian']);
        }
    }

}

new BetterAngelsPlugin();
