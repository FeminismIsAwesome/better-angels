<?php
if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.
/**
 * Teams are groups/lists of potential responders managed by one user
 * who invites them to join said team. They are implemented as a WP
 * custom post type.
 *
 * @author maymay <bitetheappleback@gmail.com>
 * @copyright Copyright (c) 2015-2016 by Meitar "maymay" Moscovitz
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 * @package WordPress\Plugin\WP_Buoy_Plugin\Teams
 */
class WP_Buoy_Team extends WP_Buoy_Plugin {

    /**
     * The team post.
     *
     * @var WP_Post
     */
    private $post;

    /**
     * The team owner.
     *
     * @var WP_user
     */
    private $author;

    /**
     * An array of WP_User objects representing the team membership.
     *
     * The members list include those who are not yet confirmed (pending).
     *
     * @var WP_User[]
     */
    private $members = array();

    /**
     * Constructor.
     *
     * @param int $team_id The post ID of the team.
     *
     * @return WP_Buoy_Team
     */
    public function __construct ($team_id) {
        $this->post = get_post($team_id);
        $this->members = array_map('get_userdata', array_unique(get_post_meta($this->post->ID, '_team_members')));
        $this->author = get_userdata($this->post->post_author);
        return $this;
    }

    public function __get ($name) {
        return $this->$name;
    }

    /**
     * Gets a list of all the user IDs associated with this team.
     *
     * This does not do any checking about whether the given user ID
     * is "confirmed" or not.
     *
     * @see WP_Buoy_Team::is_confirmed()
     *
     * @return string[] IDs are actually returned as string values.
     */
    public function get_member_ids () {
        return array_unique(get_post_meta($this->post->ID, '_team_members'));
    }

    /**
     * Checks whether or not the given user ID is on the team.
     *
     * This does not check whether the user is confirmed or not, only
     * whether the user has been at least invited to be a member of a
     * team.
     *
     * @see WP_Buoy_Team::is_confirmed()
     *
     * @param int $user_id
     *
     * @return bool
     */
    public function is_member ($user_id) {
        return in_array($user_id, $this->get_member_ids());
    }

    /**
     * Adds a user to this team (a new member).
     *
     * @uses add_post_meta()
     * @uses do_action()
     *
     * @param int $user_id
     *
     * @return WP_Buoy_Team
     */
    public function add_member ($user_id) {
        add_post_meta($this->post->ID, '_team_members', $user_id, false);

        /**
         * Fires when a user is added to a team.
         *
         * @param int $user_id
         * @param WP_Buoy_Team $this
         */
        do_action(parent::$prefix . '_team_member_added', $user_id, $this);

        return $this;
    }

    /**
     * Removes a member from this team.
     *
     * @uses delete_post_meta()
     * @uses do_action()
     *
     * @param int $user_id
     *
     * @return WP_Buoy_Team
     */
    public function remove_member ($user_id) {
        delete_post_meta($this->post->ID, '_team_members', $user_id);

        /**
         * Fires when a user is removed from a team.
         *
         * @param int $user_id
         * @param WP_Buoy_Team $this
         */
        do_action(parent::$prefix . '_team_member_removed', $user_id, $this);

        return $this;
    }

    /**
     * Sets the confirmation flag for a user on this team.
     *
     * @param int $user_id
     *
     * @return WP_Buoy_Team
     */
    public function confirm_member ($user_id) {
        add_post_meta($this->post->ID, "_member_{$user_id}_is_confirmed", true, true);
        return $this;
    }

    /**
     * Unsets the confirmation flag for a user on this team.
     *
     * @param int $user_id
     *
     * @return WP_Buoy_Team
     */
    public function unconfirm_member ($user_id) {
        delete_post_meta($this->post->ID, "_member_{$user_id}_is_confirmed");
        return $this;
    }

    /**
     * Checks whether or not a user is "confirmed" to be on the team.
     *
     * "Confirmation" consists of a flag in the team post's metadata.
     *
     * @param int $user_id
     *
     * @return bool
     */
    public function is_confirmed ($user_id) {
        return get_post_meta($this->post->ID, "_member_{$user_id}_is_confirmed", true);
    }

