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
use WHMCS\View\Menu\Item as MenuItem;

require_once(ROOTDIR . '/includes/api/paymentwall_api/lib/paymentwall.php');
require_once(ROOTDIR . '/modules/gateways/paymentwall/helpers/helper.php');

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
    $gateway = getGatewayVariablesByName("paymentwall");

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

        $gateway = getGatewayVariablesByName($invoiceData['paymentmethod']);

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
        $gateway = getGatewayVariablesByName($invoiceData['paymentmethod']);

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

$brickConfigs = getGatewayVariablesByName('brick');

if ($_SESSION['uid'] && $brickConfigs) {
    add_hook('ClientAreaPrimaryNavbar', 1, function($primaryNavbar) {
        if (!is_null($primaryNavbar->getChild('Billing'))) {
            /** @var \WHMCS\View\Menu\Item $primaryNavbar */
            $primaryNavbar->getChild('Billing')->addChild(
                'uniqueMenuItemName',
                array(
                    'label' => 'Manage Credit Card (Paymentwall)',
                    'uri' => 'pwmanagecard.php',
                    'order' => 50
                )
            );
        }
    });

    add_hook('ClientAreaSecondarySidebar', 1, function($secondarySidebar) {
        /** @var \WHMCS\View\Menu\Item $secondarySidebar */
        if (!is_null($secondarySidebar->getChild('Billing'))) {
            $newMenu = $secondarySidebar->getChild('Billing')->addChild(
                'uniqueMenuItemName',
                array(
                    'label' => 'Manage Credit Card (Paymentwall)',
                    'uri' => 'pwmanagecard.php',
                    'order' => 40
                )
            );
        }
    });

    add_hook('ClientAreaSecondaryNavbar', 1, function($secondaryNavbar) {
        /** @var \WHMCS\View\Menu\Item $secondaryNavbar */
        if (!is_null($secondaryNavbar->getChild('Account'))) {
            $newMenu = $secondaryNavbar->getChild('Account')->addChild(
                'uniqueMenuItemName',
                array(
                    'label' => 'Manage Credit Card (Paymentwall)',
                    'uri' => 'pwmanagecard.php',
                    'order' => 20
                )
            );
        }
    });
}


add_hook("AfterModuleCreate", 1, "afterSetupProductEventListener");

add_hook("InvoiceCancelled", 1, "cancelSubscription");

add_hook("InvoiceRefunded", 1, "refundInvoice");
