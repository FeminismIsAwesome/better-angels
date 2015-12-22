<?php
/**
 * Plugin Name: Buoy (a Better Angels crisis response system)
 * Plugin URI: https://github.com/meitar/better-angels
 * Description: A community-based crisis response system. <strong>Like this plugin? Please <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=TJLPJYXHSRBEE&amp;lc=US&amp;item_name=Better%20Angels&amp;item_number=better-angels&amp;currency_code=USD&amp;bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted" title="Send a donation to the developer of Better Angels">donate</a>. &hearts; Thank you!</strong>
 * Version: 0.1
 * Author: Maymay <bitetheappleback@gmail.com>
 * Author URI: http://maymay.net/
 * Text Domain: buoy
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

class WP_Buoy_Plugin {

    private $prefix = 'buoy';

    public function __construct () {
        add_action('plugins_loaded', array($this, 'registerL10n'));

        add_action('init', array($this, 'registerPostTypes'));

        add_action('admin_menu', array($this, 'registerAdminMenu'));

        add_action('pre_get_posts', array($this, 'filterTeamPostsList'));

        add_action("save_post_{$this->prefix}_team", array($this, 'saveTeam'));
        add_action('deleted_post_meta', array($this, 'deletedPostMeta'));

        add_filter('user_has_cap', array($this, 'filterCaps'));
    }

    public function registerL10n () {
        load_plugin_textdomain('better-angels', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function registerPostTypes () {
        register_post_type("{$this->prefix}_alert", array(
            'label' => __('Alerts', 'better-angels'),
            'description' => __('A call for help.', 'better-angels'),
            'public' => false,
            'show_ui' => false,
            'hierarchical' => false,
            'supports' => array(
                'title',
                'author'
            ),
            'has_archive' => false,
            'rewrite' => false,
            'can_export' => false
        ));

        register_post_type("{$this->prefix}_team", array(
            'label' => __('My Teams', 'better-angels'),
            'labels' => array(
                'add_new_item' => __('Add New Team', 'buoy'),
                'edit_item' => __('Edit Team', 'buoy'),
                'search_items' => __('Search Teams', 'Buoy')
            ),
            'description' => __('Groups of crisis responders', 'better-angels'),
            'public' => false,
            'show_ui' => true,
            'capability_type' => "{$this->prefix}_team",
            'map_meta_cap' => true,
            'hierarchical' => false,
            'supports' => array(
                'title',
                'author'
            ),
            'register_meta_box_cb' => array($this, 'registerTeamMetaBoxes'),
            'has_archive' => false,
            'rewrite' => false,
            'can_export' => false,
            'menu_icon' => plugins_url('img/icon-bw-life-preserver.svg', __FILE__)
        ));
    }

    public function registerTeamMetaBoxes ($post) {
        add_meta_box(
            "{$this->prefix}-add-team-member",
            esc_html__('Add a team member', 'buoy'),
            array($this, 'renderAddTeamMemberMetaBox'),
            null,
            'normal',
            'high'
        );

        add_meta_box(
            "{$this->prefix}-current-team",
            sprintf(
                esc_html__('Current team members %s', 'buoy'),
                '<span class="count">' . count(array_unique(get_post_meta($post->ID, '_team_members'))) . '</span>'
            ),
            array($this, 'renderCurrentTeamMetaBox'),
            null,
            'normal',
            'high'
        );
    }

    public function registerAdminMenu () {
        $hooks = array();

        $hooks[] = add_options_page(
            __('Buoy Settings', 'buoy'),
            __('Buoy', 'buoy'),
            'manage_options',
            $this->prefix . '_settings',
            array($this, 'renderOptionsPage')
        );

        $hooks[] = add_submenu_page(
            "edit.php?post_type={$this->prefix}_team",
            __('Team membership', 'buoy'),
            __('Team membership', 'buoy'),
            'read',
            $this->prefix . '_team_membership',
            array($this, 'renderTeamMembershipPage')
        );

        $hooks[] = add_submenu_page(
            "edit.php?post_type={$this->prefix}_team",
            __('Safety information', 'buoy'),
            __('Safety information', 'buoy'),
            'read',
            $this->prefix . '_safety_info',
            array($this, 'renderSafetyInfoPage')
        );
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
     * @param array $caps The user's actual capabilities.
     * @return array $caps
     */
    public function filterCaps ($caps) {
        $caps["edit_{$this->prefix}_teams"] = true;
        $caps["delete_{$this->prefix}_teams"] = true;
        $caps["publish_{$this->prefix}_teams"] = true;
        $caps["edit_published_{$this->prefix}_teams"] = true;
        $caps["delete_published_{$this->prefix}_teams"] = true;
        return $caps;
    }

    /**
     * Ensures that users can only see their own crisis teams in the
     * WP admin view when viewing their "My Teams" dashboard page.
     *
     * @param WP_Query $query 
     */
    public function filterTeamPostsList ($query) {
        $screen = get_current_screen();
        if ("edit-{$this->prefix}_team" === $screen->id && current_user_can("edit_{$this->prefix}_teams")) {
            $query->set('author', get_current_user_id());
            add_filter('views_' . $screen->id, array($this, 'removeTeamPostFilterLinks'));
            add_filter('post_row_actions', array($this, 'removeTeamPostActionRowLinks'));
        }
    }

    /**
     * Removes the filter links in the Team posts table list.
     */
    public function removeTeamPostFilterLinks ($items) {
        unset($items['all']);
        unset($items['publish']);
        unset($items['draft']);
        return $items;
    }

    /**
     * Removes the "Quick Edit" link in the Team posts action row.
     */
    public function removeTeamPostActionRowLinks ($items) {
        unset($items['inline hide-if-no-js']);
        return $items;
    }

    public function renderAddTeamMemberMetaBox ($post) {
        wp_nonce_field($this->prefix . '_add_team_member', $this->prefix . '_add_team_member_nonce');
        require 'includes/functions.php';
        require 'pages/add-team-member-meta-box.php';
    }

    public function renderCurrentTeamMetaBox ($post) {
        wp_nonce_field($this->prefix . '_choose_team', $this->prefix . '_choose_team_nonce');
        require 'pages/current-team-meta-box.php';
    }

    public function renderTeamMembershipPage () {
        require_once 'pages/confirm-guardianship.php';
    }

    public function saveTeam ($post_id) {
        // Remove any team members indicated.
        if (isset($_POST[$this->prefix . '_choose_team_nonce']) && wp_verify_nonce($_POST[$this->prefix . '_choose_team_nonce'], $this->prefix . '_choose_team')) {
            if (is_array($_POST['remove_team_members'])) {
                foreach ($_POST['remove_team_members'] as $id) {
                    delete_post_meta($post_id, '_team_members', $id);
                }
            }
        }

        // Add a new team member
        if (isset($_POST[$this->prefix . '_add_team_member_nonce']) && wp_verify_nonce($_POST[$this->prefix . '_add_team_member_nonce'], $this->prefix . '_add_team_member')) {
            $current_team = array_unique(get_post_meta($post_id, '_team_members'));
            $user_id = username_exists($_REQUEST[$this->prefix . '_add_team_member']);
            if (false !== $user_id && !in_array($user_id, $current_team)) {
                add_post_meta($post_id, '_team_members', $user_id, false);
            }
        }
    }

    public function deletedPostMeta ($meta_ids, $post_id, $meta_key, $meta_value) {
        if ('_team_members' === $meta_key) { // delete confirmation when removing a team member
            delete_post_meta($post_id, "_member_{$meta_value}_is_confirmed");
        }
    }

}

new WP_Buoy_Plugin();
