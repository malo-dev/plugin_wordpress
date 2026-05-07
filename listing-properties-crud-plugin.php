<?php
/*
Plugin Name: Listing Properties CRUD
Description: CRUD WordPress + MySQL pour gerer des proprietes et les afficher en front avec un design type Tesoro Resales via le shortcode [listing_properties].
Version: 1.0.0
Author: GPT-5.4
*/

if (!defined('ABSPATH')) {
    exit;
}

define('LPC_SOURCE', 'tesoro');
define('LPC_MANAGER_PASSWORD_OPTION', 'lpc_manager_password_hash');
define('LPC_MANAGER_COOKIE', 'lpc_manager_access');

function lpc_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'listing_properties';
}

function lpc_manager_cookie_token() {
    $hash = (string) get_option(LPC_MANAGER_PASSWORD_OPTION, '');
    if ($hash === '') {
        return '';
    }

    return hash_hmac('sha256', 'lpc-front-manager', wp_salt('auth') . $hash);
}

function lpc_manager_has_password() {
    return get_option(LPC_MANAGER_PASSWORD_OPTION, '') !== '';
}

function lpc_manager_is_authorized() {
    if (current_user_can('manage_options')) {
        return true;
    }

    $cookie = isset($_COOKIE[LPC_MANAGER_COOKIE]) ? (string) wp_unslash($_COOKIE[LPC_MANAGER_COOKIE]) : '';
    $token  = lpc_manager_cookie_token();

    return $cookie !== '' && $token !== '' && hash_equals($token, $cookie);
}

