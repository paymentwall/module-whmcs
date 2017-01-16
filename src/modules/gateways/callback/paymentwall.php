<?php
# Required File Includes
if (!file_exists("../../../init.php")) {
    // For v5.x
    include("../../../dbconnect.php");
} else {
    // For v6.x
    include("../../../init.php");
}

include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");

define('PW_WHMCS_ITEM_TYPE_HOSTING', 'Hosting');

require_once(ROOTDIR . "/includes/api/paymentwall_api/lib/paymentwall.php");

$invoiceid = $_GET['goodsid'];
if(!$invoiceid) {
    die('Order is invalid!');
}

$orderData = mysql_fetch_assoc(select_query('tblorders', 'userid,id,paymentmethod', array("invoiceid" => $invoiceid)));

$gateway = getGatewayVariables($orderData['paymentmethod']);

if (!$gateway["type"]) {
    die($gateway['name'] . " is not activated");
}

$pingback = new Paymentwall_Pingback($_GET, getRealClientIP());
Paymentwall_Config::getInstance()->set(array(
    'api_type' => Paymentwall_Config::API_GOODS,
    'private_key' => $gateway['secretKey'] // available in your Paymentwall merchant area
));

$invoiceid = checkCbInvoiceID($pingback->getProductId(), $gateway["paymentmethod"]);

if ($invoiceid && $pingback->validate()) {

    $userData = mysql_fetch_assoc(select_query('tblclients', 'email, firstname, lastname, country, address1, state, phonenumber, postcode, city, id', array("id" => $orderData['userid'])));

    if ($pingback->isDeliverable()) {
        processDeliverable($invoiceid, $pingback, $gateway, $userData, $orderData);
    } elseif ($pingback->isCancelable()) {
        processCancelable($orderData, $gateway);
    } else {
        switch ($pingback->getType()) {
            /*
            case Paymentwall_Pingback::PINGBACK_TYPE_SUBSCRIPTION_EXPIRED:
            case Paymentwall_Pingback::PINGBACK_TYPE_SUBSCRIPTION_PAYMENT_FAILED:
                // Do not process transaction
                break;
            */
            case Paymentwall_Pingback::PINGBACK_TYPE_SUBSCRIPTION_CANCELLATION:
                // Cancel Recurring billing
                updateSubscriptionId('', array('subscriptionid' => $pingback->getReferenceId()));
                break;
        }
        logTransaction($gateway['name'], $_GET, "Successful");
    }
    echo 'OK';
} else {
    echo $pingback->getErrorSummary();
    logTransaction($gateway["name"], $_GET, "Unsuccessful");
}

/**
 * @param $invoiceid
 * @param $pingback
 * @param $gateway
 * @param $userData
 * @param $orderData
 */
function processDeliverable($invoiceid, $pingback, $gateway, $userData, $orderData) {
    addInvoicePayment($invoiceid, $pingback->getReferenceId(), null, null, $gateway['paymentmethod']);

    $invoiceItems = select_query(
        'tblinvoiceitems',
        '*',
        array("invoiceid" => $invoiceid));

    $hosting = false;
    if ($hostId = getHostId($invoiceItems)) {
        $hosting = mysql_fetch_assoc(select_query(
            'tblhosting', // table name
            'tblhosting.id,tblhosting.username,tblproducts.autosetup,tblhosting.packageid', // fields name
            array("tblhosting.id" => $hostId), // where conditions
            false, // order by
            false, // order by order
            1, // limit
            "tblproducts ON tblhosting.packageid=tblproducts.id" // join
        ));
    }

    // Update subscription id
    if ($hosting) {
        updateSubscriptionId($pingback->getReferenceId(), array('id' => $hosting['id']));
    }

    // Check enable delivery request
    if (isset($gateway['enableDeliveryApi']) && $gateway['enableDeliveryApi'] && $hosting) {
        sendDeliveryApiRequest($invoiceid, $hosting, $userData, $orderData, $pingback);
    }

    logTransaction($gateway['name'], $_GET, "Successful");
}

