<?php
/**
 * This is the old original code, now deprecated, will be removed in next merge.
 *
 * @deprecated
 */

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

class BetterAngelsPlugin {

    public function __construct () {
        add_action('wp_ajax_' . $this->prefix . 'upload-media', array($this, 'handleMediaUpload'));
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

}
