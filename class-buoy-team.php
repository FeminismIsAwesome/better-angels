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
     * @var WP_User
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
     * Checks to ensure there is at least one confirmed member on the
     * team.
     *
     * A "responder" in this context is a confirmed team member.
     *
     * @uses WP_Buoy_Team::is_confirmed()
     *
     * @return bool
     */
    public function has_responder () {
        foreach ($this->members as $member) {
            if ($this->is_confirmed($member->ID)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets the confirmed members of this team.
     *
     * @return int[]
     */
    public function get_confirmed_members () {
        $responders = array();
        foreach ($this->get_member_ids() as $id) {
            if ($this->is_confirmed($id)) {
                $responders[] = $id;
            }
        }
        return $responders;
    }

    /**
     * @return void
     */
    public static function register () {
        if (!class_exists('Buoy_Team_Membership_List_Table')) { // for the admin UI
            require plugin_dir_path(__FILE__) . 'class-buoy-team-membership-list-table.php';
        }

        $post_type = parent::$prefix . '_team';
        register_post_type($post_type, array(
            'label' => __('My Teams', 'buoy'),
            'labels' => array(
                'add_new_item' => __('Add New Team', 'buoy'),
                'edit_item' => __('Edit Team', 'buoy'),
                'search_items' => __('Search Teams', 'Buoy')
            ),
            'description' => __('Groups of crisis responders', 'buoy'),
            'public' => false,
            'show_ui' => true,
            'capability_type' => $post_type,
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
        add_action('load-post.php', array('WP_Buoy_Plugin', 'addHelpTab'));
        add_action('load-post-new.php', array('WP_Buoy_Plugin', 'addHelpTab'));
        add_action('load-edit.php', array('WP_Buoy_Plugin', 'addHelpTab'));

        wp_enqueue_style(
            __CLASS__ . '-style',
            plugins_url('css/admin-teams.css', __FILE__)
        );

        add_action('current_screen', array(__CLASS__, 'processTeamTableActions'));

        add_action('admin_menu', array(__CLASS__, 'registerAdminMenu'));

        add_action('pre_get_posts', array(__CLASS__, 'filterTeamPostsList'));

        add_action('post_updated', array(__CLASS__, 'postUpdated'), 10, 3);
        add_action("save_post_{$post_type}", array(__CLASS__, 'saveTeam'));

        add_action('deleted_post_meta', array(__CLASS__, 'deletedPostMeta'), 10, 4);

        add_action("{$post_type}_member_removed", array(__CLASS__, 'checkMemberCount'), 10, 2);

        add_filter('user_has_cap', array(__CLASS__, 'filterCaps'));

        add_filter("manage_{$post_type}_posts_columns", array(__CLASS__, 'filterTeamPostsColumns'));
        add_filter("manage_edit-{$post_type}_sortable_columns", array(__CLASS__, 'filterSortableColumns'));
        add_action('wp', array(__CLASS__, 'orderTeamPosts'));

        add_action("manage_{$post_type}_posts_custom_column", array(__CLASS__, 'renderTeamPostsColumn'), 10, 2);
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
        $post_type = parent::$prefix . '_team';
        $team_table = new Buoy_Team_Membership_List_Table($post_type);
        $team_table->prepare_items();
        print '<div class="wrap">';
        print '<form'
            . ' action="' . admin_url('edit.php?post_type=' . $post_type . '&page=' . parent::$prefix . '_team_membership') . '"'
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
        $table = new Buoy_Team_Membership_List_Table(parent::$prefix . '_team');
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
     * Checks to ensure a user doesn't leave themselves without any
     * responders.
     *
     * Teams are only "active" is they are in the "publish" status.
     * This checks a team transition and if the action leaves a user
     * without any responders, it will re-set the team's status.
     *
     * @see https://developer.wordpress.org/reference/hooks/post_updated/
     *
     * @param int $post_id
     * @param WP_Post $post_after
     * @param WP_Post $post_before
     *
     * @return void
     */
    public static function postUpdated ($post_id, $post_after, $post_before) {
        if (parent::$prefix . '_team' !== $post_after->post_type) {
            return;
        }
        if ('publish' === $post_before->post_status && 'publish' !== $post_after->post_status) {
            $buoy_user = new WP_Buoy_User($post_before->post_author);
            if (!$buoy_user->has_responder()) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_status' => 'publish'
                ));
            }
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
     * Checks if a team no longer has any members.
     *
     * If a team is emptied for any reason, whether because the user
     * has removed all their members or the members themselves decide
     * to leave, this will fire a "{$post->post_type}_emptied" hook.
     *
     * @param int $user_id The user who was just removed.
     * @param WP_Buoy_Team The team they left.
     */
    public static function checkMemberCount ($user_id, $team) {
        if (empty($team->get_member_ids())) {
            /**
             * Fires after the last member of a team is removed (or leaves).
             *
             * @param WP_Buoy_Team $team
             */
            do_action($team->post->post_type . '_emptied', $team);
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
     * Add custom columns shown in the "My Teams" admin UI.
     *
     * @see https://developer.wordpress.org/reference/hooks/manage_post_type_posts_columns/
     *
     * @param array $post_columns
     *
     * @return array
     */
    public static function filterTeamPostsColumns ($post_columns) {
        unset($post_columns['author']);
        $post_columns['num_members']       = __('Members', 'buoy');
        $post_columns['confirmed_members'] = __('Confirmed Members', 'buoy');
        return $post_columns;
    }

    /**
     * Makes the custom columns sortable in the "My Teams" admin UI.
     *
     * @see https://developer.wordpress.org/reference/hooks/manage_this-screen-id_sortable_columns/
     *
     * @param array $sortable_columns
     *
     * @return array
     */
    public static function filterSortableColumns ($sortable_columns) {
        $sortable_columns['num_members']       = parent::$prefix . '_team_member_count';
        $sortable_columns['confirmed_members'] = parent::$prefix . '_team_confirmed_members';
        return $sortable_columns;
    }

    /**
     * Re-orders the query results based on the team member count.
     *
     * This changes the global `$wp_query->posts` array directly.
     *
     * @todo Possibly the count should be its own meta field managed by us so this hook is more performant?
     *
     * @global $wp_query
     *
     * @uses WP_Buoy_Team::get_member_ids()
     * @uses WP_Buoy_Team::get_confirmed_members()
     *
     * @param WP $wp
     *
     * @return void
     */
    public static function orderTeamPosts ($wp) {
        if (is_admin() && isset($wp->query_vars['orderby'])) {

            if (parent::$prefix . '_team_member_count' === $wp->query_vars['orderby']) {
                $method = 'get_member_ids';
            } else if (parent::$prefix . '_team_confirmed_members' === $wp->query_vars['orderby']) {
                $method = 'get_confirmed_members';
            }

            if (isset($method)) {
                global $wp_query;
                $member_counts = array();
                foreach ($wp_query->posts as $post) {
                    $team = new WP_Buoy_Team($post->ID);
                    $member_counts[count($team->$method())][] = $post; // variable function
                }

                ksort($member_counts);

                if ('desc' === $wp->query_vars['order']) {
                    $member_counts = array_reverse($member_counts);
                }

                $sorted = array();
                foreach ($member_counts as $counts) {
                    foreach ($counts as $post) {
                        $sorted[] = $post;
                    }
                }

                $wp_query->posts = $sorted;
            }
        }
    }

    /**
     * Add the column content for custom columns in the "My Teams" UI.
     *
     * @see https://developer.wordpress.org/reference/hooks/manage_post-post_type_posts_custom_column/
     *
     * @param string $column_name
     * @param int $post_id
     *
     * @return void
     */
    public static function renderTeamPostsColumn ($column_name, $post_id) {
        $team = new WP_Buoy_Team($post_id);
        switch ($column_name) {
            case 'num_members':
                print esc_html(count($team->get_member_ids()));
                break;
            case 'confirmed_members':
                print esc_html(count($team->get_confirmed_members()));
                break;
        }
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
        if (is_admin() && $screen = get_current_screen()) {
            if ('edit-' . parent::$prefix .'_team' === $screen->id && current_user_can('edit_' . parent::$prefix . '_teams')) {
                $query->set('author', get_current_user_id());
                add_filter('views_' . $screen->id, array(__CLASS__, 'filterTeamPostViews'));
                add_filter('post_row_actions', array(__CLASS__, 'removeTeamPostActionRowLinks'));
            }
        }
    }

    /**
     * Removes the views links in the Team posts table.
     *
     * @see https://developer.wordpress.org/reference/hooks/views_this-screen-id/
     *
     * @param array $items
     *
     * @return array
     */
    public static function filterTeamPostViews ($items) {
        return array();
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
