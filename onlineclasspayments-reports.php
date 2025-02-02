<?php
use OnlineClassPayments\Payment;
use OnlineClassPayments\Client;
use OnlineClassPayments\ClientType;
use OnlineClassPayments\CustomVariables; 
use OnlineClassPayments\PaymentReceipt;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   
$tabs=['uploads'=>'Recent Uploads/Syncs','trends'=>'Trends','clients'=>'Clients','merge'=>"Merge",'payments'=>'Payments','reg'=>"Regression",'sanity'=>"Sanity Check"];
$active_tab=Client::show_tabs($tabs);
?>
<div id="pluginwrap">
	<?php
	if (Payment::request_handler()) { print "</div>"; return;} //important to do this first
	if (Client::request_handler())  { print "</div>"; return;}	
	?>
	<h1>Report Page: <?php print esc_html($tabs[$active_tab])?></h1>
	<?php
	if (Client::input('view','get')=='detail'){
		?><h2>Detailed View: <?php print esc_html(Client::input('view','report'))?></h2><?php
		switch(Client::input('view','report')){
			case "onlineclasspayments_report_monthly":
				onlineclasspayments_report_monthly();
			break;
		}
		print "</div>";
		return;
	}

	switch ($active_tab){
		case "uploads":
			Payment::payment_upload_groups();
		break;		
		case "clients":
			print "fix this: onlineclasspayments_report_clients()";
			//onlineclasspayments_report_clients();
		break;
		case "merge":
			Client::merge_suggestions();
		break;
		case "payments":
			onlineclasspayments_report_payments();
		break;
		case "reg":
			onlineclasspayments_client_regression();
		break;
		case "trends":
			onlineclasspayments_report_top();
			onlineclasspayments_report_current_monthly();
			onlineclasspayments_report_monthly();
		break;	
		case "sanity":
			onlineclasspayments_report_sanity();
		break;
	}
	?>	
</div>
<?php


function onlineclasspayments_report_sanity(){
	$dupcheck=[];
	$clients=Client::get(array("(MergedId IS NULL OR MergedId =0)"),null,['key'=>true]);
	//dd($clients);
	foreach($clients as $client){
		if($client->Email) $dupcheck["Email"][strtolower($client->Email)][]=$client->ClientId;
		$name=  preg_replace( '/[^a-z]/i', '', strtolower($client->Name));
		if ($name) $dupcheck["Name"][$name][]=$client->ClientId;
		$name=  preg_replace( '/[^a-z]/i', '', strtolower($client->Name2));
		//remove last word of sentance $sentance
		$address=preg_replace( '/[^a-z]/i', '',preg_replace('/\W\w+\s*(\W*)$/', '$1', strtolower($client->Address1)).substr($client->PostalCode,0,5));
		if ($address && $client->Address1){
			$dupcheck["Address"][$address][]=$client->ClientId;
		}
		
	}
	//dd($dupcheck);
	if(sizeof($dupcheck)>0){
		?><h2>Client Duplicate Check</h2>
		<table class="dp">
		<tr><th>Field</th><th>Value</th><th>Count</th><th>ClientIds</th></tr>
		<?php
		foreach($dupcheck as $field=>$values){
			foreach($values as $value=>$clientIds){
				if (sizeof($clientIds)>1){
					$lastClient=null;
					?><tr><td><?php print esc_html($field)?></td><td><?php print esc_html($value)?></td><td><?php print sizeof($clientIds)?></td><td><?php 
					foreach($clientIds as $clientId){
						?><div><a href="<?php print esc_url("?page=onlineclasspayments-index&ClientId=".$clientId)?>"><?php print esc_html($clientId)?></a> <?php
						$client=$clients[$clientId];
						if ($client){
							print $client->Name." ".$client->Address;
						}
						if ($lastClient){
							?> <a target="merge" href="<?php print esc_url("?page=onlineclasspayments-index&ClientId=".$clientId."&Function=MergeConfirm&MergeFrom=".$clientId."&MergedId=".$lastClient)?>">Merge</a><?php
						}
						$lastClient=$clientId;						
						?>
				
						</div><?php
					}?>
					</td></tr><?php
				}
			}
		}
		?></table><?php
	}

	$ddupcheck=[];
	$payments=Payment::get([],null,['key'=>true]);
	foreach($payments as $payment){
		$date=substr($payment->Date,0,10);
		if ($payment->TransactionID){			
			$ddupcheck["TransactionID"][$payment->ClientId."|".$payment->TransactionID][]=$payment->PaymentId;
			$ddupcheck["TransactionIDDate"][$date."|".$payment->TransactionID][]=$payment->PaymentId;
		}
		$ddupcheck["DateAmount"][$payment->ClientId."|".$date."|".$payment->Gross][]=$payment->PaymentId;		
	}
	if(sizeof($ddupcheck)>0){
		?><h2>Payment Duplicate Check</h2>
		<table class="dp">
		<tr><th>Field</th><th>Value</th><th>Count</th><th>PaymentIds</th></tr>
		<?php
		foreach($ddupcheck as $field=>$values){
			foreach($values as $value=>$paymentIds){
				if (sizeof($paymentIds)>1){
					?><tr><td><?php print esc_html($field)?></td><td><?php print esc_html($value)?></td><td><?php print sizeof($paymentIds)?></td><td><?php 
					foreach($paymentIds as $paymentId){
						?><div><a href="<?php print esc_url("?page=onlineclasspayments-index&PaymentId=".$paymentId)?>"><?php print esc_html($paymentId)?></a> <?php
						$payment=$payments[$paymentId];
						if($payment){
							$client=$clients[$payment->ClientId];
							if ($client){
								print $client->Name." ";
							}
							print "$".number_format($payment->Gross,2)." on ".$payment->Date." Transaction: ".$payment->TransactionID;
						}						
						
						
						?></div><?php
					}?>
					</td></tr><?php
				}
			}
		}
		?></table><?php
	}

	if (sizeof($dupcheck)==0 && sizeof($ddupcheck)==0){
		print "No duplicates found.";
	}
}

