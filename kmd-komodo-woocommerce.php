<?php
/**
 * Plugin Name: Komodo for WooCommerce
 * Plugin URI: https://github.com/KomodoPlatform/WooCommerce-KMD
 * Description: Komodo for WooCommerce plugin allows you to accept payments in KMD for physical and digital products at your WooCommerce-powered online store.
 * Version: 1.0.0
 * Author: Komodo Team
 * Author URI: https://komodoplatform.com
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: komodo-for-woocommerce
 *
 * @package WordPress
 * @since 1.0.0
 */


// Include everything
include (dirname(__FILE__) . '/kmd-include-all.php');

//---------------------------------------------------------------------------
// Add hooks and filters

// create custom plugin settings menu
add_action( 'admin_menu',                   'KMD_create_menu' );

register_activation_hook(__FILE__,          'KMD_activate');
register_deactivation_hook(__FILE__,        'KMD_deactivate');
register_uninstall_hook(__FILE__,           'KMD_uninstall');

add_filter ('cron_schedules',               'KMD__add_custom_scheduled_intervals');
add_action ('BWWC_cron_action',             'KMD_cron_job_worker'); 

KMD_set_lang_file();
//---------------------------------------------------------------------------

//===========================================================================
// activating the default values
function KMD_activate()
{
    global  $g_KMD__config_defaults;

    $kmd_default_options = $g_KMD__config_defaults;

    // This will overwrite default options with already existing options but leave new options (in case of upgrading to new version) untouched.
    $kmd_settings = KMD__get_settings ();

    foreach ($kmd_settings as $key=>$value)
    	$kmd_default_options[$key] = $value;

    update_option (KMD_SETTINGS_NAME, $kmd_default_options);

    // Re-get new settings.
    $kmd_settings = KMD__get_settings ();

    // Create necessary database tables if not already exists...
    KMD__create_database_tables ($kmd_settings);
    KMD__SubIns ();

    //----------------------------------
    // Setup cron jobs

    if ($kmd_settings['enable_soft_cron_job'] && !wp_next_scheduled('KMD_cron_action'))
    {
    	$cron_job_schedule_name = strpos($_SERVER['HTTP_HOST'], 'ttt.com')===FALSE ? $kmd_settings['soft_cron_job_schedule_name'] : 'seconds_30';
    	wp_schedule_event(time(), $cron_job_schedule_name, 'KMD_cron_action');
    }
    //----------------------------------

}
//---------------------------------------------------------------------------
// Cron Subfunctions
function KMD__add_custom_scheduled_intervals ($schedules)
{
	$schedules['seconds_30']     = array('interval'=>30,     'display'=>__('Once every 30 seconds'));     // For testing only.
	$schedules['minutes_1']      = array('interval'=>1*60,   'display'=>__('Once every 1 minute'));
	$schedules['minutes_2.5']    = array('interval'=>2.5*60, 'display'=>__('Once every 2.5 minutes'));
	$schedules['minutes_5']      = array('interval'=>5*60,   'display'=>__('Once every 5 minutes'));

	return $schedules;
}
//---------------------------------------------------------------------------
//===========================================================================

//===========================================================================
// deactivating
function KMD_deactivate ()
{
    // Do deactivation cleanup. Do not delete previous settings in case user will reactivate plugin again...

   //----------------------------------
   // Clear cron jobs
   wp_clear_scheduled_hook ('KMD_cron_action');
   //----------------------------------
}
//===========================================================================

//===========================================================================
// uninstalling
function KMD_uninstall ()
{
    $kmd_settings = KMD__get_settings();

    if ($kmd_settings['delete_db_tables_on_uninstall'])
    {
        // delete all settings.
        delete_option(KMD_SETTINGS_NAME);

        // delete all DB tables and data.
        KMD__delete_database_tables ();
    }
}
//===========================================================================

//===========================================================================
function KMD_create_menu()
{

    // create new top-level menu
    // http://www.fileformat.info/info/unicode/char/e3f/index.htm
    add_menu_page (
        __('Woo Commerce', KMD_I18N_DOMAIN),                    // Page title
        __('Komodo', KMD_I18N_DOMAIN),                        // Menu Title - lower corner of admin menu
        'administrator',                                        // Capability
        'kmd-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'KMD__render_general_settings_page',                   // Function

        plugins_url('/images/kmd_16x.png', __FILE__)      // Icon URL
        );

    add_submenu_page (
        'kmd-settings',                                        // Parent
        __("Komodo for WooCommerce", KMD_I18N_DOMAIN),                   // Page title
        __("General Settings", KMD_I18N_DOMAIN),               // Menu Title
        'administrator',                                        // Capability
        'kmd-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'KMD__render_general_settings_page'                    // Function
        );
}
//===========================================================================

//===========================================================================
// load language files
function KMD_set_lang_file()
{
    # set the language file
    $currentLocale = get_locale();
    if(!empty($currentLocale))
    {
        $moFile = dirname(__FILE__) . "/lang/" . $currentLocale . ".mo";
        if (@file_exists($moFile) && is_readable($moFile))
        {
            load_textdomain(KMD_I18N_DOMAIN, $moFile);
        }

    }
}
//===========================================================================
/*
function tl_save_error() {
    update_option( 'plugin_error',  ob_get_contents() );
}
add_action( 'activated_plugin', 'tl_save_error' );

echo get_option( 'plugin_error' );

file_put_contents( 'C:\errors' , ob_get_contents() );
*/