    /**
     * @return void
     */
    public static function register () {
        if (!class_exists('Buoy_Teams_List_Table')) { // for the admin UI
            require plugin_dir_path(__FILE__) . 'class-buoy-teams-list-table.php';
        }

        register_post_type(parent::$prefix . '_team', array(
            'label' => __('My Teams', 'buoy'),
            'labels' => array(
                'add_new_item' => __('Add New Team', 'buoy'),
                'edit_item' => __('Edit Team', 'buoy'),
                'search_items' => __('Search Teams', 'Buoy')
            ),
            'description' => __('Groups of crisis responders', 'buoy'),
            'public' => false,
            'show_ui' => true,
            'capability_type' => parent::$prefix . '_team',
            'map_meta_cap' => true,
            'hierarchical' => false,
            'supports' => array(
                'title',
                'author'
            ),
            'register_meta_box_cb' => array(__CLASS__, 'registerMetaBoxes'),
            'has_archive' => false,
            'rewrite' => false,
            'can_export' => false,
            'menu_icon' => plugins_url('img/icon-bw-life-preserver.svg', __FILE__)
        ));
        add_action('load-post-new.php', array('WP_Buoy_Plugin', 'addHelpTab'));
        add_action('load-edit.php', array('WP_Buoy_Plugin', 'addHelpTab'));

        wp_enqueue_style(
            __CLASS__ . '_style',
            plugins_url('admin-teams.css', __FILE__)
        );

        add_action('current_screen', array(__CLASS__, 'processTeamTableActions'));

        add_action('admin_menu', array(__CLASS__, 'registerAdminMenu'));

        add_action('pre_get_posts', array(__CLASS__, 'filterTeamPostsList'));

        add_action('save_post_' . parent::$prefix . '_team', array(__CLASS__, 'saveTeam'));

        add_action('deleted_post_meta', array(__CLASS__, 'deletedPostMeta'), 10, 4);

        add_filter('user_has_cap', array(__CLASS__, 'filterCaps'));
    }

    /**
     * @param WP_Post $post
     *
     * @return void
     */
    public static function registerMetaBoxes ($post) {
        $team = new self($post->ID);
        add_meta_box(
            'add-team-member',
            esc_html__('Add a team member', 'buoy'),
            array(__CLASS__, 'renderAddTeamMemberMetaBox'),
            null,
            'normal',
            'high'
        );

        add_meta_box(
            'current-team',
            sprintf(
                esc_html__('Current team members %s', 'buoy'),
                '<span class="count">(' . count($team->members) . ')</span>'
            ),
            array(__CLASS__, 'renderCurrentTeamMetaBox'),
            null,
            'normal',
            'high'
        );
    }

    /**
     * @return void
     */
    public static function renderAddTeamMemberMetaBox ($post) {
        wp_nonce_field(parent::$prefix . '_add_team_member', parent::$prefix . '_add_team_member_nonce');
        require 'pages/add-team-member-meta-box.php';
    }

    /**
     * @return void
     */
    public static function renderCurrentTeamMetaBox ($post) {
        wp_nonce_field(parent::$prefix . '_choose_team', parent::$prefix . '_choose_team_nonce');
        require 'pages/current-team-meta-box.php';
    }

    /**
     * @return void
     */
    public static function renderTeamMembershipPage () {
        $team_table = new Buoy_Teams_List_Table(parent::$prefix . '_team');
        $team_table->prepare_items();
        print '<div class="wrap">';
        print '<form'
            . ' action="' . admin_url('edit.php?post_type=' . parent::$prefix . '_team&page=' . parent::$prefix . '_team_membership') . '"'
            . ' method="post">';
        print '<h1>' . esc_html__('Team membership', 'buoy') . '</h1>';
        $team_table->display();
        print '</form>';
        print '</div>';
    }

