<?php

# Required File Includes
include("../../../dbconnect.php");
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");
require_once("../../../includes/api/paymentwall_api/lib/paymentwall.php");

$gateway = getGatewayVariables("paymentwall");
if (!$gateway["type"]) {
    die("Module Not Activated");
}

Paymentwall_Config::getInstance()->set(array(
    'api_type' => Paymentwall_Config::API_GOODS,
    'public_key' => $gateway['appKey'], // available in your Paymentwall merchant area
    'private_key' => $gateway['secretKey'] // available in your Paymentwall merchant area
));

$pingback = new Paymentwall_Pingback($_GET, $_SERVER['REMOTE_ADDR']);

if ($pingback->validate()) {
    $invoiceid = checkCbInvoiceID($_GET['goodsid'], $gateway["name"]);
    $invoiceData = mysql_fetch_array(select_query("tblinvoices", "subtotal, paymentmethod", array("id" => $invoiceid)));
    $orderData = mysql_fetch_array(select_query('tblorders', 'userid', array("invoiceid" => $invoiceid)));
    $userData = mysql_fetch_array(select_query('tblclients', 'email, firstname, lastname, country, address1, state, phonenumber, postcode, city', array("id" => $orderData['userid'])));
    if ($pingback->isDeliverable()) {
        addInvoicePayment($invoiceid, $_GET['ref'], null, null, $invoiceData['paymentmethod']);
        if (isset($gateway['enableDeliveryApi']) && $gateway['enableDeliveryApi'] != '') {
            $delivery = new Paymentwall_GenerericApiObject('delivery');
            $response = $delivery->post(array(
                'payment_id' => $_GET['ref'],
                //'type' => 'physical',
                'type' => 'digital',
                'status' => 'delivered',
                'estimated_delivery_datetime' => date('Y/m/d H:i:s'),
                'estimated_update_datetime' => date('Y/m/d H:i:s'),
                'refundable' => 'yes',
                'details' => 'Item will be delivered via email by ' . date('Y/m/d H:i:s'),
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
                'is_test' => isset($gateway['isTest']) ? 1 : 0,
                'product_description' => '',
            ));
            if (isset($response['error'])) {
                var_dump($response['error'], $response['notices']);
            }
        }
    } elseif ($pingback->isCancelable()) {
        $cancelStatus = mysql_fetch_row(select_query("tblorderstatuses", "title", array("showcancelled" => 1)));
        // Update invoice for Cancel Order
        localAPI('updateinvoice', array(
            'invoiceid' => $invoiceid,
            'status' => $cancelStatus[0]
        ), 'admin');
        update_query('tblorders', array("status" => $cancelStatus[0]), array("invoiceid" => $invoiceid));
    }
    echo 'OK';
} else {
    echo $pingback->getErrorSummary();
}
die;

?>