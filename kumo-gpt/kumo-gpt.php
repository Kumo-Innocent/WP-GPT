<?php
/**
 * Kumo GPT
 *
 * @package KUMOGPT
 * @author Kum0 <nathan@kumo.fr>
 * @copyright 2024 Kumo
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Kumo plugin for ChatGPT support
 * Requires Plugins: classic-editor
 * Description: This plugin add ChatGPT functionalities the Classic Editor (tinyMCE editor)
 * Version: 1.0
 * Author: Kum0 <nathan@kumo.fr>
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kumo-gpt
 * Domain Path: /languages/
 */

use \Kumo\Encryption;

if( ! defined( 'WPINC' ) ) die( 'Why are you here ?' );

define( 'KUMO_GPT_API_URL', '' ); // Backend API URL
define( 'KUMO_LOGGED_IN_KEY', '' ); // Random KEY for encryption
define( 'KUMO_LOGGED_IN_SALT', '' ); // Random SALT for encryption
define( 'KUMO_GPT_DOMAIN', 'kumo-gpt' );

do_action( 'before_kumo_gpt' );

if( ! function_exists( 'kumo_gpt_activation' ) ) {
    /**
     * Activation hook : Save GPT key to WP
     *
     * @return void
     */
    function kumo_gpt_activation() {
        require_once WP_PLUGIN_DIR . '/kumo-gpt/Encryption.php';
        $security = new Encryption();
        $prompts = $security->encrypt( serialize( kumo_gpt_get_prompts() ) );
        add_option( 'kumo_gpt_prompts', $prompts );
        add_option( 'kumo_gpt_key', '' );
    }
    register_activation_hook( __FILE__, 'kumo_gpt_activation' );
}

if( ! function_exists( 'kumo_gpt_buttons' ) ) {
    /**
     * Init buttons filters for adding buttons
     *
     * @return void
     */
    function kumo_gpt_buttons() {
        add_filter( 'mce_external_plugins', 'kumo_gpt_add_buttons' );
        add_filter( 'mce_buttons', 'kumo_gpt_register_buttons' );
    }
    add_action( 'init', 'kumo_gpt_buttons' );
}

if( ! function_exists( 'kumo_gpt_editor_style' ) ) {
    /**
     * Add editor style to the editor page
     *
     * @return void
     */
    function kumo_gpt_editor_style() {
        wp_register_style( 'kumo-gpt-style', WP_PLUGIN_URL . '/kumo-gpt/assets/style.css', false, '1.0.0' );
        wp_enqueue_style( 'kumo-gpt-style' );
    }
    add_action( 'admin_enqueue_scripts', 'kumo_gpt_editor_style' );
}

if( ! function_exists( 'kumo_gpt_add_buttons' ) ) {
    /**
     * Add button JS script to the tinyMCE editor
     *
     * @param array $plugin
     * @return array
     */
    function kumo_gpt_add_buttons( array $plugin ) : array {
        $plugin[ 'kumo_gpt' ] = plugin_dir_url( __FILE__ ) . '/assets/editor.js';
        return $plugin;
    }
}

if( ! function_exists( 'kumo_gpt_register_buttons' ) ) {
    /**
     * Register the button for the tinyMCE core
     *
     * @param array $buttons
     * @return array
     */
    function kumo_gpt_register_buttons( array $buttons ) : array {
        array_push( $buttons, 'kumo_gpt' );
        return $buttons;
    }
}

