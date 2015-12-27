<?php

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

/**
 * Main class for creating and delegating responses to alerts.
 *
 * Alerts are posts that record some incident information such as the
 * location and attached media recordings of what's going on.
 *
 * @author maymay <bitetheappleback@gmail.com>
 * @copyright Copyright (c) 2015-2016 by Meitar "maymay" Moscovitz
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 * @package WordPress\Plugin\WP_Buoy_Plugin\Teams
 */
class WP_Buoy_Alert extends WP_Buoy_Plugin {

    /**
     * Alert post.
     *
     * @var WP_Post
     */
    public $wp_post;

    /**
     * The author of the alert.
     *
     * @var WP_User
     */
    private $_user;

    /**
     * The teams to which this alert was sent.
     *
     * @var int[]
     */
    private $_teams;

    /**
     * The alert's WP_Post data.
     *
     * This holds the initialization data for the alert's WP_Post data
     * and is the same as `wp_insert_post()`'s `$postarr` parameter.
     *
     * @see https://developer.wordpress.org/reference/functions/wp_insert_post/
     *
     * @var array
     */
    private $_postarr;

    /**
     * The alert's public identifier.
     *
     * The `$_hash` is a randomly generated lookup value that is used
     * instead of a WordPress post ID. This is because a post ID is a
     * sequential number, and would expose the Buoy to attack if a bad
     * (malicious) actor. Using a hash value instead of an integer in
     * this context makes it harder for attackrs to guess quantity and
     * frequency of alerts that this Buoy maintains.
     *
     * @see https://www.owasp.org/index.php/How_to_protect_sensitive_data_in_URL%27s
     *
     * @var string
     */
    private $_hash;

    /**
     * The chat room associated with this alert.
     *
     * @var string
     */
    private $_chat_room_name;

    /**
     * Array of postmeta keys to look for alert hash strings.
     *
     * For backwards-compatibility
     *
     * @var string[]
     */
    private $_hash_keys;

    /**
     * Constructor.
     *
     * Retrieves an alert post as a WP_Buoy_Alert object, or an empty,
     * new such object if no `$lookup` value is provided with which to
     * search for a pre-existing alert.
     *
     * @uses WP_Buoy_Alert::load()
     *
     * @param int|WP_Post|string $lookup Optional lookup value, WP_Post, ID, or hash.
     *
     * @return WP_Buoy_Alert
     */
    public function __construct ($lookup = null) {
        // Search for hashes in both keyspaces for back-compat.
        $this->_hash_keys = array(parent::$prefix . '_hash', parent::$prefix . '_incident_hash');

        if (null !== $lookup) {
            return $this->load($lookup);
        }

        return $this;
    }

