<?php
# Required File Includes
if (!file_exists("../../../init.php")) {
    // For v5.x
    include("../../../dbconnect.php");
} else {
    // For v6.x, v7.x
    include("../../../init.php");
}

include(ROOTDIR . "/includes/functions.php");
include(ROOTDIR . "/includes/gatewayfunctions.php");
include(ROOTDIR . "/includes/invoicefunctions.php");

require_once(ROOTDIR . "/includes/api/paymentwall_api/lib/paymentwall.php");
require_once(ROOTDIR . '/modules/gateways/paymentwall/helpers/helper.php');

$relId = $_GET['goodsid'];
$refId = $_GET['ref'];

if (!$relId) {
    die('RelId is invalid!');
}

$invoiceId = getInvoiceIdPingback($_GET);
if(empty($invoiceId)) {
    die("Invoice is not found");
} elseif ($invoiceId == 'Invoice is already paid') {
    die($invoiceId);
}

$orderData = mysql_fetch_assoc(select_query('tblorders', 'userid,id,paymentmethod', ["invoiceid" => $invoiceId]));
$invoiceData = mysql_fetch_assoc(select_query('tblinvoices', 'userid,total,paymentmethod', ["id" => $invoiceId]));
$gateway = getGatewayVariables($invoiceData['paymentmethod']);

if (!$gateway["type"]) {
    die($gateway['name'] . " is not activated");
}

Paymentwall_Config::getInstance()->set([
    'api_type' => Paymentwall_Config::API_GOODS,
    'private_key' => ($gateway['isTest'] && $gateway['paymentmethod'] != 'paymentwall') ? $gateway['privateTestKey'] : $gateway['secretKey']  // available in your Paymentwall merchant area
]);

$pingback = new Paymentwall_Pingback($_GET, getRealClientIP());
checkCbInvoiceID($invoiceId, $gateway["paymentmethod"]);
if ($pingback->validate(true)) {
    if ($invoiceId) {
        $userData = mysql_fetch_assoc(select_query('tblclients', 'email, firstname, lastname, country, address1, state, phonenumber, postcode, city, id', ["id" => $orderData['userid']]));
        if ($pingback->isDeliverable()) {
            processDeliverable($invoiceId, $pingback, $gateway, $userData, $orderData);
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
                    updateSubscriptionId('', ['subscriptionid' => $pingback->getReferenceId()]);
                    processCancelable($orderData, $gateway);
                    break;
            }
            logTransaction($gateway['name'], $_GET, "Successful");
        }
    }

    echo 'OK';
} else {
    echo $pingback->getErrorSummary();
    logTransaction($gateway["name"], $_GET, "Unsuccessful");
}

/**
 * @param $invoiceId
 * @param $pingback
 * @param $gateway
 * @param $userData
 * @param $orderData
 */
function processDeliverable($invoiceId, $pingback, $gateway, $userData, $orderData)
{
    $invoice = mysql_fetch_assoc(select_query('tblinvoices', '*', ['id' => $invoiceId]));
    $hosting = [];

    if ($hostIdArray = getHostIdFromInvoice($invoiceId)) {
        $hosting = mysql_fetch_assoc(select_query(
            'tblhosting', // table name
            'tblhosting.id,tblhosting.username,tblhosting.packageid,tblhosting.userid', // fields name
            ["tblhosting.id" => $hostIdArray['id']], // where conditions
            false, // order by
            false, // order by order
            1 // limit
        ));
    }

    if ($invoice['status'] == 'Unpaid') {
        addInvoicePayment($invoiceId, $pingback->getReferenceId(), null, null, $gateway['paymentmethod']);

        if ($pingback->getProduct()->getType() == Paymentwall_Product::TYPE_SUBSCRIPTION) {
            updateSubscriptionId($pingback->getReferenceId(), ['id' => $hosting['id']]);
        }

        // Check enable delivery request
        if (isset($gateway['enableDeliveryApi']) && $gateway['enableDeliveryApi'] && $hosting) {
            sendDeliveryApiRequest($invoiceId, $hosting, $userData, $orderData, $pingback);
        }

    } else {

        // If Overpaid order, add credit to client
        if ($hosting && $pingback->getProduct()->getType() == Paymentwall_Product::TYPE_SUBSCRIPTION) {

            $recurring = getRecurringBillingValues($invoiceId);
            $amount = (float)$recurring['firstpaymentamount'] ? $recurring['firstpaymentamount'] : $recurring['recurringamount'];

            // Add credit
            insert_query("tblaccounts", [
                "userid" => $hosting['userid'],
                "currency" => 0,
                "gateway" => $gateway['paymentmethod'],
                "date" => "now()",
                "description" => ucfirst($gateway['paymentmethod']) . " Credit Payment for Invoice #" . $invoiceId,
                "amountin" => $amount,
                "fees" => 0,
                "rate" => 1,
                "transid" => $pingback->getReferenceId()
            ]);

            insert_query("tblcredit", [
                "clientid" => $hosting['userid'],
                "date" => "now()",
                "description" => "Subscription Transaction ID " . $pingback->getReferenceId(),
                "amount" => $amount
            ]);

            update_query("tblclients", ["credit" => "+=" . $amount], ["id" => $hosting['userid']]);
            logTransaction($gateway['paymentmethod'], "Credit Subscription ID " . $pingback->getReferenceId() . " with Amount is " . $amount, "Credit Added");
        }
    }
    logTransaction($gateway['name'], $_GET, "Successful");
}

