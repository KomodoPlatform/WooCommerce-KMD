<?php
/**
Komodo for WooCommerce
https://github.com/KomodoPlatform/WooCommerce-KMD
 */

// Include everything
include (dirname(__FILE__) . '/kmd-include-all.php');

//===========================================================================
function KMD__render_general_settings_page ()   { KMD__render_settings_page   ('general'); }
function KMD__render_advanced_settings_page ()  { KMD__render_settings_page   ('advanced'); }
//===========================================================================

//===========================================================================
function KMD__render_settings_page ($menu_page_name)
{

   if (isset ($_POST['button_update_kmd_settings']))
      {
        check_admin_referer( 'kmd-settings' ); // CSRF protection
        if ( !current_user_can( 'manage_options' ) ) // Access control
          wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
      KMD__update_settings ("", false);
echo <<<HHHH
<div align="center" style="background-color:#FFFFE0;padding:5px;font-size:120%;border: 1px solid #E6DB55;margin:5px;border-radius:3px;">
Settings updated!
</div>
HHHH;
      }
   else if (isset($_POST['button_reset_kmd_settings']))
      {
        check_admin_referer( 'kmd-settings' ); // CSRF protection
		if ( !current_user_can( 'manage_options' ) ) // Access control
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
      KMD__reset_all_settings (false);
echo <<<HHHH
<div align="center" style="background-color:#FFFFE0;padding:5px;font-size:120%;border: 1px solid #E6DB55;margin:5px;border-radius:3px;">
All settings reverted to all defaults
</div>
HHHH;
      }
   else if (isset($_POST['button_reset_partial_KMD_settings']))
      {

        check_admin_referer( 'kmd-settings' ); // CSRF protection
		if ( !current_user_can( 'manage_options' ) ) // Access control
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
      KMD__reset_partial_settings (false);
echo <<<HHHH
<div align="center" style="background-color:#FFFFE0;padding:5px;font-size:120%;border: 1px solid #E6DB55;margin:5px;border-radius:3px;">
Settings on this page reverted to defaults
</div>
HHHH;
      }
   else if (isset($_POST['validate_kmd-license']))
      {
        check_admin_referer( 'kmd-settings' ); // CSRF protection
		if ( !current_user_can( 'manage_options' ) ) // Access control
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
      KMD__update_settings ("", false);
      }

   // Output full admin settings HTML
  $gateway_status_message = "";
  $gateway_valid_for_use = KMD__is_gateway_valid_for_use($gateway_status_message);
  if (!$gateway_valid_for_use)
  {
    $gateway_status_message =
    '<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#EE0000;background-color:#FFFFAA;">' .
    "Komodo Payment Gateway is NOT operational (try to re-enter and save settings): " . $gateway_status_message .
    '</p>';
  }
  else
  {
    $gateway_status_message =
    '<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#004400;background-color:#CCFFCC;">' .
    "Komodo Payment Gateway is operational" .
    '</p>';
  }

  $currency_code = false;
  if (function_exists('get_woocommerce_currency'))
    $currency_code = @get_woocommerce_currency();
  if (!$currency_code)
    $currency_code = 'USD';

  $exchange_rate_message =
    '<p style="border:1px solid #DDD;padding:5px 10px;background-color:#cceeff;">' .
    KMD__get_exchange_rate_per_komodo ($currency_code, 'getfirst', true) .
    '</p>';

   echo '<div class="wrap">';

   switch ($menu_page_name)
      {
      case 'general'     :
        echo  KMD__GetPluginNameVersionEdition(true);
        echo  $gateway_status_message . $exchange_rate_message;
        KMD__render_general_settings_page_html();
        break;

      case 'advanced'    :
        echo  KMD__GetPluginNameVersionEdition(false);
        echo  $gateway_status_message . $exchange_rate_message;
        KMD__render_advanced_settings_page_html();
        break;

      default            :
        break;
      }

   echo '</div>'; // wrap
}
//===========================================================================