function lpc_manager_set_cookie() {
    $token = lpc_manager_cookie_token();
    if ($token === '') {
        return;
    }

    setcookie(LPC_MANAGER_COOKIE, $token, time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    $_COOKIE[LPC_MANAGER_COOKIE] = $token;
}

function lpc_manager_clear_cookie() {
    setcookie(LPC_MANAGER_COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    unset($_COOKIE[LPC_MANAGER_COOKIE]);
}

function lpc_redirect_url($fallback = '') {
    $redirect = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
    if ($redirect !== '') {
        return $redirect;
    }

    if ($fallback !== '') {
        return $fallback;
    }

    $referer = wp_get_referer();
    return $referer ? $referer : home_url('/');
}

function lpc_activate_plugin() {
    global $wpdb;

    $table_name      = lpc_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id varchar(64) NOT NULL,
        ref varchar(64) DEFAULT '',
        property_date datetime DEFAULT NULL,
        price decimal(15,2) DEFAULT 0,
        currency varchar(10) DEFAULT 'EUR',
        price_freq varchar(20) DEFAULT 'sale',
        country varchar(120) DEFAULT '',
        province varchar(120) DEFAULT '',
        town varchar(120) DEFAULT '',
        location_detail varchar(255) DEFAULT '',
        property_type varchar(120) DEFAULT '',
        new_build tinyint(1) DEFAULT 0,
        part_ownership tinyint(1) DEFAULT 0,
        leasehold tinyint(1) DEFAULT 0,
        beds int DEFAULT 0,
        baths int DEFAULT 0,
        pool tinyint(1) DEFAULT 0,
        surface_built decimal(10,2) DEFAULT NULL,
        surface_plot decimal(10,2) DEFAULT NULL,
        energy_consumption varchar(10) DEFAULT '',
        energy_emissions varchar(10) DEFAULT '',
        description_raw longtext NULL,
        features_json longtext NULL,
        images_json longtext NULL,
        urls_raw longtext NULL,
        email varchar(190) DEFAULT '',
        prime tinyint(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY town (town),
        KEY property_type (property_type),
        KEY property_date (property_date),
        KEY price (price)
    ) {$charset_collate};";

    dbDelta($sql);
}

register_activation_hook(__FILE__, 'lpc_activate_plugin');

function lpc_bool($value) {
    return !empty($value) ? 1 : 0;
}

function lpc_nullable_string($value) {
    $value = is_string($value) ? trim(wp_unslash($value)) : '';
    return $value === '' ? null : $value;
}

function lpc_nullable_number($value) {
    if ($value === null || $value === '') {
        return null;
    }

    $value = str_replace(',', '.', (string) $value);
    return is_numeric($value) ? (float) $value : null;
}

function lpc_normalize_admin_datetime($value) {
    $value = is_string($value) ? trim(wp_unslash($value)) : '';
    if ($value === '') {
        return current_time('mysql');
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return current_time('mysql');
    }

    return gmdate('Y-m-d H:i:s', $timestamp);
}

function lpc_parse_features_text($text) {
    $text  = is_string($text) ? trim(wp_unslash($text)) : '';
    $items = preg_split('/[\r\n,]+/', $text);
    $items = array_filter(array_map('trim', $items));
    return array_values(array_unique($items));
}

function lpc_parse_images_text($text) {
    $text = is_string($text) ? trim(wp_unslash($text)) : '';

    if ($text === '') {
        return array();
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        $normalized = array();
        foreach ($decoded as $index => $item) {
            if (is_array($item) && !empty($item['url'])) {
                $normalized[] = array(
                    'id'  => isset($item['id']) ? (string) $item['id'] : (string) ($index + 1),
                    'url' => esc_url_raw(trim((string) $item['url'])),
                );
            } elseif (is_string($item) && trim($item) !== '') {
                $normalized[] = array(
                    'id'  => (string) ($index + 1),
                    'url' => esc_url_raw(trim($item)),
                );
            }
        }
        return array_values(array_filter($normalized, function ($item) {
            return !empty($item['url']);
        }));
    }

    $lines      = preg_split('/\r\n|\r|\n/', $text);
    $normalized = array();
    foreach ($lines as $index => $line) {
        $url = esc_url_raw(trim($line));
        if ($url !== '') {
            $normalized[] = array(
                'id'  => (string) ($index + 1),
                'url' => $url,
            );
        }
    }

    return $normalized;
}

function lpc_images_to_lines($images_json) {
    $images = json_decode((string) $images_json, true);
    if (!is_array($images)) {
        return '';
    }

    $urls = array();
    foreach ($images as $image) {
        if (!empty($image['url'])) {
            $urls[] = $image['url'];
        }
    }

    return implode("\n", $urls);
}

function lpc_parse_language_blocks($raw) {
    if (!$raw) {
        return array();
    }

    if (is_array($raw)) {
        return $raw;
    }

    $result = array();
    foreach (array('en', 'es', 'fr', 'nl', 'de') as $lang) {
        if (preg_match('/<' . preg_quote($lang, '/') . '>([\s\S]*?)<\/' . preg_quote($lang, '/') . '>/i', (string) $raw, $match)) {
            $result[$lang] = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return $result;
}

function lpc_build_property_payload_from_request() {
    $id_input = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    $id       = $id_input !== '' ? $id_input : wp_generate_uuid4();
    $features = lpc_parse_features_text($_POST['features_text'] ?? '');
    $images   = lpc_parse_images_text($_POST['images_text'] ?? '');

    $data = array(
        'id'                 => $id,
        'ref'                => sanitize_text_field(wp_unslash($_POST['ref'] ?? '')),
        'property_date'      => lpc_normalize_admin_datetime($_POST['property_date'] ?? ''),
        'price'              => (float) ($_POST['price'] ?? 0),
        'currency'           => sanitize_text_field(wp_unslash($_POST['currency'] ?? 'EUR')),
        'price_freq'         => sanitize_text_field(wp_unslash($_POST['price_freq'] ?? 'sale')),
        'country'            => sanitize_text_field(wp_unslash($_POST['country'] ?? '')),
        'province'           => sanitize_text_field(wp_unslash($_POST['province'] ?? '')),
        'town'               => sanitize_text_field(wp_unslash($_POST['town'] ?? '')),
        'location_detail'    => sanitize_text_field(wp_unslash($_POST['location_detail'] ?? '')),
        'property_type'      => sanitize_text_field(wp_unslash($_POST['property_type'] ?? '')),
        'new_build'          => lpc_bool($_POST['new_build'] ?? 0),
        'part_ownership'     => lpc_bool($_POST['part_ownership'] ?? 0),
        'leasehold'          => lpc_bool($_POST['leasehold'] ?? 0),
        'beds'               => (int) ($_POST['beds'] ?? 0),
        'baths'              => (int) ($_POST['baths'] ?? 0),
        'pool'               => lpc_bool($_POST['pool'] ?? 0),
        'surface_built'      => lpc_nullable_number($_POST['surface_built'] ?? ''),
        'surface_plot'       => lpc_nullable_number($_POST['surface_plot'] ?? ''),
        'energy_consumption' => sanitize_text_field(wp_unslash($_POST['energy_consumption'] ?? '')),
        'energy_emissions'   => sanitize_text_field(wp_unslash($_POST['energy_emissions'] ?? '')),
        'description_raw'    => isset($_POST['description_raw']) ? trim(wp_unslash($_POST['description_raw'])) : '',
        'features_json'      => wp_json_encode($features),
        'images_json'        => wp_json_encode($images),
        'urls_raw'           => isset($_POST['urls_raw']) ? trim(wp_unslash($_POST['urls_raw'])) : '',
        'email'              => sanitize_email(wp_unslash($_POST['email'] ?? '')),
        'prime'              => lpc_bool($_POST['prime'] ?? 0),
    );

    $formats = array(
        '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s',
        '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%f', '%s',
        '%s', '%s', '%s', '%s', '%s', '%s', '%d',
    );

    return array($id, $data, $formats);
}

function lpc_save_property_record($id, $data, $formats) {
    global $wpdb;

    $table_name = lpc_table_name();
    $exists     = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE id = %s", $id));

    if ($exists) {
        $wpdb->update($table_name, $data, array('id' => $id), $formats, array('%s'));
        return 'updated';
    }

    $wpdb->insert($table_name, $data, $formats);
    return 'created';
}

function lpc_delete_property_record($id) {
    global $wpdb;
    $wpdb->delete(lpc_table_name(), array('id' => $id), array('%s'));
}

function lpc_pick_lang_value($raw) {
    $parsed = lpc_parse_language_blocks($raw);
    foreach (array('en', 'es', 'fr', 'nl', 'de') as $lang) {
        if (!empty($parsed[$lang])) {
            return $parsed[$lang];
        }
    }
    return '';
}

function lpc_extract_first_url($raw) {
    $parsed = lpc_parse_language_blocks($raw);
    foreach (array('en', 'es', 'fr', 'nl', 'de') as $lang) {
        if (!empty($parsed[$lang])) {
            return trim($parsed[$lang]);
        }
    }
    return '';
}

function lpc_format_property_row($row) {
    $features = json_decode((string) $row['features_json'], true);
    $images   = json_decode((string) $row['images_json'], true);

    return array(
        'id'              => (string) $row['id'],
        'ref'             => (string) $row['ref'],
        'date'            => $row['property_date'] ? gmdate('Y-m-d H:i:s', strtotime($row['property_date'])) : null,
        'price'           => isset($row['price']) ? (float) $row['price'] : 0,
        'currency'        => $row['currency'] ? (string) $row['currency'] : 'EUR',
        'price_freq'      => $row['price_freq'] ? (string) $row['price_freq'] : 'sale',
        'country'         => $row['country'] !== '' ? (string) $row['country'] : null,
        'province'        => $row['province'] !== '' ? (string) $row['province'] : null,
        'town'            => $row['town'] !== '' ? (string) $row['town'] : null,
        'location_detail' => $row['location_detail'] !== '' ? (string) $row['location_detail'] : null,
        'type'            => $row['property_type'] !== '' ? (string) $row['property_type'] : null,
        'new_build'       => !empty($row['new_build']),
        'part_ownership'  => !empty($row['part_ownership']),
        'leasehold'       => !empty($row['leasehold']),
        'beds'            => isset($row['beds']) ? (int) $row['beds'] : 0,
        'baths'           => isset($row['baths']) ? (int) $row['baths'] : 0,
        'pool'            => !empty($row['pool']),
        'surface_area'    => array(
            'built' => $row['surface_built'] !== null ? (float) $row['surface_built'] : null,
            'plot'  => $row['surface_plot'] !== null ? (float) $row['surface_plot'] : null,
        ),
        'energy_rating'   => array(
            'consumption' => $row['energy_consumption'] !== '' ? (string) $row['energy_consumption'] : null,
            'emissions'   => $row['energy_emissions'] !== '' ? (string) $row['energy_emissions'] : null,
        ),
        'description'     => $row['description_raw'],
        'features'        => is_array($features) ? array_values($features) : array(),
        'images'          => is_array($images) ? array_values($images) : array(),
        'urls'            => $row['urls_raw'],
        'email'           => $row['email'] !== '' ? (string) $row['email'] : null,
        'prime'           => !empty($row['prime']),
    );
}

function lpc_query_properties($args = array()) {
    global $wpdb;

    $defaults = array(
        'page'      => 1,
        'limit'     => 20,
        'search'    => '',
        'town'      => '',
        'type'      => '',
        'bedrooms'  => '',
        'price_min' => '',
        'price_max' => '',
        'id'        => '',
    );

    $args       = wp_parse_args($args, $defaults);
    $table_name = lpc_table_name();
    $where      = array('1=1');
    $params     = array();

    if ($args['id'] !== '') {
        $where[]  = 'id = %s';
        $params[] = $args['id'];
    }

    if ($args['search'] !== '') {
        $like     = '%' . $wpdb->esc_like($args['search']) . '%';
        $where[]  = '(town LIKE %s OR province LIKE %s OR country LIKE %s OR ref LIKE %s OR property_type LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($args['town'] !== '') {
        $where[]  = 'town = %s';
        $params[] = $args['town'];
    }

    if ($args['type'] !== '') {
        $where[]  = 'property_type = %s';
        $params[] = $args['type'];
    }

    if ($args['bedrooms'] !== '' && is_numeric($args['bedrooms'])) {
        $where[]  = 'beds >= %d';
        $params[] = (int) $args['bedrooms'];
    }

    if ($args['price_min'] !== '' && is_numeric($args['price_min'])) {
        $where[]  = 'price >= %f';
        $params[] = (float) $args['price_min'];
    }

    if ($args['price_max'] !== '' && is_numeric($args['price_max'])) {
        $where[]  = 'price <= %f';
        $params[] = (float) $args['price_max'];
    }

    $limit  = max(1, (int) $args['limit']);
    $page   = max(1, (int) $args['page']);
    $offset = ($page - 1) * $limit;

    $where_sql = implode(' AND ', $where);
    $count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
    $data_sql  = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY property_date DESC, created_at DESC LIMIT %d OFFSET %d";

    if (!empty($params)) {
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        $rows  = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($params, array($limit, $offset))), ARRAY_A);
    } else {
        $total = (int) $wpdb->get_var($count_sql);
        $rows  = $wpdb->get_results($wpdb->prepare($data_sql, $limit, $offset), ARRAY_A);
    }

    $properties = array_map('lpc_format_property_row', $rows ?: array());
    $total_pages = max(1, (int) ceil($total / $limit));

    return array(
        'source'     => LPC_SOURCE,
        'total'      => $total,
        'page'       => $page,
        'limit'      => $limit,
        'totalPages' => $total_pages,
        'properties' => $properties,
    );
}

function lpc_get_filters_payload() {
    global $wpdb;

    $table_name = lpc_table_name();
    $towns      = $wpdb->get_col("SELECT DISTINCT town FROM {$table_name} WHERE town <> '' ORDER BY town ASC");
    $types      = $wpdb->get_col("SELECT DISTINCT property_type FROM {$table_name} WHERE property_type <> '' ORDER BY property_type ASC");

    return array(
        'towns' => array_values(array_filter($towns)),
        'types' => array_values(array_filter($types)),
    );
}

function lpc_register_rest_routes() {
    register_rest_route('listing-properties/v1', '/properties', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'lpc_rest_properties',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('listing-properties/v1', '/properties/filters', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'lpc_rest_filters',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('listing-properties/v1', '/properties/(?P<id>[A-Za-z0-9\-_]+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'lpc_rest_single_property',
        'permission_callback' => '__return_true',
    ));
}

add_action('rest_api_init', 'lpc_register_rest_routes');

function lpc_rest_properties(WP_REST_Request $request) {
    $payload = lpc_query_properties(array(
        'page'      => $request->get_param('page'),
        'limit'     => $request->get_param('limit'),
        'search'    => (string) $request->get_param('search'),
        'town'      => (string) $request->get_param('town'),
        'type'      => (string) $request->get_param('type'),
        'bedrooms'  => $request->get_param('bedrooms'),
        'price_min' => $request->get_param('priceMin'),
        'price_max' => $request->get_param('priceMax'),
    ));

    return rest_ensure_response($payload);
}

function lpc_rest_filters() {
    return rest_ensure_response(lpc_get_filters_payload());
}

function lpc_rest_single_property(WP_REST_Request $request) {
    $payload = lpc_query_properties(array(
        'id'    => sanitize_text_field((string) $request['id']),
        'page'  => 1,
        'limit' => 1,
    ));

    if (empty($payload['properties'])) {
        return new WP_Error('lpc_property_not_found', 'Property not found.', array('status' => 404));
    }

    return rest_ensure_response(array(
        'source'   => LPC_SOURCE,
        'property' => $payload['properties'][0],
    ));
}

function lpc_register_admin_menu() {
    add_menu_page(
        'Listing Properties',
        'Listing Properties',
        'manage_options',
        'listing-properties-crud',
        'lpc_render_admin_page',
        'dashicons-admin-home',
        26
    );
}

add_action('admin_menu', 'lpc_register_admin_menu');

function lpc_handle_save_property() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('lpc_save_property');

    list($id, $data, $formats) = lpc_build_property_payload_from_request();
    $message = lpc_save_property_record($id, $data, $formats);

    wp_safe_redirect(admin_url('admin.php?page=listing-properties-crud&message=' . $message));
    exit;
}

add_action('admin_post_lpc_save_property', 'lpc_handle_save_property');

function lpc_handle_delete_property() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
    check_admin_referer('lpc_delete_property_' . $id);

    lpc_delete_property_record($id);

    wp_safe_redirect(admin_url('admin.php?page=listing-properties-crud&message=deleted'));
    exit;
}

add_action('admin_post_lpc_delete_property', 'lpc_handle_delete_property');

function lpc_handle_save_manager_settings() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('lpc_save_manager_settings');

    $password = isset($_POST['manager_password']) ? (string) wp_unslash($_POST['manager_password']) : '';
    if ($password !== '') {
        update_option(LPC_MANAGER_PASSWORD_OPTION, wp_hash_password($password));
        lpc_manager_clear_cookie();
    }

    wp_safe_redirect(admin_url('admin.php?page=listing-properties-crud&message=settings_saved'));
    exit;
}

