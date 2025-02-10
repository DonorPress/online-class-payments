<?php
namespace OnlineClassPayments;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   

class Payment extends ModelLite
{
    protected $table = 'payment';
	protected $primaryKey = 'PaymentId';
	### Fields that can be passed //,"Time","TimeZone"
    public $fillable = ["Date","DateDeposited","ClientId","Name","Type","Status","Currency","Gross","Fee","Net","FromEmailAddress","ToEmailAddress","Source","SourceId","TransactionID","AddressStatus","CategoryId","ReceiptID","ContactPhoneNumber","ItemCode","ItemName","ItemDescription","ItemQuantity","Note","PaymentSource","TransactionType"];	 

    public $flat_key = ["Date","Name","Gross","FromEmailAddress","TransactionID"];
    protected $duplicateCheck=["Date","Gross","FromEmailAddress","TransactionID"]; //check these fields before reinserting a matching entry.
   
    public $tinyIntDescriptions=[
        "Status"=>["9"=>"Completed","7"=>"Pending","0"=>"Unknown","-1"=>"Deleted","-2"=>"Denied"],
        "AddressStatus"=>[0=>"Not Set",-1=>"Non-Confirmed",1=>"Confirmed"],
        "PaymentSource"=>[0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Instant","6"=>"ACH/Bank Transfer","10"=>"Paypal","11"=>"Givebutter"],
        "Type"=>[0=>"Other",1=>"One Time Payment",2=>"Website Payment",5=>"Recurring/Subscription",-2=>"General Currency Conversion",-1=>"General Withdrawal","-3"=>"Expense"],
        "Currency"=>["USD"=>"USD","CAD"=>"CAD","GBP"=>"GBP","EUR"=>"EUR","AUD"=>"AUD"],
        "TransactionType"=>["1"=>"Product","-1"=>"Expense"]
    ];

    public $dateFields=[
        "CreatedAt"=>"Upload",
        "DateDeposited"=>"Deposit",
        "Date"=>"Donated"
    ];

    ### Default Values
	protected $attributes = [        
        'Currency' => 'USD',
        'Type'=>'1',
        'Status'=>'9',
        'AddressStatus'=>1,
        'PaymentSource'=>0,
        'TransactionType'=>1,
       
	];

    protected $fieldLimits = [ //SELECT concat("'",column_name,"'=>",character_maximum_length ,",") as grid FROM information_schema.columns where table_name = 'wp_payment' and table_schema='wordpress' and data_type='varchar'
        'Name'=>80,
        'TypeOther'=>30,
        'Currency'=>3,
        'FromEmailAddress'=>70,
        'ToEmailAddress'=>26,
        'Source'=>20,
        'SourceId'=>50,
        'TransactionID'=>17,
        'ReceiptID'=>16,
        'ContactPhoneNumber'=>20,
        'ItemCode'=>50,
        'ItemName'=>255,
        'ItemDescription'=>255,
    ];

    // public $incrementing = true;
    const PAYMENT_TO_CLIENT = ['Name'=>'Name','Name2'=>'Name2','FromEmailAddress'=>'Email','ContactPhoneNumber'=>'Phone','Address1'=>'Address1', 'Address2'=>'Address2','City'=>'City','Region'=>'Region',	'PostalCode'=>'PostalCode',	'Country'=>'Country'];

	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';      
    
    static public function from_paypal_api_detail($detail){  

        $transaction=$detail->transaction_info;
        $item=$detail->cart_info?$detail->cart_info->item_details:[];
        $payer=$detail->payer_info;
        $payment=new self();
        $payment->Source='paypal';
        $payment->SourceId=$transaction->paypal_account_id;
        $payment->TransactionID=$transaction->transaction_id;
        $payment->Date=date("Y-m-d H:i:s",strtotime($transaction->transaction_initiation_date));
        $payment->DateDeposited=date("Y-m-d",strtotime($transaction->transaction_initiation_date));
        $payment->Gross=$transaction->transaction_amount->value;
        $payment->Currency=$transaction->transaction_amount->currency_code;
        $payment->Fee=$transaction->fee_amount->value;
        $payment->Net=$payment->Gross+$payment->Fee;
        $categoryName=$item[0]->item_code;
        list($itemName, $itemDescription) = explode(':',$item[0]->item_name, 2); //split: Mariposa School Skype Lesson:Weekly for 24 weeks          
        $payment->ItemCode=$categoryName;
        $payment->ItemName=$itemName;
        $payment->ItemDescription=$itemDescription;
        $payment->ItemQuantity=$item[0]->item_quantity;
        $payment->Note=$transaction->transaction_note;
        $payment->Name=$payer->payer_name->alternate_full_name;
        if (!$payment->Name && $transaction->bank_reference_id){
            $payment->Name="Bank Withdrawal";
            if (!$payment->SourceId) $payment->SourceId=$transaction->bank_reference_id;
        }
        $payment->FromEmailAddress=$payer->email_address;
        $payment->PaymentSource=10;
        $payment->Type=self::transaction_event_code_to_type($transaction->transaction_event_code);

        if (!$payment->FromEmailAddress && $payment->Type<0){ 
            //Detect deposit situation or some sort of negative transaction. 
            //Default to email set as contact email.
            $payment->FromEmailAddress=self::get_deposit_email();
        }
        //Fields we should drop:
        //$payment->AddressStatus=$payer->address_status=="Y"?1:-1;    
        $payment->TransactionType =0;

        return $payment;

        ####
        //$payment->Status=$transaction->transaction_status; //?
        //calculated -> "Type",
        ###
        //"ClientId",,"Status","ToEmailAddress",""AddressStatus","CategoryId","ReceiptID","ContactPhoneNumber",];	 

    }

    static public function get_deposit_email(){
        $email=CustomVariables::get_option('ContactEmail');
        if (!$email) $email='deposit';
        return $email;
    }

    static public function transaction_event_code_to_type($transaction_event_code){
        //https://developer.paypal.com/docs/reports/reference/tcodes/
        switch($transaction_event_code){
            case "T0002": return 5; break; //subscription Payment
            case "T0004": //ebay
            case "T0005": //Direct credit card payment
            case "T0006": //Express Checkout Payment
                return "101"; //assumes this is income from sales of something not a payment
            break;
            case "T0013": return 1; break; //Payment Payment
            case "T0401": //auto-sweep
            case "T0402": //Hyperallet
            case "T0403": //manual withdrawal
            case "T0400": 
                return -1; //bank transfer
            break; 
            default: return 0; break;
        }
    }

    public function payment_to_client($override=array()){
        ### When uploading new dontations, this will find existings clients, or create new ones automatically.
        global $wpdb,$payment_to_client; //cache client lookups to speed up larger imports.       
        if ($this->ClientId>0){
            $this->suggest_client_changes($override);
            return $this->ClientId;
        } 
        if (!$this->Name && $this->FromEmailAddress){ //if no name, but a email address (often the case for withdrawel account)
            $this->Name= $this->FromEmailAddress;
        }
        if (!$this->Name && $override['Name']){
            $this->Name=$override['Name'];
        }
        if (!$this->Name && $override['Email']){
            $this->Name=$override['Email'];
        }
        
        ## Attempt to Match on these key fields first.
        $matchFields=['Email'=>'FromEmailAddress','Name'=>'Name','Name2'=>'Name']; //,'Phone'=>'ContactPhoneNumber'
       
        foreach($matchFields as $clientField=>$paymentField){

            if (trim($this->$paymentField)){
                if ($payment_to_client[$clientField][$this->$paymentField]){
                    $this->ClientId=$payment_to_client[$clientField][$this->$paymentField];
                    $this->suggest_client_changes($override);
                    return  $this->ClientId;
                }
                switch($clientField){
                    case "Email":
                    case "Name":
                    case "Name1":
                    case "Name2":                        
                        $where="REPLACE(LOWER(`".$clientField."`), ' ', '') = '".addslashes(strtolower(str_replace(" ","",$this->$paymentField)))."'";
                    break;
                    default:
                    $where="`".$clientField."` = '".addslashes($this->$paymentField)."'";
                    break;
                }
                $client = $wpdb->get_row( $wpdb->prepare("SELECT ClientId,MergedId FROM ".Client::s()->get_table()." WHERE ".$where." Order By MergedId"));
                if ($client->MergedId>0) $client->ClientId=$client->MergedId; //If this entry has been merged, send the merged entry. It's possible the merged entry will have a merged entry, but we don't check for that here. Handle this with a cleanup page.
                if ($client->ClientId>0){                
                    $payment_to_client[$clientField][$this->$paymentField]=$client->ClientId;
                    $this->ClientId=$client->ClientId;
                    $this->suggest_client_changes($override,$clientField."=>".$this->$paymentField);
                    return $this->ClientId;
                }           
            }
        }

        ### Insert or Update Entry with existing Values
        $newEntry=[];
        
        ### Pull info from the payment:       
        foreach(self::PAYMENT_TO_CLIENT as $paymentField=>$clientField){
            if ($this->$paymentField && in_array($clientField,Client::get_fillable())){ //if field passed in AND it is a field on Client Table
                $newEntry[$clientField]=$this->$paymentField;
            }
        }
        ### Pull Info from the override
        if (sizeof($override)>0){
            foreach($override as $field=>$value){
                if ($value && in_array($field,Client::get_fillable())){
                 $newEntry[$field]=$value;
                }
            }
        }

       $newEntry=$this->new_from_payment($override);
        if (sizeof($newEntry)>0){
            $client=new Client($newEntry);
            $client->save();            
            $this->ClientId=$client->ClientId;
            return $client->ClientId;
        }

    }

    static public function stats($where=array()){
        $wpdb=self::db();  
        PaymentCategory::consolidate_categories();

        $SQL="SELECT COUNT(DISTINCT ClientId) as TotalClients, Count(*) as TotalPayments,SUM(`Gross`) as TotalRaised FROM ".self::get_table_name()." DD WHERE ".implode(" AND ",$where);
        $results = $wpdb->get_results($SQL);
        ?><table class="dp"><tr><th colspan=2>Period Stats</th><th>Avg</th></tr><?php
        foreach ($results as $r){
            ?><tr><td>Total Clients</td><td align=right><?php print esc_html($r->TotalClients)?></td><td align=right>$<?php print $r->TotalClients<>0?number_format($r->TotalRaised/$r->TotalClients,2):"-"?> avg per Client</td></tr>
            <tr><td>Payment Count</td><td align=right><?php print esc_html($r->TotalPayments)?></td><td align=right><?php print $r->TotalClients<>0?number_format($r->TotalPayments/$r->TotalClients,2):"-"?> avg # per Client</td></tr>
            <tr><td>Payment Total</td><td align=right><?php print number_format($r->TotalRaised,2)?></td><td align=right>$<?php print $r->TotalPayments<>0?number_format($r->TotalRaised/$r->TotalPayments,2):"-"?> average Payment</td></tr>
            
            <?php
        }
         ?></table><?php

        $GroupFields=array('Type'=>'Type','Category'=>'CategoryId',"Source"=>'PaymentSource',"Month"=>"month(date)");
        $tinyInt=self::s()->tinyIntDescriptions;

        //load all payment categories since this is DB and not hardcoded.
        $result=PaymentCategory::get();
        foreach($result as $r){
            $tinyInt['CategoryId'][$r->CategoryId]=$r->Category;
        }
        foreach($GroupFields as $gfa=>$gf){   
            $SQL="SELECT $gf as $gfa, COUNT(DISTINCT ClientId) as TotalClients, Count(*) as TotalPayments,SUM(`Gross`) as TotalRaised FROM ".self::get_table_name()." DD WHERE ".implode(" AND ",$where)." Group BY $gf";
            $results = $wpdb->get_results($SQL);
            if (sizeof($results)>0){
                ?><table class="dp"><tr><th><?php print esc_html($gfa)?></th><th>Total</th><th>Payments</th><th>Clients</th></tr><?php
                foreach ($results as $r){
                    ?><tr><td><?php print $r->$gfa.(isset($tinyInt[$gf][$r->$gfa])?" - ". $tinyInt[$gf][$r->$gfa]:"")?></td>
                    <td align=right>$<?php print number_format($r->TotalRaised,2)?></td>
                    <td align=right><?php print number_format($r->TotalPayments)?></td>
                    <td align=right><?php print number_format($r->TotalClients)?></td>
                    </tr><?php

                }?></table><?php
            }
        }
    }
         
        
    public function new_from_payment($override=array()){
        $newEntry=[];
        
        ### Pull info from the payment:       
        foreach(self::PAYMENT_TO_CLIENT as $paymentField=>$clientField){
            if ($this->$paymentField && in_array($clientField,Client::get_fillable())){ //if field passed in AND it is a field on Client Table
                $newEntry[$clientField]=$this->$paymentField;
            }
        }
        ### Pull Info from the override
        if (sizeof($override)>0){
            foreach($override as $field=>$value){
                if ($value && in_array($field,Client::get_fillable())){
                 $newEntry[$field]=$value;
                }
            }
        }
        return $newEntry;
    }
    public function suggest_client_changes($override=array(),$matchOn=""){
        if (!$this->ClientId) return false;
        global $suggest_client_changes;
        $skip=array('AddressStatus');
        $newEntry=$this->new_from_payment($override);
        if ($this->ClientId){ //first pull in exising values            
            $client=Client::get(array('ClientId='.$this->ClientId));
            if ($client){
                foreach(Client::get_fillable() as $field){
                    if (in_array($field,$skip)) continue;
                    if ($field=="Name" && $newEntry[$field]==$newEntry['Email']){
                        continue; // skip change suggestion if Name was made the e-mail address 
                    }
                    if (strtoupper($client[0]->$field)!=strtoupper($newEntry[$field]) && $newEntry[$field]){
                        $suggest_client_changes[$this->ClientId]['Name']['c']=$client[0]->Name;//Cache this to save a lookup later
                        if($matchOn) $suggest_client_changes[$this->ClientId]['MatchOn'][]=$matchOn;
                        $suggest_client_changes[$this->ClientId][$field]['c']=$client[0]->$field;
                        $suggest_client_changes[$this->ClientId][$field]['n'][$newEntry[$field]]++; //support multiple differences
                    }                
                }
            }
        }
        return $suggest_client_changes[$this->ClientId];        
    }

    static public function payment_upload_groups(){
        $limit=is_int(self::input('limit','get'))?self::input('limit','get'):20;
        $wpdb=self::db();  
        $SQL="SELECT `CreatedAt`,MIN(`DateDeposited`) as DepositedMin, MAX(`DateDeposited`) as DepositedMax,COUNT(*) as C,Count(R.ReceiptId) as ReceiptSentCount
        FROM ".self::get_table_name()." D
        LEFT JOIN ".PaymentReceipt::get_table_name()." R
        ON KeyType='PaymentId' AND R.KeyId=D.PaymentId WHERE 1
        Group BY `CreatedAt` Order BY `CreatedAt` DESC LIMIT ".$limit;
         $results = $wpdb->get_results($SQL);
         $linkBase="?page=".self::input('page','get')."&tab=".self::input('tab','get')."&limit=".$limit."&dateField=CreatedAt&SummaryView=f";
         ?><h2>Upload Groups</h2>
         <form method="get" action="">
            <input type="hidden" name="page" value="<?php print self::input('page','get')?>" />
            <input type="hidden" name="tab" value="<?php print self::input('tab','get')?>" />
            Limit: <input type="number" name="limit" value="<?php print esc_attr($limit)?>"/>
			Summary From <input type="date" name="df" value="<?php print self::input('df','get')?>"/> to <input type="date" name="dt" value="<?php print self::input('dt','get')?>"/> Date Field: <select name="dateField">
            <?php foreach (self::s()->dateFields as $field=>$label){?>
                <option value="<?php print esc_attr($field)?>"<?php print self::input('dateField','get')==$field?" selected":""?>><?php print esc_html($label)?> Date</option>
            <?php } ?>
            </select>
            <button type="submit" name="ActionView" value="t">View action List</button>
            <button type="submit" name="SummaryView" value="t">View Summary</button>
        </form>
        <div> 
            <a href="<?php print esc_url($linkBase."&df=".date("Y-m-d"))?>">Today</a> | 
            <a href="<?php print esc_url($linkBase."&df=".date("Y-m-d",strtotime("-7 days")))?>">Last 7 Days</a> | 
            <a href="<?php print esc_url($linkBase."&df=".date("Y-m-d",strtotime("-30 days")))?>">Last 30 Days</a>
        </div>

         <table class="dp"><tr><th>Upload Date</th><th>Payment Deposit Date Range</th><th>Count</th><th></th></tr><?php
         foreach ($results as $r){?>
             <tr><td><?php print esc_html($r->CreatedAt)?></td><td align=right><?php print $r->DepositedMin.($r->DepositedMax!==$r->DepositedMin?" to ".$r->DepositedMax:"")?></td><td><?php print $r->ReceiptSentCount." of ".$r->C?></td>
             <td><a href="<?php print esc_url('?page='.self::input('page','get').'&UploadDate='.urlencode($r->CreatedAt))?>">View All</a> 
             <?php print ($r->ReceiptSentCount<$r->C?" | <a href='".esc_url("?page=".self::input('page','get')."&UploadDate=".urlencode($r->CreatedAt)."&unsent=t")."'>View Unsent</a>":"")?>| <a href="<?php print esc_url('?page='.self::input('page','get').'&SummaryView=t&UploadDate='.urlencode($r->CreatedAt))?>">View Summary</a></td></tr><?php            
         }?></table><?php
    }

    static public function view_payments($where=[],$settings=array()){ //$type[$r->Type][$r->ClientId]++;       
        if (sizeof($where)==0){
           self::display_error("No Criteria Given");
        }
        $wpdb=self::db();  
        $clientIdList=array();
        $type=array();
        
        if ($settings['unsent']){
            $where[]="R.ReceiptId IS NULL";           
        }
        print "<div><strong>Criteria:</strong> ".implode(", ",$where)."</div>";
        $SQL="Select D.*,R.Type as ReceiptType,R.Address,R.DateSent,R.ReceiptId
          FROM ".self::get_table_name()." D
        LEFT JOIN ".PaymentReceipt::get_table_name()." R ON KeyType='PaymentId' AND R.KeyId=D.PaymentId AND R.ReceiptId=(Select MAX(ReceiptId) FROM ".PaymentReceipt::get_table_name()." WHERE KeyType='PaymentId' AND KeyId=D.PaymentId)        
        WHERE ".implode(" AND ", $where)." Order BY D.Date DESC,  D.PaymentId DESC;";
        //print $SQL;
        $payments = $wpdb->get_results($SQL);
        foreach ($payments as $r){
            $clientIdList[$r->ClientId]=(isset($clientIdList[$r->ClientId])?$clientIdList[$r->ClientId]:0)+1;
            $type[$r->Type][$r->ClientId][$r->PaymentId]=$r;
        }
        //
        if (sizeof($clientIdList)>0){
            $clients=Client::get(array("ClientId IN ('".implode("','",array_keys($clientIdList))."')"),'',array('key'=>true));
            // Find if first time payment
            $result=$wpdb->get_results("Select ClientId, Count(*) as C From ".self::get_table_name()." where ClientId IN ('".implode("','",array_keys($clientIdList))."') Group BY ClientId");
            foreach ($result as $r){
                $clientCount[$r->ClientId]=$r->C;
            }
        }
        
        if (sizeof($payments)>0){   
            if ( $settings['summary']){
                ksort($type);
                foreach ($type as $t=>$paymentsByType){                    
                    $total=0;
                    ?><h2><?php print self::s()->tinyIntDescriptions["Type"][$t]?></h2>
                    <table class="dp"><tr><th>Client Id</th><th>Client</th><th>E-mail</th><th>Date</th><th>Gross</th><th>Item Name</th><th>Item Description</th><th>Note</th><th>LifeTime</th></tr>
                    <?php
                    foreach($paymentsByType as $payments){
                        $payment=new self($payments[key($payments)]);
                        ?><tr>
                            <td  rowspan="<?php print esc_html(sizeof($payments))?>"><?php                        
                                print $payment->show_field('ClientId');
                            ?></td>                            
                            <td rowspan="<?php print esc_html(sizeof($payments))?>"><?php
                        if ($clients[$payment->ClientId]){
                            //print $clients[$payment->ClientId]->display_key()." ".
                            print $clients[$payment->ClientId]->name_check();
                        }else{ 
                            print $payment->ClientId;
                        }
                    
                        ?></td>
                        <td rowspan="<?php print esc_html(sizeof($payments))?>"><?php print $clients[$payment->ClientId]?$clients[$payment->ClientId]->display_email('Email'):""?></td><?php
                        $count=0;
                        foreach($payments as $r){                          
                            if ($count>0){
                                $payment=new self($r);
                                print "<tr>";
                            } 
                           ?><td><?php print wp_kses_post($payment->show_field('Date'))?></td>
                           <td align=right><?php print esc_html($payment->show_field('Gross')." ".$payment->Currency)?></td>
                           <td><?php
                            print $payment->show_field("ItemName");
                            ?></td>
                            <td><?php
                            print $payment->show_field("ItemDescription");
                            ?></td>
                            <td><?php print $payment->show_field("Note")?></td>  
                            <td <?php print $clientCount[$payment->ClientId]==1?" style='background-color:orange;'":""?>><?php  print "x".$clientCount[$payment->ClientId].($clientCount[$payment->ClientId]==1?" FIRST TIME!":"")."";?> </td>                            
                            </tr><?php
                            $total+=$payment->Gross;
                            $count++;
                        }
                    }
                    ?><tfoot><tr><td colspan=3>Totals:</td><td align=right><?php print number_format($total,2)?></td><td></td><td></td><td></td></tr></tfoot></table><?php
                }

            }else{
                $qbAction=[];
                $items=[];                
                
                ?>
                <form method="post">
                    <button type="submit" name="Function" value="EmailPaymentReceipts">Send E-mail Receipts</button>
                    <button type="submit" name="Function" value="PdfPaymentReceipts" disabled>Generate Pdf Receipts</button>
                    |
                    <button type="submit" name="Function" value="PdfLabelPaymentReceipts">Generate Labels</button>
                    Labels Start At: <strong>Column:</strong> (1-3) &#8594; <input name="col" type="number" value="1"  min="1" max="3" /> &#8595; <strong>Row:</strong> (1-10)<input name="row" type="number" value="1" min="1" max="10"   />

                <table class="dp"><tr><th></th><th>Payment</th><th>Date</th><th>ClientId</th><th>Gross</th><th>Item Name</th><th>Item Description</th><th>Note</th><th>Type</th><th>Transaction Type</th></tr><?php
                foreach($payments as $r){
                    $payment=new self($r);
                    ?><tr><td><?php
                        if ($r->ReceiptType){
                            print "Sent: ".$r->ReceiptType." ".$r->Address;
                        }else{
                            ?> <input type="checkbox" name="EmailPaymentId[]" value="<?php print esc_attr($payment->PaymentId)?>" checked/> <a target="payment" href="<?php print esc_url('?page=onlineclasspayments-index&PaymentId='.$payment->PaymentId)?>">Custom Response</a><?php
                        }?></td><td><?php print wp_kses_post($payment->display_key())?></td><td><?php print esc_html($payment->Date)?></td><td <?php print ($clientCount[$payment->ClientId]==1?" style='background-color:orange;'":"")?>><?php
                        if ($clients[$payment->ClientId]){
                            print $clients[$payment->ClientId]->display_key()." ".$clients[$payment->ClientId]->name_check();
                        }else print $payment->ClientId;
                        print " (x".$clientCount[$payment->ClientId]
                        .($clientCount[$payment->ClientId]==1?" FIRST TIME!":"")
                        .")";
                        ?></td><td><?php print esc_html($payment->show_field('Gross')." ".$payment->Currency)?></td>
                        <td><?php print $payment->show_field("ItemName")?></td>
                        <td><?php print $payment->show_field("ItemDescription")?></td>
                        <td><?php print $payment->show_field("Note")?></td>
                        <td><?php print $payment->show_field("Type")?></td>
                        <td><?php print $payment->show_field("TransactionType")?></td>
                    </tr><?php
                }
                ?></table>
                </form>
                <?php
            }

        }
    
        if (!$settings['summary']){
           // $all=self::get($where);
           // print self::show_results($all);
        }        
    }

    static public function request_handler(){
        if(isset($_FILES['fileToUpload'])){
            PaymentUpload::csv_upload_check();
            return true;
        }elseif(self::input('Function','post')=="ProcessMapFile"){
            PaymentUpload::process_map_file();
        }elseif (self::input('f','get')=="AddPayment"){           
            $payment=new self();
            if (self::input('ClientId','get')){
               $client=Client::find(self::input('ClientId','get'));
               $clientText=" for Client #".$client->ClientId." ".$client->Name;
               ### copy settings from the last payment...
               $lastPayment=self::first(['ClientId ='.$client->ClientId],"PaymentId DESC",
               ['select'=>'ClientId,Name,FromEmailAddress,CategoryId,PaymentSource,TransactionType']);
               if ($lastPayment) $payment=$lastPayment;               
            }
            print "<h2>Add payment".$clientText."</h2>";
            $payment->ClientId=$client->ClientId;
            ### Client Settings override whatever is autopopulated from previous payment
            if ($client->Name) $payment->Name=$client->Name;
            if ($client->Email) $payment->FromEmailAddress=$client->Email;
            if ($client->Phone) $payment->ContactPhoneNumber=$client->Phone;
            ### Defaults set IF prevous payment not pulled.
            $payment->PaymentSource=$payment->PaymentSource?$payment->PaymentSource:1;
            $payment->Type=$payment->Type?$payment->Type:1;
            $payment->Status=9;

            $payment->edit_simple_form();           
            return true;
        }elseif (self::input('Function','post')=="Cancel" && self::input('table','post')=="payment"){
            $client=Client::find(self::input('ClientId','post'));
            $client->view();
            return true;
        }elseif (self::input('PaymentId','request')){	
            if (self::input('Function','post')=="Delete" && self::input('table','post')=="payment"){
                $payment=new self(self::input_model('post'));
                if ($payment->delete()){
                    self::display_notice("Payment #".$payment->show_field("PaymentId")." for $".$payment->Gross." from ".$payment->Name." on ".$payment->Date." deleted");                   
                    return true;
                }
            }
            if (self::input('Function','post')=="Save" && self::input('table','post')=="payment"){
                $payment=new self(self::input_model('post'));
                if ($payment->save()){
                    self::display_notice("Payment #".$payment->show_field("PaymentId")." saved.");
                    $payment=self::find(self::input('PaymentId','request')); //reload the payment, because not all fields may be passed in the save form
                    $payment->full_view();
                    return true;
                }else{
                    print "problem saving";
                }
            }
            if (self::input('syncPaymentToInvoiceQB','post')=="t"){
                $qb=new QuickBooks();
                $invoice_id=$qb->payment_to_invoice_check(self::input('PaymentId','post'),self::input('ItemId','post')); //ItemId
                if ($invoice_id) self::display_notice("Quickbooks Invoice: ".QuickBooks::qbLink('Invoice',$invoice_id) ." added to Payment #".self::input('PaymentId','post')." saved.");
                
            }

            $payment=self::find(self::input('PaymentId','request'));	
            $payment->full_view();
            return true;
        }elseif (self::input('Function','post')=="Save" && self::input('table','post')=="payment"){
            $payment=new self(self::input_model('post'));            
            if ($payment->save()){
                self::display_notice("Payment #".$payment->show_field("PaymentId")." saved.");
                $payment->full_view();
            }else{
                print "problem saving on insert";
            }
            return true;
        }elseif (self::input('Function','post')=="QBClientToCustomerCheck" && self::input('ClientsToCreateInQB','post')){
            $qb=new QuickBooks();
            $qb->ClientToCustomer(explode("|",self::input('ClientsToCreateInQB','post')));  
            return true;         
            
        }elseif(self::input('Function','post')=="QBPaymentToInvoice" && self::input('PaymentsToCreateInQB','post')){
            $paymentIds=explode("|",self::input('PaymentsToCreateInQB','post'));            
            $payments=self::get(["PaymentId IN ('".implode("','",$paymentIds)."')"]);            
            foreach($payments as $payment){
                $payment->QBItemId=self::input ('QBItemId_'.$payment->PaymentId,'post');
                $payment->send_to_QB(array('silent'=>true));
            }
            self::display_notice(sizeof($payments)." invoices/payments created in QuickBooks.");  
            return true;     
        }elseif(self::input('UploadDate','get') || self::input('SummaryView','get') || self::input('ActionView','get')){    
            if (self::input('Function','post')=="LinkMatchQBtoClientId"){
                $qb=new QuickBooks();
                $qb->process_customer_match(self::input('match','post'),self::input('rmatch','post'));                    
            }
            
            if (self::input('Function','post')=="EmailPaymentReceipts" && sizeof(self::input('EmailPaymentId','post'))>0){               
                foreach(self::input('EmailPaymentId','post') as $paymentId){
                    $payment=self::find($paymentId);
                    if ($payment->FromEmailAddress){                        
                        print $payment->email_receipt($payment->FromEmailAddress);
                    }else{
                        print "not sent to: ".$paymentId." ".$payment->Name."<br>";
                    }                   
                }                
            }
            ?>
             <div>
                    <div><a href="<?php print esc_url('?page='.self::input('page','get'))?>">Return</a></div><?php
                    $where=[];
                    if (self::input('UploadDate','get')){
                        $where[]="`CreatedAt`='".self::input('UploadDate','get')."'";
                    }
                    
                    $dateField=(isset(self::s()->dateFields[self::input('dateField','get')])?self::input('dateField','get'):key(self::s()->dateFields));
                    if (self::input('df','get')){
                        $where[]="DATE(`$dateField`)>='".self::input('df','get').($dateField=="Date"?"":" 00:00:00")."'";
                    }
                    if (self::input('dt','get')){
                        $where[]="DATE(`$dateField`)<='".self::input('dt','get').($dateField=="Date"?"":" 23:59:59")."'";
                    }               
                    self::view_payments($where,
                        array(
                            'unsent'=>self::input('unsent','get')=="t"?true:false,
                            'summary'=>self::input('SummaryView','get')=="t"?true:false
                            )
                        );                    
             ?></div><?php
             exit();
            return true;
        }else{
            return false;
        }
    }

    public function full_view(){?>
        <div>
            <form method="get">
                <input type="hidden" name="page" value="onlineclasspayments-index"/>
                <div><a href="<?php print esc_url('?page='.self::input('page','get'))?>">Home</a> |
                <a href="<?php print esc_url('?page=onlineclasspayments-index&ClientId='.$this->ClientId)?>">View Client</a> | Client Search: <input id="clientSearch" name="dsearch" value=""> <button>Go</button></div>
            </form>
            <h1>Payment #<?php print $this->PaymentId?$this->PaymentId:"Not Found"?></h1><?php
            if ($this->PaymentId){
                if (self::input('edit','request')){
                    if (self::input('raw','request')) $this->edit_form();
                    else{ $this->edit_simple_form(); }
                }else{
                    ?><div><a href="<?php print esc_url('?page=onlineclasspayments-index&PaymentId='.$this->PaymentId)?>&edit=t">Edit Payment</a></div><?php
                    $this->view();
                    $this->receipt_form();
                }
            }else{
            }?>
            
        </div><?php
    }

    public function select_drop_down($field,$showKey=true,$allowBlank=false){
        ?><select name="<?php print esc_attr($field)?>"><?php
        if ($allowBlank){
            ?><option></option<?php
        }
        foreach($this->tinyIntDescriptions[$field] as $key=>$label){
            ?><option value="<?php print esc_attr($key)?>"<?php print ($key==$this->$field?" selected":"")?>><?php print ($showKey?$key." - ":"").$label?></option><?php
        }
        ?></select><?php
    }
    public function edit_simple_form(){  
        $hiddenFields=['PaymentId','ToEmailAddress','ReceiptID','AddressStatus']; //these fields more helpful when using paypal import, but are redudant/not necessary when manually entering a transaction
        //?page=onlineclasspayments-index&PaymentId=4458&edit=t
        if ($this->PaymentId){
            ?><div><a href="<?php print esc_url('?page=onlineclasspayments-index&PaymentId='.$this->PaymentId)?>&edit=t&raw=t">Edit Raw</a></div><?php
        }?>
        <form method="post" action="<?php print esc_url('?page=onlineclasspayments-index'.($this->PaymentId?'&PaymentId='.$this->PaymentId:''))?>" style="border: 1px solid #999; padding:20px; width:90%;">
        <input type="hidden" name="table" value="payment">
        <?php foreach ($hiddenFields as $field){?>
		    <input type="hidden" name="<?php print esc_attr($field)?>" value="<?php print esc_attr($this->$field)?>"/>
        <?php } ?>
        <script>
            function calculateNet(){
                var net= document.getElementById('payment_gross').value-document.getElementById('payment_fee').value;
                net = net.toFixed(2);
                document.getElementById('payment_net').value=net;
                document.getElementById('payment_net_show').innerHTML=net;
            }
        </script> 
        <table><tbody>
        <tr>
            <td align="right">Total Amount</td>
            <td><input id="payment_gross" style="text-align:right;" onchange="calculateNet();" required type="number" step=".01" name="Gross" value="<?php print esc_attr($this->Gross)?>"> <?php $this->select_drop_down('Currency',false);?></td></tr>
        <tr>
            <td align="right">Fee</td>
            <td><input id="payment_fee" style="text-align:right;"  onchange="calculateNet();" type="number" step=".01" name="Fee" value="<?php print esc_attr($this->Fee?$this->Fee:0)?>"> <strong>Net:</strong> <input id="payment_net" type="hidden" name="Net" value="<?php print esc_attr($this->Net?$this->Net:0)?>"/>$<span id="payment_net_show"><?php print number_format($this->Net?$this->Net:0,2)?></span></td></tr> 
        <tr><td align="right">Check #/Transaction ID</td><td><input type="txt" name="TransactionID" value="<?php print esc_attr($this->TransactionID)?>"></td></tr>
        <tr><td align="right">Check/Sent Date</td><td><input type="date" name="Date" value="<?php print ($this->Date?date("Y-m-d",strtotime($this->Date)):date("Y-m-d"))?>"></td></tr>
        <tr><td align="right">Date Deposited</td><td><input type="date" name="DateDeposited" value="<?php print ($this->DateDeposited?$this->DateDeposited:date("Y-m-d"))?>"></td></tr>
        
        <tr><td align="right">ClientId</td><td><?php
        if ($this->ClientId){
            ?><input type="hidden" name="ClientId" value="<?php print esc_attr($this->ClientId)?>"> #<?php print esc_html($this->ClientId)?><?php
        }else{
            ?><input type="text" name="ClientId" value="<?php print esc_attr($this->ClientId)?>"> Todo: Make a chooser or allow blank, and/or create after this step. <?php
        }
        ?></td></tr>
        <tr><td align="right">Name</td><td><input type="text" name="Name" value="<?php print esc_attr($this->Name)?>"></td></tr>
        <tr><td align="right">Email Address</td><td><input type="email" name="FromEmailAddress" value="<?php print esc_attr($this->FromEmailAddress)?>"></td></tr>
        <tr><td align="right">Phone Number</td><td><input type="tel" name="ContactPhoneNumber" value="<?php print esc_attr($this->ContactPhoneNumber)?>"></td></tr>

        <tr><td align="right">Payment Source</td><td> <?php $this->select_drop_down('PaymentSource');?></td></tr>
        <tr><td align="right">Type</td><td> <?php $this->select_drop_down('Type');?></td></tr>        
        <tr><td align="right">Status</td><td><?php $this->select_drop_down('Status');?></td></tr>
       
       <tr><td align="right">Item Code</td><td><input type="text" name="ItemCode" value="<?php print esc_attr($this->ItemCode)?>"></td></tr>
       <tr><td align="right">Item Name</td><td><input type="text" name="ItemName" value="<?php print esc_attr($this->ItemName)?>"></td></tr>
       <tr><td align="right">Item Description</td><td><input type="text" name="ItemDescription" value="<?php print esc_attr($this->ItemDescription)?>"></td></tr>
        <tr><td align="right">Note</td><td><textarea name="Note"><?php print esc_textarea($this->Note)?></textarea></td></tr>
        <tr><td align="right">Transaction Type</td><td><?php print esc_html($this->select_drop_down('TransactionType'))?><div><em>Set to "Not Tax Deductible" if they have already been giving credit for the payment by donating through a client advised fund, or if this is a payment for a service.</div></td></tr>
        <tr></tr><tr><td colspan="2"><button type="submit" class="Primary" name="Function" value="Save">Save</button><button type="submit" name="Function" class="Secondary" value="Cancel" formnovalidate>Cancel</button>
        <?php 
        if ($this->PaymentId){
            ?> <button type="submit" name="Function" value="Delete">Delete</button><?php
        }
        ?>
    </td></tr>
		</tbody></table>
		</form>
        <?php
    }

    function receipt_email(){
        if (isset($this->emailBuilder) && $this->emailBuilder) return;
        $this->emailBuilder=new \stdClass();

        if ($this->CategoryId){
            $page=PaymentCategory::find($this->CategoryId)->getTemplate();
        }
        if (!isset($page) || !isset($page->post_content)){
            if ($this->TransactionType==1){                   
                $page = PaymentTemplate::get_by_name('no-tax-thank-you');  
                if (!$page){ ### Make the template page if it doesn't exist.
                    self::make_receipt_template_no_tax();
                    $page = PaymentTemplate::get_by_name('no-tax-thank-you');  
                    self::display_notice("Email Template /no-tax-thank-you created. <a target='edit' href='?page=onlineclasspayments-settings&tab=email&PaymentTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
                }
            }elseif ($this->TransactionType==3){                   
                $page = PaymentTemplate::get_by_name('ira-qcd');  
                if (!$page){ ### Make the template page if it doesn't exist.
                    self::make_receipt_template_ira_qcd();
                    $page = PaymentTemplate::get_by_name('ira-qcd');  
                    self::display_notice("Email Template /ira-qcd created. <a target='edit' href='?page=onlineclasspayments-settings&tab=email&PaymentTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
                }
            }else{
                $page = PaymentTemplate::get_by_name('receipt-thank-you');  
                if (!$page){ ### Make the template page if it doesn't exist.
                    self::make_receipt_template_thank_you();
                    $page = PaymentTemplate::get_by_name('receipt-thank-you');  
                    self::display_notice("Email Template /receipt-thank-you created. <a target='edit' href='?page=onlineclasspayments-settings&tab=email&PaymentTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
                }
            }
        }
            
        if (!$page){ ### Make the template page if it doesn't exist.
            self::make_receipt_template_thank_you();
            $page = PaymentTemplate::get_by_name('receipt-thank-you');  
            self::display_notice("Page /receipt-thank-you created. <a target='edit' href='?page=onlineclasspayments-settings&tab=email&PaymentTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
       
        if (!isset($this->Client) || !$this->Client)  $this->Client=Client::find($this->ClientId);
        $address=$this->Client->mailing_address();
        $subject=$page->post_title;
        $body=$page->post_content;

        $body=str_replace("##Name##",$this->Client->name_combine(),$body);
        $body=str_replace("##Gross##","$".number_format($this->Gross,2),$body);
        if (!$address){ //remove P
            $body=str_replace("<p>##Address##</p>",$address,$body);
        }
        $body=str_replace("##Address##",$address,$body);
        $body=str_replace("##Date##",date("F j, Y",strtotime($this->Date)),$body);
        $body=str_replace("##Year##",date("Y",strtotime($this->Date)),$body);
        $body=str_replace("##ItemName##",$this->ItemName,$body);
        $body=str_replace("##ItemDescription##",$this->ItemDescription,$body);
        $body=str_replace("##ItemQuantity##",$this->ItemQuantity,$body);
        
        ### replace custom variables.
        foreach(CustomVariables::variables as $var){
            if (substr($var,0,strlen("Quickbooks"))=="Quickbooks") continue;
            if (substr($var,0,strlen("Paypal"))=="Paypal") continue;
            $body=str_replace("##".$var."##", get_option( CustomVariables::base.'_'.$var),$body);
            $subject=str_replace("##".$var."##",get_option( CustomVariables::base.'_'.$var),$subject);                   
        }   

        $body=str_replace("<!-- wp:paragraph -->",'',$body);
        $body=str_replace("<!-- /wp:paragraph -->",'',$body);
        $this->emailBuilder->subject=$subject;
        $this->emailBuilder->body=$body;    
        $this->emailBuilder->fontsize=$page->post_excerpt_fontsize;
        $this->emailBuilder->margin=$page->post_excerpt_margin; 
    }

    static function make_receipt_template_no_tax(){
        $page = PaymentTemplate::get_by_name('no-tax-thank-you');  
        if (!$page){                   
            $tempLoc=onlineclasspayments_plugin_base_dir()."/resources/template_default_receipt_no_tax.html"; 
            $t=new PaymentTemplate();          
            $t->post_content=file_get_contents($tempLoc);            
            $t->post_title='Thank You For Your ##Organization## Gift';
            $t->post_name='no-tax-thank-you';
            $t->post_excerpt='{"fontsize":"12","margin":".25"}';
            $t->save();
            return $t;          

        }
    }

    static function make_receipt_template_ira_qcd(){
        $page = PaymentTemplate::get_by_name('ira-qcd');  
        if (!$page){
            $tempLoc=onlineclasspayments_plugin_base_dir()."/resources/template_default_receipt_ira_qcd.html"; 
            $t=new PaymentTemplate();          
            $t->post_content=file_get_contents($tempLoc);            
            $t->post_title='Thank You For IRA Qualified Charitable Distribution To ##Organization##';
            $t->post_name='ira-qcd';
            $t->post_excerpt='{"fontsize":"12","margin":".25"}';
            $t->save();
            return $t;
        }
    }

    static function make_receipt_template_thank_you(){
        $page = PaymentTemplate::get_by_name('receipt-thank-you');  
        if (!$page){
            $tempLoc=onlineclasspayments_plugin_base_dir()."/resources/template_default_receipt_thank_you.html";
            $t=new PaymentTemplate();          
            $t->post_content=file_get_contents($tempLoc);            
            $t->post_title='Thank You For Your ##Organization## Payment';
            $t->post_name='receipt-thank-you';
            $t->post_excerpt='{"fontsize":"12","margin":".25"}';
            $t->save();
            return $t;           
        }    
    }

    public function email_receipt($email="",$customMessage=null,$subject=null){ 
        $notice="";     
        $this->receipt_email();
        if (!$email){
            return false;         
        }
        if (wp_mail($this->email_string_to_array($email), $subject?$subject:$this->emailBuilder->subject, $customMessage?$customMessage:$this->emailBuilder->body,array('Content-Type: text/html; charset=UTF-8'))){ 
            if ($customMessage && $customMessage==$this->emailBuilder->body){
                $customMessage=null; //don't bother saving if template hasn't changed.
            }
            $notice="<div class=\"notice notice-success is-dismissible\">E-mail sent to ".$email."</div>";
            $dr=new PaymentReceipt(array("ClientId"=>$this->ClientId,"KeyType"=>"PaymentId","KeyId"=>$this->PaymentId,"Type"=>"e","Address"=>$email,"DateSent"=>date("Y-m-d H:i:s"),
            "Subject"=>$subject,"Content"=>$customMessage));
            $dr->save();
        }else{
            self::display_error("Error sending e-mail to: ".$email.". Check your wordpress email sending settings.");
        }
        return $notice;
    }

    static function pdf_class_check(){
        if (class_exists("TCPDF")){
            return "tcpdf";
        }else{
            if (file_exists(WP_PLUGIN_DIR.'/doublewp-tcpdf-wrapper/lib/tcpdf/tcpdf.php')){
             require ( WP_PLUGIN_DIR.'/doublewp-tcpdf-wrapper/lib/tcpdf/tcpdf.php' );
            }
        }

        if (class_exists("TCPDF")){
            return "tcpdf";
        }
        if (class_exists("Dompdf\Dompdf")){        
            return "dompdf";    
       }
       self::display_error("PDF Writing is not installed. You must run 'composer install' on the client-press plugin directory to get this to function or install <a href='/wp-admin/plugin-install.php?s=DoublewP%2520TCPDF%2520Wrapper&tab=search&type=term'>DoublewP TCPDF Wrapper</a> ");
       wp_die(); 
       return false;
    }

    public function pdf_receipt($customMessage=null){
        if ($pdfLib=self::pdf_class_check()){
        }else{ return false; }
        ob_clean();
        $this->receipt_email(); 
        $file=$this->receipt_file_info();    
        $dpi=72;    
        if ($pdfLib=="dompdf"){
            //$margin=($this->emailBuilder->margin?$this->emailBuilder->margin:.5)*$dpi;
            //print $customMessage?$customMessage:$this->emailBuilder->body; exit();
            $options = new \Dompdf\Options();
            $options->setDpi($dpi);
            $options->set('defaultFont', 'Helvetica');
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled',true);
            //$options->set('tempDir', '/tmp'); //folder name and location can be changed
            $dompdf = new \Dompdf\Dompdf($options);
    
            $style="<style>@page { font-size:".(($this->emailBuilder->fontsize?$this->emailBuilder->fontsize:12))."px;";
            if ($this->emailBuilder->margin) $style.="margin:".$this->emailBuilder->margin."in;";
            $style.="}</style>";
            //print $style.($customMessage?$customMessage:$this->emailBuilder->body); exit();
            $dompdf->loadHtml($style.($customMessage?$customMessage:$this->emailBuilder->body));
            $dompdf->set_paper('letter', 'portrait');
            $dompdf->render();
            $file=$this->receipt_file_info(); 
            $dompdf->stream($file,array("Attachment" => false));
            return true;
        
        }else{
            $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            $margin=($this->emailBuilder->margin?$this->emailBuilder->margin:.25)*72;
            $pdf->SetMargins($margin,$margin,$margin);
            $pdf->SetFont('helvetica', '', $this->emailBuilder->fontsize?$this->emailBuilder->fontsize:12);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false); 
                   
            $pdf->AddPage();
            $pdf->writeHTML($customMessage?$customMessage:$this->emailBuilder->body, true, false, true, false, '');  
            if (!$pdf->Output($file, 'D')){
                return false; 
            }
        } 
        $dr=new PaymentReceipt(array("ClientId"=>$this->ClientId,"KeyType"=>"PaymentId","KeyId"=>$this->PaymentId,"Type"=>"p","Address"=>$this->Client->mailing_address(),"DateSent"=>date("Y-m-d H:i:s"),"Subject"=>$this->emailBuilder->subject,"Content"=>$customMessage));
        $dr->save();        
        return true;        
    }

    public function receipt_file_info(){
        return substr(str_replace(" ","",get_bloginfo('name')),0,12)."-D".$this->ClientId.'-DT'.$this->PaymentId.'.pdf';
    }

    public function save($time=""){
        if ($this->CategoryId && (!$this->TransactionType || $this->TransactionType==0)){ //this is slightly problematic if we want it to be "0", but it is overwritten by the category on save. Could cause some perceived buggy behavior.      
            $this->TransactionType=PaymentCategory::get_default_transaction_type($this->CategoryId);          
        }
        return parent::save($time);

    }


    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
        $sql = "CREATE TABLE IF NOT EXISTS `".self::get_table_name()."` (
                `PaymentId` int(11) NOT NULL AUTO_INCREMENT,
                `Date` datetime NOT NULL,
                `DateDeposited` date DEFAULT NULL,
                `ClientId` int(11) DEFAULT NULL,
                `Name` varchar(80) NOT NULL,
                `Type` tinyint(4) DEFAULT NULL,
                `TypeOther` varchar(30) DEFAULT NULL,
                `Status` tinyint(4) DEFAULT NULL,
                `Currency` varchar(3) DEFAULT NULL,
                `Gross` DECIMAL(10,2) NOT NULL,
                `Fee` decimal(6,2) DEFAULT NULL,
                `Net` DECIMAL(10,2) DEFAULT NULL,
                `FromEmailAddress` varchar(70) NOT NULL,
                `ToEmailAddress` varchar(26) DEFAULT NULL,
                `Source` varchar(20) NOT NULL,
                `SourceId` varchar(50) NOT NULL,
                `TransactionID` varchar(17) DEFAULT NULL,
                `AddressStatus` tinyint(4) DEFAULT NULL,
                `CategoryId` tinyint(4) DEFAULT NULL,
                `ReceiptID` varchar(16) DEFAULT NULL,
                `ContactPhoneNumber` varchar(20) DEFAULT NULL,
                `ItemCode` varchar(50) DEFAULT NULL,
                `ItemName` varchar(255) DEFAULT NULL,
                `ItemDescription` varchar(255) DEFAULT NULL,
                `ItemQuantity` int(11) DEFAULT NULL,
                `Note` text,
                `PaymentSource` tinyint(4) DEFAULT NULL,
                `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `TransactionType` tinyint(4) DEFAULT '1' COMMENT '1=Product',               
                PRIMARY KEY (`PaymentId`)
                )";               
        dbDelta( $sql );        
    }

    
    public function receipt_form(){
        $client=Client::find($this->ClientId); 
        $this->receipt_email();            
        if (self::input('Function','post')=="SendPaymentReceipt" && self::input('Email','post')){
            print $this->email_receipt(self::input('Email','post'),stripslashes_deep(self::input('customMessage','post')),stripslashes_deep(self::input('EmailSubject','post')));
            
        }
        $file=$this->receipt_file_info();
               
        print "<div class='no-print'><a href='?page=".self::input('page','get')."'>Home</a> | <a href='?page=".self::input('page','get')."&ClientId=".$this->ClientId."'>Return to Client Overview</a></div>";
        $receipts=PaymentReceipt::get(array("ClientId='".$this->ClientId."'","KeyType='PaymentId'","KeyId='".$this->PaymentId."'"));
        $lastReceiptKey=is_array($receipts)?sizeof($receipts)-1:0;
        $bodyContent=isset($receipts[$lastReceiptKey]) && $receipts[$lastReceiptKey]->Content!=""?$receipts[$lastReceiptKey]->Content:$this->emailBuilder->body; //retrieve last saved custom message
        $bodyContent=self::input('customMessage','post')?stripslashes_deep(self::input('customMessage','post')):$bodyContent; //Post value overrides this though.
        $subject=self::input('EmailSubject','post')?stripslashes_deep(self::input('EmailSubject','post')):$this->emailBuilder->subject;

        if (self::input('resetLetter','get')=="t"){
            $subject=$this->emailBuilder->subject;
            $bodyContent=$this->emailBuilder->body;
        }
   
        print PaymentReceipt::show_results($receipts,"You have not sent this client a Receipt.");

        $emailToUse=(self::input('Email','post')?self::input('Email','post'):$this->FromEmailAddress);
        if (!$emailToUse) $emailToUse=$this->Client->Email;
        ?><form method="post" action="<?php print esc_url('?page='.self::input('page','get').'&PaymentId='.$this->PaymentId)?>">
            <h2>Send Receipt</h2>
            <input type="hidden" name="PaymentId" value="<?php print esc_attr($this->PaymentId)?>">
            <div><strong>Send Receipt To:</strong> <input type="text" name="Email" value="<?php print esc_attr($emailToUse)?>">
                <button type="submit" name="Function" value="SendPaymentReceipt">Send E-mail Receipt</button> 
                <button type="submit" name="Function" value="PaymentReceiptPdf">Generate PDF</button>                  
            </div>
            <div><a target='pdf' href='<?php print esc_url('?page=onlineclasspayments-settings&tab=email&PaymentTemplateId='.$this->emailBuilder->pageID.'&edit=t')?>'>Edit Template</a> | <a href="<?php print esc_url('?page=onlineclasspayments-index&PaymentId='.$this->PaymentId)?>&resetLetter=t">Reset Letter</a></div>
            <div style="font-size:18px;"><strong>Email Subject:</strong> <input style="font-size:18px; width:500px;" name="EmailSubject" value="<?php print esc_attr($subject);?>"/>
            <?php wp_editor($bodyContent, 'customMessage',array("media_buttons" => false,"wpautop"=>false)); ?>
        </form>
        <?php    
    }

    static function label_by_id($paymentIds,$col_start=1,$row_start=1,$limit=100000){
        if (sizeof($paymentIds)<$limit) $limit=sizeof($paymentIds);
        $type=Payment::pdf_class_check();
        if ($type!='tcpdf'){
            self::display_error("TCPDF is required to generate this PDF.");
            wp_die();
        }     
        $SQL="Select DT.PaymentId,DR.*
        FROM ".self::get_table_name()." DT
        INNER JOIN ".Client::get_table_name()." DR ON DT.ClientId=DR.ClientId 
        WHERE DT.PaymentId IN (".implode(",",$paymentIds).")";      
        
        $payments = self::db()->get_results($SQL);
        
        foreach ($payments as $r){
            $paymentList[$r->PaymentId]=new Client($r);
           
        }
        $a=[];

        $defaultCountry=CustomVariables::get_option("DefaultCountry");       
        foreach($paymentIds as $id){
            if ($paymentList[$id]){
                $address=$paymentList[$id]->mailing_address("\n",true,['DefaultCountry'=>$defaultCountry]);
                if(!$address) $address=$paymentList[$id]->name_combine();
                if ($address) $a[]=$address;
            }
        }

        $dpi=72;	
        $pad=10;
        $margin['x']=13.5;// 3/16th x
        $margin['y']=.5*$dpi;
        ob_clean();
        $pdf = new \TCPDF('P', 'pt', 'LETTER', true, 'UTF-8', false); 
        $pdf->SetFont('helvetica', '', 12);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false); 	

        $pdf->AddPage();
        $pdf->SetCellPadding($pad);
        $pdf->SetAutoPageBreak(true);
        $pdf->SetMargins($margin['x'],$margin['y'],$margin['x']);
        $pdf->SetCreator('Client-Press Plugin');
        $pdf->SetAuthor('Client-Press');
        $pdf->SetTitle('Year End Labels');	 
        $starti=($col_start>0?($col_start-1)%3:0)+($row_start>0?3*floor($row_start-1):0);
        $border=0; $j=0;
        for ($i=$starti;$i<sizeof($a)+$starti;$i++){
            $col=$i%3;
            $row=floor($i/3)%10;
            if ($i%30==0 && $j!=0){ $pdf->AddPage();}	
            $pdf->MultiCell(2.625*$dpi,1*$dpi,$a[$j],$border,"L",0,0,$margin['x']+$col*2.75*$dpi,$margin['y']+$row*1*$dpi,true);
            $j++;		
        }	
        $pdf->Output("OnlineClassesPaymentsPaymentLabels.pdf", 'D');

    }

    public function send_to_QB($settings=[]){
        $qb=new QuickBooks();
        return $qb->payment_to_invoice_process($this);
    }

    static function report($top=null,$dateField=null){
        if (!$dateField) $dateField=Client::input('dateField','get')?Client::input('dateField','get'):'Date';
        $where=["(DT.Status>=0 OR DT.Status IS NULL)"];
		if (Client::input('PaymentSource','get')){
			$where[]="PaymentSource='".(Client::input('PaymentSource','get')=="ZERO"?0:Client::input('PaymentSource','get'))."'";		
		}	
		if (Client::input('Type','get')){
			$where[]="`Type`='".(Client::input('Type','get')=="ZERO"?0:Client::input('Type','get'))."'";			
		}
		if (Client::input('TransactionType','get')){
			$where[]="TransactionType='".(Client::input('TransactionType','get')=="ZERO"?0:Client::input('TransactionType','get'))."'";			
		}
		if (Client::input('df','get')){
			$where[]="`$dateField`>='".Client::input('df','get').($dateField=="Date"?"":" 00:00:00")."'";
		}
		if (Client::input('dt','get')){
			$where[]="`$dateField`<='".Client::input('dt','get').($dateField=="Date"?"":" 23:59:59")."'";
		}		
		if (Client::input('af','get') && Client::input('at','get')){
			$where[]="DT.Gross BETWEEN '".Client::input('af','get')."' AND '".Client::input('at','get')."'";
		}elseif (Client::input('af','get')){
			$where[]="DT.Gross='".Client::input('af','get')."'";
		}elseif (Client::input('at','get')){
			$where[]="DT.Gross='".Client::input('at','get')."'";
		}   

		$SQL="Select DT.PaymentId,D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City, DT.`Date`,DT.DateDeposited,DT.Gross,DT.TransactionID,DT.ItemCode,DT.ItemName,DT.ItemDescription,DT.ItemQuantity,DT.Note,DT.PaymentSource,DT.Type ,DT.Source,DT.CategoryId,DT.TransactionType
        FROM ".Client::get_table_name()." D INNER JOIN ".self::get_table_name()." DT ON D.ClientId=DT.ClientId 
        WHERE ".implode(" AND ",$where)." Order BY ".$dateField." DESC,PaymentId ".($top?" LIMIT ".$top:""); 
		//print "<pre>".$SQL."</pre>";     
        $results = self::db()->get_results($SQL);
		if (Client::input('Function','get')=="PaymentListCsv"){
			$fileName="all";
			if (Client::input('df','get')){
				$fileName=Client::input('df','get')."-";
				if (Client::input('dt','get')){
					$fileName.=Client::input('dt','get');
				}
			}elseif ($fileName=Client::input('dt','get')){
				$fileName="-".Client::input('dt','get');
			}
            return self::resultToCSV($results,array('name'=>'Payments_'.$fileName,'namecombine'=>true));
		}else{
            $total=0;
            ?><table class="dp"><tr><th>PaymentId</th><th>Name</th><th>Amount</th><th>Date</th><th>Item Name</th><th>Item Description</th><th>Note</th><th>Transaction</th></tr><?php
            foreach ($results as $r){
                $payment=new self($r);
                $client=new Client($r);
                $total+=$r->Gross;
                ?>
                <tr>
                <td><a target="payment" href="<?php print esc_url('?page=onlineclasspayments-index&PaymentId='.$r->PaymentId)?>"><?php print esc_html($r->PaymentId)?></a></td>
                <td><a target="payment" href="<?php print esc_url('?page=onlineclasspayments-index&ClientId='.$r->ClientId)?>"><?php print esc_html($client->name_combine())?></a></td>                
                <td style="text-align:right;"><?php print number_format($r->Gross,2)?></td>
                <td><?php print esc_html($r->Date)?></td>
                <td><?php print esc_html($r->ItemName)?></td>
                <td><?php print esc_html($r->ItemDescription)?></td>
                <td><?php print wp_kses_post($payment->show_field("Note"));?></td>
                <td><?php print $payment->Source.": ".$r->TransactionID?></td>
                </tr>
                <?php
            }
            ?>
            <tfoot>
                <tr><td colspan="2">Total:</td><td style="text-align:right;"><?php print number_format($total,2)?></td><td colspan="5"></td></tr>
            </tfoot>           
            </table>
            <?php           
        }	
    }
}