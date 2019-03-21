<?php

/*
 * Plugin Name: Paymentwall for WHMCS
 * Plugin URI: https://docs.paymentwall.com/modules/whmcs
 * Description: Official Paymentwall module for Prestashop.
 * Version: v1.6.2
 * Author: The Paymentwall Team
 * Author URI: http://www.paymentwall.com/
 * License: The MIT License (MIT)
 *
 */
if (!defined("WHMCS")) {
    exit("This file cannot be accessed directly");
}

function paymentwall_config()
{
    $configs = array(
        "FriendlyName" => array("Type" => "System", "Value" => "Paymentwall"),
        "UsageNotes" => array("Type" => "System", "Value" => "Please read the <a href='https://www.paymentwall.com/en/documentation/WHMCS/826'>documentation</a> to get more information"),
        "appKey" => array("FriendlyName" => "Project Key", "Type" => "text", "Size" => "40"),
        "secretKey" => array("FriendlyName" => "Secret Key", "Type" => "text", "Size" => "40"),
        "widget" => array("FriendlyName" => "Widget", "Type" => "text", "Size" => "5"),
        "isTest" => array("FriendlyName" => "Is Test", "Type" => "yesno", "Description" => "Tick this box to enable Test mode"),
        "enableDeliveryApi" => array("FriendlyName" => "Enable Delivery Api", "Type" => "yesno", "Description" => "Tick this box to enable Delivery Confirmation API"),
        "forceOneTime" => array("FriendlyName" => "Force Onetime Payments", "Type" => "yesno", "Description" => "Only use the Onetime button when checkout"),
        "forceSubscription" => array("FriendlyName" => "Force Subscription Payments", "Type" => "yesno", "Description" => "Only use the Subscription button when checkout, hide Onetime button"),
        "forceHttps" => array("FriendlyName" => "Force Https Site", "Type" => "yesno", "Description" => "Only use https when to redirecting to the payment page."),
    );

    return $configs;
}

function init_paymentwall_config($params)
{
    require_once(ROOTDIR . '/includes/api/paymentwall_api/lib/paymentwall.php');
    require_once(ROOTDIR . '/modules/gateways/paymentwall/helpers/helper.php');
    Paymentwall_Config::getInstance()->set(array(
        'api_type' => Paymentwall_Config::API_GOODS,
        'public_key' => $params['appKey'], // available in your Paymentwall merchant area
        'private_key' => $params['secretKey'] // available in your Paymentwall merchant area
    ));
}

function paymentwall_link($params)
{
    init_paymentwall_config($params);
    $product = null;
    $recurring = getRecurringBillingValuesFromInvoice($params['invoiceid']);

    $code = '';
    $hasTrial = false;
    $subscriptionProduct = false;

    if ($recurring) {
        $subscriptionProduct = get_subscription_product($params, $recurring, $hasTrial);
    }

    $onetimeProduct = get_one_time_product($params);

    if ($subscriptionProduct && (!$params['forceOneTime'] || $params['forceSubscription'])) {
        $subscriptionWidget = new Paymentwall_Widget(
            $params['clientdetails']['userid'],
            $params['widget'],
            array(
                $subscriptionProduct
            ),
            array_merge(
                array(
                    'integration_module' => 'whmcs',
                    'test_mode' => $params['isTest'] == 'on' ? 1 : 0,
                    'hide_post_trial_good' => $hasTrial ? 1 : 0,
                    'success_url' => $params['systemurl'].'/cart.php?a=complete'
                ),
                get_user_profile_data($params)
            )
        );
        $code .= get_widget_code($subscriptionWidget, $params, 'subscribe');
    }

    if ((!$params['forceSubscription'] && $recurring) || !$recurring) {
        $onetimeWidget = new Paymentwall_Widget(
            $params['clientdetails']['userid'],
            $params['widget'],
            array(
                $onetimeProduct
            ),
            array_merge(
                array(
                    'integration_module' => 'whmcs',
                    'test_mode' => $params['isTest'] == 'on' ? 1 : 0,
                    'success_url' => $params['systemurl'].'/cart.php?a=complete'
                ),
                get_user_profile_data($params)
            )
        );

        $code .= get_widget_code($onetimeWidget, $params, 'check_out');
    }
    $code .= '<br><span style="font-size: 11px; color: #AAAAAA">Secure payments by <a href="https://www.paymentwall.com">Paymentwall Inc</a>.<span>';

    return $code;
}

/**
 * @param $params
 * @return array
 */
function get_user_profile_data($params)
{
    return array(
        'customer[city]' => $params['clientdetails']['city'],
        'customer[state]' => $params['clientdetails']['fullstate'],
        'customer[address]' => $params['clientdetails']['address1'],
        'customer[country]' => $params['clientdetails']['countrycode'],
        'customer[zip]' => $params['clientdetails']['postcode'],
        'customer[username]' => $params['clientdetails']['userid'] ? $params['clientdetails']['userid'] : $params['clientdetails']['email'],
        'customer[firstname]' => $params['clientdetails']['firstname'],
        'customer[lastname]' => $params['clientdetails']['lastname'],
        'email' => $params['clientdetails']['email'],
    );
}

/**
 * @param $params
 * @return Paymentwall_Product
 */
function get_one_time_product($params)
{
    $hostIdArray = getHostIdFromInvoice($params['invoiceid']);
    return new Paymentwall_Product(
        $hostIdArray['id'].":".$params['invoiceid'].":".$hostIdArray['type'],
        $params['amount'],
        $params['currency'],
        $params["description"],
        Paymentwall_Product::TYPE_FIXED
    );
}

/**
 * @param $params
 * @param $recurring
 * @param $hasTrial
 * @return Paymentwall_Product
 */
function get_subscription_product($params, $recurring, &$hasTrial)
{
    $trialProduct = null;

    if (isset($recurring['firstpaymentamount'])) {
        // Product + Setup Fee
        $trialProduct = new Paymentwall_Product(
            $recurring['primaryserviceid'].":".$params['invoiceid'].":".$recurring['primaryservicetype'], // Pass hosting id instead of invoice
            $recurring['firstpaymentamount'],
            $params['currency'],
            $params["description"] . ' - first time payment',
            Paymentwall_Product::TYPE_SUBSCRIPTION,
            $recurring['firstcycleperiod'],
            get_period_type($recurring['firstcycleunits']),
            true
        );
        $hasTrial = true;
    }

    return new Paymentwall_Product(
        $recurring['primaryserviceid'].":".$params['invoiceid'].":".$recurring['primaryservicetype'],
        $recurring['recurringamount'],
        $params['currency'],
        $params["description"] . ' - recurring payment',
        Paymentwall_Product::TYPE_SUBSCRIPTION,
        $recurring['recurringcycleperiod'],
        get_period_type($recurring['recurringcycleunits']),
        true,
        $trialProduct
    );
}

/**
 * @param $widget
 * @param $params
 * @param $type
 * @return string
 */
function get_widget_code($widget, $params, $type)
{
    $systemUrl = $params['systemurl'];
    if($params['forceHttps'] == 'on') {
        $systemUrl = str_replace('http://', 'https://', $systemUrl);
    }
    return '<form method=POST action="' . $systemUrl . '/paymentwallwidget.php" style="display:inline;">
        <input type="hidden" name="data" value="' . encrypt($widget->getHtmlCode(array(
        'width' => '100%',
        'height' => '650'
    ))) . '" />
            <input type="image" src="' . $systemUrl . '/images/paymentwall/' . $type . '.png" onClick="this.form.submit()"/>
    </form>';
}