add_action('admin_post_lpc_save_manager_settings', 'lpc_handle_save_manager_settings');

function lpc_handle_front_manager_login() {
    check_admin_referer('lpc_front_manager_login');

    $redirect = lpc_redirect_url(home_url('/'));
    $password = isset($_POST['manager_password']) ? (string) wp_unslash($_POST['manager_password']) : '';
    $hash     = (string) get_option(LPC_MANAGER_PASSWORD_OPTION, '');

    if ($hash !== '' && wp_check_password($password, $hash)) {
        lpc_manager_set_cookie();
        wp_safe_redirect(add_query_arg('lpc_message', 'front_logged_in', $redirect));
        exit;
    }

    wp_safe_redirect(add_query_arg('lpc_message', 'front_login_error', $redirect));
    exit;
}

add_action('admin_post_nopriv_lpc_front_manager_login', 'lpc_handle_front_manager_login');
add_action('admin_post_lpc_front_manager_login', 'lpc_handle_front_manager_login');

function lpc_handle_front_manager_logout() {
    check_admin_referer('lpc_front_manager_logout');

    $redirect = lpc_redirect_url(home_url('/'));
    lpc_manager_clear_cookie();
    wp_safe_redirect(add_query_arg('lpc_message', 'front_logged_out', $redirect));
    exit;
}

add_action('admin_post_nopriv_lpc_front_manager_logout', 'lpc_handle_front_manager_logout');
add_action('admin_post_lpc_front_manager_logout', 'lpc_handle_front_manager_logout');

function lpc_handle_front_save_property() {
    if (!lpc_manager_is_authorized()) {
        wp_die('Unauthorized');
    }

    check_admin_referer('lpc_front_save_property');

    list($id, $data, $formats) = lpc_build_property_payload_from_request();
    $message = lpc_save_property_record($id, $data, $formats);
    $redirect = lpc_redirect_url(home_url('/'));

    wp_safe_redirect(add_query_arg('lpc_message', $message, $redirect));
    exit;
}

add_action('admin_post_nopriv_lpc_front_save_property', 'lpc_handle_front_save_property');
add_action('admin_post_lpc_front_save_property', 'lpc_handle_front_save_property');

function lpc_handle_front_delete_property() {
    if (!lpc_manager_is_authorized()) {
        wp_die('Unauthorized');
    }

    $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
    check_admin_referer('lpc_front_delete_property_' . $id);

    lpc_delete_property_record($id);
    $redirect = lpc_redirect_url(home_url('/'));

    wp_safe_redirect(add_query_arg('lpc_message', 'deleted', $redirect));
    exit;
}

add_action('admin_post_nopriv_lpc_front_delete_property', 'lpc_handle_front_delete_property');
add_action('admin_post_lpc_front_delete_property', 'lpc_handle_front_delete_property');

function lpc_get_admin_edit_property() {
    global $wpdb;

    $edit_id = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
    if ($edit_id === '') {
        return null;
    }

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . lpc_table_name() . ' WHERE id = %s', $edit_id), ARRAY_A);
}