//===========================================================================
function KMD__render_general_settings_page_html ()
{
  $kmd_settings = KMD__get_settings ();
  global $g_KMD__cron_script_url;

?>

    <form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
      <?php wp_nonce_field( 'kmd-settings' ); ?>
      <p class="submit">
        <input type="submit" class="button-primary"    name="button_update_kmd_settings"        value="<?php _e('Save Changes') ?>"             />
        <input type="submit" class="button-secondary"  style="color:red;" name="button_reset_partial_kmd_settings" value="<?php _e('Reset settings') ?>" onClick="return confirm('Are you sure you want to reset settings on this page?');" />
      </p>
      <table class="form-table">


        <tr valign="top">
          <th scope="row">Delete all plugin-specific settings, database tables and data on uninstall:</th>
          <td>
            <input type="hidden" name="delete_db_tables_on_uninstall" value="0" /><input type="checkbox" name="delete_db_tables_on_uninstall" value="1" <?php if ($kmd_settings['delete_db_tables_on_uninstall']) echo 'checked="checked"'; ?> />
            <p class="description">If checked - all plugin-specific settings, database tables and data will be removed from Wordpress database upon plugin uninstall (but not upon deactivation or upgrade).</p>
          </td>
        </tr>

        <tr valign="top" style="display:none">
          <th scope="row">Komodo Service Provider:</th>
          <td>
            <select name="service_provider" class="select " >
              <option <?php if ($kmd_settings['service_provider'] == 'electrum_wallet') echo 'selected="selected"'; ?> value="electrum_wallet">Your own Komodo Electro wallet</option>
            </select>
            <p class="description">
              Please select your Komodo service provider and press [Save changes]. Then fill-in necessary details and press [Save changes] again.
              <br />Recommended setting: <b>Your own Komodo wallet</b>.
            </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">Komodo Master Public Key (MPK):</th>
          <td>
            <textarea style="width:75%;" name="electrum_mpk_saved"><?php echo $kmd_settings['electrum_mpk_saved']; ?></textarea>
            <p class="description">
              <ol class="description">
                <li>
                  Launch Komodo paper wallet and get Master Public Key value from xpub tab.
                </li>
                <li>
                  Enter seed to get xpub key.
                </li>
                <li>
                  Copy long number string and paste it in this field.
                </li>
                <li>
                  Save changes.
                </li>
              </ol>
            </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">Number of confirmations required before accepting payment:</th>
          <td>
            <input type="text" name="confs_num" value="<?php echo $kmd_settings['confs_num']; ?>" size="4" />
            <p class="description">
              After a transaction is broadcast to the Komodo network, it may be included in a block that is published
              to the network. When that happens it is said that one <a href="https://en.bitcoin.it/wiki/Confirmation"><b>confirmation</b></a> has occurred for the transaction.
              With each subsequent block that is found, the number of confirmations is increased by one. To protect against double spending, a transaction should not be considered as confirmed until a certain number of blocks confirm, or verify that transaction.
              6 is considered very safe number of confirmations, although it takes longer to confirm.
            </p>
          </td>
        </tr>

        <tr valign="top" style="display:none">
          <th scope="row">Komodo Exchange rate calculation type:</th>
          <td>
            <select name="exchange_rate_type" class="select ">
              <option <?php if ($kmd_settings['exchange_rate_type'] == 'vwap') echo 'selected="selected"'; ?> value="vwap">Weighted Average</option>
              <option <?php if ($kmd_settings['exchange_rate_type'] == 'realtime') echo 'selected="selected"'; ?> value="realtime">Real Time</option>
              <option <?php if ($kmd_settings['exchange_rate_type'] == 'bestrate') echo 'selected="selected"'; ?> value="bestrate">Most profitable</option>
            </select>
            <p class="description">
              Weighted Average (recommended): <a href="http://en.wikipedia.org/wiki/Volume-weighted_average_price">weighted average</a> rates polled from a number of exchange services
              <br />Real time: the most recent transaction rates polled from a number of exchange services.
              <br />Most profitable: pick better exchange rate of all indicators (most favorable for merchant). Calculated as: MIN (Weighted Average, Real time)
            </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">Exchange rate multiplier:</th>
          <td>
            <input type="text" name="exchange_multiplier" value="<?php echo $kmd_settings['exchange_multiplier']; ?>" size="4" />
            <p class="description">
              Extra multiplier to apply to convert store default currency to komodo price.
              <br />Example: 1.05 - will add extra 5% to the total price in komodo.
              May be useful to compensate for market volatility or for merchant's loss to fees when converting komodo to local currency,
              or to encourage customer to use komodo for purchases (by setting multiplier to < 1.00 values).
            </p>
          </td>
        </tr>

        <tr valign="top" style="display:none">
            <th scope="row">Extreme privacy mode enabled?</th>
            <td>


              <select name="PRO_EDITION_ONLY" class="select">
                <option selected="selected" disabled="disabled" value="0">No (default)</option>
              </select> <?php echo KMD__GetProLabel(); ?>


              <p class="description">
                <b>No</b> (default, recommended) - will allow to recycle komodo addresses that been generated for each placed order but never received any payments. The drawback of this approach is that potential snoop can generate many fake (never paid for) orders to discover sequence of komodo addresses that belongs to the wallet of this store and then track down sales through blockchain analysis. The advantage of this option is that it very efficiently reuses empty (zero-balance) komodo addresses within the wallet, allowing only 1 sale per address without growing the wallet size (Eletrum "gap" value).
                <br />
                <b>Yes</b> - COMMING SOON. This will guarantee to generate unique komodo address for every order (real, accidental or fake). This option will provide the most anonymity and privacy to the store owner's wallet. The drawback is that it will likely leave a number of addresses within the wallet never used (and hence will require setting very high 'gap limit' within the Electrum wallet much sooner).
                <br />It is recommended to regenerate new wallet after number of used komodo addresses reaches 1000. Wallets with very high gap limits (>1000) are very slow to sync with blockchain and they put an extra load on the network. <br />
                Extreme privacy mode offers the best anonymity and privacy to the store albeit with the drawbacks of potentially flooding your Electrum wallet with expired and zero-balance addresses. Hence, for vast majority of cases (where you just need a secure way to operate komodo based store) it is suggested to set this option to 'No').<br />
              </p>
            </td>
        </tr>

        <tr valign="top">
          <th scope="row">Auto-complete paid orders:</th>
          <td>
            <input type="hidden" name="autocomplete_paid_orders" value="0" /><input type="checkbox" name="autocomplete_paid_orders" value="1" <?php if ($kmd_settings['autocomplete_paid_orders']) echo 'checked="checked"'; ?> />
            <p class="description">If checked - fully paid order will be marked as 'completed' and '<i>Your order is complete</i>' email will be immediately delivered to customer.
            	<br />If unchecked: store admin will need to mark order as completed manually - assuming extra time needed to ship physical product after payment is received.
            	<br />Note: virtual/downloadable products will automatically complete upon receiving full payment (so this setting does not have effect in this case).
            </p>
          </td>
        </tr>

        <tr valign="top">
            <th scope="row">Cron job type:</th>
            <td>
              <select name="enable_soft_cron_job" class="select ">
                <option <?php if ($kmd_settings['enable_soft_cron_job'] == '1') echo 'selected="selected"'; ?> value="1">Soft Cron (Wordpress-driven)</option>
                <option <?php if ($kmd_settings['enable_soft_cron_job'] != '1') echo 'selected="selected"'; ?> value="0">Hard Cron (Cpanel-driven)</option>
              </select>
              <p class="description">
                <?php if ($kmd_settings['enable_soft_cron_job'] != '1') echo '<p style="background-color:#FFC;color:#2A2;"><b>NOTE</b>: Hard Cron job is enabled: make sure to follow instructions below to enable hard cron job at your hosting panel.</p>'; ?>
                Cron job will take care of all regular komodo payment processing tasks, like checking if payments are made and automatically completing the orders.<br />
                <b>Soft Cron</b>: - Wordpress-driven (runs on behalf of a random site visitor).
                <br />
                <b>Hard Cron</b>: - Cron job driven by the website hosting system/server (usually via CPanel). <br />
                When enabling Hard Cron job - make this script to run every 5 minutes at your hosting panel cron job scheduler:<br />
                <?php echo '<tt style="background-color:#FFA;color:#B00;padding:0px 6px;">wget -O /dev/null ' . $g_KMD__cron_script_url . '?hardcron=1</tt>'; ?>
                <br /><b style="color:red;">NOTE:</b> Cron jobs <b>might not work</b> if your site is password protected with HTTP Basic auth or other methods. This will result in WooCommerce store not seeing received payments (even though funds will arrive correctly to your komodo addresses).
                <br /><u>Note:</u> You will need to deactivate/reactivate plugin after changing this setting for it to have effect.<br />
                "Hard" cron jobs may not be properly supported by all hosting plans (many shared hosting plans has restrictions in place).               
              </p>
            </td>
        </tr>

      </table>

      <p class="submit">
          <input type="submit" class="button-primary"    name="button_update_kmd_settings"        value="<?php _e('Save Changes') ?>"             />
          <input type="submit" class="button-secondary"  style="color:red;" name="button_reset_partial_kmd_settings" value="<?php _e('Reset settings') ?>" onClick="return confirm('Are you sure you want to reset settings on this page?');" />
      </p>
    </form>
<?php
}
//===========================================================================

//===========================================================================
function KMD__render_advanced_settings_page_html ()
{
   $kmd_settings = KMD__get_settings ();


?>
      </table>
      <?php wp_nonce_field( 'kmd-settings' ); ?>
      <p class="submit">
          <input type="submit" class="button-primary"    name="button_update_kmd_settings"        value="{$value_save_changes}"             />
          <input type="submit" class="button-secondary"  style="color:red;" name="button_reset_partial_kmd_settings" value="{$value_reset_settings}" onClick="return confirm('Are you sure you want to reset settings on this page?');" />
      </p>
  </form>
TTT;
  echo $html_out;
//{{{/PLACEHOLDER}}} ?> ///+{EDITION==Pro}///

<?php
}
//===========================================================================
