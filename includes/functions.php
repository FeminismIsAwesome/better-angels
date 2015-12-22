<?php
/**
 * This file contains various helper functions that don't really
 * make sense to keep in the plugin class proper.
 */

function buoy_print_users_for_datalist () {
    $args = array();
    if (!current_user_can('list_users')) {
        $args = array(
            'meta_key' => 'buoy_public_responder',
            'meta_value' => 1
        );
    }
    $users = get_users($args);
    foreach ($users as $usr) {
        if ($usr->ID !== get_current_user_id()) {
            print "<option value=\"{$usr->user_nicename}\" />{$usr->display_name}</options>";
        }
    }
}
