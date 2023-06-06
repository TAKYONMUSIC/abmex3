<?php
/*
Plugin Name: ABMEX Pay Gateway  for WooCommerce
Description: Plugin para adicionar opção de pagamento com a plataforma ABMEX no WooCommerce.
Version: 1.0
*/

add_action('plugins_loaded', 'init_abmex_gateway');

function init_abmex_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Abmex extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'abmex_gateway';
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = __('ABMEX Gateway', 'woocommerce');
            $this->method_description = __('Allows payments through the ABMEX payment gateway.', 'woocommerce');

            $this->supports = array(
                'products',
                'subscriptions',
                'subscription_cancellation',
                'subscription_reactivation',
                'subscription_suspension',
                'subscription_amount_changes',
                'subscription_date_changes',
                'refunds',
            );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->client_id = $this->get_option('client_id');
            $this->client_secret = $this->get_option('client_secret');

            add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_'.$this->id, array($this, 'receipt_page'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable ABMEX Payment Gateway.', 'woocommerce'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title'       => __('Title', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default'     => __('ABMEX Gateway', 'woocommerce'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default'     => __('Pay with your credit card via our super-cool payment gateway!', 'woocommerce'),
                ),
                'client_id' => array(
                    'title'       => __('Client ID', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Enter your ABMEX Client ID here.', 'woocommerce'),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'client_secret' => array(
                    'title'       => __('Client Secret', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Enter your ABMEX Client Secret here.', 'woocommerce'),
                    'default'     => '',
                    'desc_tip'    => true,
                )
            );
        }

        public function process_payment($order_id)
        {
            global $woocommerce;

            $order = wc_get_order($order_id);
            $order_total = $order->get_total();
            $orderId = $order->get_order_number();
            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $customer_email = $order->get_billing_email();
            $payment_type = 'credit_card';
            $installments = 1;
            $redirect_success_url = $this->get_return_url($order);
            $redirect_cancel_url = $order->get_cancel_order_url_raw();

            // Cria uma nova sessão
            $url = 'https://api.abmex.com.br/sessions';
            $data = array('amount' => $order_total, 'currency' => 'BRL');
            $headers = array(
                'Authorization: Basic '.base64_encode($this->client_id.':'.$this->client_secret),
                'Content-Type: application/json'
            );

            $options = array(
                'http' => array(
                    'header'  => $headers,
                    'method'  => 'POST',
                    'content' => json_encode($data)
                )
            );

            $context  = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $session_id = json_decode($response, true)['id'];

            // Obtenção de informações da transação
            $transaction_id = uniqid();
            $url = 'https://api.abmex.com.br/transactions/'.$transaction_id;
            $headers = array('Authorization: Bearer '.$session_id);
            $options = array(
                'http' => array(
                    'header'  => $headers,
                    'method'  => 'GET'
                )
            );

            $context  = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $transaction_info = json_decode($response, true);

            // Criação de uma nova transação
            $data = array(
                'amount' => $order_total,
                'currency' => 'BRL',
                'reference' => $orderId,
                'customer' => array(
                    'name' => $customer_name,
                    'email' => $customer_email
                ),
                'payment_method' => array(
                    'type' => $payment_type,
                    'installments' => $installments
                ),
                'redirect_urls' => array(
                    'success' => $redirect_success_url,
                    'cancel' => $redirect_cancel_url
                )
            );

            $url = 'https://api.abmex.com.br/transactions';
            $options = array(
                'http' => array(
                    'header'  => $headers,
                    'method'  => 'POST',
                    'content' => json_encode($data)
                )
            );

            $context  = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $payment_link = json_decode($response, true)['payment_link'];

            // Redirecionar o comprador para o link de pagamento
            wp_redirect($payment_link);
            exit;
        }

        public function receipt_page($order_id)
        {
            echo '<p>'.__('Thank you for your order.', 'woocommerce').'</p>';
        }
    }

    add_filter('woocommerce_payment_gateways', 'add_abmex_gateway');

    function add_abmex_gateway($gateways)
    {
        $gateways[] = 'WC_Abmex';

        return $gateways;
    }
}
