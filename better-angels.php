<?php
/**
 * Plugin Name: Buoy (a Better Angels first responder system)
 * Plugin URI: https://github.com/meitar/better-angels
 * Description: Tell your friends where you are and what you need. (A community-driven emergency first responder system.) <strong>Like this plugin? Please <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=TJLPJYXHSRBEE&amp;lc=US&amp;item_name=Better%20Angels&amp;item_number=better-angels&amp;currency_code=USD&amp;bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted" title="Send a donation to the developer of Better Angels">donate</a>. &hearts; Thank you!</strong>
 * Version: 0.1
 * Author: Maymay <bitetheappleback@gmail.com>
 * Author URI: http://maymay.net/
 * Text Domain: better-angels
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

class BetterAngelsPlugin {

    private $prefix = 'better-angels_'; //< Internal prefix for settings, etc., derived from shortcode.
    private $incident_hash; //< Hash of the current incident ("alert").
    private $chat_room_name; //< The name of the chat room for this incident.

    private $default_alert_ttl_num = 2;
    private $default_alert_ttl_multiplier = DAY_IN_SECONDS;

    private $Error; //< WP_Error object

    public function __construct () {
        $this->Error = new WP_Error();

        add_action('admin_init', array($this, 'configureCron'));
        add_action('send_headers', array($this, 'redirectShortUrl'));
        add_action('wp_before_admin_bar_render', array($this, 'addIncidentMenu'));

        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueMapsScripts'));
        add_action('admin_head-dashboard_page_' . $this->prefix . 'activate-alert', array($this, 'doAdminHeadActivateAlert'));
        add_action('admin_notices', array($this, 'showAdminNotices'));

        add_action('wp_ajax_' . $this->prefix . 'schedule-alert', array($this, 'handleScheduledAlert'));
        add_action('wp_ajax_' . $this->prefix . 'unschedule-alert', array($this, 'handleUnscheduleAlert'));
        add_action('wp_ajax_' . $this->prefix . 'update-location', array($this, 'handleLocationUpdate'));
        add_action('wp_ajax_' . $this->prefix . 'upload-media', array($this, 'handleMediaUpload'));
        add_action('wp_ajax_' . $this->prefix . 'dismiss-installer', array($this, 'handleDismissInstaller'));

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

    public function configureCron () {
        $options = get_option($this->prefix . 'settings');
        $path_to_wp_cron = ABSPATH . 'wp-cron.php';
        $os_cronjob_comment = '# Buoy WordPress Plugin Cronjob';
        require_once plugin_dir_path(__FILE__) . 'includes/crontab-manager.php';
        $C = new BuoyCrontabManager();
        $os_cron = false;
        foreach ($C->getCron() as $line) {
            if (strpos($line, $path_to_wp_cron)) {
                $os_cron = true;
                break;
            }
        }
        if (empty($options['future_alerts']) && $os_cron) {
            try {
                $C->removeCronJobs("/$os_cronjob_comment/");
            } catch (Exception $e) {
                $this->Error->add(
                    'crontab-remove-jobs',
                    __('Error removing system crontab jobs for timed alerts.', 'better-angels')
                    . PHP_EOL . $e->getMessage(),
                    'error'
                );
            }
        } else if (!empty($options['future_alerts']) && !$os_cron) {
            // TODO: Variablize the frequency
            $job = '*/5 * * * * php ' . $path_to_wp_cron . ' >/dev/null 2>&1 ' . $os_cronjob_comment;
            try {
                $C->appendCronJobs($job)->save();
            } catch (Exception $e) {
                $this->Error->add(
                    'crontab-add-jobs',
                    __('Error installing system cronjob for timed alerts.', 'better-angels')
                    . PHP_EOL . $e->getMessage(),
                    'error'
                );
            }
        }
    }

    /**
     * The "activate alert" screen is intended to be the web app "install"
     * screen for Buoy. We insert special mobile browser specific tags in
     * order to create a native-like "installer" for the user. We only want
     * to do this on this specific screen.
     */
    public function doAdminHeadActivateAlert () {
        print '<meta name="mobile-web-app-capable" content="yes" />';       // Android/Chrome
        print '<meta name="apple-mobile-web-app-capable" content="yes" />'; // Apple/Safari
        print '<meta name="apple-mobile-web-app-status-bar-style" content="black" />';
        print '<meta name="apple-mobile-web-app-title" content="' . esc_attr('Buoy', 'better-angels') . '" />';
        print '<link rel="apple-touch-icon" href="' . plugins_url('img/apple-touch-icon-152x152.png', __FILE__) . '" />';
        // TODO: This isn't showing up, figure out why.
        //print '<link rel="apple-touch-startup-image" href="' . plugins_url('img/apple-touch-startup.png', __FILE__) . '">';
    }

    public function enqueueMapsScripts ($hook) {
        $to_hook = array( // list of pages where maps API is needed
            'dashboard_page_' . $this->prefix . 'incident-chat',
            'dashboard_page_' . $this->prefix . 'review-alert'
        );
        if ($this->isAppPage($hook, $to_hook)) {
            wp_enqueue_script(
                $this->prefix . 'maps-api',
                'https://maps.googleapis.com/maps/api/js?language=' . get_locale(),
                $this->prefix . 'script',
                null, // do not set a WP version!
                true
            );
        }
    }

    public function showAdminNotices () {
        foreach ($this->Error->get_error_codes() as $err_code) {
            foreach ($this->Error->get_error_messages($err_code) as $err_msg) {
                $class = 'notice is-dismissible';
                if ($err_data = $this->Error->get_error_data($err_code)) {
                    $class .= " $err_data";
                }
                print '<div class="' . esc_attr($class) . '"><p>' . nl2br(esc_html($err_msg)) . '</p></div>';
            }
        }
    }

    public function enqueueAdminScripts ($hook) {
        // Always enqueue this script to ensure iOS Webapp-style launches
        // remain within the webapp capable shell. Otherwise, navigating
        // to a page outside "our app" (like the WP profile page) will make
        // any subsequent navigation return to the built-in iOS Mobile Safari
        // browser, which is a confusing user experience for a user who has
        // "installed" Buoy.
        wp_enqueue_script(
            $this->prefix . 'stay-standalone',
            plugins_url('includes/stay-standalone.js', __FILE__)
        );

        $to_hook = array( // list of pages where Bootstrap CSS+JS, certain jQuery is needed
            'dashboard_page_' . $this->prefix . 'activate-alert',
            'dashboard_page_' . $this->prefix . 'incident-chat'
        );
    }

    /**
     * Checks to see if the current page, called by a WordPress hook,
     * is one of the "app pages" where important functionality provided
     * by this plugin occurrs. Used to check whether or not to enqueue
     * certain additional, heavyweight assets, like BootstrapCSS.
     *
     * @param string $hook The hook name that called this page. (Set by WordPress.)
     * @param array $matches Optional list of hook names that should be matched against, useful for checking against a single hook.
     * @return bool True if the page is "one of ours," false otherwise.
     */
    private function isAppPage ($hook, $matches = array()) {
        $our_hooks = array(
            'dashboard_page_' . $this->prefix . 'activate-alert',
            'dashboard_page_' . $this->prefix . 'incident-chat',
            'dashboard_page_' . $this->prefix . 'review-chat',
        );

        if (0 < count($matches)) { $our_hooks = $matches; }

        foreach ($our_hooks as $the_hook) {
            if ($the_hook === $hook) {
                return true;
            }
        }
        return false;
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

    public function handleScheduledAlert () {
        check_ajax_referer($this->prefix . 'activate-alert', $this->prefix . 'nonce');
        $err = new WP_Error();
        $old_timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $when_utc = strtotime(stripslashes_deep($_POST['scheduled-datetime-utc']));
        if (!$when_utc) {
            $err->add(
                'scheduled-datetime-utc',
                __('Buoy could not understand the date and time you entered.', 'better-angels')
            );
        } else {
            $dt = new DateTime("@$when_utc");
            // TODO: This fails to offset the UTC time back to server-local time
            //       correctly if the WP site is manually offset by a 30 minute
            //       offset instead of an hourly offset.
            $dt->setTimeZone(new DateTimeZone(wp_get_timezone_string()));
            $alert_id = $this->newAlert(array(
                'post_title' => $this->alertSubject(),
                'post_status' => 'future',
                'post_date' => $dt->format('Y-m-d H:i:s'),
                'post_date_gmt' => gmdate('Y-m-d H:i:s', $when_utc)
            ));
        }
        date_default_timezone_set($old_timezone);

        if (empty($err->errors)) {
            wp_send_json_success(array(
                'id' => $alert_id,
                'message' => __('Your timed alert has been scheduled. Schedule another?', 'better-angels')
            ));
        } else {
            wp_send_json_error($err);
        }
    }

    public function handleUnscheduleAlert () {
        if (isset($_GET[$this->prefix . 'nonce']) && wp_verify_nonce($_GET[$this->prefix . 'nonce'], $this->prefix . 'unschedule-alert')) {
            $post = get_post($_GET['alert_id']);
            if ($post && get_current_user_id() == $post->post_author) {
                wp_delete_post($post->ID, true); // delete immediately
                if (isset($_SERVER['HTTP_ACCEPT']) && false === strpos($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                    wp_safe_redirect(urldecode($_GET['r']));
                    exit();
                } else {
                    wp_send_json_success();
                }
            }
        } else {
            wp_send_json_error();
        }
    }

    /**
     * Responds to Ajax POSTs containing new position information of responders/alerter.
     * Sends back the location of all responders to this alert.
     */
    public function handleLocationUpdate () {
        check_ajax_referer($this->prefix . 'incident-nonce', $this->prefix . 'nonce');
        $new_position = $_POST['pos'];
        $alert_post = $this->getAlert($_POST['incident_hash']);
        $me = wp_get_current_user();
        $mkey = ($me->ID == $alert_post->post_author) ? 'alerter_location': "responder_{$me->ID}_location";
        update_post_meta($alert_post->ID, $this->prefix . $mkey, $new_position);

        $alerter = get_userdata($alert_post->post_author);
        $alerter_info = array(
            'id' => $alert_post->post_author,
            'geo' => get_post_meta($alert_post->ID, $this->prefix . 'alerter_location', true),
            'display_name' => $alerter->display_name,
            'avatar_url' => get_avatar_url($alerter->ID, array('size' => 32))
        );
        $phone_number = get_user_meta($alert_post->post_author, $this->prefix . 'sms', true);
        if (!empty($phone_number)) {
            $alerter_info['call'] = $phone_number;
        }
        $data = array($alerter_info);
        wp_send_json_success(array_merge($data, $this->getResponderInfo($alert_post)));
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

    public function handleDismissInstaller () {
        check_ajax_referer($this->prefix . 'incident-nonce', $this->prefix . 'nonce');

        update_user_meta(get_current_user_id(), $this->prefix . 'installer-dismissed', true);
    }

    /**
     * Retrieves an array of responder metadata for an alert.
     *
     * @param object $alert_post The WP_Post object of the alert.
     * @return array
     */
    public function getResponderInfo ($alert_post) {
        $responders = $this->getIncidentResponders($alert_post);
        $res = array();
        foreach ($responders as $responder_id) {
            $responder_data = get_userdata($responder_id);
            $this_responder = array(
                'id' => $responder_id,
                'display_name' => $responder_data->display_name,
                'avatar_url' => get_avatar_url($responder_id, array('size' => 32)),
                'geo' => $this->getResponderGeoLocation($alert_post, $responder_id)
            );
            $phone_number = get_user_meta($responder_id, $this->prefix . 'sms', true); 
            if (!empty($phone_number)) {
                $this_responder['call'] = $phone_number;
            }
            $res[] = $this_responder;
        }
        return $res;
    }

    /**
     * Detects an alert "short URL," which is a GET request with a special querystring parameter
     * that matches the first 8 characters of an alert's incident hash value and, if matched,
     * redirects to the full URL of that particular alert, then `exit()`s.
     *
     * @return void
     */
    public function redirectShortUrl () {
        $get_param = str_replace('_', '-', $this->prefix) . 'alert';
        if (!empty($_GET[$get_param]) && 8 === strlen($_GET[$get_param])) {
            $post = $this->getAlert(urldecode($_GET[$get_param]));
            $full_hash = get_post_meta($post->ID, $this->prefix . 'incident_hash', true);
            if ($full_hash) {
                wp_safe_redirect(admin_url(
                    '?page=' . $this->prefix . 'review-alert'
                    . '&' . $this->prefix . 'incident_hash=' . urlencode($full_hash)
                ));
                exit();
            }
        }
    }

    /**
     * Gets alert posts with an incident hash.
     *
     * @return array
     */
    public function getActiveAlerts () {
        return get_posts(array(
            'post_type' => str_replace('-', '_', $this->prefix) . 'alert',
            'meta_key' => $this->prefix . 'incident_hash'
        ));
    }

    /**
     * Gets scheduled alert posts.
     *
     * @param int $uid The WordPress user ID of an author's scheduled posts to look up.
     * @return array
     */
    public function getScheduledAlerts ($uid = false) {
        $args = array(
            'post_type' => str_replace('-', '_', $this->prefix) . 'alert',
            'post_status' => 'future'
        );
        if (false !== $uid) {
            $args['author'] = $uid;
        }
        return get_posts($args);
    }

    public function addIncidentMenu () {
        global $wp_admin_bar;

        $alerts = array(
            'my_alerts' => array(),
            'my_responses' => array(),
            'my_scheduled_alerts' => array()
        );
        foreach ($this->getActiveAlerts() as $post) {
            if (get_current_user_id() == $post->post_author) {
                $alerts['my_alerts'][] = $post;
            } else if (in_array(get_current_user_id(), $this->getIncidentResponders($post))) {
                $alerts['my_responses'][] = $post;
            }
        }
        foreach ($this->getScheduledAlerts(get_current_user_id()) as $post) {
            if (get_current_user_id() == $post->post_author) {
                $alerts['my_scheduled_alerts'][] = $post;
            }
        }

        if (!empty($alerts['my_alerts']) || !empty($alerts['my_responses']) || !empty($alerts['my_scheduled_alerts'])) {
            $wp_admin_bar->add_menu(array(
                'id' => $this->prefix . 'active-incidents-menu',
                'title' => __('Active alerts', 'better-angels')
            ));
        }

        // Add group nodes to WP Toolbar
        foreach ($alerts as $group_name => $posts) {
            $wp_admin_bar->add_group(array(
                'id' => $this->prefix . $group_name,
                'parent' => $this->prefix . 'active-incidents-menu'
            ));
        }

        $dtfmt = get_option('date_format') . ' ' . get_option('time_format');
        foreach ($alerts['my_alerts'] as $post) {
            $author = get_userdata($post->post_author);
            $url = wp_nonce_url(
                admin_url('?page=' . $this->prefix . 'incident-chat&' . $this->prefix . 'incident_hash=' . get_post_meta($post->ID, $this->prefix . 'incident_hash', true)),
                $this->prefix . 'chat', $this->prefix . 'nonce'
            );
            $wp_admin_bar->add_node(array(
                'id' => $this->prefix . 'active-incident-' . $post->ID,
                'title' => sprintf(__('My alert on %2$s', 'better-angels'), $author->display_name, date($dtfmt, strtotime($post->post_date))),
                'parent' => $this->prefix . 'my_alerts',
                'href' => $url
            ));
        }

        foreach ($alerts['my_responses'] as $post) {
            $author = get_userdata($post->post_author);
            $url = wp_nonce_url(
                admin_url('?page=' . $this->prefix . 'incident-chat&' . $this->prefix . 'incident_hash=' . get_post_meta($post->ID, $this->prefix . 'incident_hash', true)),
                $this->prefix . 'chat', $this->prefix . 'nonce'
            );
            $wp_admin_bar->add_node(array(
                'id' => $this->prefix . 'active-incident-' . $post->ID,
                'title' => sprintf(__('Alert issued by %1$s on %2$s', 'better-angels'), $author->display_name, date($dtfmt, strtotime($post->post_date))),
                'parent' => $this->prefix . 'my_responses',
                'href' => $url
            ));
        }

        foreach ($alerts['my_scheduled_alerts'] as $post) {
            $url = wp_nonce_url(
                admin_url('admin-ajax.php?action=' . $this->prefix . 'unschedule-alert&alert_id=' . $post->ID . '&r=' . esc_url($_SERVER['REQUEST_URI'])),
                $this->prefix . 'unschedule-alert', $this->prefix . 'nonce'
            );
            $wp_admin_bar->add_node(array(
                'id' => $this->prefix . 'scheduled-alert-' . $post->ID,
                'title' => sprintf(__('Cancel scheduled alert for %1$s','better-angels'), date($dtfmt, strtotime($post->post_date))),
                'meta' => array(
                    'title' => __('Cancel this alert', 'better-angels')
                ),
                'parent' => $this->prefix . 'my_scheduled_alerts',
                'href' => $url
            ));
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

    private function getUserGenderPronoun ($user_id) {
        $pronoun = get_user_meta($user_id, $this->prefix . 'pronoun', true);
        return (empty($pronoun)) ? 'their' : $pronoun;
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

        // Anything to delete?
        // Delete before adding!
        $all_my_guardians = array_map(
            'get_userdata', get_user_meta($user_id, $this->prefix . 'guardians')
        );

        if (!empty($request[$this->prefix . 'my_guardians'])) {
            foreach ($all_my_guardians as $guard) {
                if (!in_array($guard->ID, $request[$this->prefix . 'my_guardians'])) {
                    $this->removeGuardian($guard->ID, $user_id);
                }
            }
        } else { // delete all guardians
            delete_user_meta($user_id, $this->prefix . 'guardians');
            foreach ($all_my_guardians as $guard) {
                delete_user_meta($user_id, $this->prefix . 'guardian_' . $guard->ID . '_info');
            }
        }

        // Anything to add?
        if (!empty($request[$this->prefix . 'add_guardian'])) {
            $ginfo = (isset($request[$this->prefix . 'is_fake_guardian']))
                ? array('receive_alerts' => false) : array('receive_alerts' => true);
            $guardian_id = username_exists($request[$this->prefix . 'add_guardian']);
            // add the user if a valid username was entered
            if ($guardian_id) {
                $this->setupGuardian($guardian_id, $user_id, $ginfo);
            } else if (is_email($request[$this->prefix . 'add_guardian'])) {
                $user = get_user_by('email', $request[$this->prefix . 'add_guardian']);
                if ($user) {
                    $this->setupGuardian($user->ID, $user_id, $ginfo);
                } else {
                    $subject = sprintf(
                        __('%1$s invites you to join the Buoy emergency response alternative on %2$s!', 'better-angels'),
                        $wp_user->display_name,
                        get_bloginfo('name')
                    );
                    $msg = __('Buoy is a community-driven emergency dispatch and response technology. It is designed to connect people in crisis with trusted friends, family, and other nearby allies who can help. We believe that in situations where traditional emergency services are not available, reliable, trustworthy, or sufficient, communities can come together to aid each other in times of need.', 'better-angels');
                    $msg .= "\n\n";
                    $msg .= sprintf(
                        __('%1$s wants you to join %2$s crisis response team.', 'better-angels'),
                        $wp_user->display_name, $this->getUserGenderPronoun($wp_user->ID)
                    );
                    $msg .= "\n\n";
                    $msg .= __('To join, sign up for an account here:', 'better-angels');
                    $msg .= "\n\n" . wp_registration_url();
                    wp_mail($request[$this->prefix . 'add_guardian'], $subject, $msg);
                    $this->Error->add(
                        'unknown-email',
                        sprintf(esc_html__('You have invited %s to join this Buoy site, but they are not yet on your response team. Contact them privately (such as by phone or txt) to make sure they created an account. Then come back here and add them again.', 'better-angels'), $request[$this->prefix . 'add_guardian']),
                        $request[$this->prefix . 'add_guardian']
                    );
                }
            }
        }
    }

    /**
     * Sets up a guardian relationship between two users.
     *
     * @param int $guardian_id The user ID number of the guardian.
     * @param int $user_id The user ID number of the user being guarded.
     * @param array $settings Additional metadata to set for the guardian.
     * @return void
     */
    private function setupGuardian ($guardian_id, $user_id, $settings) {
        $this->addGuardian($guardian_id, $user_id);
        $this->setGuardianInfo($guardian_id, $user_id, $settings);
    }

    public function renderReviewAlertPage () {
        if (empty($_GET[$this->prefix . 'incident_hash'])) {
            return;
        }
        $alert_post = $this->getAlert($_GET[$this->prefix . 'incident_hash']);
        if (!current_user_can('read') || !$this->isGuardian(get_current_user_id(), $alert_post->post_author)) {
            esc_html_e('You do not have sufficient permissions to access this page.', 'better-angels');
            return;
        }
        require_once 'pages/review-alert.php';
    }

    public function addIncidentResponder ($alert_post, $user_id) {
        if (!in_array($user_id, get_post_meta($alert_post->ID, $this->prefix . 'responders'))) {
            add_post_meta($alert_post->ID, $this->prefix . 'responders', $user_id, false);
        }
    }

    /**
     * Sets the geo-located metadata for a responer in the context of an alert. The responder is the current user.
     *
     * @param object $alert_post The WP post object of the alert incident.
     * @param array $geo An array with `latitude` and `longitude` keys.
     * @return void
     */
    public function setResponderGeoLocation ($alert_post, $geo) {
        update_post_meta($alert_post->ID, $this->prefix . 'responder_' . get_current_user_id() . '_location', $geo);
    }
    public function getResponderGeoLocation ($alert_post, $user_id) {
        return get_post_meta($alert_post->ID, $this->prefix . 'responder_' . $user_id . '_location', true);
    }

    /**
     * Retrieves the list of responders for a given alert.
     *
     * @param object $alert_post The WP Post object of the alert.
     * @return array
     */
    public function getIncidentResponders ($alert_post) {
        return get_post_meta($alert_post->ID, $this->prefix . 'responders', false);
    }

    public function renderIncidentChatPage () {
        $alert_post = $this->getAlert(urldecode($_GET[$this->prefix . 'incident_hash']));
        if (!$alert_post || !current_user_can('read') || !isset($_GET[$this->prefix . 'nonce']) || !wp_verify_nonce($_GET[$this->prefix . 'nonce'], "{$this->prefix}chat")) {
            esc_html_e('You do not have sufficient permissions to access this page.', 'better-angels');
            return;
        }
        if (get_current_user_id() != $alert_post->post_author) {
            $this->addIncidentResponder($alert_post, get_current_user_id());
            // TODO: Clean this up a bit, maybe the JavaScript should send JSON data?
            if (!empty($_POST[$this->prefix . 'location'])) {
                $p = explode(',', $_POST[$this->prefix . 'location']);
                $responder_geo = array(
                    'latitude' => $p[0],
                    'longitude' => $p[1]
                );
                $this->setResponderGeoLocation($alert_post, $responder_geo);
            }
        }
        require_once 'pages/incident-chat.php';
    }

    /**
     * Gets the correct HTML embeds/elements for a given media type.
     *
     * @param string $type One of 'video', 'audio', or 'image'
     * @param int $post_id The WP post ID of the attachment media.
     * @return string
     */
    private function getIncidentMediaHtml ($type, $post_id) {
        $html = '';
        switch ($type) {
            case 'video':
                $html .= wp_video_shortcode(array(
                    'src' => wp_get_attachment_url($post_id)
                ));;
                break;
            case 'image':
                $html .= '<a href="' . wp_get_attachment_url($post_id) . '" target="_blank">';
                $html .= wp_get_attachment_image($post_id);
                $html .= '</a>';
                break;
            case 'audio':
            default:
                $html .= wp_audio_shortcode(array(
                    'src' => wp_get_attachment_url($post_id)
                ));
                break;
        }
        return $html;
    }

    /**
     * Returns an HTML structure containing nested lists and list items
     * referring to any media attached to the given post ID.
     *
     * @param int $post_id The post ID from which to fetch attached media.
     * @uses BetterAngelsPlugin::getIncidentMediaHtml()
     * @return string HTML ready for insertion into an `<ul>` element.
     */
    private function getIncidentMediaList ($post_id) {
        $html = '';

        $posts = array(
            'video' => get_attached_media('video', $post_id),
            'image' => get_attached_media('image', $post_id),
            'audio' => get_attached_media('audio', $post_id)
        );

        foreach ($posts as $type => $set) {
            $html .= '<li class="' . esc_attr($type) . ' list-group">';
            $html .= '<div class="list-group-item">';
            $html .= '<h4 class="list-group-item-heading">';
            switch ($type) {
                case 'video':
                    $html .= esc_html('Video attachments', 'better-angels');
                    break;
                case 'image':
                    $html .= esc_html('Image attachments', 'better-angels');
                    break;
                case 'audio':
                    $html .= esc_html('Audio attachments', 'better-angels');
                    break;
            }
            $html .= ' <span class="badge">' . count($set) . '</span>';
            $html .= '</h4>';
            $html .= '<ul>';

            foreach ($set as $post) {
                $this->debug_log(sprintf(
                    __('Found attachment media: %1$s', 'better-angels'),
                    print_r($post, true)
                ));
                $html .= '<li id="incident-media-'. $post->ID .'" class="list-group-item">';
                $html .= '<h5 class="list-group-item-header">' . esc_html($post->post_title) . '</h5>';
                $html .= $this->getIncidentMediaHtml($type, $post->ID);
                $html .= '<p class="list-group-item-text">';
                $html .= sprintf(
                    esc_html_x('uploaded %1$s ago', 'Example: uploaded 5 mins ago', 'better-angels'),
                    human_time_diff(strtotime($post->post_date_gmt))
                );
                $u = get_userdata($post->post_author);
                $html .= ' ' . sprintf(
                    esc_html_x('by %1$s', 'a byline, like "written by Bob"', 'better-angels'),
                    $u->display_name
                );
                $html .= '</p>';
                $html .= '</li>';
            }

            $html .= '</ul>';
            $html .= '</div>';
            $html .= '</li>';
        }

        return $html;
    }

    public function renderChooseAngelsPage () {
        if (!current_user_can('read')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'better-angels'));
        }

        if (isset($_POST[$this->prefix . 'nonce'])
            && wp_verify_nonce($_POST[$this->prefix . 'nonce'], $this->prefix . 'guardians')) {
            $this->updateChooseAngels($_POST);
        }
        require_once 'pages/choose-angels.php';
    }

    public function renderTeamMembershipPage () {
        if (!current_user_can('read')) {
        // TODO: Figure out this cross-user nonce thing.
        //if (!current_user_can('read') || !wp_verify_nonce($_GET[$this->prefix . 'nonce'], $this->prefix . 'confirm-guardianship')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'better-angels'));
        }

        // Join one or more teams.
        if (isset($_POST[$this->prefix . 'nonce']) && wp_verify_nonce($_POST[$this->prefix . 'nonce'], $this->prefix . 'update-teams')) {
            $join_teams = (empty($_POST[$this->prefix . 'join_teams'])) ? array() : $_POST[$this->prefix . 'join_teams'];
            foreach ($join_teams as $owner_id) {
                $this->setGuardianInfo(get_current_user_id(), $owner_id, array('confirmed' => true));
            }

            // Leave a team.
            if (!empty($_POST[$this->prefix . 'leave-team'])) {
                $this->removeGuardian(get_current_user_id(), username_exists($_POST[$this->prefix . 'leave-team']));
            }
        }

        require_once 'pages/confirm-guardianship.php';
    }

    public function renderSafetyInfoPage () {
        if (!current_user_can('read')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'better-angels'));
        }
        $options = get_option($this->prefix . 'settings');
        print $options['safety_info']; // TODO: Can we harden against XSS here?
    }

    public function renderOptionsPage () {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'better-angels'));
        }
        $options = get_option($this->prefix . 'settings');

        require_once 'pages/options.php';

        $this->showDonationAppeal();
    }
}

