<?php
/**
   Komodo for WooCommerce
   https://github.com/KomodoPlatform/WooCommerce-KMD
 */


//---------------------------------------------------------------------------
add_action('plugins_loaded', 'KMD__plugins_loaded__load_komodo_gateway', 0);

//---------------------------------------------------------------------------

//###########################################################################
// Hook payment gateway into WooCommerce

function KMD__plugins_loaded__load_komodo_gateway()
{

    if (!class_exists('WC_Payment_Gateway'))
        // Nothing happens here is WooCommerce is not loaded
        return;

    //=======================================================================

    /**
     * Komodo Payment Gateway
     *
     * Provides a Komodo Payment Gateway
     *
     * @class        KMD_Komodo
     * @extends        WC_Payment_Gateway
     * @version         1.0.0
     * @author        
     */
    class KMD_Komodo extends WC_Payment_Gateway
    {
        //-------------------------------------------------------------------
        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            $this->id = 'komodo';
            $this->icon = plugins_url('/images/kmd_buyitnow_32x.png', __FILE__);    // 32 pixels high
            $this->has_fields = false;
            $this->method_title = __('Komodo', 'woocommerce');

            // Load KMD settings.
            $kmd_settings = KMD__get_settings();
            $this->service_provider = $kmd_settings['service_provider']; // This need to be before $this->init_settings otherwise it generate PHP Notice: "Undefined property: KMD_Komodo::$service_provider" down below.

            // Load the form fields.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->settings['title'];    // The title which the user is shown on the checkout ? retrieved from the settings which init_settings loads.
            $this->komodo_addr_merchant = $this->settings['komodo_addr_merchant'];    // Forwarding address where all product payments will aggregate.

            $this->confs_num = $kmd_settings['confs_num'];  //$this->settings['confirmations'];
            $this->description = $this->settings['description'];    // Short description about the gateway which is shown on checkout.
            $this->instructions = $this->settings['instructions'];    // Detailed payment instructions for the buyer.
            $this->instructions_multi_payment_str = __('You may send payments from multiple accounts to reach the total required.', 'woocommerce');
            $this->instructions_single_payment_str = __('You must pay in a single payment in full.', 'woocommerce');


            // Actions
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            else
                add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options')); // hook into this action to save options in the backend

            add_action('woocommerce_thankyou_' . $this->id, array($this, 'KMD__thankyou_page')); // hooks into the thank you page after payment

            // Customer Emails
            add_action('woocommerce_email_before_order_table', array($this, 'KMD__email_instructions'), 10, 2); // hooks into the email template to show additional details

            // Hook IPN callback logic
            if (version_compare(WOOCOMMERCE_VERSION, '2.0', '<'))
                add_action('init', array($this, 'KMD__maybe_komodo_ipn_callback'));
            else
                add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'KMD__maybe_komodo_ipn_callback'));

            // Validate currently set currency for the store. Must be among supported ones.
            if (!KMD__is_gateway_valid_for_use()) $this->enabled = false;
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Check if this gateway is enabled and available for the store's default currency
         *
         * @access public
         * @return bool
         */
        function is_gateway_valid_for_use(&$ret_reason_message = NULL)
        {
            $valid = true;

            //----------------------------------
            // Validate settings
            if (!$this->service_provider) {
                $reason_message = __("Komodo Service Provider is not selected", 'woocommerce');
                $valid = false;
            } else if ($this->service_provider == 'blockchain_info') {
                if ($this->komodo_addr_merchant == '') {
                    $reason_message = __("Your personal komodo address is not selected", 'woocommerce');
                    $valid = false;
                } else if ($this->komodo_addr_merchant == 'RUiuBz8mx8STccuvmRiaD299t8YR6BCpFd') {
                    $reason_message = __("Your personal komodo address is invalid. The address specified is the donation address :)", 'woocommerce');
                    $valid = false;
                }
            } else if ($this->service_provider == 'electrum_wallet') {
                $mpk = KMD__get_next_available_mpk();
                if (!$mpk) {
                    $reason_message = __("Please specify Komodo Master Public Key (MPK) in the plugin settings. <br />To retrieve MPK: launch your electron cash wallet, select: Wallet->Master Public Keys, OR: <br />Preferences->Import/Export->Master Public Key->Show", 'woocommerce');
                    $valid = false;
                } else if (!preg_match('/^[a-f0-9]{128}$/', $mpk) && !preg_match('/^xpub[a-zA-Z0-9]{107}$/', $mpk)) {
                    $reason_message = __("Electron Cash Master Public Key is invalid. Must be 128 or 111 characters long, consisting of digits and letters.", 'woocommerce');
                    $valid = false;
                } else if (!extension_loaded('gmp') && !extension_loaded('bcmath')) {
                    $reason_message = __("ERROR: neither 'bcmath' nor 'gmp' math extensions are loaded For Electron Cash wallet options to function. Contact your hosting company and ask them to enable either 'bcmath' or 'gmp' extensions. 'gmp' is preferred (much faster)! \nAlternatively you may choose another 'Komodo Service Provider' option.", 'woocommerce');
                    $valid = false;
                }
            }

            if (!$valid) {
                if ($ret_reason_message !== NULL)
                    $ret_reason_message = $reason_message;
                return false;
            }
            //----------------------------------

            //----------------------------------
            // Validate connection to exchange rate services

            $store_currency_code = get_woocommerce_currency();
            if ($store_currency_code != 'KMD') {
                $currency_rate = KMD__get_exchange_rate_per_komodo($store_currency_code, 'getfirst', false);
                if (!$currency_rate) {
                    $valid = false;

                    // Assemble error message.
                    $error_msg = "ERROR: Cannot determine exchange rates (for '$store_currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.";
                    $extra_error_message = "";
                    $fns = array('file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec');
                    $fns = array_filter($fns, 'KMD__function_not_exists');
                    $extra_error_message = "";
                    if (count($fns))
                        $extra_error_message = "The following PHP functions are disabled on your server: " . implode(", ", $fns) . ".";

                    $reason_message = str_replace('{{{ERROR_MESSAGE}}}', $extra_error_message, $error_msg);

                    if ($ret_reason_message !== NULL)
                        $ret_reason_message = $reason_message;
                    return false;
                }
            }

            return true;
        }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields()
        {
            // This defines the settings we want to show in the admin area.
            // This allows user to customize payment gateway.
            // Add as many as you see fit.
            // See this for more form elements: http://wcdocs.woothemes.com/codex/extending/settings-api/

            //-----------------------------------
            // Assemble currency ticker.
            $store_currency_code = get_woocommerce_currency();
            if (!$store_currency_code)
                $currency_code = 'USD';
            else
                $currency_code = $store_currency_code;

            $currency_ticker = KMD__get_exchange_rate_per_komodo($currency_code, 'getfirst', true);
            //-----------------------------------

            //-----------------------------------
            // Payment instructions
            $payment_instructions = '
<table class="kmd-payment-instructions-table" id="kmd-payment-instructions-table">
  <tr class="bpit-table-row">
    <td colspan="2">' . __('Please send your Komodo payment as follows:', 'woocommerce') . '</td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-amount">
      ' . __('Amount', 'woocommerce') . ' (<strong>KMD</strong>):
    </td>
    <td class="bpit-td-value bpit-td-value-amount">
      <div style="border:1px solid #FCCA09;padding:2px 6px;margin:2px;background-color:#FCF8E3;border-radius:4px;color:#CC0000;font-weight: bold;font-size: 120%;">
      	{{{KOMODO_AMOUNT}}}
      </div>
    </td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-kmdaddr">
      Address:
    </td>
    <td class="bpit-td-value bpit-td-value-kmdaddr">
      <div style="border:1px solid #FCCA09;padding:2px 6px;margin:2px;background-color:#FCF8E3;border-radius:4px;color:#555;font-weight: bold;font-size: 120%;">
        {{{KOMODO_ADDRESS}}}
      </div>
    </td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-qr">
	    QR Code:
    </td>
    <td class="bpit-td-value bpit-td-value-qr">
      <div style="border:1px solid #FCCA09;padding:5px;margin:2px;background-color:#FCF8E3;border-radius:4px;">
        <a href="komodo:{{{KOMODO_ADDRESS}}}?amount={{{KOMODO_AMOUNT}}}"><img src="https://api.qrserver.com/v1/create-qr-code/?color=000000&amp;bgcolor=FFFFFF&amp;data=komodo%3A{{{KOMODO_ADDRESS}}}%3Famount%3D{{{KOMODO_AMOUNT}}}&amp;qzone=1&amp;margin=0&amp;size=180x180&amp;ecc=L" style="vertical-align:middle;border:1px solid #888;" /></a>
      </div>
    </td>
  </tr>
</table>

' . __('Please note:', 'woocommerce') . '
<ol class="bpit-instructions">
    <li>' . __('We ONLY accept Komodo.', 'woocommerce') . '</li>
    <li>' . __('You must make a payment within 1 hour, or your order may be cancelled', 'woocommerce') . '</li>
    <li>' . __('As soon as your payment is received in full you will receive email confirmation with order delivery details.', 'woocommerce') . '</li>
    <li>{{{EXTRA_INSTRUCTIONS}}}</li>
</ol>
';
            $payment_instructions = trim($payment_instructions);

            $payment_instructions_description = '
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	' . __('Specific instructions given to the customer to complete Komodo payment.<br />You may change it, but make sure these tags will be present: <b>{{{KOMODO_AMOUNT}}}</b>, <b>{{{KOMODO_ADDRESS}}}</b> and <b>{{{EXTRA_INSTRUCTIONS}}}</b> as these tags will be replaced with customer - specific payment details.', 'woocommerce') . '
						  </p>
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	Payment Instructions, original template (for reference):<br />
					    	<textarea rows="2" onclick="this.focus();this.select()" readonly="readonly" style="width:100%;background-color:#f1f1f1;height:4em">' . $payment_instructions . '</textarea>
						  </p>
					';
            $payment_instructions_description = trim($payment_instructions_description);
            //-----------------------------------

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Komodo Payments', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Komodo Payment', 'woocommerce')
                ),

                'komodo_addr_merchant' => array(
                    'title' => __('Your personal komodo address', 'woocommerce'),
                    'type' => 'text',
                    'css' => $this->service_provider != 'blockchain_info' ? 'display:none;' : '',
                    'disabled' => $this->service_provider != 'blockchain_info' ? true : false,
                    'description' => $this->service_provider != 'blockchain_info' ? __('Available when Komodo service provider is set to: <b>Blockchain.info</b> (at Komodo plugin settings page)', 'woocommerce') : __('Your own komodo address - where you would like the payment to be sent. When customer sends you payment for the product - it will be automatically forwarded to this address by blockchain.info APIs.', 'woocommerce'),
                    'default' => '',
                ),


                'description' => array(
                    'title' => __('Customer Message', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Initial instructions for the customer at checkout screen', 'woocommerce'),
                    'default' => __('Please proceed to the next screen to see necessary payment details.', 'woocommerce')
                ),
                'instructions' => array(
                    'title' => __('Payment Instructions (HTML)', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => $payment_instructions_description,
                    'default' => $payment_instructions,
                ),
            );
        }

        //-------------------------------------------------------------------

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @access public
         * @return void
         */
        public function admin_options()
        {
            $validation_msg = "";
            $store_valid = KMD__is_gateway_valid_for_use($validation_msg);

            // After defining the options, we need to display them too; thats where this next function comes into play:
            ?>
            <h3><?php _e('Komodo Payment', 'woocommerce'); ?></h3>
            <p>
                <?php _e('Allows to accept payments in komodo. <a href="https://komodoplatform.com/" target="_blank">Komodo</a> is peer-to-peer, decentralized digital currency that enables instant payments from anyone to anyone, anywhere in the world', 'woocommerce'); ?>
            </p>
            <?php
            echo $store_valid ? ('<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#004400;background-color:#CCFFCC;">' .
                __('Komodo payment gateway is operational', 'woocommerce') .
                '</p>') : ('<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#EE0000;background-color:#FFFFAA;">' .
                __('Komodo payment gateway is not operational (try to re-enter and save Komodo Plugin settings): ', 'woocommerce') . $validation_msg . '</p>');
            ?>
            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table><!--/.form-table-->
            <?php
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        // Hook into admin options saving.
        public function process_admin_options()
        {
            // Call parent
            parent::process_admin_options();

            return;

        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id)
        {
            $kmd_settings = KMD__get_settings();
            $order = wc_get_order($order_id);

            // TODO: Implement CRM features within store admin dashboard
            $order_meta = array();
            $order_meta['kmd_order'] = $order;
            $order_meta['kmd_items'] = $order->get_items();
            $order_meta['kmd_b_addr'] = $order->get_formatted_billing_address();
            $order_meta['kmd_s_addr'] = $order->get_formatted_shipping_address();
            $order_meta['kmd_b_email'] = $order->get_billing_email();
            $order_meta['kmd_currency'] = $order->get_currency();
            $order_meta['kmd_settings'] = $kmd_settings;
            $order_meta['kmd_store'] = plugins_url('', __FILE__);


            //-----------------------------------
            // Save komodo payment info together with the order.
            // Note: this code must be on top here, as other filters will be called from here and will use these values ...
            //
            // Calculate realtime komodo price (if exchange is necessary)

            $exchange_rate = KMD__get_exchange_rate_per_komodo(get_woocommerce_currency(), 'getfirst');

            if (!$exchange_rate) {
                $msg = 'ERROR: Cannot determine Komodo exchange rate. Possible issues: store server does not allow outgoing connections, exchange rate servers are blocking incoming connections or down. ' .
                    'You may avoid that by setting store currency directly to Komodo (KMD)';
                KMD__log_event(__FILE__, __LINE__, $msg);
                exit ('<h2 style="color:red;">' . $msg . '</h2>');
            }

            $order_total_in_kmd = ($order->get_total() / $exchange_rate);
            if (get_woocommerce_currency() != 'KMD')
                // Apply exchange rate multiplier only for stores with non-komodo default currency.
                $order_total_in_kmd = $order_total_in_kmd;

            $order_total_in_kmd = sprintf("%.8f", $order_total_in_kmd);

            $kmd_address = false;

            $order_info =
                array(
                    'order_meta' => $order_meta,
                    'order_id' => $order_id,
                    'order_total' => $order_total_in_kmd,  // Order total in KMD
                    'order_datetime' => date('Y-m-d H:i:s T'),
                    'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
                    'requested_by_ua' => @$_SERVER['HTTP_USER_AGENT'],
                    'requested_by_srv' => KMD__base64_encode(serialize($_SERVER)),
                );

            $ret_info_array = array();

            if ($this->service_provider == 'electrum_wallet') {
                $ret_info_array = KMD__get_address_for_payment__electrum(KMD__get_next_available_mpk(), $order_info);
                $komodo_address = @$ret_info_array['generated_address'];
                //echo '<script>console.log("HELLO error: ' .@$ret_info_array['generated_address'] . '")</script>';
            }

            if (!$komodo_address) {
                $msg = "ERROR: cannot generate komodo address for the order: '" . @$ret_info_array['message'] . "'";
                KMD__log_event(__FILE__, __LINE__, $msg);
                exit ('<h2 style="color:red;">' . $msg . '</h2>');
            }

            KMD__log_event(__FILE__, __LINE__, "     Generated unique komodo address: '{$komodo_address}' for order_id " . $order_id);

            update_post_meta(
                $order_id,            // post id ($order_id)
                'order_total_in_kmd',    // meta key
                $order_total_in_kmd    // meta value. If array - will be auto-serialized
            );
            update_post_meta(
                $order_id,            // post id ($order_id)
                'komodo_address',    // meta key
                $komodo_address    // meta value. If array - will be auto-serialized
            );
            update_post_meta(
                $order_id,            // post id ($order_id)
                'komodo_paid_total',    // meta key
                "0"    // meta value. If array - will be auto-serialized
            );
            update_post_meta(
                $order_id,            // post id ($order_id)
                'komodo_refunded',    // meta key
                "0"    // meta value. If array - will be auto-serialized
            );
            update_post_meta(
                $order_id,                // post id ($order_id)
                '_incoming_payments',    // meta key. Starts with '_' - hidden from UI.
                array()                    // array (array('datetime'=>'', 'from_addr'=>'', 'amount'=>''),)
            );
            update_post_meta(
                $order_id,                // post id ($order_id)
                '_payment_completed',    // meta key. Starts with '_' - hidden from UI.
                0                    // array (array('datetime'=>'', 'from_addr'=>'', 'amount'=>''),)
            );
            //-----------------------------------


            // The komodo gateway does not take payment immediately, but it does need to change the orders status to on-hold
            // (so the store owner knows that komodo payment is pending).
            // We also need to tell WooCommerce that it needs to redirect to the thankyou page ? this is done with the returned array
            // and the result being a success.
            //
            global $woocommerce;

            //	Updating the order status:

            // Mark as on-hold (we're awaiting for komodo payment to arrive)
            $order->update_status('on-hold', __('Awaiting Komodo payment to arrive', 'woocommerce'));

            /*
                        ///////////////////////////////////////
                        // timbowhite's suggestion:
                        // -----------------------
                        // Mark as pending (we're awaiting for komodo payment to arrive), not 'on-hold' since
                  // woocommerce does not automatically cancel expired on-hold orders. Woocommerce handles holding the stock
                  // for pending orders until order payment is complete.
                        $order->update_status('pending', __('Awaiting komodo payment to arrive', 'woocommerce'));

                        // Me: 'pending' does not trigger "Thank you" page and neither email sending. Not sure why.
                        //			Also - I think cancellation of unpaid orders needs to be initiated from cron job, as only we know when order needs to be cancelled,
                        //			by scanning "on-hold" orders through 'assigned_address_expires_in_mins' timeout check.
                        ///////////////////////////////////////
            */
            // Remove cart
            $woocommerce->cart->empty_cart();

            // Empty awaiting payment session
            if (isset($_SESSION['order_awaiting_payment'])) unset($_SESSION['order_awaiting_payment']);

            // Return thankyou redirect
            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))))
                );
            } else {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('key', $order->get_order_key(), add_query_arg('order', $order_id, $this->get_return_url($order)))
                );
            }
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        function KMD__thankyou_page($order_id)
        {
            // KMD__thankyou_page is hooked into the "thank you" page and in the simplest case can just echo?s the description.

            // Get order object.
            // http://wcdocs.woothemes.com/apidocs/class-WC_Order.html
            $order = wc_get_order($order_id);

            // Assemble detailed instructions.
            $order_total_in_kmd = get_post_meta($order->get_id(), 'order_total_in_kmd', true); // set single to true to receive properly unserialized array
            $komodo_address = get_post_meta($order->get_id(), 'komodo_address', true); // set single to true to receive properly unserialized array


            $instructions = $this->instructions;
            $instructions = str_replace('{{{KOMODO_AMOUNT}}}', $order_total_in_kmd, $instructions);
            $instructions = str_replace('{{{KOMODO_ADDRESS}}}', $komodo_address, $instructions);
            $instructions =
                str_replace(
                    '{{{EXTRA_INSTRUCTIONS}}}',

                    $this->instructions_multi_payment_str,
                    $instructions
                );
            $order->add_order_note(__("Order instructions: price=KMD {$order_total_in_kmd}, incoming account:{$komodo_address}", 'woocommerce'));

            echo wpautop(wptexturize($instructions));
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @return void
         */
        function KMD__email_instructions($order, $sent_to_admin)
        {
            if ($sent_to_admin) return;
            if (!in_array($order->get_status(), array('pending', 'on-hold'), true)) return;
            if ($order->get_payment_method() !== 'komodo') return;

            // Assemble payment instructions for email
            $order_total_in_kmd = get_post_meta($order->get_id(), 'order_total_in_kmd', true); // set single to true to receive properly unserialized array
            $komodo_address = get_post_meta($order->get_id(), 'komodo_address', true); // set single to true to receive properly unserialized array


            $instructions = $this->instructions;
            $instructions = str_replace('{{{KOMODO_AMOUNT}}}', $order_total_in_kmd, $instructions);
            $instructions = str_replace('{{{KOMODO_ADDRESS}}}', $komodo_address, $instructions);
            $instructions =
                str_replace(
                    '{{{EXTRA_INSTRUCTIONS}}}',

                    $this->instructions_multi_payment_str,
                    $instructions
                );

            echo wpautop(wptexturize($instructions));
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Check for Komodo-related IPN callabck
         *
         * @access public
         * @return void
         */
        function KMD__maybe_komodo_ipn_callback()
        {
            // If example.com/?komodo=1 is present - it is callback URL.
            if (isset($_REQUEST['komodo']) && $_REQUEST['komodo'] == '1') {
                KMD__log_event(__FILE__, __LINE__, "KMD__maybe_komodo_ipn_callback () called and 'komodo=1' detected. REQUEST  =  " . serialize(@$_REQUEST));

                if (@$_GET['src'] != 'bcinfo') {
                    $src = $_GET['src'];
                    KMD__log_event(__FILE__, __LINE__, "Warning: received IPN notification with 'src'= '{$src}', which is not matching expected: 'bcinfo'. Ignoring ...");
                    exit();
                }

                // Processing IPN callback from blockchain.info ('bcinfo')


                $order_id = @$_GET['order_id'];

                $secret_key = get_post_meta($order_id, 'secret_key', true);
                $secret_key_sent = @$_GET['secret_key'];
                // Check the Request secret_key matches the original one (blockchain.info sends all params back)
                if ($secret_key_sent != $secret_key) {
                    KMD__log_event(__FILE__, __LINE__, "Warning: secret_key does not match! secret_key sent: '{$secret_key_sent}'. Expected: '{$secret_key}'. Processing aborted.");
                    exit ('Invalid secret_key');
                }

                $confirmations = @$_GET['confirmations'];


                if ($confirmations >= $this->confs_num) {

                    // The value of the payment received in satoshi (not including fees). Divide by 100000000 to get the value in KMD.
                    $value_in_kmd = @$_GET['value'] / 100000000;
                    $txn_hash = @$_GET['transaction_hash'];
                    $txn_confirmations = @$_GET['confirmations'];

                    //---------------------------
                    // Update incoming payments array stats
                    $incoming_payments = get_post_meta($order_id, '_incoming_payments', true);
                    $incoming_payments[$txn_hash] =
                        array(
                            'txn_value' => $value_in_kmd,
                            'dest_address' => @$_GET['address'],
                            'confirmations' => $txn_confirmations,
                            'datetime' => date("Y-m-d, G:i:s T"),
                        );

                    update_post_meta($order_id, '_incoming_payments', $incoming_payments);
                    //---------------------------

                    //---------------------------
                    // Recalc total amount received for this order by adding totals from uniquely hashed txn's ...
                    $paid_total_so_far = 0;
                    foreach ($incoming_payments as $k => $txn_data)
                        $paid_total_so_far += $txn_data['txn_value'];

                    update_post_meta($order_id, 'komodo_paid_total', $paid_total_so_far);
                    //---------------------------

                    $order_total_in_kmd = get_post_meta($order_id, 'order_total_in_kmd', true);
                    if ($paid_total_so_far >= $order_total_in_kmd) {
                        KMD__process_payment_completed_for_order($order_id, false);
                    } else {
                        KMD__log_event(__FILE__, __LINE__, "NOTE: Payment received (for KMD {$value_in_kmd}), but not enough yet to cover the required total. Will be waiting for more. Komodo: now/total received/needed = {$value_in_kmd}/{$paid_total_so_far}/{$order_total_in_kmd}");
                    }

                    // Reply '*ok*' so no more notifications are sent
                    exit ('*ok*');
                } else {
                    // Number of confirmations are not there yet... Skip it this time ...
                    // Don't print *ok* so the notification resent again on next confirmation
                    KMD__log_event(__FILE__, __LINE__, "NOTE: Payment notification received (for KMD {$value_in_kmd}), but number of confirmations is not enough yet. Confirmations received/required: {$confirmations}/{$this->confs_num}");
                    exit();
                }
            }
        }
        //-------------------------------------------------------------------
    }

    //=======================================================================


    //-----------------------------------------------------------------------
    // Hook into WooCommerce - add necessary hooks and filters
    add_filter('woocommerce_payment_gateways', 'KMD__add_komodo_gateway');

    // Disable unnecessary billing fields.
    /// Note: it affects whole store.
    /// add_filter ('woocommerce_checkout_fields' , 	'KMD__woocommerce_checkout_fields' );

    add_filter('woocommerce_currencies', 'KMD__add_kmd_currency');
    add_filter('woocommerce_currency_symbol', 'KMD__add_kmd_currency_symbol', 10, 2);

    // Change [Order] button text on checkout screen.
    /// Note: this will affect all payment methods.
    /// add_filter ('woocommerce_order_button_text', 	'KMD__order_button_text');
    //-----------------------------------------------------------------------

    //=======================================================================
    /**
     * Add the gateway to WooCommerce
     *
     * @access public
     * @param array $methods
     * @package
     * @return array
     * /
     */
    function KMD__add_komodo_gateway($methods)
    {
        $methods[] = 'KMD_KOMODO';
        return $methods;
    }

    //=======================================================================

    //=======================================================================
    // Our hooked in function - $fields is passed via the filter!
    function KMD__woocommerce_checkout_fields($fields)
    {
        unset($fields['order']['order_comments']);
        unset($fields['billing']['billing_first_name']);
        unset($fields['billing']['billing_last_name']);
        unset($fields['billing']['billing_company']);
        unset($fields['billing']['billing_address_1']);
        unset($fields['billing']['billing_address_2']);
        unset($fields['billing']['billing_city']);
        unset($fields['billing']['billing_postcode']);
        unset($fields['billing']['billing_country']);
        unset($fields['billing']['billing_state']);
        unset($fields['billing']['billing_phone']);
        return $fields;
    }

    //=======================================================================

    //=======================================================================
    function KMD__add_kmd_currency($currencies)
    {
        $currencies['KMD'] = __('Komodo', 'woocommerce');
        return $currencies;
    }

    //=======================================================================

    //=======================================================================
    function KMD__add_kmd_currency_symbol($currency_symbol, $currency)
    {
        switch ($currency) {
            case 'KMD':
                $currency_symbol = 'K';
                break;
        }

        return $currency_symbol;
    }

    //=======================================================================

    //=======================================================================
    function KMD__order_button_text()
    {
        return 'Continue';
    }
    //=======================================================================
}