/**
 * @param $orderData
 */
function processCancelable($orderData, $gateway)
{
    $orderId = $orderData['id'];
    $result = select_query("tblorders", "id", ["id" => $orderId]);

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
function updateOrderStatus($orderId = null, $status)
{
    if (empty($orderId)) {
        die("Order not found !");
    }
    update_query('tblorders', ['status' => $status], ['id' => $orderId]);
}

/**
 * @param $subscriptionId
 * @param $conditions
 */
function updateSubscriptionId($subscriptionId, $conditions)
{
    update_query('tblhosting', ['subscriptionid' => $subscriptionId], $conditions);
}

/**
 * @param $invoiceId
 * @param $hosting
 * @param $userData
 * @param $orderData
 * @param Paymentwall_Pingback $pingback
 */
function sendDeliveryApiRequest($invoiceId, $hosting, $userData, $orderData, $pingback)
{
    // Get Delivery data from DB
    $deliveryData = mysql_fetch_assoc(select_query('pw_delivery_data', '*', [
        "package_id" => $hosting['packageid'],
        "user_id" => $userData['id'],
        "username" => $hosting['username'],
        "order_id" => $orderData['id'],
        "status" => "unsent",
    ]));

    if ($deliveryData) {
        $data = array_merge(
            [
                'payment_id' => $pingback->getReferenceId(),
                'status' => 'delivered',
                'estimated_delivery_datetime' => date('Y/m/d H:i:s'),
                'estimated_update_datetime' => date('Y/m/d H:i:s'),
                'details' => 'Item will be delivered via email by ' . date('Y/m/d H:i:s')
            ],
            json_decode($deliveryData['data'], true)
        );

        $delivery = new Paymentwall_GenerericApiObject('delivery');
        $response = $delivery->post($data);

        // Update status
        updateDeliveryStatus($deliveryData['id'], 'sent', $data, $invoiceId, $pingback->getReferenceId());
    }
}

/**
 * @param $deliveryId
 * @param $status
 * @param $data
 * @param $invoiceId
 * @param $refId
 */
function updateDeliveryStatus($deliveryId, $status, $data, $invoiceId, $refId)
{
    update_query('pw_delivery_data', [
        'status' => $status,
        'reference_id' => $refId,
        'invoice_id' => $invoiceId,
        'data' => json_encode($data),
        'updated_date' => time(),
    ],
        ['id' => $deliveryId]
    );
}

function getRealClientIP()
{

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = $_SERVER;
    }

    //Get the forwarded IP if it exists
    if (array_key_exists('X-Forwarded-For', $headers) && filter_var($headers['X-Forwarded-For'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $the_ip = $headers['X-Forwarded-For'];
    } elseif (array_key_exists('HTTP_X_FORWARDED_FOR', $headers) && filter_var($headers['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $the_ip = $headers['HTTP_X_FORWARDED_FOR'];
    } elseif (array_key_exists('Cf-Connecting-Ip', $headers)) {
        $the_ip = $headers['Cf-Connecting-Ip'];
    } else {
        $the_ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }

    return $the_ip;
}

function getInvoiceIdPingback($requestData)
{
    $goodsId = $requestData['goodsid'];
    if (strpos($goodsId,":")===false) {
        $relId = $goodsId;
        $uid   = $requestData['uid'];
        $query = "SELECT tblinvoices.id as invoiceid, tblinvoices.userid, tblinvoices.status 
        FROM tblinvoiceitems 
        INNER JOIN tblinvoices ON tblinvoices.id=tblinvoiceitems.invoiceid 
        WHERE tblinvoiceitems.relid='" . (int)$relId . "' 
        " . (!empty($uid) ? " AND tblinvoiceitems.userid = '" . (int)$uid. "' " : "") . "
        AND (tblinvoiceitems.type='".PW_WHMCS_ITEM_TYPE_HOSTING."' OR tblinvoiceitems.type='".PW_WHMCS_ITEM_TYPE_DOMAIN."' OR tblinvoiceitems.type='".PW_WHMCS_ITEM_TYPE_INVOICE."' OR tblinvoiceitems.type='".PW_WHMCS_ITEM_TYPE_ADDON."') 
        ORDER BY tblinvoices.id ASC";
        $result = full_query($query);
        $invoiceList = array();
        while ($data = mysql_fetch_assoc($result)) {
            $invoiceList[] = $data;
        }

        if (!count($invoiceList)) {
            $query = "SELECT tblinvoices.id as invoiceid, tblinvoices.status 
            FROM tblinvoiceitems 
            INNER JOIN tblinvoices ON tblinvoices.id=tblinvoiceitems.invoiceid 
            WHERE tblinvoices.id='" . (int)$relId . "' 
            AND (tblinvoiceitems.type='".PW_WHMCS_ITEM_TYPE_CREDIT."'  OR (tblinvoiceitems.type='' AND tblinvoiceitems.relid=0))
            ORDER BY tblinvoices.id ASC";
            $result = full_query($query);
            $invoiceList = array();
            while ($data = mysql_fetch_assoc($result)) {
                $invoiceList[] = $data;
            }
        }
        $invoiceid = getInvoiceFromInvoiceList($invoiceList, $requestData);
    } else {
        $goodsArray = explode(":",$goodsId);
        if ((int)$requestData['slength'] <= 0) {
            $query = "
                SELECT *
                FROM tblinvoices i 
                WHERE i.id = ".$goodsArray[1]."
                LIMIT 0,1
            ";
            $result = full_query($query);
            $data = mysql_fetch_assoc($result);
            if ($data['status'] == 'Paid' && $requestData['type'] == 0)
                return 'Invoice is already paid';
            else
                $invoiceid = $goodsArray[1];
        } else {
            $query = "
                SELECT it.*, i.status
                FROM tblinvoiceitems it
                INNER JOIN tblinvoices i ON i.id=it.invoiceid
                WHERE it.relid = ".$goodsArray[0]." 
                AND it.type = '".$goodsArray[2]."'
                AND it.userid = '".$requestData['uid']."' 
                ORDER BY i.id ASC
            ";
            $result = full_query($query);
            $invoiceList = array();
            while ($data = mysql_fetch_assoc($result)) {
                $invoiceList[] = $data;
            }

            $invoiceid = getInvoiceFromInvoiceList($invoiceList, $requestData);
        }
    }

    return $invoiceid;
}

function getInvoiceFromInvoiceList($invoiceList, $requestData) {
    $invoiceid = null;
    if (count($invoiceList) == 1) {
        if ($requestData['type'] == 0 && $invoiceList[0]['status'] == 'Paid') {
            $invoiceid = 'Invoice is already paid';
        } else {
            $invoiceid = $invoiceList[0]['invoiceid'];
        }
    } else {
        foreach ($invoiceList as $inv) {
            if (($requestData['type'] == 0 && $inv['status'] == 'Unpaid') || ($requestData['type'] == 2 && $inv['status'] == 'Paid')) {
                $invoiceid = $inv['invoiceid'];
                break;
            }
        }
    }
    return $invoiceid;
}

die;
