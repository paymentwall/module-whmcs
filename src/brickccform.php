<?php
define('CLIENTAREA', true);
define('FORCESSL', true);

include('init.php');
require_once(ROOTDIR . '/includes/api/paymentwall_api/lib/paymentwall.php');
require_once(ROOTDIR . '/modules/gateways/paymentwall/helpers/helper.php');
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
        $invoiceId = $_POST['invoiceid'];
        $invoice->setID($invoiceId);
        $invoiceData = $invoice->getOutput();

        $smartyvalues = array_merge($smartyvalues, get_smarty_values($invoice, $invoiceData, $gateways, $publicKey, $whmcsVer));

        if ($_POST['fromCCForm'] == 'true') { # Check form submit & capture payment
            $recurring = getRecurringBillingValues($invoiceId);
            $paid = 0;
            if (isset($recurring)) {
                $post['brick_token'] = $_POST['brick_token'];
                $post['brick_fingerprint'] = $_POST['brick_fingerprint'];
                $subscription = create_subscription($CONFIG,$invoiceData,$recurring,$post);
                $response = $subscription->getPublicData();
                $responseData = json_decode($subscription->getRawResponseData(), true);
                if ($subscription->isSuccessful() && empty($responseData['secure'])) {
                    $paid = 1;
                }
            } else {
                $cardInfo = array(
                    'email' => $invoiceData['clientsdetails']['email'],
                    'amount' => $post['amount'],
                    'currency' => $post['currency'],
                    'token' => $_POST['brick_token'],
                    'fingerprint' => $_POST['brick_fingerprint'],
                    'description' => $invoiceData['pagetitle'],
                    'plan' => $invoiceId
                );
                $charge = create_charge($CONFIG, $invoiceData, $cardInfo);
                $response = $charge->getPublicData();
                $responseData = json_decode($charge->getRawResponseData(), true);
                if ($charge->isSuccessful() && empty($responseData['secure'])) {
                    $paid = 1;
                }
            }

            if ($paid && empty($responseData['secure'])) {
                if (isset($subscription)) {
                    if ($subscription->isActive()) {
                        //addInvoicePayment($invoiceId, $subscription->getId(), null, null, 'brick');
                    }
                    logTransaction($gateway['name'], $recurring, 'Successful');
                    $smartyvalues['success'] = true;
                } elseif (isset($charge)) {
                    if ($charge->isCaptured()) {
                        addInvoicePayment($invoiceId, $charge->getId(), null, null, 'brick');
                    } elseif ($charge->isUnderReview()) {
                        // decide on risk charge
                    }
                    logTransaction($gateway['name'], $cardInfo, 'Successful');
                    $smartyvalues['success'] = true;
                }
            } elseif (!empty($responseData['secure'])) {
                $smartyvalues['formHTML'] = $responseData['secure']['formHTML'] . "<script>document.forms[0].submit();</script>";
                $_SESSION['3dsecure'] = array(
                    'invoiceData' => $invoiceData,
                    'postData' => $_POST['data']
                );
                if (isset($recurring)) {
                    $_SESSION['3dsecure']['recurring'] = $recurring;
                    $_SESSION['3dsecure']['post'] = $post;
                } else
                    $_SESSION['3dsecure']['cardInfo'] = $cardInfo;
                logTransaction($gateway['name'], isset($recurring) ? $recurring : $cardInfo, 'Confirm 3ds');
            } else {
                $error = json_decode($response, true);
                $smartyvalues['processingerror'] = '<li>' . $error['error']['message'] . '</li>';
                logTransaction($gateway['name'], isset($recurring) ? $recurring : $cardInfo, 'Unsuccessful');
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

    $paid = 0;
    if (isset($secureData['cardInfo'])) {
        $cardInfo = $secureData['cardInfo'];
        $cardInfo['charge_id'] = $_POST['brick_charge_id'];
        $cardInfo['secure_token'] = $_POST['brick_secure_token'];
        $charge = create_charge($CONFIG, $invoiceData, $cardInfo);
        $response = $charge->getPublicData();
        if ($charge->isSuccessful()) {
            $paid = 1;
        }
    } elseif (isset($secureData['recurring'])) {
        $subscription = create_subscription($CONFIG,$invoiceData,$secureData['recurring'],$secureData['post']);
        $response = $subscription->getPublicData();
        if ($subscription->isSuccessful()) {
            $paid = 1;
        }
    }
    if ($paid) {
        if (isset($charge)) {
            if ($charge->isCaptured()) {
                addInvoicePayment($invoiceData['invoiceid'], $charge->getId(), null, null, 'brick');
                unset($_SESSION['3dsecure']);
            } elseif ($charge->isUnderReview()) {
                // decide on risk charge
            }
            logTransaction($gateway['name'], $cardInfo, 'Successful');
        } elseif (isset($subscription)) {
            if ($subscription->isActive()) {
                //addInvoicePayment($invoiceId, $subscription->getId(), null, null, 'brick');
            }
            logTransaction($gateway['name'], $recurring, 'Successful');
        }
        $smartyvalues['success'] = true;
    } else {
        $error = json_decode($response, true);
        $smartyvalues['processingerror'] = '<li>You have canceled confirm 3ds.</li>';
        logTransaction($gateway['name'], isset($subscription) ? $secureData['recurring'] : $cardInfo, 'Unsuccessful');
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

function create_subscription($config,$invoiceData,$recurring,$post) {
    $subscription = new Paymentwall_Subscription();
    $subscription->create(array_merge(
        prepare_subscription_data($post,$invoiceData,$recurring),
        brick_get_user_profile_data($invoiceData),
        get_extra_data($config, $invoiceData)
    ));
    return $subscription;
}

function prepare_subscription_data($post,$invoiceData,$recurring) {
    if (!isset($post['brick_token'])) {
        throw new Exception("Payment Invalid!");
    }
    $trial_data = isset($recurring['firstpaymentamount']) ? prepare_trial_data($post, $recurring) : array();
    return array_merge(
        array(
            'token' => $post['brick_token'],
            'amount' => $post['amount'],
            'currency' => $post['currency'],
            'email' => $invoiceData['clientsdetails']['email'],
            'fingerprint' => $post['brick_fingerprint'],
            'description' => $post['description'],
//            'plan' => $invoiceData['id'],
            'plan' => $recurring['primaryserviceid'],
            'period' => 'day',
            'period_duration' => 1,
            //'period' => get_period_type($recurring['recurringcycleunits']),
            //'period_duration' => $recurring['recurringcycleperiod'],
            'secure_token' => !empty($_POST['brick_secure_token']) ? $_POST['brick_secure_token'] : null,
            'charge_id' => !empty($_POST['brick_charge_id']) ? $_POST['brick_charge_id'] : null,
        ),
        $trial_data
    );
}

function prepare_trial_data($post, $recurring) {
    return array(
        'trial[amount]' => $recurring['firstpaymentamount'],
        'trial[currency]' => $post['currency'],
        'trial[period]' => get_period_type($recurring['recurringcycleunits']),
        'trial[period_duration]' => $recurring['recurringcycleperiod'],
    );
}