if( ! function_exists( 'kumo_gpt_api' ) ) {
    /**
     * Run the effective API call
     *
     * @return void
     */
    function kumo_gpt_api() {
        if( ! isset( $_POST[ 'content' ] ) ) wp_send_json_error( __( 'Please provide content to continue.', KUMO_GPT_DOMAIN ) );
        require_once WP_PLUGIN_DIR . '/kumo-gpt/Encryption.php';
        $temp_key = get_option( 'kumo_gpt_key', null );
        if( ! isset( $temp_key ) ) wp_send_json_error( __( 'No GPT API key set.', KUMO_GPT_DOMAIN ) );
        $security = new Encryption();
        $api_key = $security->decrypt( sanitize_text_field( $temp_key ) );
        $content = $_POST[ 'content' ];
        $prompt_slug = sanitize_text_field( $_POST[ 'slug' ] );
        $response = wp_remote_post( KUMO_GPT_API_URL . '/prompt', array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => "Bearer $api_key",
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode( array(
                'message' => $content,
                'slug' => $prompt_slug
            ) )
        ) );
        if( is_wp_error( $response ) ) wp_send_json_error( $response );
        $body = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );
        if(
            ! boolval( $result[ 'code' ] ) &&
            ! empty( $result[ 'message' ] )
        ) wp_send_json_success( $result[ 'message' ] );
        wp_send_json_error( __( 'API response error.', KUMO_GPT_DOMAIN ) );
    }
    add_action( 'wp_ajax_oct_gpt_correction', 'kumo_gpt_api' );
}

if( ! function_exists( 'kumo_gpt_get_prompts' ) ) {
    /**
     * Get all availables prompts in array
     *
     * @return array
     */
    function kumo_gpt_get_prompts() {
        require_once WP_PLUGIN_DIR . '/kumo-gpt/Encryption.php';
        $temp_key = get_option( 'kumo_gpt_key', null );
        if( ! isset( $temp_key ) ) return array();
        $security = new Encryption();
        $api_key = $security->decrypt( sanitize_text_field( $temp_key ) );
        $response = wp_remote_get( KUMO_GPT_API_URL . '/get/prompts', array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => "Bearer $api_key",
            )
        ) );
        if( is_wp_error( $response ) ) return array();
        $body = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );
        if( isset( $result[ 'prompts' ] ) ) return $result[ 'prompts' ];
        return array();
    }
}

if( ! function_exists( 'kumo_gpt_deactivation' ) ) {
    /**
     * Deactivation hook : Delete GPT key from WP
     *
     * @return void
     */
    function kumo_gpt_deactivation() {
        delete_option( 'kumo_gpt_prompts' );
        delete_option( 'kumo_gpt_key' );
    }
    register_deactivation_hook( __FILE__, 'kumo_gpt_deactivation' );
}

if( ! function_exists( 'kumo_gpt_meta_box' ) ) {
    /**
     * Load meta box to the post editor page
     *
     * @return void
     */
    function kumo_gpt_meta_box() {
        add_meta_box(
            'kumo-gpt-meta-box',
            __( 'Kumo GPT addon', KUMO_GPT_DOMAIN ),
            'kumo_render_meta_box',
            'post',
            'normal',
            'low'
        );
    }
    add_action( 'add_meta_boxes', 'kumo_gpt_meta_box' );
}

if( ! function_exists( 'kumo_render_meta_box' ) ) {
    /**
     * Render the meta box using templates
     *
     * @return void
     */
    function kumo_render_meta_box() {
        require_once WP_PLUGIN_DIR . '/kumo-gpt/Encryption.php';
        $prompts = get_option( 'kumo_gpt_prompts', null );
        $security = new Encryption();
        $prompts = unserialize( $security->decrypt( $prompts ) );
        if( empty( $prompts ) ) {
            $security = new Encryption();
            $prompts = kumo_gpt_get_prompts();
            $temp_prompts = $security->encrypt( serialize( $prompts ) );
            update_option( 'kumo_gpt_prompts', $temp_prompts );
        }
        load_template(
            plugin_dir_path( __FILE__ ) . 'templates/meta-box.php',
            true,
            array(
                'prompts' => array_map(
                    fn( $y ) => array_filter(
                        $y,
                        function( $x ) {
                            return in_array( $x, array( 'uuid', 'nom', 'slug', 'config', 'description', 'values' ) );
                        },
                        ARRAY_FILTER_USE_KEY
                    ),
                    $prompts
                )
            )
        );
    }
}

