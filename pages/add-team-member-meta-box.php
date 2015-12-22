<table class="form-table" summary="">
    <tbody>
        <tr>
            <th>
                <label for="add-team-member"><?php esc_html_e('Add a team member', 'buoy');?></label>
            </th>
            <td>
                <input
                    list="available-team-members-list"
                    id="add-team-member"
                    name="<?php print esc_attr($this->prefix);?>_add_team_member"
                    placeholder="<?php esc_attr_e('Michelle', 'buoy');?>"
                />
                <datalist id="available-team-members-list">
                    <?php buoy_print_users_for_datalist();?>
                </datalist>
            </td>
        </tr>
    </tbody>
</table>
