<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
use OnlineClassPayments\Client;
use OnlineClassPayments\Paypal;
use OnlineClassPayments\CustomVariables;
if (Client::request_handler())  { print "</div>"; return;}

$paypal = new Paypal();
$clientId=CustomVariables::get_option('PaypalClientId');
$clientSecret=CustomVariables::get_option('PaypalSecret');

?>
<div id="pluginwrap">
    <h2>Paypal API Import</h2><?php
    if (Client::input('Function','post')=="MakeClientChanges"){
        Paypal::display_notice("Client Records Updated");
    }elseif(Client::input('Function','post')=="PaypalDateSync"){
        $paypal->syncDateResponse(Client::input('date_from','post'),Client::input('date_to','post'));        
    }

    if (!$clientId || !$clientSecret){
        print Paypal::display_error("Paypal API Client/Password not setup. Create a <a target='paypaltoken' href='https://developer.paypal.com/dashboard/applications/live'>Client/Password on Paypal</a> first, and then <a href='?page=onlineclasspayments-settings'>paste them in the settings</a>.");
    }else{
        $date_from=CustomVariables::get_option('PaypalLastSyncDate');
        if (!$date_from) $date_from=Client::input('date_from','get')?Client::input('date_from','get'):date("Y-01-01");
        $date_to=Client::input('date_to','get')?Client::input('date_to','get'):date("Y-m-d");
        ?>
        <form method=post>
            Sync Transactions From: 
            <input type="date" name="date_from" value="<?php print date('Y-m-d',strtotime($date_from))?>"/> 
            to 
            <input type="date" name="date_to" value="<?php print date('Y-m-d',strtotime($date_to))?>"/>
            <button name="Function" value="PaypalDateSync">Sync</button>
        </form><?php
    }
?>
</div>