    /**
     * @return void
     */
    public static function processTeamTableActions () {
        $table = new Buoy_Teams_List_Table(parent::$prefix . '_team');
        $teams = array();
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'single-' . $table->_args['plural'])) {
                $teams[] = $_GET['team_id'];
        } else if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'bulk-' . $table->_args['plural'])) {
            $teams = array_merge($teams, $_POST['teams']);
        }

        if (!empty($teams)) {
            foreach ($teams as $team_id) {
                $team = new self($team_id);
                if ('leave' === $table->current_action()) {
                    $team->remove_member(get_current_user_id());
                }
                if ('join' === $table->current_action()) {
                    $team->confirm_member(get_current_user_id());
                }
            }
            wp_safe_redirect(admin_url(
                'edit.php?page=' . urlencode($_GET['page'])
                . '&post_type=' . urlencode($_GET['post_type'])
            ));
        }
    }

    /**
     * Updates the team metadata (membership list).
     *
     * This is called by WordPress's `save_post_{$post->post_type}` hook.
     *
     * @see https://developer.wordpress.org/reference/hooks/save_post_post-post_type/
     *
     * @global $_POST
     *
     * @uses wp_verify_nonce()
     * @uses WP_Buoy_Team::remove_member()
     * @uses WP_Buoy_Team::get_member_ids()
     * @uses WP_Buoy_Team::add_member()
     *
     * @param int $post_id
     *
     * @return void
     */
    public static function saveTeam ($post_id) {
        $team = new self($post_id);
        // Remove any team members indicated.
        if (isset($_POST[parent::$prefix . '_choose_team_nonce']) && wp_verify_nonce($_POST[parent::$prefix . '_choose_team_nonce'], parent::$prefix . '_choose_team')) {
            if (isset($_POST['remove_team_members'])) {
                foreach ($_POST['remove_team_members'] as $id) {
                    $team->remove_member($id);
                }
            }
        }

        // Add a new team member
        if (isset($_POST[parent::$prefix . '_add_team_member_nonce']) && wp_verify_nonce($_POST[parent::$prefix . '_add_team_member_nonce'], parent::$prefix . '_add_team_member')) {
            $user_id = username_exists($_REQUEST[parent::$prefix . '_add_team_member']);
            if (false !== $user_id && !in_array($user_id, $team->get_member_ids())) {
                $team->add_member($user_id);
            }
        }
    }

    /**
     * Hooks the `deleted_post_meta` action.
     *
     * This is used primarily to detect when a user is removed from a
     * team and, when this occurrs, remove the confirmation flag, too.
     *
     * @see https://developer.wordpress.org/reference/hooks/deleted_meta_type_meta/
     *
     * @param array $meta_ids
     * @param int $post_id
     * @param string $meta_key
     * @param mixed $meta_value
     *
     * @return void
     */
    public static function deletedPostMeta ($meta_ids, $post_id, $meta_key, $meta_value) {
        $team = new self($post_id);
        if ('_team_members' === $meta_key) {
            // delete confirmation when removing a team member
            $team->unconfirm_member($meta_value);
        }
    }

    /**
     * @return void
     */
    public static function registerAdminMenu () {
        $hooks = array();

        $hooks[] = add_submenu_page(
            'edit.php?post_type=' . parent::$prefix . '_team',
            __('Team membership', 'buoy'),
            __('Team membership', 'buoy'),
            'read',
            parent::$prefix . '_team_membership',
            array(__CLASS__, 'renderTeamMembershipPage')
        );

        foreach ($hooks as $hook) {
            add_action('load-' . $hook, array('WP_Buoy_Plugin', 'addHelpTab'));
        }
    }

    /**
     * Dynamically configures user capabilities.
     * This dynamism prevents the need to write capabilities into the
     * database's `options` table's `wp_user_roles` record itself.
     *
     * Currently simply unconditionally gives every user the required
     * capabilities to manage their own crisis response teams.
     *
     * Called by the `user_has_cap` filter.
     *
     * @see https://developer.wordpress.org/reference/hooks/user_has_cap/
     *
     * @param array $caps The user's actual capabilities.
     *
     * @return array $caps
     */
    public static function filterCaps ($caps) {
        $caps['edit_'             . parent::$prefix . '_teams'] = true;
        $caps['delete_'           . parent::$prefix . '_teams'] = true;
        $caps['publish_'          . parent::$prefix . '_teams'] = true;
        $caps['edit_published_'   . parent::$prefix . '_teams'] = true;
        $caps['delete_published_' . parent::$prefix . '_teams'] = true;
        return $caps;
    }

    /**
     * Ensures that users can only see their own crisis teams in the
     * WP admin view when viewing their "My Teams" dashboard page.
     *
     * @param WP_Query $query 
     *
     * @return void
     */
    public static function filterTeamPostsList ($query) {
        if (is_admin()) {
            $screen = get_current_screen();
            if ('edit-' . parent::$prefix .'_team' === $screen->id && current_user_can('edit_' . parent::$prefix . '_teams')) {
                $query->set('author', get_current_user_id());
                add_filter('views_' . $screen->id, array(__CLASS__, 'removeTeamPostFilterLinks'));
                add_filter('post_row_actions', array(__CLASS__, 'removeTeamPostActionRowLinks'));
            }
        }
    }

    /**
     * Removes the filter links in the Team posts table list.
     *
     * @see https://developer.wordpress.org/reference/hooks/views_this-screen-id/
     *
     * @param array $items
     *
     * @return array $items
     */
    public static function removeTeamPostFilterLinks ($items) {
        unset($items['all']);
        unset($items['publish']);
        unset($items['draft']);
        return $items;
    }

    /**
     * Removes the "Quick Edit" link in the Team posts action row.
     *
     * @see https://developer.wordpress.org/reference/hooks/post_row_actions/
     *
     * @param array $items
     *
     * @return array $items
     */
    public static function removeTeamPostActionRowLinks ($items) {
        unset($items['inline hide-if-no-js']);
        return $items;
    }

}
