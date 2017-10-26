<?php
# Required File Includes
if (!file_exists("../../init.php")) {
    // For v5.x
    include("../../dbconnect.php");
} else {
    // For v6.x, v7.x
    include("../../init.php");
}

include("../../includes/functions.php");
include("../../includes/gatewayfunctions.php");
include("../../includes/invoicefunctions.php");

require_once(ROOTDIR . '/includes/api/paymentwall_api/lib/paymentwall.php');

function prepare_delivery_data($userData, $gateway)
{
    return array(
        'type' => 'digital',
        'refundable' => 'yes',
        'shipping_address[email]' => $userData['email'],
        'shipping_address[firstname]' => $userData['firstname'],
        'shipping_address[lastname]' => $userData['lastname'],
        'shipping_address[country]' => $userData['country'],
        'shipping_address[street]' => $userData['address1'],
        'shipping_address[state]' => $userData['state'],
        'shipping_address[phone]' => $userData['phonenumber'],
        'shipping_address[zip]' => $userData['postcode'],
        'shipping_address[city]' => $userData['city'],
        'reason' => 'none',
        'is_test' => $gateway['isTest'] == 'on' ? 1 : 0
    );
}

function handleProductAutoSetup($vars, $hosting = array(), $gateway = array())
{
    // Automatically setup the product as soon as the first payment is received
    // Do not store delivery data to DB
    return insert_query('pw_delivery_data', array(
        'user_id' => $vars['params']['userid'],
        'order_id' => $hosting['orderid'],
        'package_id' => $vars['params']['packageid'],
        'username' => $vars['params']['username'],
        'status' => 'unsent',
        'created_date' => time(),
        'data' => json_encode(prepare_delivery_data($vars['params']['clientsdetails'], $gateway)),
    ));


}

function afterSetupProductEventListener($vars)
{
    $gateway = getGatewayVariables("paymentwall");

    if (!isset($gateway['enableDeliveryApi']) || $gateway['enableDeliveryApi'] == '') {
        return;
    }

    if ($vars['params']['packageid'] && $product = mysql_fetch_assoc(select_query('tblproducts', '*', array('id' => $vars['params']['packageid'])))) {

        // Get hosting data
        $hosting = mysql_fetch_assoc(select_query('tblhosting', 'orderid, paymentmethod', array('username' => $vars['params']['username'])));

        if (!$hosting || $hosting['paymentmethod'] != 'paymentwall') {
            return;
        }

        handleProductAutoSetup($vars, $hosting, $gateway);
    }
}


function cancelSubscription($vars)
{
    $invoiceId = $vars['invoiceid'];
    if(isset($invoiceId)) {
        require_once(ROOTDIR . '/modules/gateways/brick.php');
        $invoiceData = mysql_fetch_assoc(select_query('tblinvoices', 'userid,total,paymentmethod', ["id" => $invoiceId]));
        $gateway = getGatewayVariables($invoiceData['paymentmethod']);

        if (!$gateway["type"]) {
            die($gateway['name'] . " is not activated");
        }
        init_brick_config($gateway);

        $result = select_query("tblaccounts", "transid", array("invoiceid" => $invoiceId));
        $data = mysql_fetch_assoc($result);
        if($data['transid']) {
            $subscription_api = new Paymentwall_Subscription($data['transid']);
            $result = $subscription_api->cancel();
        }
    }
}

function refundInvoice($vars) {
    $invoiceId = $vars['invoiceid'];
    if(isset($invoiceId)) {
        require_once(ROOTDIR . '/modules/gateways/brick.php');
        $invoiceData = mysql_fetch_assoc(select_query('tblinvoices', 'userid,total,paymentmethod', ["id" => $invoiceId]));
        $gateway = getGatewayVariables($invoiceData['paymentmethod']);

        if (empty($gateway["type"])) {
            die($gateway['name'] . " is not activated");
        }
        if ($gateway['paymentmethod'] != 'brick')
            return;

        init_brick_config($gateway);

        $result = select_query("tblaccounts", "transid", array("invoiceid" => $invoiceId));
        $data = mysql_fetch_assoc($result);
        if($data['transid']) {
            $chargeId = preg_replace("/^([^0-9])*/", "", $data['transid']);
            $charge = new Paymentwall_Charge($chargeId);
            $charge->refund();
        }
    }
}


add_hook("AfterModuleCreate", 1, "afterSetupProductEventListener");

add_hook("InvoiceCancelled", 1, "cancelSubscription");

add_hook("InvoiceRefunded", 1, "refundInvoice");