new BetterAngelsPlugin();

/**
 * Helpers.
 */
if (!function_exists('wp_get_timezone_string')) {
    /**
    * Helper to retrieve the timezone string for a site until
    * a WP core method exists (see http://core.trac.wordpress.org/ticket/24730).
    *
    * Adapted from http://www.php.net/manual/en/function.timezone-name-from-abbr.php#89155
    * Copied from WooCommerce code:
    * https://github.com/woothemes/woocommerce/blob/5893875b0c03dda7b2d448d1a904ccfad3cdae3f/includes/wc-formatting-functions.php#L441-L485
    *
    * @return string valid PHP timezone string
    */
    function wp_get_timezone_string() {
        // if site timezone string exists, return it
        if ( $timezone = get_option( 'timezone_string' ) ) {
            return $timezone;
        }
        // get UTC offset, if it isn't set then return UTC
        if ( 0 === ( $utc_offset = get_option( 'gmt_offset', 0 ) ) ) {
            return 'UTC';
        }
        // adjust UTC offset from hours to seconds
        $utc_offset *= 3600;
        // attempt to guess the timezone string from the UTC offset
        $timezone = timezone_name_from_abbr( '', $utc_offset, 0 );
        // last try, guess timezone string manually
        if ( false === $timezone ) {
            $is_dst = date( 'I' );
            foreach ( timezone_abbreviations_list() as $abbr ) {
                foreach ( $abbr as $city ) {
                    if ( $city['dst'] == $is_dst && $city['offset'] == $utc_offset ) {
                        return $city['timezone_id'];
                    }
                }
            }
            // fallback to UTC
            return 'UTC';
        }
        return $timezone;
    }
}