/**
 * @param $orderData
 */
function processCancelable($orderData, $gateway) {
    if (empty($orderData)) {
        logTransaction($gateway["name"], $_GET, "Unsuccessful");
        die("Order ID not found or Status not Pending !");
    }

    $orderId = $orderData['id'];
    $result = select_query("tblorders", "id", array("id" => $orderId, "status" => "Pending"));

    if (!empty($result)) {
        updateOrderStatus($orderId, "Cancelled");
        logTransaction($gateway["name"], $_GET, "Successful");
    } else {
        logTransaction($gateway["name"], $_GET, "Unsuccessful");
        die("Order ID not found or Status not Pending !");
    }
}

/**
 * @param $orderId
 * @param $status
 */
function updateOrderStatus($orderId = null, $status) {
    if (empty($orderId)) {
        die("Order not found !");
    }
    update_query('tblorders', array(
        'status' => $status
    ), array('id' => $orderId));
}

/**
 * @param $subscriptionId
 * @param $conditions
 */
function updateSubscriptionId($subscriptionId, $conditions) {
    update_query('tblhosting', array('subscriptionid' => $subscriptionId), $conditions);
}

/**
 * @param $invoiceid
 * @param $hosting
 * @param $userData
 * @param $orderData
 * @param $pingback
 */
function sendDeliveryApiRequest($invoiceid, $hosting, $userData, $orderData, $pingback) {
    // Get Delivery data from DB
    $deliveryData = mysql_fetch_assoc(select_query('pw_delivery_data', '*', array(
        "package_id" => $hosting['packageid'],
        "user_id" => $userData['id'],
        "username" => $hosting['username'],
        "order_id" => $orderData['id'],
        "status" => "unsent",
    )));

    if ($deliveryData) {
        $data = array_merge(
            array(
                'payment_id' => $pingback->getReferenceId(),
                'status' => 'delivered',
                'estimated_delivery_datetime' => date('Y/m/d H:i:s'),
                'estimated_update_datetime' => date('Y/m/d H:i:s'),
                'details' => 'Item will be delivered via email by ' . date('Y/m/d H:i:s')
            ),
            json_decode($deliveryData['data'], true)
        );

        $delivery = new Paymentwall_GenerericApiObject('delivery');
        $response = $delivery->post($data);

        // Update status
        updateDeliveryStatus($deliveryData['id'], 'sent', $data, $invoiceid, $pingback->getReferenceId());
    }
}

/**
 * @param $deliveryId
 * @param $status
 * @param $data
 * @param $invoiceId
 * @param $refId
 */
function updateDeliveryStatus($deliveryId, $status, $data, $invoiceId, $refId) {
    update_query('pw_delivery_data', array(
        'status' => $status,
        'reference_id' => $refId,
        'invoice_id' => $invoiceId,
        'data' => json_encode($data),
        'updated_date' => time(),
    ), array(
        'id' => $deliveryId
    ));
}

/**
 * @param $invoiceItems
 * @return mixed
 */
function getHostId($invoiceItems) {
    while ($item = mysql_fetch_assoc($invoiceItems)) {
        if ($item['relid'] != 0 && $item['type'] == PW_WHMCS_ITEM_TYPE_HOSTING) {
            return $item['relid'];
        }
    }
}

function getRealClientIP()
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = $_SERVER;
    }

    //Get the forwarded IP if it exists
    if (array_key_exists('X-Forwarded-For', $headers)
        && filter_var($headers['X-Forwarded-For'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
    ) {
        $the_ip = $headers['X-Forwarded-For'];
    } elseif (array_key_exists('HTTP_X_FORWARDED_FOR', $headers)
        && filter_var($headers['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
    ) {
        $the_ip = $headers['HTTP_X_FORWARDED_FOR'];
    } elseif(array_key_exists('Cf-Connecting-Ip', $headers)) {
        $the_ip = $headers['Cf-Connecting-Ip'];
    } else {
        $the_ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }
    return $the_ip;
}

die;