if( ! function_exists( 'kumo_settings_page' ) ) {
    /**
     * Register Kumo settings page
     *
     * @return void
     */
    function kumo_settings_page(){
        add_menu_page(
            __( 'Kumo GPT Paramètres', KUMO_GPT_DOMAIN ),
            'Kumo GPT',
            'manage_options',
            'gpt-kumo-settings',
            'kumo_gpt_settings_page',
            'data:image/svg+xml;base64,' . base64_encode( '<svg width="1024pt" height="1024pt" version="1.0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" fill="#F0F6FC"><path d="M699.5 124.8c-6.7 2.3-10.3 4.9-25.3 18-25.1 21.8-61.9 64.2-92.4 106.2-7.7 10.7-14.4 19.8-14.7 20.2s-3.3 0-6.6-.7c-33.9-8-83.5-6.8-123.7 3.1-7.7 1.9-14.7 3.4-15.6 3.4-.9-.1-4.3-3.5-7.4-7.8-9.4-12.7-34.7-43.5-46.2-56.2-27.8-30.4-48.8-50.7-68.3-65.9-14.7-11.4-21.9-14.3-30.8-12.7-8.4 1.6-12.1 9.8-17.9 39.1-8.8 45.2-12.6 84.5-12.6 130.7.1 37.9 2.7 65.5 10.4 109L253 437l-2.3 5.2c-5 11.4-9.1 28.8-12.1 51.8-7.8 59.2-14.7 131.8-13.1 136.9.8 2.7 13.5 19.8 23.4 31.6 10 11.9 34.5 36.4 45.1 45.2 26.8 22 49.1 36 79.5 49.7 7.2 3.3 14.3 7.1 15.8 8.5 3.7 3.4 10.8 18.4 48.4 102.1 8.5 19 16.6 36.7 18 39.4l2.4 4.8 5.7-.6c14.8-1.8 56.4-11.2 68.2-15.6 1.4-.5 7.9-2.8 14.5-5.1 6.5-2.2 14.2-5.1 17-6.4 2.7-1.2 7.9-3.5 11.5-5.1 26.7-11.5 70.1-37.2 94.9-56.2 28.8-22.1 54-46.7 80.2-78.2 5.8-7.1 15.3-19.9 16.8-22.7.8-1.5 1.1-15.1 1.1-45.6v-43.5l3.5-9.1c8.3-21.9 14.2-45.5 16.4-66.3 1.4-12.3 1.4-42.6.1-54.3-.6-5-2.4-15.8-4.1-24l-3.1-15 1.6-18.5c6.8-77.2 4-130.3-10.5-198-6-28-20.5-75.1-27.7-89.8-4.4-9-10.7-12.5-19-10.3-5.8 1.6-11.4 6.3-21.8 18.5-20.7 23.9-43 55.8-60.9 87.1-9.9 17.2-26.5 49.7-26.5 51.9 0 .6 4.9 3.3 11 6 22.5 9.9 47.1 26 66.5 43.4 28.2 25.4 53 62.5 64.8 97.3 6.4 18.6 12.2 48.4 13.3 68.5 1.3 23.1-2.9 53.6-10.5 76.4-14.7 43.8-46.8 82.3-90 108-47.3 28.1-110.2 48.3-165.1 53.1-17.8 1.5-49.5.6-66.6-2-49-7.4-92.5-27.5-135.4-62.6-18.6-15.2-35.9-33.9-53.5-57.5l-7.7-10.5.6-12c1.4-24.2 4.4-53 12.2-115.5 1.9-15.4 4.4-27.3 9.5-44.7 11.2-38.2 25.5-63.8 53.3-95.2 18.5-20.8 38-35.9 63.1-48.6 23.6-12 44.8-18.8 77-24.6 9.7-1.8 17.1-2.3 38.9-2.6 29.6-.6 41.3.3 60.1 4.3 19.9 4.2 17.1 4.8 24.2-5.8C615.9 229.3 664 173.6 699 145.1c7.8-6.5 17.8-12.1 21.4-12.1 1.2 0 2.8-.5 3.6-1 1.3-.8 1.2-1.1-.5-2.5-7.6-5.9-16.1-7.6-24-4.7zm-430 60.4c.3 1.8 1 6.4 1.5 10.3.6 3.8 2 11.5 3.1 17 1.2 5.5 2.5 12 3 14.5 1.4 6.7 10.8 44.9 12.3 50 .8 2.5 2.2 7.2 3.1 10.5 3.4 11.7 6.7 22.2 11.2 35 6.7 19.3 6.9 17.8-2.2 28.2-13 14.8-24.2 30.6-32 45-1.5 2.9-3.1 5.3-3.5 5.3s-1-1.5-1.3-3.3c-.3-1.7-1.2-7-2.1-11.7-8.9-50.9-8.5-119.8.9-182.4.8-5.5 1.5-11.4 1.5-13.2 0-3.5 1.8-8.4 3.1-8.4.5 0 1.1 1.5 1.4 3.2z"/><path d="M530.4 313.4c-3.8 1.7-7.1 5.7-8.5 10.7-1.6 5.2.3 11.8 4.7 16.3l3.4 3.5v26.9c0 24.9.1 27.2 1.9 29.3 1.3 1.7 2.9 2.3 5.6 2.3 6.4 0 6.5-.4 6.5-30.8l.1-27.1 3.2-2c10.5-6.7 10.4-21-.2-28.2-3.9-2.7-11.7-3.1-16.7-.9z"/><path d="M589.4 363.2c-5.7 5.3-6.1 14-1 19.7 1.4 1.5 1.6 4.8 1.6 21.4v19.6l-7.1-2.4c-11.5-3.9-21-5.5-33.3-5.5-15.8 0-25.7 2.2-40.8 9.2l-6.7 3-4.5-4.2c-2.5-2.3-5-5.8-5.6-7.6-1.4-4.2-4.4-6.4-8.8-6.4-7.5 0-12.3 10.4-6.9 15 10.5 9.3 12.7 11.4 12.7 12.2 0 .5-3.2 4.6-7.1 9.1-16.6 19.1-23.9 39.1-23.9 64.9.1 33.5 15.2 62.2 42.5 80.3 8 5.4 23.1 12.4 26.6 12.5.9 0 1.9 1 2.3 2.2.3 1.3.6 18.3.6 37.9v35.7l11.8 12.1 11.7 12.1h5c4.8 0 5.2.2 7 3.6 4.4 8.1 15.2 11.1 22.6 6.1 3.7-2.4 7.9-9.7 7.9-13.7 0-3.6-4-11-7.2-13.4-1.8-1.2-4.9-2.1-8.5-2.4-5.3-.4-6.2-.1-11 3.2-2.9 2-5.8 3.6-6.5 3.6-1.5 0-5.3-3.4-13-11.7l-5.8-6.2V605.8l3.3.6c5.3 1 21.8-1.1 30.8-4 15.5-4.9 27.3-12 38.3-23.1 14.8-14.8 21.8-27.5 27.6-50.1l1.1-4.2 48.7.3c26.8.3 50.6.8 52.9 1.2 3.9.6 4.5.4 7.3-2.3 4-4.1 4.3-11 .5-14.7-3-3.1-8.2-3.3-11.8-.5-2.5 2-3.8 2-49.9 1.8l-47.3-.3-.3-4c-1.6-25.9-12.1-47.6-32.2-67.3l-9-8.7V408l.1-22.5 2.9-2.9c5.5-5.4 5.3-14.4-.6-19.5-2.9-2.6-4.3-3.1-8.4-3.1-4.3 0-5.6.5-8.6 3.2zm-17.9 82.2c28.2 9.6 47.4 36.1 47.5 65.4 0 19.9-7 36.6-21.2 50.4-13.5 13.1-30.9 19.4-51.1 18.6-18.7-.9-32.1-7.2-45.8-21.6-35.7-37.4-17.7-100.9 32.3-114.2 10.9-2.9 27.5-2.3 38.3 1.4z"/><path d="M541 454.7c-10.8 1.8-23.1 8.5-31.1 16.9-6 6.3-9 11.2-12.4 20.4-2.5 6.5-2.8 9-2.9 18.5-.1 8.3.4 12.3 1.8 16.5 8.3 24.3 26.9 39.2 50.6 40.7 24 1.6 47.5-13.7 56-36.4 11.9-31.7-6.7-67.4-39.5-75.8-5.5-1.4-16.6-1.8-22.5-.8zm16.1 15.7c3.7.8 8.6 2.4 11 3.6 6.4 3.3 14.7 11.7 18.3 18.5 3 5.8 3.1 6.5 3.1 18 0 11.1-.2 12.5-2.9 18.3-3.5 7.6-11.8 15.7-20.3 19.9-5.2 2.5-6.8 2.8-15.8 2.8-9.3 0-10.5-.2-16.6-3.2-13.1-6.5-20.8-16.1-23.5-29.7-1.9-9.1-1-16.3 3.2-25.6 4.8-10.7 17.5-20.6 29.6-22.9 6.1-1.2 6.6-1.2 13.9.3z"/><path d="M542.7 491c-6.8 2-12.5 9-14 16.8-.9 4.8 1 11.3 4.5 15.6 8.5 10.4 25 10.2 32.7-.4 11.7-16.1-4-37.7-23.2-32zm10.6 9.1c6.8 3.5 9.1 9.2 6.2 15.3-2.1 4.3-5.9 6.6-11.1 6.6-5.4 0-10.4-5.3-10.4-11 0-5.2 1.3-7.6 5.3-10 3.6-2.3 6.8-2.5 10-.9zM665.1 378.8c-6.8 3.4-8.6 11.2-4.1 17.4 1.1 1.5 2 3.4 2 4.2 0 .9-3.3 5.2-7.3 9.7l-7.2 8.1-5.6.1c-6.3.2-10 2.2-12.9 7-4.5 7.2-.8 17.7 7.5 21.7 6.3 3.1 10.6 2.1 16.2-3.5 4.1-4.1 4.3-4.5 4.3-10.4V427l8.5-8.9c7.8-8.2 8.5-9.4 8.5-13 0-3 .5-4.3 2.1-5.1 2.8-1.6 5.9-6.9 5.9-10.5 0-6-6.3-12.5-12.2-12.5-1.3 0-3.8.8-5.7 1.8zM303 469.4c-7 1.5-8.4 2.3-17.5 10-4.1 3.5-9.5 7.2-12 8.2-3.5 1.3-4.3 2-3.5 3 2 2.4 11.7 8.5 15.6 9.9l3.9 1.3 1.2 9.7c2.2 17.6 8.5 32.4 18.5 43 8.5 9.2 10.1 9.8 6.6 2.7-4.2-8.6-6-18.1-6-31.7-.1-13.6 1.7-23 5.9-30.7 2.6-4.8 8.4-10.8 10.3-10.8.6 0 1 14.5 1 38.7 0 23.2.4 40.3 1 42.5.9 3.1 1.7 3.8 5.7 5.2 5.3 1.8 14.1 2.1 19.5.6 3.4-1 3.7-1.4 4.8-6.3.6-2.8 1.4-10.4 1.7-16.7 1.4-27.1-5.4-50.1-18.9-64.5-11.4-12.3-24.4-17.1-37.8-14.1zM738.5 540.2c-8.6 3.1-13 13.2-9.3 21.3 1.1 2.5.7 3-8.2 12l-9.5 9.5H666l-2.9 2.7c-1.5 1.6-9.5 9.9-17.6 18.5L630.8 620h-11.3c-11 0-11.3-.1-12.9-2.5-2.8-4.3-7.5-6.5-13.7-6.5-10 0-16.4 6.8-15.6 16.6.9 12 13 18.8 23.7 13.4 2.2-1.1 4.6-2.9 5.2-4 1.2-1.8 2.4-2 15.5-2h14.1l35.6-37h45.1l12.5-12.9c12-12.3 12.7-12.9 17.2-13.3 5.9-.7 10.5-4 12.9-9.2 2.3-5.2 2.3-9 0-14.2-3.2-7.1-13-11-20.6-8.2zM374.1 605.6c-12.3 3.3-18 7.6-16.7 12.7.3 1.3 4.7 7.1 9.7 12.8 17 19.3 19.3 23.5 23.4 41.1 1.4 6.1 1.4 7.1-.1 11.5-2.1 6.1-4.7 11.3-9.2 18-1.9 2.9-3.2 5.6-2.9 6 1.7 1.6 9.8-2.2 14.6-6.9l5.1-5 6.4 4.2c8.1 5.2 14.5 7.7 22.7 8.6l6.4.7-7-5.3c-23.7-18.1-28.3-24.4-30.2-42.2-.9-8.6 1.7-12.9 16.8-28.1 7-7 12.9-13.6 13.2-14.6 2-7.9-15.4-15.2-36.2-15-5.9 0-13 .7-16 1.5zM228.4 677.8c-17.3 9.5-16.6 9-15.4 13.4 1.6 5.6 3.9 6.4 9.7 3.2 11.6-6.3 23.3-13.9 23.3-15.1 0-1.5-4-8.3-4.8-8.2-.4 0-6.2 3.1-12.8 6.7zM243.2 700.3c-5.6 5.6-10.2 11-10.2 12 0 2.2 4.7 6.7 7 6.7 2.2 0 20-18.6 20-20.8 0-1.6-4.6-8.2-5.8-8.2-.4 0-5.3 4.6-11 10.3z"/></svg>' ),
            20
        );
    }

    add_action('admin_menu', 'kumo_settings_page');
}

