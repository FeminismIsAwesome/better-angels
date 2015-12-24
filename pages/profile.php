<h2 id="<?php print esc_attr(WP_Buoy_Plugin::$prefix)?>-preferences"><?php esc_html_e('Buoy Preferences', 'buoy');?></h2>
<table id="" class="form-table">
    <tbody>
        <tr>
            <th>
                <label for="<?php esc_html_e(WP_Buoy_Plugin::$prefix);?>_gender_pronoun_possessive">
                    <?php esc_html_e('Gender pronoun (possessive)', 'buoy');?>
                </label>
            </th>
            <td>
                <input type="text"
                    id="<?php print esc_attr(WP_Buoy_Plugin::$prefix);?>_gender_pronoun_possessive"
                    name="<?php print esc_attr(WP_Buoy_Plugin::$prefix);?>_gender_pronoun_possessive"
                    value="<?php print esc_attr($options->get('gender_pronoun_possessive'));?>"
                />
                <p class="description"><?php esc_html_e('Your gender pronoun is a word you use to refer to yourself. The possessive construction of this word could be "her," "his," or "their," for example. Leave this blank to use a gender-neutral default.', 'buoy');?></p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_html_e(WP_Buoy_Plugin::$prefix);?>_phone_number">
                    <?php esc_html_e('Phone number', 'buoy');?>
                </label>
            </th>
            <td>
                <input type="tel"
                    id="<?php print esc_attr(WP_Buoy_Plugin::$prefix);?>_phone_number"
                    name="<?php print esc_attr(WP_Buoy_Plugin::$prefix);?>_phone_number"
                    value="<?php print esc_attr($options->get('phone_number'));?>"
                    pattern="(?:\\+\\d\\d?[-. ]?)?[-. ()0-9]+"
                />
                <span class="description"><?php esc_html_e('Optional, but will enable you to receive SMS/txt messages of alerts.', 'buoy');?></span>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_html_e(WP_Buoy_Plugin::$prefix);?>_sms_provider">
                    <?php esc_html_e('Phone company', 'buoy');?>
                </label>
            </th>
            <td>
                <select id="<?php esc_html_e(WP_Buoy_Plugin::$prefix);?>_sms_provider" name="<?php esc_html_e(WP_Buoy_Plugin::$prefix);?>_sms_provider">
                    <?php foreach ($options->default['sms_provider'] as $v) : ?>
                    <option <?php selected($v, $options->get('sms_provider'));?>><?php print esc_html($v);?></option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e('If you provided a phone number, you must also enter a phone company for SMS/txt messages to work.', 'buoy');?></span>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_html_e(WP_Buoy_Plugin::$prefix);?>_crisis_message">
                    <?php esc_html_e('Crisis message', 'buoy');?>
                </label>
            </th>
            <td>
                <textarea
                    id="<?php print esc_attr(WP_Buoy_Plugin::$prefix);?>_crisis_message"
                    name="<?php print esc_attr(WP_Buoy_Plugin::$prefix)?>_crisis_message"
                    maxlength="160"
                    ><?php print esc_textarea($options->get('crisis_message'));?></textarea>
                <p class="description">
                    <?php print sprintf(
                        esc_html__('Your crisis message is the call for help you send to your emergency team members. Make this short enough to fit inside a txt message!', 'buoy')
                    );?>
                </p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_html_e(WP_Buoy_Plugin::$prefix);?>_public_responder">
                    <?php esc_html_e('Suggest me to anyone making a response team', 'buoy');?>
                </label>
            </th>
            <td>
                <input type="checkbox"
                    id="<?php print esc_attr(WP_Buoy_Plugin::$prefix);?>_public_responder"
                    name="<?php print esc_attr(WP_Buoy_Plugin::$prefix);?>_public_responder"
                    <?php checked($options->get('public_responder'));?>
                    value="1"
                />
                <span class="description">
                    <?php print sprintf(
                        esc_html__('Check this box to list yourself publicly as an available crisis response team member. This enables users you do not know personally to invite you to join crisis response teams they are making.', 'buoy')
                    );?>
                </span>
            </td>
        </tr>
    </tbody>
</table>