function onlineclasspayments_report_payments(){ 
	$top=is_int(Client::input('top','get'))?Client::input('top','get'):1000;	
	$dateField=Client::input('dateField','get')?Client::input('dateField','get'):'Date';
	?>
	<form method="get" style="font-size:90%;">
		<input type="hidden" name="page" value="<?php print esc_attr(Client::input('page','get'))?>" />
		<input type="hidden" name="tab" value="<?php print esc_attr(Client::input('tab','get'))?>" />
        Top: <input type="number" name="top" value="<?php print esc_attr($top)?>"/>
		Dates From <input type="date" name="df" value="<?php print esc_attr(Client::input('df','get'))?>"/> to 
		<input type="date" name="dt" value="<?php print esc_attr(Client::input('dt','get'))?>"/> 
		Date Field: <select name="dateField"><?php 
		foreach (Payment::s()->dateFields as $field=>$label){?>
			<option value="<?php print esc_attr($field)?>"<?php print ($dateField==$field?" selected":"")?>><?php print esc_html($label)?> Date</option>
		<?php } ?>
        </select>
		<br>
		Amount:  <input type="number" step=".01" name="af" value="<?php print esc_attr(Client::input('af','get'))?>" style="width:120px;"/>
		to <input type="number" step=".01" name="at" value="<?php print esc_attr(Client::input('at','get'))?>" style="width:120px;"/><br>
		Source:
		<select name="PaymentSource">
			<option value="">--All--</option>
			<?php			
			foreach(Payment::s()->tinyIntDescriptions["PaymentSource"] as $key=>$label){
				?><option value="<?php print esc_attr($key==0?"ZERO":$key)?>"<?php print (($key==0?"ZERO":$key)==Client::input('PaymentSource','get')?" selected":"")?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>		

		Type:
		<select name="Type">
			<option value="">--All--</option>
			<?php			
			foreach(Payment::s()->tinyIntDescriptions["Type"] as $key=>$label){
				?><option value="<?php print esc_attr($key==0?"ZERO":$key)?>"<?php print ($key==0?"ZERO":$key)==Client::input('Type','get')?" selected":""?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>
		Transaction Type:
		<select name="TransactionType">
			<option value="">--All--</option>
			<?php
			foreach(Payment::s()->tinyIntDescriptions["TransactionType"] as $key=>$label){
				?><option value="<?php print esc_attr($key==0?"ZERO":$key)?>"<?php print ($key==0?"ZERO":$key)==Client::input('TransactionType','get')?" selected":""?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>
		<button name="Function" value="PaymentList">Go</button><button name="Function" value="PaymentListCsv">CSV Download</button>
	</form>	<?php
	if(Client::input('Function','get')=="PaymentList"){
		Payment::report($top,$dateField);
	}
}


function onlineclasspayments_report_current_monthly(){
	global $wpdb;

	$where=array("`Type` IN (5)","Date>='".date("Y-m-d",strtotime("-3 months"))."'");
	$selectedCatagories=Client::input('category','get')?Client::input('category','get'):array();
	if (sizeof($selectedCatagories)>0){
		$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}

	$SQL="SELECT `Name`,AVG(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstPayment, MAX(Date)as LastPayment FROM ".Payment::get_table_name()." WHERE ".implode(" AND ",$where)." Group BY `Name` ORder BY AVG(`Gross`) DESC";
	$results = $wpdb->get_results($SQL);	
	if (sizeof($results)>0){
		?><form method="get" action=""><input type="hidden" name="page" value="<?php print esc_attr(Client::input('page','get'))?>" /></form>
		<h2>Current Monthly Clients</h2>
		<table class="dp"><tr><th></th><th>Name</th><th>Monthly Give</th><th>Count</th><th>Give Day</th></tr>
		<?php $i=0;
		foreach ($results as $r){ 
			$i++;
			?><tr><td><?php print $i?></td><td><?php print esc_html($r->Name)?></td><td align=right><?php print number_format($r->Total,2)?></td><td><?php print esc_html($r->Count)?></td><td><?php print date("d",strtotime($r->LastPayment))?></td></tr><?php
		}
		//print "<pre>"; print_r($results); print "</pre>";
		?></table><?php
	}
}

function onlineclasspayments_report_top($top=20){
	global $wpdb,$wp;
	$dateFrom=Client::input('topDf','get');
	$dateTo=Client::input('topDt','get');

	$selectedCatagories=Client::input('category','get')?Client::input('category','get'):array();

	?><form method="get" action="">
		<input type="hidden" name="page" value="<?php print esc_attr(Client::input('page','get'))?>" />
		<input type="hidden" name="tab" value="<?php print esc_attr(Client::input('tab','get'))?>" />
		<h3>Top <input type="number" name="topL" value="<?php print esc_attr(Client::input('topL','get')?Client::input('topL','get'):$top)?>" style="width:50px;"/>Client Report From <input type="date" name="topDf" value="<?php print esc_attr(Client::input('topDf','get'))?>"/> to <input type="date" name="topDt" value="<?php print esc_attr(Client::input('topDt','get'))?>"/> 
		
		<button type="submit">Go</button></h3>
		<div><?php
		for($y=date("Y");$y>=date("Y")-4;$y--){
			?><a href="<?php print esc_url("?page=".Client::input('page','get').'&tab='.Client::input('tab','get').'&topDf='.$y.'-01-01&topDt='.$y.'-12-31')?>"><?php print esc_html($y)?></a> | <?php
		}
		?>
		<!-- <a href="<?php print esc_url("?page=".Client::input('page','get').'&tab='.Client::input('tab','get').'&f=SummaryList&df='.$dateFrom.'&dt='.$dateTo)?>">View Payment Individual Summary for this Time Range</a> -->
		<!-- | <a href="<?php print esc_url("?page=".Client::input('page','get').'&tab='.Client::input('tab','get').'&SummaryView=t&df='.($dateFrom?$dateFrom:date("Y-m-d",strtotime("-1 year"))).'&dt='.($dateTo?$dateTo:date("Y-m-d")))?>">Payment Report</a> -->
		</div>
	</form><?php

	$where=array("Type>0");

	if ($dateFrom) $where[]="Date>='".$dateFrom." 00:00:00'";
	if ($dateTo) $where[]="Date<='".$dateTo."  23:59:59'";
	
	if (sizeof($selectedCatagories)>0){
		$where[]="DD.CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}
	Payment::stats($where);	

	$results = $wpdb->get_results("SELECT D.`ClientId`,D.`Name`, SUM(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstPayment, MAX(Date)as LastPayment, AVG(`Gross`) as Average
	FROM ".Payment::get_table_name()." DD INNER JOIN ".Client::get_table_name()." D ON D.ClientId=DD.ClientId WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")." Group BY  D.`ClientId`,D.`Name` Order BY SUM(`Gross`) DESC, COUNT(*) DESC LIMIT ".$top);
	if (sizeof($results)>0){?>
		
		<table class="dp"><tr><th>Name</th><th>Total</th><th>Average</th><th>Count</th><th>First Payment</th><th>Last Payment</th>
		<?php
		foreach ($results as $r){
			?><tr>
				<td><a href="<?php print esc_url('?page=onlineclasspayments-index&ClientId='.$r->ClientId)?>"><?php print esc_html($r->Name)?></a></td>
				<td align=right><?php print number_format($r->Total)?></td>
				<td align=right><?php print number_format($r->Average)?></td>
				<td align=right><?php print esc_html($r->Count)?></td>
				<td align=right><?php print date("Y-m-d",strtotime($r->FirstPayment))?></td>
				<td align=right><?php print date("Y-m-d",strtotime($r->LastPayment))?></td>
			</tr>
			<?php
		}
		?></table><?php
	}
}

function onlineclasspayments_client_regression($where=[]){
	global $wpdb;
	if (!Client::input('yf','get')){
		$results = $wpdb->get_results("SELECT MIN(Year(`Date`)) as YearMin, MAX(Year(`Date`)) as YearMax	FROM ".Payment::get_table_name());
		$_GET['yf']=isset($results[0]->YearMin)?$results[0]->YearMin:date("Y")-1;
		if (!Client::input('yt','get')) $_GET['yt']=isset($results[0]->YearMax)?$results[0]->YearMax:date("Y");
	}

	?><form method="get">
			<input type="hidden" name="page" value="<?php print esc_attr(Client::input('page','get'))?>" />
			<input type="hidden" name="tab" value="<?php print esc_attr(Client::input('tab','get'))?>" />
			Year: <input type="number" name="yf" value="<?php print esc_attr( Client::input('yf','get'))?>"/> to <input type="number" name="yt" value="<?php print esc_attr(Client::input('yt','get'))?>"/>
			<button>Go</button>		
	</form>
	<?php
	
	$where[]='`Gross`>0';
	$where[]="Year(`Date`) BETWEEN '".Client::input('yf','get')."' AND '".Client::input('yt','get')."'";
	if(Client::input('RegressionClientId','get')){
		$where[]="D.ClientId='".Client::input('RegressionClientId','get')."'";
	}
	$results = $wpdb->get_results("SELECT D.`ClientId`,D.`Name`,D.Name2,D.Email, Year(`Date`) as Year, SUM(`Gross`) as Total, Count(*) as Count
	FROM ".Payment::get_table_name()." DD INNER JOIN ".Client::get_table_name()." D ON D.ClientId=DD.ClientId WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")." 
	Group BY  D.`ClientId`,D.`Name`,D.Name2,D.Email,Year(`Date`) Order BY Year(`Date`),SUM(`Gross`) DESC, COUNT(*)");//DESC LIMIT ".$top
	foreach ($results as $r){
		$clientYear[$r->ClientId][$r->Year]=$r->Total;
		$clientCount[$r->ClientId][$r->Year]=$r->Count;
		$allYears[$r->Year]=$r->Total;
		$client[$r->ClientId]=new Client(['ClientId'=>$r->ClientId,'Name'=>$r->Name,'Name2'=>$r->Name2,'Email'=>$r->Email]);
		//$clientEmail[$r->ClientId]=$r->Email;
	}
	//Stategy: take the earliest client year for a specific client. Compare the average of that start date to last year to this year, and show as a percentage and total.
	foreach ($clientYear as $clientId=>$years){
		ksort($years);
		for($year=key($years);$year<Client::input('yt','get');$year++){
			$clientStats[$clientId]['years'][$year]=$clientYear[$clientId][$year];
		}
		if ($clientStats[$clientId]['years']) $clientStats[$clientId]['avg']=array_sum($clientStats[$clientId]['years'])/count($clientStats[$clientId]['years']);
		
		$amountDiff[$clientId]=$clientYear[$clientId][Client::input('yt','get')]-$clientStats[$clientId]['avg'];
	}
	asort($amountDiff);

	if (sizeof($results)>0){?>		
		<table class="dp"><tr><th>#</th><th>Name</th><th>Email</th><?php
		foreach($allYears as $year=>$total) print "<th>".$year."</th>";
		?><th>Avg</th><th>%</th></tr><?php
		foreach ($amountDiff as $clientId=>$diff){
			$years=$clientYear[$clientId];
			if ($years[Client::input('yt','get')]-$clientStats[$clientId]['avg']<0){
			?><tr>
				<td><?php print wp_kses_post($client[$clientId]->show_field('ClientId',['target'=>'client']))?> <a href="<?php print esc_url('?page=onlineclasspayments-reports&tab=stats&RegressionClientId='.$clientId)?>" target="client">Summary</a></td>
				<td><?php print esc_html($client[$clientId]->name_combine())?></td>
				<td><?php print esc_html($client[$clientId]->Email)?></td>
				<?php foreach($allYears as $year=>$total) print "<td align=right>".number_format($years[$year])."</td>";
				?><td align=right><?php print number_format($clientStats[$clientId]['avg'])?></td>
				<td align=right><?php print $clientStats[$clientId]['avg']?number_format(100*($years[Client::input('yt','get')]-$clientStats[$clientId]['avg'])/$clientStats[$clientId]['avg'],2)."%":"-"?></td>
			</tr>
			<?php
			}
		}
		?></table><?php
	}
	if(Client::input('RegressionClientId','get')){
		?>
		<div>Counts</div>	
		<table class="dp"><tr><th>#</th><th>Name</th><th>Email</th><?php
		foreach($allYears as $year=>$total) print "<th>".$year."</th>";
		?></tr><?php
		foreach ($amountDiff as $clientId=>$diff){
			$years=$clientCount[$clientId];
			if ($years[Client::input('yt','get')]-$clientStats[$clientId]['avg']<0){
			?><tr>
				<td><?php print wp_kses_post($client[$clientId]->show_field('ClientId',['target'=>'client']))?> <a href="<?php print esc_url('?page=onlineclasspayments-reports&tab=stats&RegressionClientId='.$clientId)?>" target="client">Summary</a></td>
				<td><?php print esc_html($client[$clientId]->name_combine())?></td>
				<td><?php print wp_kses_post($client[$clientId]->show_field('Email'))?></td>
				<?php foreach($allYears as $year=>$total) print "<td align=right>".number_format($years[$year])."</td>";
				?>				
			</tr>
			<?php
			}
		}
		?></table><?php
	}

}

function onlineclasspayments_report_monthly(){
	global $wpdb,$wp;
	$where=array("Gross>0"); //,"Status=9"//,"Currency='USD'"
	//,"`Type` IN ('Subscription Payment','Payment Payment','Website Payment')"
	if (Client::input('view','report')=="onlineclasspayments_report_monthly" && Client::input('view','get')=='detail'){
		if (Client::input('month','get')){
			$where[]="EXTRACT(YEAR_MONTH FROM `Date`)='".addslashes(Client::input('month','get'))."'";
		}

		$selectedCatagories=Client::input('category','get')?Client::input('category','get'):array();
		if (sizeof($selectedCatagories)>0){
			$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
		}

		if (Client::input('type','get')){
			$where[]="`Type`='".addslashes(Client::input('type','get'))."'";
		}
		$results=Payment::get($where);
		print Payment::show_results($results);		
		return;
		
	}


	if (Client::input('topDf','get')) $where[]="Date>='".Client::input('topDf','get')."'";
	if (Client::input('topDt','get')) $where[]="Date<='".Client::input('topDt','get')."'";
	
	$selectedCatagories=Client::input('category','get')?Client::input('category','get'):array();
	if (sizeof($selectedCatagories)>0){
		$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}

	$countField=(Client::input('s','get')=="Count"?"Count":"Gross");	

	$graph=array('Month'=>array(),'WeekDay'=>array(),'YearMonth'=>array(),'Total'=>array(),'Count'=>array(),'time'=>array());
	$SQL="SELECT `Date`, `Type`, Gross, PaymentSource FROM ".Payment::get_table_name()." WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")."";
	$results = $wpdb->get_results($SQL);		
	foreach ($results as $r){
		$timestamp=strtotime($r->Date);
		$yearMonth=date("Ym",$timestamp);
		$type=$r->Type;
		/* set variables to avoid wrnings */
		if (!isset($graph['Month'][date("n",$timestamp)])) $graph['Month'][date("n",$timestamp)]=0;
		if (!isset($graph['WeekDay'][date("N",$timestamp)])) $graph['WeekDay'][date("N",$timestamp)]=0;
		if (!isset($graph['time'][date("H",$timestamp)])) $graph['time'][date("H",$timestamp)]=0;
		if (!isset($graph['YearMonth'][date("Y",$timestamp)][date("n",$timestamp)])) $graph['YearMonth'][date("Y",$timestamp)][date("n",$timestamp)]=0;
		if (!isset($graph['Total'][$yearMonth][$type])) $graph['Total'][$yearMonth][$type]=0;
		if (!isset($graph['Count'][$yearMonth][$type])) $graph['Count'][$yearMonth][$type]=0;
		if (!isset($graph['Type'][$type])) $graph['Type'][$type]=0;

		if ($r->Type<>5){ //skip autopayments / subcriptions for day/time graph
			$graph['Month'][date("n",$timestamp)]+=($countField=="Gross"?$r->Gross:1);			
			$graph['WeekDay'][date("N",$timestamp)]+=($countField=="Gross"?$r->Gross:1);			
			if (date("His",$timestamp)>0){ //ignore entries without timestamp
				$graph['time'][date("H",$timestamp)*1]+=($countField=="Gross"?$r->Gross:1);
			}			
		}
		$graph['YearMonth'][date("Y",$timestamp)][date("n",$timestamp)]+=($countField=="Gross"?$r->Gross:1);

		$graph['Total'][$yearMonth][$type]+=$r->Gross;
		$graph['Count'][$yearMonth][$type]++;			
		$graph['Type'][$type]+=$r->Gross;
	}
	ksort($graph['YearMonth']);
	foreach($graph['Type'] as $type=>$total){
		$graph['TypeDescription'][$type]=Payment::get_tiny_description('Type',$type)??$type;
	}
	if ($graph['WeekDay']) ksort($graph['WeekDay']);
	if ($graph['time']) ksort($graph['time']);
	if ($graph['Type']) krsort($graph['Type']);

	$weekDays=array("1"=>"Mon","2"=>"Tue","3"=>"Wed","4"=>"Thu","5"=>"Fri",6=>"Sat",7=>"Sun");

	$google_charts=CustomVariables::get_option('GoogleCharts');
	if ($graph['Type'] && sizeof($graph['Type'])>0){	
		ksort($graph[$countField=="Gross"?'Total':'Count']);
		
		if ($google_charts){
		?>
  <script type="text/javascript" src="<?php print esc_url($google_charts)?>"></script>
  <script type="text/javascript">
    google.charts.load("current", {packages:['corechart']});
    google.charts.setOnLoadCallback(drawMonthlyChart);
    function drawMonthlyChart() {
		var data = google.visualization.arrayToDataTable([
        ['Type', '<?php print implode("', '",$graph['TypeDescription'])?>']
		<?php
		foreach($graph[$countField=="Gross"?'Total':'Count'] as $date=>$types){
			print ", ['".$date."'";
			foreach($graph['TypeDescription'] as $type=>$desc){
				print ",".($types[$type]?$types[$type]:0);
			}
			print "]";
		}
		?>
      ]);

      var options = {
		title:'Monthly Payment by <?php print esc_html($countField)?>',
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true
      };
	
	  var chart = new google.visualization.ColumnChart(document.getElementById("MonthlyPaymentsChart"));
	  chart.draw(data, options);

<?php if ($graph['WeekDay']){?> 
	  var data2 = google.visualization.arrayToDataTable([
        ['Week Day', '<?php print esc_html($countField)?>']
		<?php
		foreach($graph['WeekDay'] as $day=>$count){
			print ", ['".$weekDays[$day]."',".$count."]";
		}
		?>
      ]);
	  var options2 = {
		title:'Day of Week by <?php print esc_html($countField)?>',
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true,		
      };
	
	  var chart2 = new google.visualization.ColumnChart(document.getElementById("WeekDay"));
	  chart2.draw(data2, options2);
<?php } ?>

	  var data3 = google.visualization.arrayToDataTable([
        ['Week Day', '<?php print esc_html($countField)?>']
		<?php
		for ($i=0;$i<=23;$i++){
			print ", ['".$i."',".(isset($graph['time'][$i])?$graph['time'][$i]:0)."]";
		}
		
		?>
      ]);
	  var options3 = {
		title:'Time of Day by <?php print esc_html($countField)?>',
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true,		
      };

	  var chart3 = new google.visualization.ColumnChart(document.getElementById("TimeChart"));
	  chart3.draw(data3, options3);

	  var data4 = google.visualization.arrayToDataTable([
        ['Week Day', '<?php print esc_html($countField)?>']
		<?php
		for ($i=1;$i<=12;$i++){
			print ", ['".$i."',".(isset($graph['Month'][$i])?$graph['Month'][$i]:0)."]";
		}	
		?>
      ]);
	  var options4 = {
		title:'Month by <?php print esc_html($countField)?>',
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true,		
      };
	
	  var chart4 = new google.visualization.ColumnChart(document.getElementById("MonthChart"));
	  chart4.draw(data4, options4);

	  var data5 = google.visualization.arrayToDataTable([
		  ['Year' <?php
		 	foreach($graph['YearMonth'] as $y=>$a){
				print ",'".$y."'";
			}?>] 
			<?php
			for ($i=1;$i<=12;$i++){
				print ", ['".$i."'";
				foreach($graph['YearMonth'] as $y=>$a){
					print ",".(isset($a[$i])?$a[$i]:0);
				}
				print "]";
			}?>
		]);

        var options5 = {          
            title: 'Year Monthly Trends'			
        };
        var chart5 = new google.visualization.ColumnChart(document.getElementById('YearMonthChart'));
        chart5.draw(data5, options5);

	}
	</script>
	<?php
		}
	?>
<form method="get">
	<input type="hidden" name="page" value="<?php print esc_attr(Client::input('page','get'))?>" />
	<input type="hidden" name="tab" value="<?php print esc_attr(Client::input('tab','get'))?>" />
			<h3>Monthly Payments Report From <input type="date" name="topDf" value="<?php print esc_attr(Client::input('topDf','get'))?>"/> to <input type="date" name="topDt" value="<?php print esc_attr(Client::input('topDt','get'))?>"/> 
			Show: <select name="s">
				<option value="Gross"<?php print ($countField=="Gross"?" selected":"")?>>Gross</option>	
				<option value="Count"<?php print ($countField=="Count"?" selected":"")?>>Count</option>
					
			</select>
			<button type="submit">Go</button></h3>
		<?php if ($google_charts){?>
			<div id="MonthlyPaymentsChart" style="width: 1200px; height: 500px;"></div>
			<div id="YearMonthChart" style="width: 1200px; height: 500px;"></div>
			<div id="MonthChart" style="width: 1200px; height: 500px;"></div>		
			
			<?php if ($graph['WeekDay']){?> 
				<div id="WeekDay" style="width: 1200px; height: 500px;"></div>
			<?php }?>
			<div id="TimeChart" style="width: 1200px; height: 500px;"></div>
		<?php } ?>
	<table class="dp"><tr><th>Month</th><th>Type</th><th>Amount</th><th>Count</th>
		<?php
		foreach ($graph['Total'] as $yearMonth =>$types){
			foreach($types as $type=>$total){
				?><tr><td><?php print  $yearMonth?></td><td><?php print esc_html(Payment::get_tiny_description('Type',$type)?Payment::get_tiny_description('Type',$type):$type)?></td>
				<td align=right><a href="<?php print esc_url("?page=".Client::input('page','get').'&tab='.Client::input('tab','get').'&report=onlineclasspayments_report_monthly&view=detail&month='.$yearMonth.'&type='.$type)?>"><?php print number_format($total,2)?></a></td><td align=right><?php print esc_html($graph['Count'][$yearMonth][$type])?></td></tr><?php
		
			}
		}
		?></table>
		</form>	
		
		<?php
	}
}