    /**
     * Get an alert from the WordPress database based on lookup value.
     *
     * @param int|WP_Post|string $lookup The lookup value.
     *
     * @return WP_Buoy_Alert
     */
    public function load ($lookup) {
        if (get_post_status($lookup)) {
            $this->wp_post = get_post($lookup);
        } else if (strlen($lookup) > 7) {
            $posts = get_posts(array(
                'post_type' => parent::$prefix . '_alert',
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key' => $this->_hash_keys[0],
                        'value' => "^$lookup",
                        'compare' => 'REGEXP'
                    ),
                    array(
                        'key' => $this->_hash_keys[1],
                        'value' => "^$lookup",
                        'compare' => 'REGEXP'
                    )
                )
            ));
            if (!empty($posts)) {
                $this->wp_post = array_pop($posts);
            }
        }

        if ($this->wp_post) {
            $this->set_hash();
            $this->set_chat_room_name();
            $this->_user = get_userdata($this->wp_post->post_author);
            $this->_teams = array_map(
                'absint', get_post_meta($this->wp_post->ID, parent::$prefix . '_teams', true)
            );
        }

        return $this;
    }

    /**
     * Saves the alert (incident) in the WordPress database.
     *
     * @uses wp_insert_post()
     * @uses get_post()
     * @uses WP_Buoy_Alert::set_hash()
     * @uses WP_Buoy_Alert::set_chat_room_name()
     *
     * @return int|WP_Error Result of `wp_insert_post()`.
     */
    public function save () {
        $result = wp_insert_post($this->_postarr, true);
        if (is_int($result)) {
            $this->wp_post = get_post($result);
            $this->set_hash();
            $this->set_chat_room_name();
        }
        return $result;
    }

    /**
     * Sets the WP_Post data for this alert.
     *
     * @see https://developer.wordpress.org/reference/functions/wp_insert_post/
     *
     * @param array $postarr Same as `wp_insert_post()`'s `$postarr` parameter.
     *
     * @return WP_Buoy_Alert
     */
    public function set ($postarr = array()) {
        // These args are always hardcoded.
        $postarr['post_type']      = parent::$prefix . '_alert';
        $postarr['post_content']   = ''; // empty content
        $postarr['ping_status']    = 'closed';
        $postarr['comment_status'] = 'closed';

        $defaults = array(
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        );

        $postarr = wp_parse_args($postarr, $defaults);

        $alerter = new WP_Buoy_User($postarr['post_author']);
        $default_meta = array(
            parent::$prefix . '_hash' => $this->make_hash(),
            parent::$prefix . '_chat_room_name' => $this->make_chat_room_name(),
            parent::$prefix . '_teams' => array($alerter->get_default_team())
        );

        if (!isset($postarr['meta_input'])) {
            $postarr['meta_input'] = array();
        }
        $postarr['meta_input'] = wp_parse_args($postarr['meta_input'], $default_meta);

        $this->_postarr = $postarr;

        return $this;
    }

    /**
     * @return string
     */
    public function get_hash () {
        return $this->_hash;
    }

    /**
     * Gets the teams to which this alert was sent.
     *
     * @return int[]
     */
    public function get_teams () {
        return $this->_teams;
    }

    /**
     * Checks whether a user is allowed to respond to this alert.
     *
     * A user is allowed to respond to an alert if they are listed as
     * a "confirmed" member in one of the teams associated with this
     * alert.
     *
     * @todo Currently, an alert dynamically looks up who is on the
     *       teams associated with it. This should be changed so it
     *       keeps a snapshotted list of the confirmed team members
     *       at the time the alert was created. This will prevent a
     *       user from being added to a team (and thus granted access
     *       to an alert) *after* the alert has been sent out.
     *
     * @param int $user_id
     *
     * @return bool
     */
    public function can_respond ($user_id) {
        foreach ($this->get_teams() as $team_id) {
            $team = new WP_Buoy_Team($team_id);
            if (in_array($user_id, $team->get_confirmed_members())) {
                return true;
            }
        }
        return false;
    }

    /**
     * Loads the alert hash from the database.
     *
     * @return void
     */
    private function set_hash () {
        foreach ($this->_hash_keys as $k) {
            $prev_hash = sanitize_text_field(get_post_meta($this->wp_post->ID, $k, true));
            if ($prev_hash) {
                $this->_hash = $prev_hash;
                break;
            }
        }
    }

    /**
     * @return void
     */
    private function set_chat_room_name () {
        $this->_chat_room_name = sanitize_text_field(get_post_meta($this->wp_post->ID, parent::$prefix . '_chat_room_name', true));
    }

    /**
     * @return string
     */
    public function get_chat_room_name () {
        return $this->_chat_room_name;
    }

    /**
     * Makes a random lookup hash for this alert.
     *
     * @uses WP_Buoy_Alert::get_random_seed()
     * @uses hash()
     *
     * @return string
     */
    private function make_hash () {
        return hash('sha256', $this->get_random_seed());
    }

    /**
     * Makes a randomized chat room name for this alert.
     *
     * @uses WP_Buoy_Alert::get_random_seed()
     * @uses hash()
     *
     * @return WP_Buoy_Alert
     */
    private function make_chat_room_name () {
        // need to limit the length of this string due to Tlk.io integration for now
        return parent::$prefix . '_' . substr(hash('sha1', $this->get_random_seed()), 0, 20);
    }

    /**
     * This function tries to use the best available source of random
     * numbers to create the seed data for a hash that it can find.
     *
     * @uses random_bytes()
     * @uses openssl_random_pseudo_bytes()
     * @uses mt_rand()
     * @uses microtime()
     * @uses getmypid()
     * @uses uniqid()
     *
     * @return string
     */
    private function get_random_seed () {
        $preferred_functions = array(
            // sorted in order of preference, strongest functions 1st
            'random_bytes', 'openssl_random_pseudo_bytes'
        );
        $length = MB_IN_BYTES * mt_rand(1, 4);
        foreach ($preferred_functions as $func) {
            if (function_exists($func)) {
                $seed = $func($length);
                break;
            } else {
                static::debug_log(sprintf(
                    __('WARNING! Your system does not have %s available to generate alert hashes.', 'buoy'),
                    $func . '()'
                ));
            }
        }
        return (isset($seed)) ? $seed : mt_rand() . microtime() . getmypid() . uniqid('', true);
    }

    /**
     * @return void
     */
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

        add_action('wp_ajax_' . parent::$prefix . '_new_alert', array(__CLASS__, 'handleNewAlert'));

        add_action('publish_' . parent::$prefix . '_alert', array('WP_Buoy_Notification', 'publishAlert'), 10, 2);
    }

    /**
     * @return void
     */
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
        add_action('load-' . $hook, array(__CLASS__, 'addInstallerScripts'));

        $hooks[] = add_submenu_page(
            null,
            __('Respond to Alert', 'buoy'),
            __('Respond to Alert', 'buoy'),
            'read',
            parent::$prefix . '_review_alert',
            array(__CLASS__, 'renderReviewAlertPage')
        );

        $hooks[] = add_submenu_page(
            null,
            __('Incident Chat', 'buoy'),
            __('Incident Chat', 'buoy'),
            'read',
            parent::$prefix . '_chat',
            array(__CLASS__, 'renderIncidentChatPage')
        );

        foreach ($hooks as $hook) {
            add_action('load-' . $hook, array(__CLASS__, 'enqueueFrontEndScripts'));
            add_action('load-' . $hook, array(__CLASS__, 'enqueueFrameworkScripts'));
        }
    }

    /**
     * @return void
     */
    public static function renderActivateAlertPage () {
        $buoy_user = new WP_Buoy_User(get_current_user_id());
        if (!$buoy_user->has_responder()) {
            require_once 'pages/no-responders-available.php';
        } else {
            require_once 'pages/activate-alert.php';
        }
    }

    /**
     * @global $_GET
     *
     * @return void
     */
    public static function renderReviewAlertPage () {
        if (empty($_GET[parent::$prefix . '_hash'])) {
            return;
        }
        $alert = new WP_Buoy_Alert($_GET[parent::$prefix . '_hash']);
        if (!current_user_can('read') || !$alert->can_respond(get_current_user_id())) {
            esc_html_e('You do not have sufficient permissions to access this page.', 'buoy');
            return;
        }
        require_once 'pages/review-alert.php';
    }

    /**
     * @global $_GET
     *
     * @return void
     */
    public static function renderIncidentChatPage () {
        $alert = new WP_Buoy_Alert(urldecode($_GET[parent::$prefix . '_hash']));
        if (!$alert->wp_post || !current_user_can('read') || !isset($_GET[parent::$prefix . '_nonce']) || !wp_verify_nonce($_GET[parent::$prefix . '_nonce'], parent::$prefix . '_chat')) {
            esc_html_e('You do not have sufficient permissions to access this page.', 'buoy');
            return;
        }
        require_once 'pages/incident-chat.php';
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
    public static function enqueueFrontEndScripts () {
        $plugin_data = get_plugin_data(plugin_dir_path(__FILE__) . parent::$prefix . '.php');
        wp_enqueue_style(
            parent::$prefix . '-style',
            plugins_url('css/' . parent::$prefix . '.css', __FILE__),
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
     * along with jQuery and Google library plugins used for Alert UI.
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
            'bootstrap-css',
            'https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css'
        );
        wp_enqueue_script(
            'bootstrap-js',
            'https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js',
            array(),
            null,
            true
        );

        wp_enqueue_script(
            'google-maps-api',
            'https://maps.googleapis.com/maps/api/js?language=' . get_locale(),
            array(),
            null,
            true
        );

        // Enqueue a custom pulse loader CSS animation.
        wp_enqueue_style(
            parent::$prefix . '-pulse-loader',
            plugins_url('includes/pulse-loader.css', __FILE__)
        );

        if (is_ssl() || WP_Buoy_Settings::get_instance()->get('debug')) {
            add_filter('style_loader_tag', array(__CLASS__, 'addIntegrityAttribute'), 9999, 2);
            add_filter('script_loader_tag', array(__CLASS__, 'addIntegrityAttribute'), 9999, 2);
        }
    }

    /**
     * Sets subresource integrity attributes on elements loaded via CDN.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity
     * @see https://developer.wordpress.org/reference/hooks/style_loader_tag/
     * @see https://developer.wordpress.org/reference/hooks/script_loader_tag/
     *
     * @param string $html
     * @param string $handle
     *
     * @return string
     */
    public static function addIntegrityAttribute ($html, $handle) {
        $integrities = array(
            // sha*-$hash => handle
            'sha384-1q8mTJOASx8j1Au+a5WDVnPi2lkFfwwEAa8hDDdjZlpLegxhjVME1fgjWPGmkzs7' => 'bootstrap-css',
            'sha384-0mSbJDEHialfmuBBQP6A4Qrprq5OVfW37PRR3j5ELqxss1yVqOtnepnHVP9aJ7xS' => 'bootstrap-js'
            // TODO: Figure out if the Google Maps API can also be SRI-enabled
        );
        if ($integrity = array_search($handle, $integrities)) {
            $sri_att = ' crossorigin="anonymous" integrity="' . $integrity . '"';
            $insertion_pos = strpos($html, '>');
            // account for self-closing tags
            if (0 === strpos($html, '<link ')) {
                $insertion_pos--;
                $sri_att .= ' ';
            }
            return substr($html, 0, $insertion_pos) . $sri_att . substr($html, $insertion_pos);
        }
        return $html;
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

    /**
     * Responds to requests activated from the main emergency alert button.
     *
     * @see https://developer.wordpress.org/reference/hooks/wp_ajax__requestaction/
     *
     * @global $_POST
     *
     * @uses WP_Buoy_Plugin::$prefix
     * @uses check_ajax_referer()
     * @uses get_current_user_id()
     * @uses WP_Buoy_User_Settings::get()
     * @uses sanitize_text_field()
     * @uses stripslashes_deep()
     * @uses WP_Buoy_Alert::set()
     * @uses WP_Buoy_Alert::save()
     * @uses WP_Buoy_Alert::get_hash()
     * @uses wp_send_json_error()
     * @uses wp_send_json_success()
     * @uses wp_safe_redirect()
     *
     * @return void
     */
    public static function handleNewAlert () {
        check_ajax_referer(parent::$prefix . '_new_alert', parent::$prefix . '_nonce');

        $meta_input = array();

        // Collect info from the browser via Ajax request
        $alert_position = (empty($_POST['pos'])) ? false : $_POST['pos']; // TODO: array_map and sanitize this?
        if ($alert_position) {
            $meta_input['geo_latitude'] = $alert_position['latitude'];
            $meta_input['geo_longitude'] = $alert_position['longitude'];
        }
        if (isset($_POST['teams']) && is_array($_POST['teams'])) {
            $meta_input[parent::$prefix . '_teams'] = array_map('absint', $alert_teams);
        }

        // Create and publish the new alert.
        $buoy_user = new WP_Buoy_User(get_current_user_id());
        $alert_subject = (empty($_POST['msg']))
            ? $buoy_user->get_crisis_message()
            : sanitize_text_field(stripslashes_deep($_POST['msg']));

        $postarr = array('post_title' => $alert_subject);
        if (!empty($meta_input)) {
            $postarr['meta_input'] = $meta_input;
        }

        $buoy_alert = new self();
        $post_id = $buoy_alert->set($postarr)->save();
        if (is_wp_error($post_id)) {
            wp_send_json_error($post_id);
        }

        // Construct the redirect URL to the alerter's chat room
        $next_url = wp_nonce_url(
            admin_url(
                '?page=' . parent::$prefix . '_chat'
                . '&' . parent::$prefix . '_hash=' . $buoy_alert->get_hash()
            ),
            parent::$prefix . '_chat', parent::$prefix . '_nonce'
        );

        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accepts = explode(',', $_SERVER['HTTP_ACCEPT']);
        }
        if ($accepts && 'application/json' === array_shift($accepts)) {
            wp_send_json_success($next_url);
        } else {
            wp_safe_redirect(html_entity_decode($next_url));
            exit();
        }
    }

    /**
     * Returns an HTML structure containing nested lists and list items
     * referring to any media attached to the given post ID.
     *
     * @param int $post_id The post ID from which to fetch attached media.
     *
     * @uses WP_Buoy_Alert::getIncidentMediaHtml()
     *
     * @return string HTML ready for insertion into an `<ul>` element.
     */
    private static function getIncidentMediaList ($post_id) {
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
                    $html .= esc_html('Video attachments', 'buoy');
                    break;
                case 'image':
                    $html .= esc_html('Image attachments', 'buoy');
                    break;
                case 'audio':
                    $html .= esc_html('Audio attachments', 'buoy');
                    break;
            }
            $html .= ' <span class="badge">' . count($set) . '</span>';
            $html .= '</h4>';
            $html .= '<ul>';

            foreach ($set as $post) {
                $html .= '<li id="incident-media-'. $post->ID .'" class="list-group-item">';
                $html .= '<h5 class="list-group-item-header">' . esc_html($post->post_title) . '</h5>';
                $html .= self::getIncidentMediaHtml($type, $post->ID);
                $html .= '<p class="list-group-item-text">';
                $html .= sprintf(
                    esc_html_x('uploaded %1$s ago', 'Example: uploaded 5 mins ago', 'buoy'),
                    human_time_diff(strtotime($post->post_date_gmt))
                );
                $u = get_userdata($post->post_author);
                $html .= ' ' . sprintf(
                    esc_html_x('by %1$s', 'a byline, like "written by Bob"', 'buoy'),
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

    /**
     * Gets the correct HTML embeds/elements for a given media type.
     *
     * @param string $type One of 'video', 'audio', or 'image'
     * @param int $post_id The WP post ID of the attachment media.
     *
     * @return string
     */
    private static function getIncidentMediaHtml ($type, $post_id) {
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

}