function lpc_render_admin_notice() {
    $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
    if ($message === '') {
        return;
    }

    $map = array(
        'created' => 'Propriete creee avec succes.',
        'updated' => 'Propriete mise a jour avec succes.',
        'deleted' => 'Propriete supprimee avec succes.',
        'settings_saved' => 'Acces partage mis a jour avec succes.',
    );

    if (!isset($map[$message])) {
        return;
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($map[$message]) . '</p></div>';
}

function lpc_render_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;

    $editing    = lpc_get_admin_edit_property();
    $table_name = lpc_table_name();
    $items      = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY property_date DESC, created_at DESC", ARRAY_A);
    $sample     = array(
        '<en>https://example.com/en/property/demo</en>',
        '<es>https://example.com/es/property/demo</es>',
        '<fr>https://example.com/fr/property/demo</fr>',
    );
    ?>
    <div class="wrap">
        <h1>Listing Properties CRUD</h1>
        <?php lpc_render_admin_notice(); ?>
        <p>
            Utilise le shortcode <code>[listing_properties]</code> pour afficher le listing public.
            L'API JSON est disponible sur <code><?php echo esc_html(rest_url('listing-properties/v1/properties')); ?></code>.
        </p>

        <style>
            .lpc-grid{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:16px;max-width:1200px}
            .lpc-grid .wide{grid-column:1 / -1}
            .lpc-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;margin-top:18px}
            .lpc-card h2{margin-top:0}
            .lpc-field label{display:block;font-weight:600;margin-bottom:6px}
            .lpc-field input[type="text"],.lpc-field input[type="email"],.lpc-field input[type="number"],.lpc-field input[type="datetime-local"],.lpc-field textarea,.lpc-field select{width:100%}
            .lpc-checks{display:flex;gap:18px;flex-wrap:wrap}
            .lpc-table td,.lpc-table th{vertical-align:top}
            .lpc-muted{color:#646970}
            @media (max-width: 900px){.lpc-grid{grid-template-columns:1fr}}
        </style>

        <div class="lpc-card">
            <h2>Acces partage en front</h2>
            <p>
                Cree une page WordPress avec le shortcode <code>[listing_properties_manager]</code> pour obtenir une URL partageable protegee par mot de passe.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lpc_save_manager_settings">
                <?php wp_nonce_field('lpc_save_manager_settings'); ?>
                <div class="lpc-grid">
                    <div class="lpc-field">
                        <label for="lpc-manager-password">Mot de passe de la page partagee</label>
                        <input type="text" id="lpc-manager-password" name="manager_password" value="" placeholder="<?php echo lpc_manager_has_password() ? 'Laisse vide pour conserver le mot de passe actuel' : 'Definis un mot de passe'; ?>">
                    </div>
                    <div class="lpc-field">
                        <label>Etat</label>
                        <p class="lpc-muted" style="margin-top:10px"><?php echo lpc_manager_has_password() ? 'Acces protege actif.' : 'Aucun mot de passe defini pour le moment.'; ?></p>
                    </div>
                </div>
                <p>
                    <button type="submit" class="button">Enregistrer l'acces partage</button>
                </p>
            </form>
        </div>

        <div class="lpc-card">
            <h2><?php echo $editing ? 'Modifier la propriete' : 'Ajouter une propriete'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lpc_save_property">
                <?php wp_nonce_field('lpc_save_property'); ?>

                <div class="lpc-grid">
                    <div class="lpc-field">
                        <label for="lpc-id">ID</label>
                        <input type="text" id="lpc-id" name="id" value="<?php echo esc_attr($editing['id'] ?? ''); ?>" placeholder="Laisse vide pour generation auto">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-ref">Reference</label>
                        <input type="text" id="lpc-ref" name="ref" value="<?php echo esc_attr($editing['ref'] ?? ''); ?>" placeholder="ELI3">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-date">Date</label>
                        <input type="datetime-local" id="lpc-date" name="property_date" value="<?php echo esc_attr(!empty($editing['property_date']) ? gmdate('Y-m-d\TH:i', strtotime($editing['property_date'])) : gmdate('Y-m-d\TH:i', current_time('timestamp', true))); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-price">Prix</label>
                        <input type="number" step="0.01" id="lpc-price" name="price" value="<?php echo esc_attr($editing['price'] ?? '0'); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-currency">Devise</label>
                        <input type="text" id="lpc-currency" name="currency" value="<?php echo esc_attr($editing['currency'] ?? 'EUR'); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-price-freq">Frequence prix</label>
                        <select id="lpc-price-freq" name="price_freq">
                            <option value="sale" <?php selected($editing['price_freq'] ?? 'sale', 'sale'); ?>>sale</option>
                            <option value="month" <?php selected($editing['price_freq'] ?? '', 'month'); ?>>month</option>
                            <option value="week" <?php selected($editing['price_freq'] ?? '', 'week'); ?>>week</option>
                        </select>
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-country">Pays</label>
                        <input type="text" id="lpc-country" name="country" value="<?php echo esc_attr($editing['country'] ?? 'Spain'); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-province">Province</label>
                        <input type="text" id="lpc-province" name="province" value="<?php echo esc_attr($editing['province'] ?? ''); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-town">Ville</label>
                        <input type="text" id="lpc-town" name="town" value="<?php echo esc_attr($editing['town'] ?? ''); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-location-detail">Detail emplacement</label>
                        <input type="text" id="lpc-location-detail" name="location_detail" value="<?php echo esc_attr($editing['location_detail'] ?? ''); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-type">Type</label>
                        <input type="text" id="lpc-type" name="property_type" value="<?php echo esc_attr($editing['property_type'] ?? 'Apartment'); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-email">Email contact</label>
                        <input type="email" id="lpc-email" name="email" value="<?php echo esc_attr($editing['email'] ?? ''); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-beds">Chambres</label>
                        <input type="number" id="lpc-beds" name="beds" value="<?php echo esc_attr($editing['beds'] ?? '0'); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-baths">Salles de bain</label>
                        <input type="number" id="lpc-baths" name="baths" value="<?php echo esc_attr($editing['baths'] ?? '0'); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-surface-built">Surface construite</label>
                        <input type="number" step="0.01" id="lpc-surface-built" name="surface_built" value="<?php echo esc_attr($editing['surface_built'] ?? ''); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-surface-plot">Surface terrain</label>
                        <input type="number" step="0.01" id="lpc-surface-plot" name="surface_plot" value="<?php echo esc_attr($editing['surface_plot'] ?? ''); ?>">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-energy-consumption">Classe conso</label>
                        <input type="text" id="lpc-energy-consumption" name="energy_consumption" value="<?php echo esc_attr($editing['energy_consumption'] ?? ''); ?>" placeholder="B">
                    </div>

                    <div class="lpc-field">
                        <label for="lpc-energy-emissions">Classe emissions</label>
                        <input type="text" id="lpc-energy-emissions" name="energy_emissions" value="<?php echo esc_attr($editing['energy_emissions'] ?? ''); ?>" placeholder="A">
                    </div>

                    <div class="lpc-field wide">
                        <label>Options</label>
                        <div class="lpc-checks">
                            <label><input type="checkbox" name="new_build" value="1" <?php checked(!empty($editing['new_build'])); ?>> New build</label>
                            <label><input type="checkbox" name="part_ownership" value="1" <?php checked(!empty($editing['part_ownership'])); ?>> Part ownership</label>
                            <label><input type="checkbox" name="leasehold" value="1" <?php checked(!empty($editing['leasehold'])); ?>> Leasehold</label>
                            <label><input type="checkbox" name="pool" value="1" <?php checked(!empty($editing['pool'])); ?>> Pool</label>
                            <label><input type="checkbox" name="prime" value="1" <?php checked(!empty($editing['prime'])); ?>> Prime</label>
                        </div>
                    </div>

                    <div class="lpc-field wide">
                        <label for="lpc-description">Description brute</label>
                        <textarea id="lpc-description" name="description_raw" rows="8" placeholder="<en>...html...</en>"><?php echo esc_textarea($editing['description_raw'] ?? ''); ?></textarea>
                    </div>

                    <div class="lpc-field wide">
                        <label for="lpc-features">Features</label>
                        <textarea id="lpc-features" name="features_text" rows="4" placeholder="Air Conditioning, Terrace"><?php echo esc_textarea(implode(', ', json_decode($editing['features_json'] ?? '[]', true) ?: array())); ?></textarea>
                    </div>

                    <div class="lpc-field wide">
                        <label for="lpc-images">Images</label>
                        <textarea id="lpc-images" name="images_text" rows="8" placeholder="Une URL par ligne ou JSON complet"><?php echo esc_textarea($editing ? lpc_images_to_lines($editing['images_json'] ?? '') : ''); ?></textarea>
                    </div>

                    <div class="lpc-field wide">
                        <label for="lpc-urls">URLs multilanguage</label>
                        <textarea id="lpc-urls" name="urls_raw" rows="5" placeholder="<?php echo esc_attr(implode("\n", $sample)); ?>"><?php echo esc_textarea($editing['urls_raw'] ?? ''); ?></textarea>
                    </div>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php echo $editing ? 'Mettre a jour' : 'Enregistrer'; ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=listing-properties-crud')); ?>" class="button">Nouveau</a>
                </p>
            </form>
        </div>

        <div class="lpc-card">
            <h2>Proprietes en base</h2>
            <table class="widefat striped lpc-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Reference</th>
                        <th>Ville</th>
                        <th>Type</th>
                        <th>Prix</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$items) : ?>
                        <tr>
                            <td colspan="7">Aucune propriete enregistree pour le moment.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($items as $item) : ?>
                            <tr>
                                <td><code><?php echo esc_html($item['id']); ?></code></td>
                                <td><?php echo esc_html($item['ref']); ?></td>
                                <td><?php echo esc_html($item['town']); ?></td>
                                <td><?php echo esc_html($item['property_type']); ?></td>
                                <td><?php echo esc_html(number_format((float) $item['price'], 0, ',', ' ')); ?> <?php echo esc_html($item['currency']); ?></td>
                                <td class="lpc-muted"><?php echo esc_html($item['property_date']); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=listing-properties-crud&edit=' . rawurlencode($item['id']))); ?>">Modifier</a>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lpc_delete_property&id=' . rawurlencode($item['id'])), 'lpc_delete_property_' . $item['id'])); ?>" onclick="return confirm('Supprimer cette propriete ?');">Supprimer</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function lpc_render_shortcode() {
    $preload_props   = lpc_query_properties(array('page' => 1, 'limit' => 20));
    $preload_filters = lpc_get_filters_payload();

    $api_base = rest_url('listing-properties/v1/properties');
    $api_one  = untrailingslashit(rest_url('listing-properties/v1/properties'));
    $api_filt = rest_url('listing-properties/v1/properties/filters');

    ob_start();
    ?>
    <style>
    .lpc-fullwidth-wrapper{width:100vw!important;max-width:100vw!important;margin-left:calc(-50vw + 50%)!important;margin-right:calc(-50vw + 50%)!important;padding-left:0!important;padding-right:0!important;overflow-x:hidden}
    #lpc-app *{box-sizing:border-box;margin:0;padding:0}
    #lpc-app{
      --sand:#fff;--clay:#FFF;--clay-dark:#e0a030;--ink:#1a1410;--ink-mid:#4a3f35;--ink-light:#8a7a6a;--sea:#2e6b8a;--sea-light:#e8f4f9;--white:#ffffff;--card-bg:#fffdf9;--border:#e8dfd0;--radius-lg:20px;--shadow:0 4px 24px rgba(26,20,16,.10);--shadow-hov:0 12px 40px rgba(26,20,16,.18);--font-serif:'Playfair Display',Georgia,serif;--font-sans:'DM Sans',system-ui,sans-serif;--tr:.25s cubic-bezier(.4,0,.2,1);--fs-xs:clamp(10px,1.1vw,12px);--fs-sm:clamp(12px,1.3vw,14px);--fs-base:clamp(14px,1.5vw,16px);--fs-lg:clamp(16px,1.8vw,20px);--fs-xl:clamp(18px,2.2vw,24px);--fs-2xl:clamp(22px,3vw,32px);--gap-sm:clamp(8px,1vw,12px);--gap-md:clamp(16px,2vw,24px);--gap-lg:clamp(24px,3vw,40px);--pad-x:clamp(16px,4vw,60px);
      font-family:var(--font-sans);font-size:var(--fs-base);color:var(--ink);background:var(--white);min-height:60vh;width:100%;max-width:100%;margin:0;overflow-x:hidden;padding-inline:10%
    }
    .lpc-filter-wrap{background:var(--white);border-bottom:1px solid var(--border);padding:var(--gap-sm) var(--pad-x);position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(26,20,16,.06);width:100%}
    .lpc-filter-bar{display:flex;flex-wrap:wrap;gap:var(--gap-sm);align-items:center;width:100%;max-width:1600px;margin:0 auto}
    .lpc-search-box{display:flex;align-items:center;gap:8px;background:var(--sand);border:1.5px solid var(--border);border-radius:8px;padding:0 14px;flex:1 1 200px;min-width:160px;color:var(--ink-light)}
    .lpc-search-box input{border:none;background:transparent;font-family:var(--font-sans);font-size:var(--fs-sm);color:var(--ink);width:100%;padding:10px 0;outline:none}
    .lpc-sel{background:var(--sand);border:1.5px solid var(--border);border-radius:8px;font-family:var(--font-sans);font-size:var(--fs-sm);color:var(--ink);padding:10px 12px;cursor:pointer;outline:none;transition:border-color var(--tr);min-width:110px;flex:1 1 110px}
    .lpc-reset-btn{display:flex;align-items:center;gap:6px;background:transparent;border:1.5px solid var(--border);border-radius:8px;padding:10px 14px;font-family:var(--font-sans);font-size:var(--fs-sm);color:var(--ink-light);cursor:pointer;transition:all var(--tr);white-space:nowrap}
    .lpc-reset-btn:hover,.lpc-sel:hover,.lpc-sel:focus{border-color:var(--clay);color:var(--clay)}
    .lpc-filter-meta{max-width:1600px;margin:8px auto 0;font-size:var(--fs-xs);color:var(--ink-light)}
    .lpc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(clamp(260px,28vw,340px),1fr));gap:var(--gap-md);padding:var(--gap-lg) var(--pad-x);width:100%;max-width:1600px;margin:0 auto}
    .lpc-card{background:var(--card-bg);border-radius:var(--radius-lg);overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow);transition:transform var(--tr),box-shadow var(--tr);cursor:pointer;animation:lpc-fadeup .4s ease both}
    .lpc-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-hov)}
    @keyframes lpc-fadeup{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
    .lpc-card-img{position:relative;height:clamp(180px,22vw,260px);overflow:hidden;background:var(--border)}
    .lpc-card-img img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease}
    .lpc-card:hover .lpc-card-img img{transform:scale(1.05)}
    .lpc-card-badge{position:absolute;top:12px;left:12px;background:var(--clay);color:var(--ink);font-size:var(--fs-xs);font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:4px 10px;border-radius:100px}
    .lpc-card-badge.new{background:var(--sea);color:var(--white)}
    .lpc-card-badge.rent{background:#6b9e2e;color:var(--white)}
    .lpc-card-photo-count{position:absolute;bottom:10px;right:10px;background:rgba(26,20,16,.7);color:var(--white);font-size:var(--fs-xs);padding:3px 10px;border-radius:100px;display:flex;align-items:center;gap:5px}
    .lpc-card-body{padding:clamp(14px,1.5vw,20px) clamp(14px,1.5vw,20px) clamp(16px,1.8vw,22px)}
    .lpc-card-location{font-size:var(--fs-xs);font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#a07030;margin-bottom:6px}
    .lpc-card-title{font-family:var(--font-serif);font-size:var(--fs-lg);font-weight:700;color:var(--ink);line-height:1.25;margin-bottom:10px}
    .lpc-card-specs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
    .lpc-card-spec{display:flex;align-items:center;gap:5px;font-size:var(--fs-xs);color:var(--ink-mid);font-weight:500}
    .lpc-card-bottom{display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border);padding-top:12px}
    .lpc-card-price{font-family:var(--font-serif);font-size:var(--fs-xl);font-weight:700;color:var(--ink)}
    .lpc-card-price small{font-family:var(--font-sans);font-size:var(--fs-xs);color:var(--ink-light);font-weight:400}
    .lpc-card-btn{background:var(--clay);color:var(--ink);border:none;border-radius:8px;padding:9px 16px;font-size:var(--fs-xs);font-weight:700;cursor:pointer;transition:background var(--tr);font-family:var(--font-sans)}
    .lpc-card-btn:hover{background:var(--clay-dark);color:var(--white)}
    .lpc-skeleton{background:var(--card-bg);border-radius:var(--radius-lg);border:1px solid var(--border);overflow:hidden;animation:lpc-pulse 1.6s ease-in-out infinite}
    @keyframes lpc-pulse{0%,100%{opacity:1}50%{opacity:.5}}
    .lpc-sk-img{height:clamp(180px,22vw,260px);background:var(--border)}
    .lpc-sk-body{padding:18px 20px 20px}
    .lpc-sk-line{height:12px;background:var(--border);border-radius:6px;margin-bottom:10px}
    .lpc-sk-line.short{width:60%}.lpc-sk-line.med{width:80%}.lpc-sk-line.long{width:100%}
    .lpc-empty{text-align:center;padding:80px 24px;grid-column:1 / -1}
    .lpc-empty h3{font-family:var(--font-serif);font-size:var(--fs-xl);color:var(--ink);margin-bottom:8px}
    .lpc-empty p{color:var(--ink-light);font-size:var(--fs-base)}
    .lpc-pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:var(--gap-md) var(--pad-x) clamp(32px,5vw,60px);flex-wrap:wrap;width:100%}
    .lpc-page-btn{min-width:40px;height:40px;border:1.5px solid var(--border);background:var(--white);border-radius:8px;font-family:var(--font-sans);font-size:var(--fs-sm);font-weight:500;color:var(--ink);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;padding:0 10px;transition:all var(--tr)}
    .lpc-page-btn:hover:not([disabled]){border-color:var(--clay);color:#a07030}
    .lpc-page-btn.active{background:var(--clay);border-color:var(--clay);color:var(--ink);font-weight:700}
    .lpc-page-btn[disabled]{opacity:.4;cursor:default;pointer-events:none}
    .lpc-page-dots{color:var(--ink-light);padding:0 4px;line-height:40px}
    .lpc-detail-view{display:none;max-width:1600px;margin:0 auto;padding:var(--gap-lg) var(--pad-x) clamp(32px,5vw,60px)}
    .lpc-detail-back{display:inline-flex;align-items:center;gap:8px;color:#a07030;text-decoration:none;font-weight:700;margin-bottom:20px}
    .lpc-detail-hero{display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:18px;margin-bottom:24px}
    .lpc-detail-main{background:var(--border);border-radius:18px;overflow:hidden;height:clamp(280px,42vw,560px)}
    .lpc-detail-main img{width:100%;height:100%;object-fit:cover;display:block}
    .lpc-detail-thumbs{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
    .lpc-detail-thumb{border:none;padding:0;background:var(--border);border-radius:14px;overflow:hidden;cursor:pointer;height:130px}
    .lpc-detail-thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .lpc-detail-top{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:20px}
    .lpc-detail-loc{font-size:var(--fs-xs);font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#a07030;margin-bottom:6px}
    .lpc-detail-title{font-family:var(--font-serif);font-size:var(--fs-2xl);font-weight:700;color:var(--ink);line-height:1.2}
    .lpc-detail-price{font-family:var(--font-serif);font-size:var(--fs-2xl);font-weight:700;color:#a07030}
    .lpc-detail-freq{font-size:var(--fs-xs);color:var(--ink-light)}
    .lpc-detail-specs{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:10px;margin-bottom:22px}
    .lpc-detail-spec-card{background:var(--sand);border-radius:10px;padding:14px 12px;text-align:center}
    .lpc-detail-spec-card .label{font-size:var(--fs-xs);font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-light);margin-bottom:4px}
    .lpc-detail-spec-card .value{font-size:var(--fs-sm);font-weight:600;color:var(--ink)}
    .lpc-detail-section{margin-bottom:22px}
    .lpc-detail-section h4{font-size:var(--fs-xs);font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#a07030;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid var(--border)}
    .lpc-detail-desc{font-size:var(--fs-sm);line-height:1.75;color:var(--ink-mid)}
    .lpc-features{display:flex;flex-wrap:wrap;gap:8px}
    .lpc-feature-tag{background:var(--sea-light);color:var(--sea);font-size:var(--fs-xs);font-weight:500;padding:5px 12px;border-radius:100px}
    .lpc-detail-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}
    .lpc-btn-primary,.lpc-btn-secondary{border-radius:10px;padding:14px 20px;font-size:var(--fs-sm);font-family:var(--font-sans);cursor:pointer}
    .lpc-btn-primary{flex:1;min-width:130px;background:var(--clay);color:var(--ink);border:none;font-weight:700}
    .lpc-btn-secondary{background:transparent;color:var(--ink-mid);border:1.5px solid var(--border);font-weight:500}
    @media (min-width:601px){.lpc-fullwidth-wrapper{position:relative;left:20%}}
    @media (max-width:900px){.lpc-grid{grid-template-columns:repeat(2,1fr);padding:var(--gap-md) var(--pad-x);gap:var(--gap-sm)}}
    @media (max-width:600px){.lpc-filter-bar{flex-direction:column}.lpc-search-box,.lpc-sel,.lpc-reset-btn{width:100%;min-width:0;flex:1 1 100%}.lpc-grid{grid-template-columns:1fr;padding:12px var(--pad-x);gap:12px}.lpc-detail-hero{grid-template-columns:1fr}.lpc-detail-main{height:clamp(220px,55vw,300px)}.lpc-detail-top{flex-direction:column}.lpc-detail-specs{grid-template-columns:repeat(3,1fr)}}
    </style>

    <div class="lpc-fullwidth-wrapper">
        <div id="lpc-app">
            <div class="lpc-filter-wrap" id="lpc-filter-wrap">
                <div class="lpc-filter-bar">
                    <div class="lpc-search-box">
                        <span>&#128269;</span>
                        <input type="text" id="lpc-search" placeholder="Search town, province...">
                    </div>
                    <select id="lpc-town" class="lpc-sel"><option value="">All Towns</option></select>
                    <select id="lpc-type" class="lpc-sel"><option value="">All Types</option></select>
                    <select id="lpc-beds" class="lpc-sel">
                        <option value="">Any Beds</option>
                        <option value="1">1+ Beds</option>
                        <option value="2">2+ Beds</option>
                        <option value="3">3+ Beds</option>
                        <option value="4">4+ Beds</option>
                    </select>
                    <select id="lpc-price-min" class="lpc-sel"><option value="">Min Price</option></select>
                    <select id="lpc-price-max" class="lpc-sel"><option value="">Max Price</option></select>
                    <button class="lpc-reset-btn" id="lpc-reset">Reset</button>
                </div>
                <div class="lpc-filter-meta"><span id="lpc-count">Loading...</span></div>
            </div>

            <div class="lpc-grid" id="lpc-grid"></div>
            <div class="lpc-pagination" id="lpc-pagination"></div>
            <div class="lpc-detail-view" id="lpc-detail-view"></div>
        </div>
    </div>

    <script>
    (function () {
      'use strict';

      var API = <?php echo wp_json_encode($api_base); ?>;
      var API_ONE = <?php echo wp_json_encode($api_one); ?>;
      var FILT = <?php echo wp_json_encode($api_filt); ?>;
      var PRELOAD_DATA = <?php echo wp_json_encode($preload_props); ?>;
      var PRELOAD_FILTERS = <?php echo wp_json_encode($preload_filters); ?>;
      var LIMIT = 20;
      var page = 1, totalPages = 1, total = 0, loading = false;
      var filters = { search:'', town:'', type:'', beds:'', priceMin:'', priceMax:'' };
      var propMap = {};
      var baseUrl = location.href.split('?')[0];

      function $(id){ return document.getElementById(id); }

      var $filterWrap = $('lpc-filter-wrap');
      var $grid = $('lpc-grid');
      var $pager = $('lpc-pagination');
      var $detail = $('lpc-detail-view');
      var $count = $('lpc-count');
      var $search = $('lpc-search');
      var $town = $('lpc-town');
      var $type = $('lpc-type');
      var $beds = $('lpc-beds');
      var $pMin = $('lpc-price-min');
      var $pMax = $('lpc-price-max');
      var $reset = $('lpc-reset');

      function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (m) {
          return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
        });
      }

      function parseLangField(raw) {
        if (!raw) return {};
        if (typeof raw === 'object') return raw;
        var result = {};
        ['en','es','fr','nl','de'].forEach(function (lang) {
          var re = new RegExp('<' + lang + '>([\\s\\S]*?)<\\/' + lang + '>', 'i');
          var match = String(raw).match(re);
          if (match && match[1]) {
            result[lang] = match[1]
              .replace(/&lt;/g, '<')
              .replace(/&gt;/g, '>')
              .replace(/&amp;/g, '&')
              .replace(/&quot;/g, '"')
              .replace(/&#039;/g, "'")
              .replace(/&apos;/g, "'");
          }
        });
        return result;
      }

      function getDesc(p) {
        var d = parseLangField(p.description || '');
        return d.en || d.es || d.fr || d.nl || d.de || 'No description available.';
      }

      function getUrlEn(p) {
        var d = parseLangField(p.urls || '');
        return d.en || d.es || d.fr || d.nl || d.de || '';
      }

      function getImages(p) {
        return Array.isArray(p.images) ? p.images.filter(function (img) { return img && img.url; }) : [];
      }

      function propertyUrl(id) {
        return baseUrl + '?prop=' + encodeURIComponent(id);
      }

      function setDetailMode(isDetail) {
        $filterWrap.style.display = isDetail ? 'none' : 'block';
        $grid.style.display = isDetail ? 'none' : 'grid';
        $pager.style.display = isDetail ? 'none' : 'flex';
        $detail.style.display = isDetail ? 'block' : 'none';
      }

      function indexProperties(data) {
        (data.properties || []).forEach(function (p) {
          propMap[p.id] = p;
        });
      }

      function formatPrice(p) {
        var price = Number(p.price || 0).toLocaleString('en');
        if (p.price_freq === 'month') {
          return '&euro;' + price + ' <small>/mo</small>';
        }
        return '&euro;' + price;
      }

      function showSkeletons() {
        var h = '';
        for (var i = 0; i < 9; i++) {
          h += '<div class="lpc-skeleton"><div class="lpc-sk-img"></div><div class="lpc-sk-body"><div class="lpc-sk-line short"></div><div class="lpc-sk-line med" style="height:18px;margin-bottom:14px"></div><div class="lpc-sk-line long"></div><div class="lpc-sk-line long"></div><div class="lpc-sk-line short" style="margin-top:14px;height:16px"></div></div></div>';
        }
        $grid.innerHTML = h;
      }

      function initFilters(data) {
        $town.innerHTML = '<option value="">All Towns</option>';
        (data.towns || []).forEach(function (v) { $town.add(new Option(v, v)); });
        $type.innerHTML = '<option value="">All Types</option>';
        (data.types || []).forEach(function (v) { $type.add(new Option(v, v)); });
        var steps = [50000,100000,150000,200000,250000,300000,350000,400000,450000,500000,600000,700000,800000,900000,1000000,1250000,1500000,2000000,3000000];
        $pMin.innerHTML = '<option value="">Min Price</option>';
        $pMax.innerHTML = '<option value="">Max Price</option>';
        steps.forEach(function (s) {
          var f = s >= 1000000 ? (s / 1000000).toFixed(s % 1000000 === 0 ? 0 : 2) + 'M' : (s / 1000) + 'K';
          $pMin.add(new Option('>=' + f + ' EUR', s));
          $pMax.add(new Option('<=' + f + ' EUR', s));
        });
      }

      function loadFilters() {
        if (PRELOAD_FILTERS) {
          initFilters(PRELOAD_FILTERS);
          return;
        }
        fetch(FILT).then(function (r) { return r.json(); }).then(initFilters).catch(function () {});
      }

      function applyData(data) {
        total = data.total || 0;
        totalPages = data.totalPages || 1;
        propMap = {};
        indexProperties(data);
        renderCards(data.properties || []);
        renderPager();
        $count.textContent = total + ' propert' + (total === 1 ? 'y' : 'ies') + ' found';
      }

      function loadPage() {
        if (loading) return;
        var isFirst = page === 1 && !filters.search && !filters.town && !filters.type && !filters.beds && !filters.priceMin && !filters.priceMax;
        if (isFirst && PRELOAD_DATA) {
          applyData(PRELOAD_DATA);
          return;
        }
        loading = true;
        var params = new URLSearchParams({ page: page, limit: LIMIT });
        if (filters.search) params.set('search', filters.search);
        if (filters.town) params.set('town', filters.town);
        if (filters.type) params.set('type', filters.type);
        if (filters.beds) params.set('bedrooms', filters.beds);
        if (filters.priceMin) params.set('priceMin', filters.priceMin);
        if (filters.priceMax) params.set('priceMax', filters.priceMax);

        fetch(API + '?' + params.toString())
          .then(function (r) { return r.json(); })
          .then(function (data) {
            applyData(data);
            loading = false;
          })
          .catch(function () {
            $grid.innerHTML = '<div class="lpc-empty"><h3>Connection Error</h3><p>Could not load properties.</p></div>';
            $count.textContent = '';
            loading = false;
          });
      }

      function renderCards(list) {
        if (!list.length) {
          $grid.innerHTML = '<div class="lpc-empty"><h3>No Properties Found</h3><p>Try adjusting your filters.</p></div>';
          return;
        }
        var h = '';
        list.forEach(function (p, i) {
          var imgs = getImages(p);
          var img = imgs.length ? imgs[0].url : 'https://via.placeholder.com/600x400?text=No+Image';
          var badge = p.new_build ? '<span class="lpc-card-badge new">New Build</span>' : (p.price_freq === 'month' ? '<span class="lpc-card-badge rent">For Rent</span>' : '<span class="lpc-card-badge">For Sale</span>');
          h += '<div class="lpc-card" style="animation-delay:' + (i * 0.05) + 's" data-id="' + escapeHtml(p.id) + '">'
            + '<div class="lpc-card-img"><img src="' + escapeHtml(img) + '" alt="' + escapeHtml(p.town || '') + '" loading="lazy">' + badge
            + (imgs.length > 1 ? '<div class="lpc-card-photo-count">' + imgs.length + ' photos</div>' : '')
            + '</div><div class="lpc-card-body">'
            + '<div class="lpc-card-location">' + escapeHtml(p.province || p.country || '') + '</div>'
            + '<div class="lpc-card-title">' + escapeHtml((p.type || 'Property') + ' - ' + (p.town || 'Spain')) + '</div>'
            + '<div class="lpc-card-specs">'
            + (p.beds ? '<span class="lpc-card-spec">' + escapeHtml(p.beds + ' bed' + (p.beds > 1 ? 's' : '')) + '</span>' : '')
            + (p.baths ? '<span class="lpc-card-spec">' + escapeHtml(p.baths + ' bath' + (p.baths > 1 ? 's' : '')) + '</span>' : '')
            + (p.surface_area && p.surface_area.built ? '<span class="lpc-card-spec">' + escapeHtml(p.surface_area.built + ' m2') + '</span>' : '')
            + (p.pool ? '<span class="lpc-card-spec">Pool</span>' : '')
            + '</div><div class="lpc-card-bottom"><div class="lpc-card-price">' + formatPrice(p) + '</div><button class="lpc-card-btn" type="button">View</button></div></div></div>';
        });
        $grid.innerHTML = h;

        Array.prototype.forEach.call($grid.querySelectorAll('.lpc-card'), function (card) {
          card.addEventListener('click', function () {
            window.location.href = propertyUrl(card.getAttribute('data-id'));
          });
        });
      }

      function pageNums(cur, tot) {
        if (tot <= 7) { var a = []; for (var i = 1; i <= tot; i++) a.push(i); return a; }
        if (cur <= 4) return [1,2,3,4,5,'...',tot];
        if (cur >= tot - 3) return [1,'...',tot - 4,tot - 3,tot - 2,tot - 1,tot];
        return [1,'...',cur - 1,cur,cur + 1,'...',tot];
      }

      function renderPager() {
        if (totalPages <= 1) {
          $pager.innerHTML = '';
          return;
        }
        var h = '<button class="lpc-page-btn"' + (page === 1 ? ' disabled' : ' data-page="' + (page - 1) + '"') + '>Prev</button>';
        pageNums(page, totalPages).forEach(function (n) {
          if (n === '...') h += '<span class="lpc-page-dots">...</span>';
          else h += '<button class="lpc-page-btn' + (n === page ? ' active' : '') + '"' + (n === page ? '' : ' data-page="' + n + '"') + '>' + n + '</button>';
        });
        h += '<button class="lpc-page-btn"' + (page === totalPages ? ' disabled' : ' data-page="' + (page + 1) + '"') + '>Next</button>';
        $pager.innerHTML = h;
        Array.prototype.forEach.call($pager.querySelectorAll('[data-page]'), function (btn) {
          btn.addEventListener('click', function () {
            page = parseInt(btn.getAttribute('data-page'), 10);
            showSkeletons();
            document.getElementById('lpc-app').scrollIntoView({ behavior:'smooth', block:'start' });
            loadPage();
          });
        });
      }

      function specCard(label, value) {
        return '<div class="lpc-detail-spec-card"><div class="label">' + escapeHtml(label) + '</div><div class="value">' + escapeHtml(value) + '</div></div>';
      }

      function bindDetailEvents(p) {
        var openBtn = document.getElementById('lpc-open-link');
        var contactBtn = document.getElementById('lpc-contact-link');
        var shareBtn = document.getElementById('lpc-share-link');
        var mainImgEl = document.getElementById('lpc-detail-main-img');
        var urlEn = getUrlEn(p);

        if (openBtn) openBtn.onclick = function () { window.open(urlEn, '_blank'); };
        if (contactBtn) contactBtn.onclick = function () { window.location.href = 'mailto:' + p.email + '?subject=Property ' + encodeURIComponent(p.ref || p.id); };
        if (shareBtn) shareBtn.onclick = function () {
          var shareUrl = propertyUrl(p.id);
          if (navigator.share) navigator.share({ title: p.ref || p.id, url: shareUrl }).catch(function () {});
          else if (navigator.clipboard) navigator.clipboard.writeText(shareUrl).then(function () { alert('Link copied!'); });
          else window.prompt('Copy this link:', shareUrl);
        };

        Array.prototype.forEach.call($detail.querySelectorAll('.lpc-detail-thumb'), function (thumb) {
          thumb.addEventListener('click', function () {
            if (mainImgEl) {
              mainImgEl.src = thumb.getAttribute('data-img');
            }
          });
        });
      }

      function renderDetailPage(p) {
        var imgs = getImages(p);
        var mainImg = imgs.length ? imgs[0].url : 'https://via.placeholder.com/1200x700?text=No+Image';
        var thumbs = imgs.slice(0, 4).map(function (img) {
          return '<button class="lpc-detail-thumb" type="button" data-img="' + escapeHtml(img.url) + '"><img src="' + escapeHtml(img.url) + '" alt="property" loading="lazy"></button>';
        }).join('');
        var priceHtml = p.price_freq === 'month' ? '&euro;' + Number(p.price || 0).toLocaleString('en') + '<span class="lpc-detail-freq"> / month</span>' : '&euro;' + Number(p.price || 0).toLocaleString('en');
        var specs = '';
        if (p.beds) specs += specCard('Beds', p.beds);
        if (p.baths) specs += specCard('Baths', p.baths);
        if (p.surface_area && p.surface_area.built) specs += specCard('Built', p.surface_area.built + ' m2');
        if (p.surface_area && p.surface_area.plot) specs += specCard('Plot', p.surface_area.plot + ' m2');
        specs += specCard('Pool', p.pool ? 'Yes' : 'No');
        if (p.new_build) specs += specCard('Build', 'New');
        if (p.energy_rating && p.energy_rating.consumption) specs += specCard('Energy', p.energy_rating.consumption);

        var feats = Array.isArray(p.features) ? p.features : [];
        var actions = '';
        if (getUrlEn(p)) actions += '<button class="lpc-btn-primary" type="button" id="lpc-open-link">Full Listing</button>';
        if (p.email) actions += '<button class="lpc-btn-secondary" type="button" id="lpc-contact-link">Contact</button>';
        actions += '<button class="lpc-btn-secondary" type="button" id="lpc-share-link">Share</button>';

        $detail.innerHTML = '<a class="lpc-detail-back" href="' + escapeHtml(baseUrl) + '">&larr; Back to properties</a>'
          + '<div class="lpc-detail-hero"><div class="lpc-detail-main"><img id="lpc-detail-main-img" src="' + escapeHtml(mainImg) + '" alt="property" loading="lazy"></div><div class="lpc-detail-thumbs">' + thumbs + '</div></div>'
          + '<div class="lpc-detail-top"><div><div class="lpc-detail-loc">' + escapeHtml([p.town,p.province,p.country].filter(Boolean).join(' - ')) + '</div><div class="lpc-detail-title">' + escapeHtml((p.type || 'Property') + (p.ref ? ' - ' + p.ref : '')) + '</div></div><div class="lpc-detail-price">' + priceHtml + '</div></div>'
          + '<div class="lpc-detail-specs">' + specs + '</div>'
          + (feats.length ? '<div class="lpc-detail-section"><h4>Features & Amenities</h4><div class="lpc-features">' + feats.map(function (f) { return '<span class="lpc-feature-tag">' + escapeHtml(f) + '</span>'; }).join('') + '</div></div>' : '')
          + '<div class="lpc-detail-section"><h4>Description</h4><div class="lpc-detail-desc">' + getDesc(p) + '</div></div>'
          + '<div class="lpc-detail-actions">' + actions + '</div>';

        setDetailMode(true);
        bindDetailEvents(p);
      }

      function loadPropertyAndRender(id) {
        if (propMap[id]) {
          renderDetailPage(propMap[id]);
          return;
        }

        fetch(API_ONE + '/' + encodeURIComponent(id))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.property && data.property.id) {
              propMap[data.property.id] = data.property;
              renderDetailPage(data.property);
            } else {
              setDetailMode(true);
              $detail.innerHTML = '<div class="lpc-empty"><h3>Property not found</h3><p>This property does not exist.</p></div>';
            }
          })
          .catch(function () {
            setDetailMode(true);
            $detail.innerHTML = '<div class="lpc-empty"><h3>Connection Error</h3><p>Could not load property details.</p></div>';
          });
      }

      function go() {
        page = 1;
        showSkeletons();
        loadPage();
      }

      var deb;
      $search.oninput = function () { clearTimeout(deb); deb = setTimeout(function () { filters.search = $search.value.trim(); go(); }, 300); };
      $town.onchange = function () { filters.town = $town.value; go(); };
      $type.onchange = function () { filters.type = $type.value; go(); };
      $beds.onchange = function () { filters.beds = $beds.value; go(); };
      $pMin.onchange = function () { filters.priceMin = $pMin.value; go(); };
      $pMax.onchange = function () { filters.priceMax = $pMax.value; go(); };
      $reset.onclick = function () {
        filters = { search:'', town:'', type:'', beds:'', priceMin:'', priceMax:'' };
        $search.value = '';
        [$town,$type,$beds,$pMin,$pMax].forEach(function (el) { el.value = ''; });
        go();
      };

      var urlParams = new URLSearchParams(window.location.search);
      var propFromUrl = urlParams.get('prop');

      if (propFromUrl) {
        if (PRELOAD_DATA) {
          indexProperties(PRELOAD_DATA);
        }
        loadPropertyAndRender(propFromUrl);
      } else {
        loadFilters();
        setDetailMode(false);
        if (PRELOAD_DATA) {
          applyData(PRELOAD_DATA);
        } else {
          showSkeletons();
          loadPage();
        }
      }
    })();
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('listing_properties', 'lpc_render_shortcode');