//###########################################################################

//===========================================================================
function KMD__process_payment_completed_for_order($order_id, $komodo_paid = false)
{

    if ($komodo_paid)
        update_post_meta($order_id, 'komodo_paid_total', $komodo_paid);

    // Payment completed
    // Make sure this logic is done only once, in case customer keep sending payments :)
    if (!get_post_meta($order_id, '_payment_completed', true)) {
        update_post_meta($order_id, '_payment_completed', '1');

        KMD__log_event(__FILE__, __LINE__, "Success: order '{$order_id}' paid in full. Processing and notifying customer ...");

        // Instantiate order object.
        $order = wc_get_order($order_id);
        $order->add_order_note(__('Order paid in full', 'woocommerce'));

        $order->payment_complete();

        $kmd_settings = KMD__get_settings();
        if ($kmd_settings['autocomplete_paid_orders']) {
            // Ensure order is completed.
            $order->update_status('completed', __('Order marked as completed according to Komodo plugin settings', 'woocommerce'));
        }

        // Notify admin about payment processed
        $email = get_option('admin_email');
        if (!$email)
            $email = get_option('admin_email');
        if ($email) {
            // Send email from admin to admin
            KMD__send_email($email, $email, "Full payment received for order ID: '{$order_id}'",
                "Order ID: '{$order_id}' paid in full. <br />Received KMD: '$komodo_paid'.<br />Please process and complete order for customer."
            );
        }
    }
}
//===========================================================================
