<?php
/**
Komodo for WooCommerce
https://github.com/KomodoPlatform/WooCommerce-KMD
 */

//---------------------------------------------------------------------------
// Global definitions
if (!defined('KMD_PLUGIN_NAME'))
  {
  define('KMD_VERSION',           '1.0.0');

  //-----------------------------------------------
  define('KMD_EDITION',           'Standard');


  //-----------------------------------------------
  define('KMD_SETTINGS_NAME',     'KMD-Settings');
  define('KMD_PLUGIN_NAME',       'Komodo for WooCommerce');


  // i18n plugin domain for language files
  define('KMD_I18N_DOMAIN',       'kmd');

  if (extension_loaded('gmp') && !defined('USE_EXT'))
    define ('USE_EXT', 'GMP');
  else if (extension_loaded('bcmath') && !defined('USE_EXT'))
    define ('USE_EXT', 'BCMATH');
  }
//---------------------------------------------------------------------------

//------------------------------------------
// Load wordpress for POSTback, WebHook and API pages that are called by external services directly.
if (defined('KMD_MUST_LOAD_WP') && !defined('WP_USE_THEMES') && !defined('ABSPATH'))
   {
   $g_blog_dir = preg_replace ('|(/+[^/]+){4}$|', '', str_replace ('\\', '/', __FILE__)); // For love of the art of regex-ing
   define('WP_USE_THEMES', false);

   // Force-elimination of header 404 for non-wordpress pages.
   header ("HTTP/1.1 200 OK");
   header ("Status: 200 OK");
   }
//------------------------------------------


// This loads necessary modules and selects best math library
if (!class_exists('bcmath_Utils')) require_once (dirname(__FILE__) . '/libs/util/bcmath_Utils.php');
if (!class_exists('gmp_Utils')) require_once (dirname(__FILE__) . '/libs/util/gmp_Utils.php');
if (!class_exists('CurveFp')) require_once (dirname(__FILE__) . '/libs/CurveFp.php');
if (!class_exists('Point')) require_once (dirname(__FILE__) . '/libs/Point.php');
if (!class_exists('NumberTheory')) require_once (dirname(__FILE__) . '/libs/NumberTheory.php');
require_once (dirname(__FILE__) . '/libs/KMDElectroHelper.php');

require_once (dirname(__FILE__) . '/kmd-cron.php');
require_once (dirname(__FILE__) . '/kmd-mpkgen.php');
require_once (dirname(__FILE__) . '/kmd-utils.php');
require_once (dirname(__FILE__) . '/kmd-admin.php');
require_once (dirname(__FILE__) . '/kmd-render-settings.php');
require_once (dirname(__FILE__) . '/kmd-komodo-gateway.php');
