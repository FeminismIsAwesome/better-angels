<table class="form-table" summary="">
    <tbody>
        <tr>
            <th>
                <?php esc_html_e('Remove team members', 'buoy');?>
            </th>
            <td>
                <ul>
<?php
$team = array_unique(get_post_meta($post->ID, '_team_members'));
foreach ($team as $user_id) :
    $user = get_userdata($user_id);
    $is_confirmed = get_post_meta($post->ID, "_member_{$user_id}_is_confirmed", true);
?>
                    <li>
                        <label>
                            <input
                                type="checkbox"
                                name="remove_team_members[]"
                                value="<?php print esc_attr($user->ID);?>"
                            />
                            <?php print esc_html($user->display_name);?>
                            <span class="description">(<?php ($is_confirmed) ? esc_html_e('confirmed', 'buoy') : esc_html_e('pending', 'buoy') ;?>)</span>
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
