<table class="form-table" summary="">
    <tbody>
        <tr>
            <th>
                <?php esc_html_e('Remove team members', 'buoy');?>
            </th>
            <td>
                <ul>
<?php
$team = new WP_Buoy_Team($post->ID);
$users = array_merge($team->get_member_ids(), $team->get_invited_users());
foreach ($users as $user_id) :
    if (is_email($user_id)) {
        $display_name = $user_id;
    } else {
        $wp_user = get_userdata($user_id);
        $display_name = $wp_user->display_name;
    }
?>
                    <li>
                        <label>
                            <input
                                type="checkbox"
                                name="remove_team_members[]"
                                value="<?php print esc_attr($user_id);?>"
                            />
                            <?php print esc_html($display_name);?>
                            <span class="description">(<?php ($team->is_confirmed($user_id)) ? esc_html_e('confirmed', 'buoy') : esc_html_e('pending', 'buoy') ;?>)</span>
                        </label>
                    </li>
<?php
endforeach;
?>
                </ul>
            </td>
        </tr>
    </tbody>
</table>
