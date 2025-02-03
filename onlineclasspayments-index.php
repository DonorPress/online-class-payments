<?php
use OnlineClassPayments\Payment;
use OnlineClassPayments\Client;
use OnlineClassPayments\CustomVariables;
use OnlineClassPayments\Paypal;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
?>
<div id="pluginwrap">
	<?php
	if (Payment::request_handler()) { print "</div>"; return;} //important to do this first
	if (Client::request_handler())  { print "</div>"; return;}

	if (!Client::input('df','get')){
		$_GET['df']=date("Y-m-01",strtotime("-3 month"));
	}

	
	if (Paypal::is_setup()){
		$paypalLastSyncDate=CustomVariables::get_option('PaypalLastSyncDate');
		## automatically run on first load of page if it hasn't run yet this day.	
		if ($paypalLastSyncDate<date("Y-m-d") || Client::input('paypalcheck','get')){
			$paypal = new Paypal();
			$paypal->syncDateResponse($paypalLastSyncDate?date("Y-m-d",strtotime($paypalLastSyncDate)-60*60*24):date("Y-m-d",strtotime("-3 month")));
		}
	}
	?>
	
	<form method="get">
	<input type="hidden" name="page" value="<?php print esc_attr(Client::input('page','get'))?>"/>
	<!-- <div class="auto-search-wrapper">
		<input type="text" id="basic" placeholder="type w">
	</div> -->
		<strong>Client Search:</strong> <input id="clientSearch" name="dsearch" value="<?php print htmlentities(stripslashes(Client::input('dsearch','get')))?>"/><button class="button-primary" type="submit">Go</button> <button class="button-secondary" name="f" value="AddClient">Add New Client</button>
	</form>
	<?php
	
	if (Client::input('dsearch','get') && trim(Client::input('dsearch','get'))<>''){
		$search=trim(strtoupper(Client::input('dsearch','get')));
		$list=Client::get(array("(UPPER(Name) LIKE '%".$search."%' 
		OR UPPER(Name2)  LIKE '%".$search."%'
		OR UPPER(Email) LIKE '%".$search."%'
		OR UPPER(Phone) LIKE '%".$search."%')","(MergedId =0 OR MergedId IS NULL)"));
		//print "do lookup here...";
		if ($list){
			print Client::show_results($list,"",['ClientId',"Name","Name2","Email","Phone","Address"]);
		}else{
			Client::display_error("No results found for: ".stripslashes(Client::input('dsearch','get')));
		}
				
	}else{
		?>	
		<h2>Payment History</h2>
		<?php
		if ($paypalLastSyncDate){
			print "<div>Paypal Last Sync Date: ".date("Y-m-d",strtotime($paypalLastSyncDate))." <a href='?page=".Client::input('page','get')."&paypalcheck=true'>Check Again</a></div>";
		}?>
		<form method="get">
		<input type="hidden" name="page" value="<?php print esc_attr(Client::input('page','get'))?>"/>
		<input type="hidden" name="dsearch" value="<?php print esc_attr(Client::input('dsearch','get'))?>"/>
		<strong>Dates From:</strong> <input type="date" name="df" value="<?php print esc_attr(Client::input('df','get'))?>"/> to 
			<input type="date" name="dt" value="<?php print esc_attr(Client::input('dt','get'))?>"/> 
			<button type="submit">Go</button>

		<?php
		Payment::report();
	}
	?>
	</form>


</div>

