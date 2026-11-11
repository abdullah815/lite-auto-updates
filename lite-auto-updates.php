<?php
/**
 * Plugin Name: Lite Auto Updates
 * Description: Lightweight native WordPress automatic updates with selectable exclusions and email notifications.
 * Version: 1.1.1
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Author: Abdullah Techs
 * Author URI: https://www.abdullahtechs.com/
 * License: GPL-2.0-or-later
 * Text Domain: lite-auto-updates
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LAU_Lite_Auto_Updates {
    private const OPTION = 'lau_settings';

    private static function defaults() {
        return array(
            'plugins' => 1, 'themes' => 1, 'core_minor' => 1, 'core_major' => 0,
            'excluded_plugins' => '', 'excluded_themes' => '',
            'email_enabled' => 1, 'email_address' => '',
        );
    }
    private static function settings() {
        $saved = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }
    public static function init() {
        add_filter( 'auto_update_plugin', array( __CLASS__, 'plugin_update' ), 10, 2 );
        add_filter( 'auto_update_theme', array( __CLASS__, 'theme_update' ), 10, 2 );
        add_filter( 'allow_minor_auto_core_updates', array( __CLASS__, 'minor_core_update' ) );
        add_filter( 'allow_major_auto_core_updates', array( __CLASS__, 'major_core_update' ) );
        add_filter( 'auto_plugin_update_send_email', array( __CLASS__, 'notification_allowed' ) );
        add_filter( 'auto_theme_update_send_email', array( __CLASS__, 'notification_allowed' ) );
        add_filter( 'auto_core_update_send_email', array( __CLASS__, 'notification_allowed' ) );
        add_filter( 'auto_plugin_theme_update_email', array( __CLASS__, 'notification_recipient' ) );
        add_filter( 'auto_core_update_email', array( __CLASS__, 'notification_recipient' ) );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
            add_action( 'admin_post_lau_save_settings', array( __CLASS__, 'save_settings' ) );
            add_action( 'admin_post_lau_test_email', array( __CLASS__, 'test_email' ) );
        }
    }
    private static function exclusion_ids( $value ) {
        if ( is_array( $value ) ) { return array_values( array_filter( array_map( 'strval', $value ) ) ); }
        return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $value ) ) ) );
    }
    public static function plugin_update( $update, $item ) {
        $s = self::settings();
        if ( ! $s['plugins'] ) { return $update; } // Preserve native per-item WordPress selection.
        if ( ! is_object( $item ) || empty( $item->plugin ) || ! empty( $item->disable_autoupdate ) ) { return $update; }
        return ! in_array( (string) $item->plugin, self::exclusion_ids( $s['excluded_plugins'] ), true );
    }
    public static function theme_update( $update, $item ) {
        $s = self::settings();
        if ( ! $s['themes'] ) { return $update; }
        if ( ! is_object( $item ) || empty( $item->theme ) || ! empty( $item->disable_autoupdate ) ) { return $update; }
        return ! in_array( (string) $item->theme, self::exclusion_ids( $s['excluded_themes'] ), true );
    }
    public static function minor_core_update( $allowed ) { return (bool) self::settings()['core_minor']; }
    public static function major_core_update( $allowed ) { return (bool) self::settings()['core_major']; }
    public static function notification_allowed( $allowed ) {
        return $allowed && (bool) self::settings()['email_enabled'];
    }
    public static function notification_recipient( $email ) {
        if ( ! is_array( $email ) ) { return $email; }
        $address = sanitize_email( self::settings()['email_address'] );
        if ( is_email( $address ) ) { $email['to'] = $address; }
        return $email;
    }
    public static function admin_menu() {
        add_options_page( 'Lite Auto Updates', 'Lite Auto Updates', 'manage_options', 'lite-auto-updates', array( __CLASS__, 'settings_page' ) );
    }
    private static function redirect( $status ) {
        wp_safe_redirect( add_query_arg( array( 'page' => 'lite-auto-updates', 'lau_notice' => $status ), admin_url( 'options-general.php' ) ) );
        exit;
    }
    private static function verify_request( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'lite-auto-updates' ) ); }
        check_admin_referer( $action );
    }
    public static function save_settings() {
        self::verify_request( 'lau_save_settings' );
        $input = isset( $_POST['lau'] ) && is_array( $_POST['lau'] ) ? wp_unslash( $_POST['lau'] ) : array();
        $s = array();
        foreach ( array( 'plugins', 'themes', 'core_minor', 'core_major', 'email_enabled' ) as $key ) {
            $s[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
        }
        // Only installed plugins/themes can be checked; retain legacy exclusions for missing items.
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed_plugins = get_plugins();
        $installed_themes = wp_get_themes();
        $existing = self::settings();
        foreach ( array( 'excluded_plugins' => $installed_plugins, 'excluded_themes' => $installed_themes ) as $key => $installed ) {
            $old = self::exclusion_ids( $existing[ $key ] );
            $missing = array_diff( $old, array_keys( $installed ) );
            $submitted = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
            $valid = array();
            foreach ( $submitted as $id ) {
                if ( is_string( $id ) && array_key_exists( $id, $installed ) ) { $valid[] = $id; }
            }
            $s[ $key ] = implode( "\n", array_unique( array_merge( $missing, $valid ) ) );
        }
        $email = isset( $input['email_address'] ) && is_string( $input['email_address'] ) ? sanitize_email( $input['email_address'] ) : '';
        if ( $email !== '' && ! is_email( $email ) ) { self::redirect( 'bad_email' ); }
        $s['email_address'] = $email;
        update_option( self::OPTION, $s, false );
        self::redirect( 'saved' );
    }
    public static function test_email() {
        self::verify_request( 'lau_test_email' );
        $address = self::settings()['email_address'];
        if ( ! is_email( $address ) ) { $address = get_option( 'admin_email' ); }
        $sent = wp_mail(
            $address,
            sprintf( '[%s] Lite Auto Updates test email', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
            'This is a test email from Lite Auto Updates on ' . home_url( '/' ) . ".\n\n" . 'Automatic update notifications use WordPress wp_mail() and any SMTP integration already configured.'
        );
        self::redirect( $sent ? 'sent' : 'failed' );
    }
    private static function checkbox( $key, $label, $settings ) {
        echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="lau[' . esc_attr( $key ) . ']" value="1" ' . checked( $settings[ $key ], 1, false ) . '> Enabled</label></td></tr>';
    }
    private static function item_picker( $kind, $items, $excluded ) {
        $field = 'excluded_' . $kind;
        echo '<tr><th scope="row">Exclude ' . esc_html( $kind ) . '</th><td>';
        echo '<fieldset style="max-height:260px;overflow-y:auto;border:1px solid #c3c4c7;background:#fff;padding:10px 14px;max-width:640px;border-radius:4px">';
        if ( ! $items ) { echo '<p>No installed items found.</p>'; }
        foreach ( $items as $id => $name ) {
            echo '<label style="display:block;padding:5px 0;line-height:1.4"><input type="checkbox" name="lau[' . esc_attr( $field ) . '][]" value="' . esc_attr( $id ) . '" ' . checked( in_array( $id, $excluded, true ), true, false ) . '> <strong>' . esc_html( $name ) . '</strong> <span class="description">(' . esc_html( $id ) . ')</span></label>';
        }
        echo '</fieldset><p class="description">Checked items are excluded from this plugin’s automatic updates. Unchecked items are eligible when automatic updates above are enabled.</p>';
        echo '</td></tr>';
    }
    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = array();
        foreach ( get_plugins() as $id => $info ) { $plugins[ $id ] = $info['Name']; }
        natcasesort( $plugins );
        $themes = array();
        foreach ( wp_get_themes() as $id => $theme ) { $themes[ $id ] = $theme->get( 'Name' ); }
        natcasesort( $themes );
        $s = self::settings();
        $notice = isset( $_GET['lau_notice'] ) ? sanitize_key( wp_unslash( $_GET['lau_notice'] ) ) : '';
        $messages = array( 'saved' => 'Settings saved.', 'sent' => 'Test email handed to WordPress mail. Check your inbox and spam folder.', 'failed' => 'WordPress could not hand off the test email. Check your mail/SMTP configuration.', 'bad_email' => 'Please enter a valid notification email address.' );
        echo '<div class="wrap"><h1>Lite Auto Updates</h1><p>Native WordPress auto-updates. No extra cron jobs, frontend assets, database tables or external services.</p>';
        if ( isset( $messages[ $notice ] ) ) {
            echo '<div class="notice ' . esc_attr( in_array( $notice, array( 'failed', 'bad_email' ), true ) ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lau_save_settings">';
        wp_nonce_field( 'lau_save_settings' );
        echo '<h2>Automatic updates</h2><table class="form-table" role="presentation"><tbody>';
        self::checkbox( 'plugins', 'Automatically update plugins', $s );
        self::checkbox( 'themes', 'Automatically update themes', $s );
        self::checkbox( 'core_minor', 'WordPress minor/security updates', $s );
        self::checkbox( 'core_major', 'WordPress major updates (higher risk)', $s );
        echo '</tbody></table><h2>Exclude individual items</h2><table class="form-table" role="presentation"><tbody>';
        self::item_picker( 'plugins', $plugins, self::exclusion_ids( $s['excluded_plugins'] ) );
        self::item_picker( 'themes', $themes, self::exclusion_ids( $s['excluded_themes'] ) );
        echo '</tbody></table><h2>Email notifications</h2><p>Uses WordPress built-in update emails, delivered through wp_mail() and any active SMTP plugin. No duplicate emails from this plugin.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::checkbox( 'email_enabled', 'Automatic update notifications', $s );
        echo '<tr><th scope="row"><label for="lau-email-address">Send notifications to</label></th><td><input class="regular-text" type="email" id="lau-email-address" name="lau[email_address]" value="' . esc_attr( $s['email_address'] ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"><p class="description">Leave blank to use the WordPress site administrator email. WordPress decides which successful/failed updates warrant an email.</p></td></tr>';
        echo '</tbody></table>';
        submit_button( 'Save Update Settings' );
        echo '</form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lau_test_email">';
        wp_nonce_field( 'lau_test_email' );
        submit_button( 'Send Test Email', 'secondary', 'submit', false );
        echo '<p class="description">Save settings before sending a test email. A successful send means WordPress accepted the message, not necessarily that it reached the inbox.</p></form>';
        echo '<p><strong>Important:</strong> Turning off plugin/theme-wide updating defers to WordPress per-item settings. Hosting restrictions, WP-Cron and updater compatibility protections still apply. Backups and staging tests are recommended for critical sites.</p>';
        echo '<p class="description" style="margin-top:18px">Developed by <a href="https://www.abdullahtechs.com/" target="_blank" rel="noopener noreferrer">Abdullah Techs</a></p></div>';
    }
}
LAU_Lite_Auto_Updates::init();
