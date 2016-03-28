<?php
define('CLIENTAREA', true);
define('FORCESSL', true);

include('init.php');
require_once(ROOTDIR . '/includes/api/paymentwall_api/lib/paymentwall.php');
$whmcs->load_function('gateway');
$whmcs->load_function('clientarea');
$whmcs->load_function('invoice');

$gateway = getGatewayVariables('brick');

$whmcsVer = substr($CONFIG['Version'], 0, 1);
if ($whmcsVer <= 5) {
    $gateways = new WHMCS_Gateways();
} else {
    $gateways = new WHMCS\Gateways();
}
$publicKey = $gateway['isTest'] ? $gateway['publicTestKey'] : $gateway['publicKey'];
Paymentwall_Config::getInstance()->set(array(
    'api_type' => Paymentwall_Config::API_GOODS,
    'public_key' => $publicKey, // available in your Paymentwall merchant area
    'private_key' => $gateway['isTest'] ? $gateway['privateTestKey'] : $gateway['privateKey'] // available in your Paymentwall merchant area
));

$pagetitle = $_LANG['clientareatitle'] . ' - Pay via Brick (Powered by Paymentwall)';
initialiseClientArea($pagetitle, '', 'Pay via Brick');

# Check login status
if ($_SESSION['uid'] && isset($_POST['data']) && $post = json_decode(decrypt($_POST['data']), true)) {

    $smartyvalues = array_merge($smartyvalues, $post);
    $smartyvalues['data'] = $_POST['data'];

    if ($_POST['frominvoice'] == 'true' || $_POST['fromCCForm'] == 'true') {

        $invoice = get_invoice($CONFIG);
        $invoice->setID($_POST['invoiceid']);
        $invoiceData = $invoice->getOutput();

        $smartyvalues = array_merge($smartyvalues, get_smarty_values($invoice, $invoiceData, $gateways, $publicKey, $whmcsVer));

        if ($_POST['fromCCForm'] == 'true') { # Check form submit & capture payment

            $cardInfo = array(
                'email' => $invoiceData['clientsdetails']['email'],
                'amount' => $post['amount'],
                'currency' => $post['currency'],
                'token' => $_POST['brick_token'],
                'fingerprint' => $_POST['brick_fingerprint'],
                'description' => $invoiceData['pagetitle']
            );

            $charge = create_charge($CONFIG, $invoiceData, $cardInfo);
            $response = $charge->getPublicData();
            $responseData = json_decode($charge->getRawResponseData(), true);

            if ($charge->isSuccessful() && empty($responseData['secure'])) {
                if ($charge->isCaptured()) {
                    addInvoicePayment($_POST['invoiceid'], $charge->getId(), null, null, 'brick');
                } elseif ($charge->isUnderReview()) {
                    // decide on risk charge
                }
                logTransaction($gateway['name'], $cardInfo, 'Successful');
                $smartyvalues['success'] = true;
            } elseif (!empty($responseData['secure'])) {
                $smartyvalues['formHTML'] = $responseData['secure']['formHTML'];
                $_SESSION['3dsecure'] = array(
                    'invoiceData' => $invoiceData,
                    'cardInfo' => $cardInfo,
                    'postData' => $_POST['data']
                );
                logTransaction($gateway['name'], $cardInfo, 'Confirm 3ds');
            } else {
                $error = json_decode($response, true);
                $smartyvalues['processingerror'] = '<li>' . $error['error']['message'] . '</li>';
                logTransaction($gateway['name'], $cardInfo, 'Unsuccessful');
            }
        }
    } else { // User is logged in but they shouldn't be here (i.e. they weren't here from an invoice)
        header("Location: " . $CONFIG['SystemURL'] . "/clientarea.php?action=details");
    }
} elseif (isset($_POST['brick_secure_token']) && isset($_POST['brick_charge_id'])) {

    $secureData = $_SESSION['3dsecure'];
    $smartyvalues['data'] = $secureData['postData'];

    $invoice = get_invoice($CONFIG);
    $invoiceData = $secureData['invoiceData'];
    $invoice->setID($invoiceData['invoiceid']);

    $smartyvalues = array_merge($smartyvalues, get_smarty_values($invoice, $invoiceData, $gateways, $publicKey, $whmcsVer));

    $cardInfo = $secureData['cardInfo'];
    $cardInfo['charge_id'] = $_POST['brick_charge_id'];
    $cardInfo['secure_token'] = $_POST['brick_secure_token'];

    $charge = create_charge($CONFIG, $invoiceData, $cardInfo);
    $response = $charge->getPublicData();

    if ($charge->isSuccessful()) {
        if ($charge->isCaptured()) {
            addInvoicePayment($invoiceData['invoiceid'], $charge->getId(), null, null, 'brick');
            unset($_SESSION['3dsecure']);
        } elseif ($charge->isUnderReview()) {
            // decide on risk charge
        }
        logTransaction($gateway['name'], $cardInfo, 'Successful');
        $smartyvalues['success'] = true;
    } else {
        $error = json_decode($response, true);
        $smartyvalues['processingerror'] = '<li>You have canceled confirm 3ds.</li>';
        logTransaction($gateway['name'], $cardInfo, 'Unsuccessful');
    }

} else {
    header("Location: " . $CONFIG['SystemURL'] . "/");
}

outputClientArea("/modules/gateways/paymentwall/templates/ccform.tpl");

function brick_get_user_profile_data($params)
{
    return array(
        'customer[city]' => $params['clientsdetails']['city'],
        'customer[state]' => $params['clientsdetails']['fullstate'],
        'customer[address]' => $params['clientsdetails']['address1'],
        'customer[country]' => $params['clientsdetails']['countrycode'],
        'customer[zip]' => $params['clientsdetails']['postcode'],
        'customer[username]' => $params['clientsdetails']['userid'] ? $params['clientsdetails']['userid'] : $params['clientsdetails']['email'],
        'customer[firstname]' => $params['clientsdetails']['firstname'],
        'customer[lastname]' => $params['clientsdetails']['lastname'],
    );
}

function get_extra_data($config, $invoiceData)
{
    $customerId = $_SERVER['REMOTE_ADDR'];
    if (!empty($invoiceData['userid'])) {
        $customerId = $invoiceData['userid'];
    }

    return array(
        'custom[integration_module]' => 'whmcs',
        'uid' => $customerId,
        'secure_redirect_url' => $config['SystemURL'] . '/brickccform.php'
    );
}

function get_invoice($config)
{
    $whmcsVer = substr($config['Version'], 0, 1);
    if ($whmcsVer <= 5) {
        $invoice = new WHMCS_Invoice();
    } else {
        $invoice = new WHMCS\Invoice();
    }

    return $invoice;
}

function get_smarty_values($invoice, $invoiceData, $gateways, $publicKey, $whmcsVer)
{
    return array(
        'client' => $invoiceData['clientsdetails'],
        'months' => $gateways->getCCDateMonths(),
        'years' => $gateways->getCCExpiryDateYears(),
        'invoice' => $invoiceData,
        'invoiceid' => $invoiceData['invoiceid'],
        'invoiceItems' => $invoice->getLineItems(),
        'whmcsVer' => $whmcsVer,
        'publicKey' => $publicKey,
        'processingerror' => '',
        'success' => false
    );
}

function create_charge($config, $invoiceData, $cardInfo)
{
    $charge = new Paymentwall_Charge();
    $charge->create(array_merge(
        $cardInfo,
        brick_get_user_profile_data($invoiceData),
        get_extra_data($config, $invoiceData)
    ));

    return $charge;
}