function lpc_render_manager_shortcode() {
    $current_url = get_permalink() ? get_permalink() : home_url(add_query_arg(array(), $GLOBALS['wp']->request ?? ''));
    $current_url = remove_query_arg(array('lpc_message', 'lpc_edit'), $current_url);
    $message     = isset($_GET['lpc_message']) ? sanitize_text_field(wp_unslash($_GET['lpc_message'])) : '';
    $editing     = null;

    if (lpc_manager_is_authorized()) {
        $edit_id = isset($_GET['lpc_edit']) ? sanitize_text_field(wp_unslash($_GET['lpc_edit'])) : '';
        if ($edit_id !== '') {
            global $wpdb;
            $editing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . lpc_table_name() . ' WHERE id = %s', $edit_id), ARRAY_A);
        }
    }

    ob_start();
    ?>
    <style>
    .lpcm-wrap{max-width:1280px;margin:40px auto;padding:0 20px;font-family:Arial,sans-serif;color:#1a1410}
    .lpcm-card{background:#fff;border:1px solid #e8dfd0;border-radius:18px;padding:24px;margin-bottom:20px;box-shadow:0 8px 26px rgba(26,20,16,.06)}
    .lpcm-title{font-size:28px;margin:0 0 8px}
    .lpcm-sub{color:#6b6257;margin-bottom:0}
    .lpcm-grid{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:16px}
    .lpcm-grid .wide{grid-column:1 / -1}
    .lpcm-label{display:block;font-weight:600;margin-bottom:6px}
    .lpcm-input,.lpcm-textarea,.lpcm-select{width:100%;border:1px solid #d8d0c2;border-radius:10px;padding:12px 14px;font-size:14px;background:#fff}
    .lpcm-textarea{min-height:110px;resize:vertical}
    .lpcm-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}
    .lpcm-btn{display:inline-block;border:none;border-radius:10px;padding:12px 18px;background:#d8aa52;color:#1a1410;font-weight:700;text-decoration:none;cursor:pointer}
    .lpcm-btn.alt{background:#f7f1e6}
    .lpcm-btn.danger{background:#f6d4d4}
    .lpcm-note{padding:14px 16px;border-radius:12px;margin-bottom:18px}
    .lpcm-note.ok{background:#edf8ee;color:#1f5f2c}
    .lpcm-note.err{background:#fff1f1;color:#8a1f1f}
    .lpcm-table{width:100%;border-collapse:collapse}
    .lpcm-table th,.lpcm-table td{padding:12px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
    .lpcm-checks{display:flex;gap:18px;flex-wrap:wrap}
    .lpcm-right{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
    @media (max-width:860px){.lpcm-grid{grid-template-columns:1fr}}
    </style>
    <div class="lpcm-wrap">
        <?php if ($message === 'front_login_error') : ?>
            <div class="lpcm-note err">Mot de passe incorrect.</div>
        <?php elseif (in_array($message, array('front_logged_in', 'front_logged_out', 'created', 'updated', 'deleted'), true)) : ?>
            <div class="lpcm-note ok">
                <?php
                $front_messages = array(
                    'front_logged_in'  => 'Connexion reussie.',
                    'front_logged_out' => 'Deconnexion reussie.',
                    'created'          => 'Propriete creee avec succes.',
                    'updated'          => 'Propriete mise a jour avec succes.',
                    'deleted'          => 'Propriete supprimee avec succes.',
                );
                echo esc_html($front_messages[$message]);
                ?>
            </div>
        <?php endif; ?>

        <?php if (!lpc_manager_has_password()) : ?>
            <div class="lpcm-card">
                <h2 class="lpcm-title">Acces non configure</h2>
                <p class="lpcm-sub">Va dans l'admin du plugin pour definir d'abord le mot de passe de la page partagee.</p>
            </div>
        <?php elseif (!lpc_manager_is_authorized()) : ?>
            <div class="lpcm-card" style="max-width:520px;margin-inline:auto">
                <h2 class="lpcm-title">Acces gestion proprietes</h2>
                <p class="lpcm-sub">Entre le mot de passe pour acceder uniquement a la gestion du listing.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="lpc_front_manager_login">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url($current_url); ?>">
                    <?php wp_nonce_field('lpc_front_manager_login'); ?>
                    <p>
                        <label class="lpcm-label" for="lpcm-pass">Mot de passe</label>
                        <input class="lpcm-input" type="password" id="lpcm-pass" name="manager_password" required>
                    </p>
                    <div class="lpcm-actions">
                        <button type="submit" class="lpcm-btn">Entrer</button>
                    </div>
                </form>
            </div>
        <?php else : ?>
            <?php
            global $wpdb;
            $items  = $wpdb->get_results('SELECT * FROM ' . lpc_table_name() . ' ORDER BY property_date DESC, created_at DESC', ARRAY_A);
            $sample = array(
                '<en>https://example.com/en/property/demo</en>',
                '<es>https://example.com/es/property/demo</es>',
            );
            ?>
            <div class="lpcm-card">
                <div class="lpcm-right">
                    <div>
                        <h2 class="lpcm-title">Gestion des proprietes</h2>
                        <p class="lpcm-sub">Ajout, modification et suppression depuis une page partageable securisee.</p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="lpc_front_manager_logout">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url($current_url); ?>">
                        <?php wp_nonce_field('lpc_front_manager_logout'); ?>
                        <button type="submit" class="lpcm-btn alt">Se deconnecter</button>
                    </form>
                </div>
            </div>

            <div class="lpcm-card">
                <h3 style="margin-top:0"><?php echo $editing ? 'Modifier la propriete' : 'Ajouter une propriete'; ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="lpc_front_save_property">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url($current_url); ?>">
                    <?php wp_nonce_field('lpc_front_save_property'); ?>
                    <div class="lpcm-grid">
                        <div><label class="lpcm-label">ID</label><input class="lpcm-input" type="text" name="id" value="<?php echo esc_attr($editing['id'] ?? ''); ?>" placeholder="Laisse vide pour auto"></div>
                        <div><label class="lpcm-label">Reference</label><input class="lpcm-input" type="text" name="ref" value="<?php echo esc_attr($editing['ref'] ?? ''); ?>"></div>
                        <div><label class="lpcm-label">Date</label><input class="lpcm-input" type="datetime-local" name="property_date" value="<?php echo esc_attr(!empty($editing['property_date']) ? gmdate('Y-m-d\TH:i', strtotime($editing['property_date'])) : gmdate('Y-m-d\TH:i', current_time('timestamp', true))); ?>"></div>
                        <div><label class="lpcm-label">Prix</label><input class="lpcm-input" type="number" step="0.01" name="price" value="<?php echo esc_attr($editing['price'] ?? '0'); ?>"></div>
                        <div><label class="lpcm-label">Devise</label><input class="lpcm-input" type="text" name="currency" value="<?php echo esc_attr($editing['currency'] ?? 'EUR'); ?>"></div>
                        <div><label class="lpcm-label">Frequence prix</label><select class="lpcm-select" name="price_freq"><option value="sale" <?php selected($editing['price_freq'] ?? 'sale', 'sale'); ?>>sale</option><option value="month" <?php selected($editing['price_freq'] ?? '', 'month'); ?>>month</option><option value="week" <?php selected($editing['price_freq'] ?? '', 'week'); ?>>week</option></select></div>
                        <div><label class="lpcm-label">Pays</label><input class="lpcm-input" type="text" name="country" value="<?php echo esc_attr($editing['country'] ?? 'Spain'); ?>"></div>
                        <div><label class="lpcm-label">Province</label><input class="lpcm-input" type="text" name="province" value="<?php echo esc_attr($editing['province'] ?? ''); ?>"></div>
                        <div><label class="lpcm-label">Ville</label><input class="lpcm-input" type="text" name="town" value="<?php echo esc_attr($editing['town'] ?? ''); ?>"></div>
                        <div><label class="lpcm-label">Detail emplacement</label><input class="lpcm-input" type="text" name="location_detail" value="<?php echo esc_attr($editing['location_detail'] ?? ''); ?>"></div>
                        <div><label class="lpcm-label">Type</label><input class="lpcm-input" type="text" name="property_type" value="<?php echo esc_attr($editing['property_type'] ?? 'Apartment'); ?>"></div>
                        <div><label class="lpcm-label">Email contact</label><input class="lpcm-input" type="email" name="email" value="<?php echo esc_attr($editing['email'] ?? ''); ?>"></div>
                        <div><label class="lpcm-label">Chambres</label><input class="lpcm-input" type="number" name="beds" value="<?php echo esc_attr($editing['beds'] ?? '0'); ?>"></div>
                        <div><label class="lpcm-label">Salles de bain</label><input class="lpcm-input" type="number" name="baths" value="<?php echo esc_attr($editing['baths'] ?? '0'); ?>"></div>
                        <div><label class="lpcm-label">Surface construite</label><input class="lpcm-input" type="number" step="0.01" name="surface_built" value="<?php echo esc_attr($editing['surface_built'] ?? ''); ?>"></div>
                        <div><label class="lpcm-label">Surface terrain</label><input class="lpcm-input" type="number" step="0.01" name="surface_plot" value="<?php echo esc_attr($editing['surface_plot'] ?? ''); ?>"></div>
                        <div><label class="lpcm-label">Classe conso</label><input class="lpcm-input" type="text" name="energy_consumption" value="<?php echo esc_attr($editing['energy_consumption'] ?? ''); ?>"></div>
                        <div><label class="lpcm-label">Classe emissions</label><input class="lpcm-input" type="text" name="energy_emissions" value="<?php echo esc_attr($editing['energy_emissions'] ?? ''); ?>"></div>
                        <div class="wide">
                            <label class="lpcm-label">Options</label>
                            <div class="lpcm-checks">
                                <label><input type="checkbox" name="new_build" value="1" <?php checked(!empty($editing['new_build'])); ?>> New build</label>
                                <label><input type="checkbox" name="part_ownership" value="1" <?php checked(!empty($editing['part_ownership'])); ?>> Part ownership</label>
                                <label><input type="checkbox" name="leasehold" value="1" <?php checked(!empty($editing['leasehold'])); ?>> Leasehold</label>
                                <label><input type="checkbox" name="pool" value="1" <?php checked(!empty($editing['pool'])); ?>> Pool</label>
                                <label><input type="checkbox" name="prime" value="1" <?php checked(!empty($editing['prime'])); ?>> Prime</label>
                            </div>
                        </div>
                        <div class="wide"><label class="lpcm-label">Description brute</label><textarea class="lpcm-textarea" name="description_raw"><?php echo esc_textarea($editing['description_raw'] ?? ''); ?></textarea></div>
                        <div class="wide"><label class="lpcm-label">Features</label><textarea class="lpcm-textarea" name="features_text"><?php echo esc_textarea(implode(', ', json_decode($editing['features_json'] ?? '[]', true) ?: array())); ?></textarea></div>
                        <div class="wide"><label class="lpcm-label">Images</label><textarea class="lpcm-textarea" name="images_text" placeholder="Une URL par ligne ou JSON complet"><?php echo esc_textarea($editing ? lpc_images_to_lines($editing['images_json'] ?? '') : ''); ?></textarea></div>
                        <div class="wide"><label class="lpcm-label">URLs multilanguage</label><textarea class="lpcm-textarea" name="urls_raw" placeholder="<?php echo esc_attr(implode("\n", $sample)); ?>"><?php echo esc_textarea($editing['urls_raw'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="lpcm-actions">
                        <button type="submit" class="lpcm-btn"><?php echo $editing ? 'Mettre a jour' : 'Enregistrer'; ?></button>
                        <a class="lpcm-btn alt" href="<?php echo esc_url($current_url); ?>">Nouveau</a>
                    </div>
                </form>
            </div>

            <div class="lpcm-card">
                <h3 style="margin-top:0">Proprietes en base</h3>
                <table class="lpcm-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Reference</th>
                            <th>Ville</th>
                            <th>Type</th>
                            <th>Prix</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$items) : ?>
                            <tr><td colspan="6">Aucune propriete enregistree.</td></tr>
                        <?php else : ?>
                            <?php foreach ($items as $item) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($item['id']); ?></code></td>
                                    <td><?php echo esc_html($item['ref']); ?></td>
                                    <td><?php echo esc_html($item['town']); ?></td>
                                    <td><?php echo esc_html($item['property_type']); ?></td>
                                    <td><?php echo esc_html(number_format((float) $item['price'], 0, ',', ' ')); ?> <?php echo esc_html($item['currency']); ?></td>
                                    <td>
                                        <a class="lpcm-btn alt" href="<?php echo esc_url(add_query_arg('lpc_edit', rawurlencode($item['id']), $current_url)); ?>">Modifier</a>
                                        <a class="lpcm-btn danger" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lpc_front_delete_property&id=' . rawurlencode($item['id']) . '&redirect_to=' . rawurlencode($current_url)), 'lpc_front_delete_property_' . $item['id'])); ?>" onclick="return confirm('Supprimer cette propriete ?');">Supprimer</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('listing_properties_manager', 'lpc_render_manager_shortcode');
