<?php
/*
Plugin Name: WooCommerce Cardnet Payment Gateway
Description: A Payment Gateway to process Cardnet Credit Card Payments.
Version: 1.0.0
Author: Ramsés Del Rosario
Author URI: http://ramsesdelr.com/
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
WC tested up to: 3.3
WC requires at least: 1.6
*/

add_action('plugins_loaded', 'woocommerce_cardnet_init', 0);
define('ASSETS', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/assets');

function woocommerce_cardnet_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (isset($_GET['msg']) && !empty($_GET['msg'])) {
        add_action('the_content', 'show_cardnet_message');
    }
    function show_cardnet_message($content)
    {
        return '<div class="' . htmlentities($_GET['type']) . '">' . htmlentities(urldecode($_GET['msg'])) . '</div>' . $content;
    }

    /**
     * Cardnet Gateway Class
     *
     * @access public
     * @param
     * @return
     */
    class WC_Cardnet extends WC_Payment_Gateway
    {

        public function __construct()
        {
            global $woocommerce;

            $this->id = 'cardnet';
            $this->icon_default = $this->logo(false);
            $this->method_title = __('Cardnet', 'cardnet-woocommerce');
            $this->method_description = __("Gateway de Cardnet para WooCommerce", 'cardnet-woocommerce');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();
            $this->language = get_bloginfo('language');
            $this->testmode = $this->settings['testmode'];
            $this->debug = "no";
            $this->show_methods = $this->settings['show_methods'];
            $this->icon_checkout = $this->settings['icon_checkout'];

            if ($this->show_methods == 'yes' && trim($this->settings['icon_checkout']) == '') {
                $this->icon = $this->icon_default;
            } elseif (trim($this->settings['icon_checkout']) != '') {
                $this->icon = $this->settings['icon_checkout'];
            } else {
                $this->icon = $this->logo();
            }

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->redirect_page_id = $this->settings['redirect_page_id'];
            $this->taxes = $this->settings['taxes'];
            $this->AcquiringInstitutionCode = $this->settings['AcquiringInstitutionCode'];
            $this->MerchantType = $this->settings['MerchantType'];
            $this->MerchantNumber = $this->settings['MerchantNumber'];
            $this->MerchantTerminal = $this->settings['MerchantTerminal'];
            $this->MerchantName = str_pad($this->settings['MerchantName'], 40, ' ');
            $this->currency = 'DOP';
            $this->textactive = 0;
            $this->form_method = $this->settings['form_method'];
            $this->liveurl =  $this->settings['live_url'];
            $this->testurl = $this->settings['test_url'];

            /* mesagges */
            $this->msg_approved = $this->settings['msg_approved'];
            $this->msg_declined = $this->settings['msg_declined'];
            $this->msg_cancel = $this->settings['msg_cancel'];
            $this->msg_pending = $this->settings['msg_pending'];

            if ($this->testmode == "yes") {
                $this->debug = "yes";
            }

            $this->msg['message'] = "";
            $this->msg['class'] = "";
            // Logs

            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>=')) {
                $this->log = new WC_Logger();
            } else {
                $this->log = $woocommerce->logger();
            }

            add_action('cardnet_init', array($this, 'cardnet_successful_request'));
            add_action('woocommerce_receipt_cardnet', array($this, 'receipt_page'));
            //update for woocommerce >2.0
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_cardnet_response'));

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                /* 2.0.0 */
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                /* 1.6.6 */
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }

        }

        /**
         * Show logo
         */

        public function logo($default = true)
        {
            $country = '';
            if (!$default) {
                $country = WC()->countries->get_base_country();
            }

            $icon = ASSETS . '/img/cardnet_logo.png';
            return $icon;
        }
        /**
         * Check if Gateway can be displayed
         *
         * @access public
         * @return void
         */
        function is_available()
        {
            global $woocommerce;

            if ($this->enabled == "yes"):

                if ($woocommerce->version < '1.5.8') {
                    return false;
                }

                if (!$this->merchant_id || !$this->account_id || !$this->apikey) {
                    // return false;
                }

                return true;
            endif;

            return false;
        }

        /**
         * Settings Options
         *
         * @access public
         * @return void
         */
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Activar/Desactivar', 'cardnet-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Activar modo de pago con Cardnet.', 'cardnet-woocommerce'),
                    'default' => 'no',
                    'description' => __('Mostrarlo como método de pago en el listado.', 'cardnet-woocommerce'),
                ),

                'icon_checkout' => array(
                    'title' => __('Logo en el checkout:', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => $this->logo(),
                    'description' => __('URL de la Imagen para mostrar en el carrro de compra.', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'title' => array(
                    'title' => __('Title:', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => __('Cardnet Online Payments', 'cardnet-woocommerce'),
                    'description' => __('Esto controla el titulo que el usuario visualizará al momento de hacer checkout.', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description:', 'cardnet-woocommerce'),
                    'type' => 'textarea',
                    'default' => __('Pay securely by Credit or Debit Card or Internet Banking through Cardnet Secure Servers.', 'cardnet-woocommerce'),
                    'description' => __('This controls the description which the user sees during checkout.', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'testmode' => array(
                    'title' => __('TEST Mode', 'cardnet-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Ambiente de desarrollo para developers.', 'cardnet-woocommerce'),
                    'default' => 'no',
                    'description' => __('Activar para hacer pruebas con Cardnet antes de pasar a producción', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'live_url' => array(
                    'title' => __('URL de Producción', 'cardnet-woocommerce') . '',
                    'type' => 'text',
                    'default' => '0',
                    'description' => __('URL para procesar pagos en producción.', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                 'test_url' => array(
                    'title' => __('URL de Desarollo', 'cardnet-woocommerce') . '',
                    'type' => 'text',
                    'default' => '0',
                    'description' => __('URL para procesar pagos en desarrollo.', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'taxes' => array(
                    'title' => __('ITBIS', 'cardnet-woocommerce') . '',
                    'type' => 'text',
                    'default' => '0',
                    'description' => __('ITBIS a incluir en el total de las ordenes.', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'AcquiringInstitutionCode' => array(
                    'title' => __('AcquiringInstitutionCode', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => '0',
                    'description' => __('Este código es asignado por EverTec ', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'MerchantType' => array(
                    'title' => __('MerchantType', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => '0',
                    'description' => __('Valor asignado por el banco adquiriente. Código de Categoria del Comercio ', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'MerchantNumber' => array(
                    'title' => __('MerchantNumber', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => '0',
                    'description' => __('Valor asignado por el banco adquiriente, completar con espacios a la derecha. ', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'MerchantTerminal' => array(
                    'title' => __('MerchantTerminal', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => '0',
                    'description' => __('Valor asignado por EverTec, campo numérico. ', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ), 
                'MerchantName' => array(
                    'title' => __('MerchantName', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => '0',
                    'description' => __('Valor Alfanumérico de 40 posiciones. ', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'form_method' => array(
                    'title' => __('Form Method', 'cardnet-woocommerce'),
                    'type' => 'select',
                    'default' => 'POST',
                    'options' => array('POST' => 'POST', 'GET' => 'GET'),
                    'description' => __('Metodo para el form de checkout ', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'redirect_page_id' => array(
                    'title' => __('Página de Retorno', 'cardnet-woocommerce'),
                    'type' => 'select',
                    'options' => $this->get_pages(__('Seleccione una  Página', 'cardnet-woocommerce')),
                    'description' => __('URL de la página: Orden Completada', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'msg_approved' => array(
                    'title' => __('Mensaje de transacción aprobada', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => __('Pago con Cardnet Aprobado', 'cardnet-woocommerce'),
                    'description' => __('Mensaje para transacción aprobada', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'msg_pending' => array(
                    'title' => __('Mensaje de transacción pendiente', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => __('Pago pendiente', 'cardnet-woocommerce'),
                    'description' => __('Mensaje para transacción pendiente', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'msg_cancel' => array(
                    'title' => __('Mensaje para transacción cancelada', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => __('Transacción Cancelada.', 'cardnet-woocommerce'),
                    'description' => __('Mensaje de transacción cancelada', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
                'msg_declined' => array(
                    'title' => __('Mensaje de transacción declinada', 'cardnet-woocommerce'),
                    'type' => 'text',
                    'default' => __('Pago rechazado por Cardnet.', 'cardnet-woocommerce'),
                    'description' => __('Mensaje para transacción rechazada ', 'cardnet-woocommerce'),
                    'desc_tip' => true,
                ),
            );

        }

        /**
         * Generate Admin Panel Options
         *
         * @access public
         * @return string
         **/
        public function admin_options()
        {
            echo '<img src="' . $this->logo() . '" alt="Cardnet" width="200"><h3>' . __('Cardnet', 'cardnet-woocommerce') . '</h3>';
            echo '<p>' . __('Gateway de pago de Cardnet para WooCommerce', 'cardnet-woocommerce') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }
        /**
         * Generate the Cardnet Payment Fields
         *
         * @access public
         * @return string
         */
        function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }

        }
        /**
         * Generate the Cardnet Form for checkout
         *
         * @access public
         * @param mixed $order
         * @return string
         **/
        function receipt_page($order)
        {
            echo '<p>' . __('Gracias por su orden, por favor presione el boton para procesar su pago con Cardnet.', 'cardnet-woocommerce') . '</p>';
            echo $this->generate_cardnet_form($order);
        }
        /**
         * Generate Cardnet POST arguments
         *
         * @access public
         * @param mixed $order_id
         * @return string
         **/
        function get_cardnet_args($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $txnid = $order->order_key . '-' . time();

            $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);
            //For wooCoomerce 2.0
            $redirect_url = add_query_arg('wc-api', get_class($this), $redirect_url);
            $redirect_url = add_query_arg('order_id', $order_id, $redirect_url);

            $productinfo = "Orden $order_id";

            $str = "$this->apikey~$this->merchant_id~$txnid~$order->order_total~$this->currency";
            $hash = strtolower(md5($str));
            $taxes = isset($order->taxes) ? $order->taxes : 0;
            $cardnet_args = array(
                // Cardnet fields
                'TransactionType' => '0200',
                'CurrencyCode' => '214',
                'AcquiringInstitutionCode' => $this->AcquiringInstitutionCode,
                'MerchantType' => $this->MerchantType,
                'MerchantNumber' => $this->MerchantNumber,
                'MerchantTerminal' => $this->MerchantTerminal,
                'ReturnUrl' => $redirect_url,
                'CancelUrl' => $redirect_url,
                'OrdenId' => $order->id,
                'TransactionId' => str_pad(substr($order->id, -6), 6, '0', STR_PAD_LEFT),
                'Amount' => str_pad(str_replace('.', '', $order->order_total), 12, '0', STR_PAD_LEFT),
                'Tax' => str_pad($taxes, 12, '0', STR_PAD_LEFT),
                'MerchantName' => $this->MerchantName,
                'Ipclient' => $_SERVER['REMOTE_ADDR'],
            );

            // Prepare the Cardnet Hash
            $cnStr = $cardnet_args['MerchantType']
                . $cardnet_args['MerchantNumber']
                . $cardnet_args['MerchantTerminal']
                . $cardnet_args['TransactionId']
                . $cardnet_args['Amount']
                . $cardnet_args['Tax'];
            $cnHash = strtolower(md5($cnStr));
            $cardnet_args['KeyEncriptionKey'] = $cnHash;
            return $cardnet_args;
        }

        /**
         * Generate the Cardnet button link
         *
         * @access public
         * @param mixed $order_id
         * @return string
         */
        function generate_cardnet_form($order_id)
        {
            global $woocommerce;

            $order = new WC_Order($order_id);

            if ($this->testmode == 'yes') {
                $payment_address = $this->testurl;
            } else {
                $payment_address = $this->liveurl;
            }

            $cardnet_args = $this->get_cardnet_args($order_id);
            $cardnet_args_array = array();

            foreach ($cardnet_args as $key => $value) {
                $cardnet_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }
            $code = 'jQuery("body").block({
                        message: "' . esc_js(__('Gracias por su pedido, le estaremos redireccionando a Carnet para procesar el pago.', 'cardnet-woocommerce')) . '",
                        baseZ: 99999,
                        overlayCSS:
                        {
                            background: "#fff",
                            opacity: 0.6
                        },
                        css: {
                            padding:        "20px",
                            zindex:         "9999999",
                            textAlign:      "center",
                            color:          "#555",
                            border:         "3px solid #aaa",
                            backgroundColor:"#fff",
                            cursor:         "wait",
                            lineHeight:     "24px",
                        }
                    });
                jQuery("#submit_cardnet_payment_form").click();';

            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>=')) {
                wc_enqueue_js($code);
            } else {
                $woocommerce->add_inline_js($code);
            }

            return '<form action="' . $payment_address . '" method="POST" id="cardnet_payment_form" target="_top">
                    ' . implode('', $cardnet_args_array) . '
                    <input type="submit" class="button alt" id="submit_cardnet_payment_form" value="' . __('Pagar con Cardnet', 'cardnet-woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancelar orden &amp; restaurar el carrito de compras', 'woocommerce') . '</a>
                </form>';
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            if ($this->form_method == 'GET') {
                $cardnet_args = $this->get_cardnet_args($order_id);
                $cardnet_args = http_build_query($cardnet_args, '', '&');
                if ($this->testmode == 'yes'):
                    $payment_address = $this->testurl . '&';
                else:
                    $payment_address = $this->liveurl . '?';
                endif;

                return array(
                    'result' => 'success',
                    'redirect' => $payment_address . $cardnet_args,
                );
            } else {
                if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>=')) {
                    return array(
                        'result' => 'success',
                        'redirect' => add_query_arg('order-pay', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay')))),
                    );
                } else {
                    return array(
                        'result' => 'success',
                        'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay')))),
                    );
                }

            }

        }
        /**
         * Check for valid Cardnet server callback
         *
         * @access public
         * @return void
         **/
        function check_cardnet_response()
        {
            @ob_clean();
            if (!empty($_REQUEST)) {
                header('HTTP/1.1 200 OK');
                do_action("cardnet_init", $_REQUEST);
            } else {
                wp_die(__("Cardnet Request Failure", 'cardnet-woocommerce'));
            }

        }

        /**
         * Process Cardnet Response and update the order information
         *
         * @access public
         * @param array $posted
         * @return void
         */
        function cardnet_successful_request($posted)
        {
            global $woocommerce;

            if (!empty($_POST['ResponseCode'])) {
                $this->cardnet_return_process($posted);
            }
            if (!empty($_POST['ResponseCode'])) {
                $this->cardnet_confirmation_process($_POST);
            }

            $redirect_url = $woocommerce->cart->get_checkout_url();
            //For wooCoomerce 2.0
            $redirect_url = add_query_arg(array('msg' => urlencode(__('Hubo un error con la solicitud, favor contactar al administrador del sitio.', 'cardnet')), 'type' => $this->msg['class']), $redirect_url);

            wp_redirect($redirect_url);
            exit;
        }

        /**
         * Process Response Page
         * @param $posted
         *
         */
        function cardnet_return_process($posted)
        {
            global $woocommerce;

            $order = $this->get_cardnet_order($posted);
            $order->add_order_note(__('Terminal de Tarjeta No: ' . $posted['CreditCardNumber'], 'cardnet-woocommerce'));
            $codes = array('00' => 'APPROVED', '57' => 'DECLINED', '06' => 'ERROR', '54' => 'EXPIRED', '09' => 'PENDING');

            if ('yes' == $this->debug) {
                $this->log->add('cardnet', 'CARDNET Found order #' . $order->id);
            }

            if ('yes' == $this->debug) {
                $this->log->add('cardnet', 'CARDNET Transaction state: ' . $posted['ResponseCode']);
            }

            $state = $posted['ResponseCode'];

            // We are here so lets check status and do actions
            switch ($codes[$state]) {
                case 'APPROVED':
                case 'PENDING':

                    // Check order not already completed
                    if ($order->status == 'completed') {
                        if ('yes' == $this->debug) {
                            $this->log->add('cardnet', __('Cancelando la orden, Order #' . $order->id . ' ya fue completada.', 'cardnet-woocommerce'));
                        }

                        exit;
                    }

                    // Payment Details

                    if (!empty($posted['AuthorizationCode'])) {
                        update_post_meta($order->id, __('Authorization Code', 'cardnet-woocommerce'), $posted['AuthorizationCode']);
                    }

                    if (!empty($posted['OrdenId'])) {
                        update_post_meta($order->id, __('Orden No.', 'cardnet-woocommerce'), $posted['OrdenId']);
                    }

                    if (!empty($posted['TransactionId'])) {
                        update_post_meta($order->id, __('Transaction ID', 'cardnet-woocommerce'), $posted['TransactionId']);
                    }

                    if ($codes[$state] == 'APPROVED') {
                        $order->add_order_note(__('Pago Aprobado con Cardnet', 'cardnet-woocommerce'));
                        $this->msg['message'] = $this->msg_approved;
                        $this->msg['class'] = 'woocommerce-message';
                        $order->payment_complete();
                    } else {
                        $order->update_status('on-hold', sprintf(__('Pago Pendiente: %s', 'cardnet-woocommerce'), $codes[$state]));
                        $this->msg['message'] = $this->msg_pending;
                        $this->msg['class'] = 'woocommerce-info';
                    }

                    break;
                case 'DECLINED':
                case 'EXPIRED':
                case 'ERROR':
                    // Order failed

                    $order->update_status('failed', sprintf(__('Pago rechazado por Cardnet. Error type: %s', 'cardnet-woocommerce'), ($codes[$state])));
                    $this->msg['message'] = $this->msg_declined;
                    $this->msg['class'] = 'woocommerce-error';
                    break;
                default:
                    $order->update_status('failed', sprintf(__('Pago rechazado por Cardnet.', 'cardnet-woocommerce'), ($codes[$state])));
                    $this->msg['message'] = $this->msg_cancel;
                    $this->msg['class'] = 'woocommerce-error';
                break;
            }

            $redirect_url = ($this->redirect_page_id == 'default' || $this->redirect_page_id == "" || $this->redirect_page_id == 0) ? $order->get_checkout_order_received_url() : get_permalink($this->redirect_page_id);
            //For wooCoomerce 2.0
            $redirect_url = add_query_arg(array('msg' => urlencode($this->msg['message']), 'type' => $this->msg['class']), $redirect_url);

            wp_redirect($redirect_url);
            exit;
        }

        /**
         * Process the confirmation page
         * @param $posted
         *
         **/
        function cardnet_confirmation_process($posted)
        {
            global $woocommerce;
            $order = $this->get_cardnet_order($posted);

            $codes = array(
                'N7' => 'INVALID DATE',
                '02' => 'REFER TO CARD ISSUER',
                '01' => 'REFER TO CARD ISSUER',
                '06' => 'ERROR',
                '14' => 'INVALID CARD NUMBER',
                '26' => 'DUPLICATE RECORD',
                '103' => 'SUBMITTED',
                '00' => 'APPROVED',
                '57' => 'DECLINED',
                '59' => 'SUSPECTED FRAUD',
                '06' => 'ERROR',
                '09' => 'PENDING',
                '54' => 'EXPIRED',
            );

            if ('yes' == $this->debug) {
                $this->log->add('cardnet', 'Found order #' . $order->id);
            }

            $state = $_POST['ResponseCode'];

            if ('yes' == $this->debug) {
                $this->log->add('cardnet', 'Payment status: ' . $codes[$state]);
            }

            // We are here so lets check status and do actions
            switch ($codes[$state]) {
                case 'APPROVED':
                case 'PENDING':

                    // Check order not already completed
                    if ($order->status == 'completed') {
                        if ('yes' == $this->debug) {
                            $this->log->add('cardnet', __('Abortando, Orden #' . $order->id . ' ya fue completada.', 'cardnet-woocommerce'));
                        }

                        exit;
                    }

                    // Payment details

                    if (!empty($posted['AuthorizationCode'])) {
                        update_post_meta($order->id, __('Authorization Code', 'cardnet-woocommerce'), $posted['AuthorizationCode']);
                    }

                    if (!empty($posted['OrdenId'])) {
                        update_post_meta($order->id, __('Orden No.', 'cardnet-woocommerce'), $posted['OrdenId']);
                    }

                    if (!empty($posted['TransactionId'])) {
                        update_post_meta($order->id, __('Transaction ID', 'cardnet-woocommerce'), $posted['TransactionId']);
                    }
                

                    if ($codes[$state] == 'APPROVED') {
                        $order->add_order_note(__('Pago aprobado por Cardnet', 'cardnet-woocommerce'));
                        $this->msg['message'] = $this->msg_approved;
                        $this->msg['class'] = 'woocommerce-message';
                        $order->payment_complete();

                        if ('yes' == $this->debug) {$this->log->add('cardnet', __('Pgo completado.', 'cardnet-woocommerce'));}

                    } else {

                        $order->update_status('on-hold', sprintf(__('Pago pendiente: %s', 'cardnet-woocommerce'), $codes[$state]));
                        $this->msg['message'] = $this->msg_pending;
                        $this->msg['class'] = 'woocommerce-info';
                    }

                    break;
                case 'DECLINED':
                case 'EXPIRED':
                case 'ERROR':
                case 'ABANDONED_TRANSACTION':
                    // Order failed

                    $order->update_status('failed', sprintf(__('Pago rechazado por Cardnet. Error type: %s', 'cardnet-woocommerce'), ($codes[$state])));
                    $this->msg['message'] = $this->msg_declined;
                    $this->msg['class'] = 'woocommerce-error';
                    break;
                default:

                    $order->update_status('failed', sprintf(__('Pago rechazado por Cardnet.', 'cardnet-woocommerce'), ($codes[$state])));
                    $this->msg['message'] = $this->msg_cancel;
                    $this->msg['class'] = 'woocommerce-error';
                    break;
            }
        }

        /**
         *  Get order information
         *
         * @access public
         * @param mixed $posted
         * @return void
         */
        function get_cardnet_order($posted)
        {
            $order_id = (int) $posted['OrdenID'];
            $order = new WC_Order($order_id);
            return $order;
        }

        /**
         * Get pages for return page setting
         *
         * @access public
         * @return bool
         */
        function get_pages($title = false, $indent = true)
        {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array('default' => __('Default Page', 'cardnet-woocommerce'));
            if ($title) {
                $page_list[] = $title;
            }

            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }
    }



    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_cardnet_gateway($methods)
    {
        $methods[] = 'WC_Cardnet';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_cardnet_gateway');
}