if( ! function_exists( 'kumo_gpt_settings_page' ) ) {
    /**
     * Kumo GPT settings page
     *
     * @return void
     */
    function kumo_gpt_settings_page() {
        require_once WP_PLUGIN_DIR . '/kumo-gpt/Encryption.php';
        $security = new Encryption();
        $refresh = function() use ( $security ) {
            $temp_prompts = $security->encrypt( serialize( kumo_gpt_get_prompts() ) );
            update_option( 'kumo_gpt_prompts', $temp_prompts );
        };
        if(
            ! empty( $_POST ) &&
            ! empty( $_POST[ 'kumo_gpt_key' ] ) &&
            ! empty( $_POST[ '_nonce' ] ) &&
            wp_verify_nonce( $_POST[ '_nonce' ], 'kumo-gpt-settings-save' )
        ) {
            update_option( 'kumo_gpt_key', $security->encrypt( $_POST[ 'kumo_gpt_key' ] ) );
            ?>
            <div class="notice notice-success is-dislissible">
                <p><?= _e( 'Les paramètres ont étés mis à jours !', KUMO_GPT_DOMAIN ) ?></p>
            </div>
            <?php
            $refresh();
        }
        if(
            isset( $_GET[ 'refresh' ] ) &&
            isset( $_GET[ 'nonce' ] ) &&
            wp_verify_nonce( $_GET[ 'nonce' ], 'kumo-gpt-prompts-refresh' )
        ) {
            $refresh();
            ?>
            <div class="notice notice-success is-dislissible">
                <p><?= _e( 'Les prompts disponibles ont étés mis à jours !', KUMO_GPT_DOMAIN ) ?></p>
            </div>
            <?php
        }
        $api_key = $security->decrypt( get_option( 'kumo_gpt_key', null ) );
        ?>
        <div class="wrap">
            <h1><?= _e( 'Kumo GPT paramètres', KUMO_GPT_DOMAIN ) ?></h1>
            <form action="admin.php?page=gpt-kumo-settings" method="POST">
                <input type="hidden" name="_nonce" value="<?= wp_create_nonce( 'kumo-gpt-settings-save' ) ?>">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?= _e( 'Clé API', KUMO_GPT_DOMAIN ) ?> (Kumo)</th>
                        <th>
                            <input type="text" name="kumo_gpt_key" value="<?= esc_attr( $api_key ) ?>">
                            <p><?= _e( 'Entrez la clé API fournis par Kumo.', KUMO_GPT_DOMAIN ) ?></p>
                        </th>
                    </tr>
                </table>
                <?php submit_button() ?>
            </form>
            <a href="admin.php?page=gpt-kumo-settings&refresh&nonce=<?= wp_create_nonce( 'kumo-gpt-prompts-refresh' ) ?>" class="button-seconday"><?= _e( 'Rafraichir les prompts disponibles', KUMO_GPT_DOMAIN ) ?></a>
        </div>
        <?php
    }
}

do_action( 'after_kumo_gpt' );