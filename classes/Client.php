<?php
namespace OnlineClassPayments;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Client extends ModelLite {
    protected $table = 'client';
	protected $primaryKey = 'ClientId';
	### Fields that can be passed 
    public $fillable = ["Name","Name2","Email","EmailStatus","Phone","Address1","Address2","City","Region","PostalCode","Country","AddressStatus","TypeId","TaxReporting","MergedId","Source","SourceId","QuickBooksId"];	    
	
    public $flat_key = ["Name","Name2","Email","Phone","City","Region"];
    ### Default Values
	protected $attributes = [        
        'Country' => 'US',
        'TaxReporting'=> 0,
        'EmailStatus'=>1,
        'AddressStatus'=>0,
        'TypeId'=>0
    ];
    
    protected $tinyIntDescriptions=[
        "EmailStatus"=>["-1"=>"Returned","0"=>"Not Set","1"=>"Valid"],
        "AddressStatus"=>["1"=>"Valid","-2"=>"Unsubscribed", "-1"=>"Returned","0"=>"Not Set"],     
    ];

    protected $fieldLimits = [//SELECT concat("'",column_name,"'=>",character_maximum_length ,",") as grid FROM information_schema.columns where table_name = 'wp_client' and table_schema='wordpress' and data_type='varchar'
        'Source'=>20,
        'SourceId'=>50,
        'Name'=>80,
        'Name2'=>80,
        'Email'=>80,
        'Phone'=>20,
        'Address1'=>80,
        'Address2'=>80,
        'City'=>50,
        'Region'=>20,
        'PostalCode'=>20,
        'Country'=>2,
    ];
	//public $incrementing = true;
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';
    /*
    ALTER TABLE `wordpress`.`dwp_client` 
ADD COLUMN `TypeId` INT NULL DEFAULT NULL AFTER `Country`;
*/
   
    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
          $sql="CREATE TABLE IF NOT EXISTS `".self::get_table_name()."` (
            `ClientId` int(11) NOT NULL AUTO_INCREMENT,
            `Source` varchar(20) NOT NULL,
            `SourceId` varchar(50) NOT NULL,
            `Name` varchar(80) NOT NULL,
            `Name2` varchar(80)  NULL,
            `Email` varchar(80)  NULL,
            `EmailStatus` tinyint(4) NOT NULL DEFAULT '1' COMMENT '-1=Bounced 1=Active',
            `Phone` varchar(20)  NULL,
            `Address1` varchar(80)  NULL,
            `Address2` varchar(80)  NULL,
            `City` varchar(50)  NULL,
            `Region` varchar(20)  NULL,
            `PostalCode` varchar(20)  NULL,
            `Country` varchar(2)  NULL,
            `AddressStatus` tinyint(4) NOT NULL DEFAULT '1' COMMENT '-2 Unsubscribed -1=Returned 1=Active', 
            `TypeId` int(11) NOT NULL DEFAULT '0',
            `MergedId` int(11) NOT NULL DEFAULT '0',            
            `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `TaxReporting` tinyint(4) DEFAULT '0' COMMENT '0 - Standard -1 Not Required -2 Opt Out',
            PRIMARY KEY (`ClientId`)
            )";
          dbDelta( $sql );
    }

    static public function from_paypal_api_detail($detail){
        $payer=$detail->payer_info;       
        $shipInfo=$detail->shipping_info;

        $type=Payment::transaction_event_code_to_type($detail->transaction_info->transaction_event_code);

        $d = new self();
        $d->Source='paypal';
        $d->SourceId=$payer->account_id;
        $d->Email=$payer->email_address; 
        
        //$d->EmailStatus=1; //if already set elswhere... should not be etting this
        $d->Name=$payer->payer_name->alternate_full_name;
        if (isset($payer->address)){
            $address=$payer->address?$payer->address->address:$shipInfo->address; //address not always provided, but if it is, look first if it is on the payer object, otherwise look at shipping_info  
        }else{
            $address=null;
        }
        
        if ($address){
            $d->Address1=$address->line1;
            if ($address->line2) $d->Address2=$address->line2;
            $d->City=$address->city;
            $d->Region=$address->state;
            $d->Country=$address->country_code;
            $d->PostalCode=$address->postal_code;
            $d->AddressStatus=1;
        }elseif($payer->country_code){
            $d->Country=$payer->country_code;  //entries without addresses usually at least have country codes.
            $d->AddressStatus=-1;
        }
        //$d->AddressStatus=$payer->address_status=="Y"?1:-1;
        //deposit scenerio detected
        if ($type<0 && !$d->Email){
            $d->Email=Payment::get_deposit_email();            
        }
        if (!$d->Name && $detail->transaction_info->bank_reference_id){
            $d->Name="Bank Withdrawal";
            if (!$d->SourceId) $d->SourceId=$detail->transaction_info->bank_reference_id;
        }

        if (!$d->SourceId) $d->SourceId=$detail->transaction_info->bank_reference_id;
        return $d;        
    }

    public function merge_form(){
        ?><form method="post">
        <input type="hidden" name="MergeFrom" value="<?php print esc_attr($this->ClientId)?>"/> 
        Merge To Id: <input type="number" name="MergedId" value="">
        <button method="submit" name="Function" value="MergeConfirm">Merge</button>
        Enter the ID of the Client you want to merge to. You will have the option to review this merge. Once merged, all payments will be relinked to the new profile.</form><?php
    }

        static public function client_update_suggestion($current,$new,$timeProcessed=null){   
        $skip=array();
        $suggest_client_changes=[];
        foreach ($new as $clientN){
            if ($clientN->ClientId && $current[$clientN->ClientId]){
                foreach(self::s()->fillable as $field){
                    if (in_array($field,$skip)) continue;
                    switch($field){
                        case "Name":
                        case "Name2":
                        case "Address1":
                        case "Address2":
                        case "City": 
                            $value=self::ucfirst_fixer($clientN->$field);                            
                            break;
                        case "Region":
                        case "Country":
                        case "PostalCode":
                            $value=strtoupper($clientN->$field);
                            break;
                        case "Email":
                            $value=strtolower($clientN->$field);
                            break;
                        case "AddressStatus";
                            $value=$clientN->$field;
                            if (in_array($current[$clientN->ClientId]->$field,array(-2,1))) { //if current AddressStatus is unsubscriptes, don't suggest a change
                                $value=$current[$clientN->ClientId]->$field; 
                            }
                        break;
                        default:
                            $value=$clientN->$field;
                        break;
                    }
                    $value=trim($value);
                    if (isset($clientN->$field) && $value!="" && $value!=$current[$clientN->ClientId]->$field){
                        $suggest_client_changes[$clientN->ClientId][$field]['c']=$current[$clientN->ClientId]->$field;
                        $suggest_client_changes[$clientN->ClientId][$field]['n'][$value]++;
                    }
                }
                //If there is any changes for this client, then set name so it can be read
                if ($suggest_client_changes[$clientN->ClientId]){
                    $suggest_client_changes[$clientN->ClientId]['Name']['c']=$current[$clientN->ClientId]->Name;
                } 
            }
        }
        self::client_update_suggestion_form($suggest_client_changes,$timeProcessed);          
    }

    static public function client_update_suggestion_form($suggest_client_changes,$timeProcessed=null){
        if (!$timeProcessed) $timeProcessed=time();
        if (sizeof($suggest_client_changes)==0) return;        
        print "<h2>The following changes are suggested</h2><form method='post'>";
        print "<table border='1'><tr><th>#</th><th>Name</th><th>Change</th></tr>";
        foreach ($suggest_client_changes as $clientId => $changes){
            print "<tr><td><a target='lookup' href='?page=onlineclasspayments-index&ClientId=".$clientId."'>".$clientId."</td><td>".$changes['Name']['c']."</td><td>";
            foreach($changes as $field=>$values){
               if (isset($values['n'])){                               
                   //krsort($values['n']);
                   $i=0;
                   foreach($values['n'] as $value=>$count){
                       print "<div><label><input ".($i==0?" checked ":"")." type='checkbox' name='changes[]' value=\"".addslashes($clientId."||".$field."||".$value)."\"/> <strong>".$field.":</strong> ".($values['c']?$values['c']:"(blank)")." -> ".$value.(sizeof($values['n'])>1?" (".$count.")":"")."</label></div>";
                       $i++;                                    
                   }
               }                         
            }
            print "</td><td>";
            if ($changes['MatchOn']){
                print_r($changes['MatchOn']);
            }
            print "</td></tr>";

        }
        print "</table><button type='submit' name='Function' value='MakeClientChanges'>Make Client Changes</button></form>";
        print "<hr>";
        print "<div><a target='viewSummary' href='?page=onlineclasspayments-reports&UploadDate=".date("Y-m-d H:i:s",$timeProcessed)."'>View All</a> | <a target='viewSummary' href='?page=onlineclasspayments-reports&SummaryView=t&UploadDate=".date("Y-m-d H:i:s",$timeProcessed)."'>View Summary</a></div>";
   
    }

    public function type(){
        return $this->TypeId;
    }

    public function merge_form_compare($oldClient){
        if ($this->ClientId==$oldClient->ClientId){
            self::display_error("Can't Merge entry to itself.");           
            return;
        }
        $where= array("ClientId IN (".$this->ClientId.",".$oldClient->ClientId.")");
        $SQL="SELECT ClientId, Count(*) as C,SUM(`Gross`) as Total, MIN(Date) as DateEarliest, MAX(Date) as DateLatest FROM ".Payment::get_table_name()." WHERE ".implode(" AND ",$where)." Group BY ClientId";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $stats[$r->ClientId]=$r;
        }
        foreach($oldClient as $field=>$value){
            if ($value!=$this->$field){
                $changes[$field]=$value;
            }
        }
        ?><form method='post'>
        <input type='hidden' name='clientIds[]' value='<?php print esc_html($this->ClientId)?>'>
        <input type='hidden' name='clientIds[]' value='<?php print esc_html($oldClient->ClientId)?>'>
        <h2>The following changes are suggested</h2>
        <form method='post'>
        <table border='1'><tr>
            <th></th><th><?php print esc_attr($changes['Name'])?></th><th><?php print esc_attr($this->Name)?></th></tr>
            <th>Field</th><th>Client A</th><th>Client B</th></tr><?php
        foreach($changes as $field=>$value){
            if ($field=="MergedId") continue; //don't allow mergeing of merge IF it is the original client
            ?><tr><td><?php print esc_html($field)?></td>
            <td><input type="radio" name="<?php print esc_attr($field)?>" value="<?php print esc_attr($value)?>"<?php print !$this->$field?" checked":""?>><?php print esc_html($value)?></td>
            <td><input type="radio" name="<?php print esc_attr($field)?>" value="<?php print esc_attr($this->$field)?>"<?php print ($this->$field?" checked":"")?>><?php print esc_html($this->$field)?></td>
            </tr><?php                                    
        }
        ?><tr><td>Payment Details Will Merge</td><td><?php
        $thisStat=$stats[$oldClient->ClientId];
        print $thisStat->C." Payments $".number_format($thisStat->Total,2);
        print " ".substr($thisStat->DateEarliest,0,10).($thisStat->DateEarliest!=$thisStat->DateLatest?" to ".substr($thisStat->DateLatest,0,10):"");
        ?></td>
        <td><?php
        $thisStat=$stats[$this->ClientId];
        print $thisStat->C." Payments $".number_format($thisStat->Total,2);
        print " ".substr($thisStat->DateEarliest,0,10).($thisStat->DateEarliest!=$thisStat->DateLatest?" to ".substr($thisStat->DateLatest,0,10):"");
        ?>
        </td></tr>
        </table>
        <button type='submit' name='Function' value='MergeClient'>Merge Clients</button>
        </form><?php

    }

    public function view(){ ?>
        <div>
            <a href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$this->ClientId.'&edit=t')?>">Edit Client</a> | 
            <a href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$this->ClientId.'&f=AddPayment')?>">Add Payment</a>
        </div>
        <?php
        $this->var_view();
        $this->merge_form();
        ?>
        <h2>Payment Summary</h2>
        <div>Year End Receipt: <a href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$this->ClientId.'&f=YearReceipt&Year='.date("Y"))?>"><?php print date("Y")?></a> 
        | <a href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$this->ClientId)?>&f=YearReceipt&Year=<?php print date("Y")-1?>"><?php print date("Y")-1?></a> | <a href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$this->ClientId)?>&f=YearReceipt&Year=<?php print date("Y")-2?>"><?php print date("Y")-2?></a></div>
        <?php
         $totals=['Count'=>0,'Total'=>0];
        $SQL="SELECT  `Type`,	SUM(`Gross`) as Total,Count(*) as Count FROM ".Payment::get_table_name()." 
        WHERE ClientId='".$this->ClientId."'  Group BY `Type`";
        $results = self::db()->get_results($SQL);
        ?><table class="dp"><tr><th>Type</th><th>Count</th><th>Amount</th></tr><?php
        foreach ($results as $r){?>
            <tr><td><?php print esc_html($r->Type)?></td><td><?php print  esc_html($r->Count)?></td><td align=right><?php print number_format($r->Total,2)?></td></tr><?php
            $totals['Count']+=$r->Count;
            $totals['Total']+=$r->Total;
        }        
        if (sizeof($results)>1){?>
        <tfoot style="font-weight:bold;"><tr><td>Totals:</td><td><?php print $totals['Count']?></td><td align=right><?php print number_format($totals['Total'],2)?></td></tr></tfoot>
        <?php } ?></table>
        <h2>Payment List</h2>
		<?php
		$results=Payment::get(array("ClientId='".$this->ClientId."'"),"Date DESC");
		print Payment::show_results($results,"",["PaymentId","Date","DateDeposited","Name","Type","Gross","FromEmailAddress","CategoryId","Subject","Note","PaymentSource","TransactionID","TransactionType"]);		
    }

    
    static public function request_handler(){      
        if (self::input('table','post')=='client' && self::input('ClientId','post') && self::input('Function','post')=="Delete"){
            //check if any payments connected to this account or merged ids..
            $payments=Payment::get(array('ClientId='.self::input('ClientId','post')));
            if (sizeof($payments)>0){
                self::display_error("Can't delete Client #".self::input('ClientId','post').". There are ".sizeof($payments)." payment(s) attached to this.");           
                return false;
            }
            $clients=Payment::get(array('MergedId='.self::input('ClientId','post')));
            if (sizeof($clients)>0){
                self::display_error("Can't delete Client #".self::input('ClientId','post').". There are ".sizeof($clients)." clients merged to this entry.");                           
                return false;
            }else{
                $dSQL="DELETE FROM ".self::get_table_name()." WHERE `ClientId`='".self::input('ClientId','post')."'";
                self::db()->query($dSQL);
                self::display_notice("Deleted Client #".self::input('ClientId','post').".");                           
                return true;
            }


        }elseif (self::input('Function','post')=='MergeClient' && self::input('ClientId','post')){         
            $data=array();
            foreach(self::s()->fillable as $field){
                if (self::input($field,'post') && $field!='ClientId'){
                    $data[$field]=self::input($field,'post');
                }
            }
            if (sizeof($data)>0){
                ### Update Master Entry with Fields from merged details
                self::db()->update(self::s()->get_table(),$data,array('ClientId'=>self::input('ClientId','post')));
            }
            $mergeUpdate['MergedId']=self::input('ClientId','post');
            foreach(self::input('clientIds','post') as $oldId){
                if ($oldId!=self::input('ClientId','post')){
                    ### Set MergedId on Old Client entry
                    self::db()->update(self::s()->get_table(),$mergeUpdate,array('ClientId'=>$oldId));
                    ### Update all payments on old client to new self
                    $uSQL="UPDATE ".Payment::s()->get_table()." SET ClientId='".self::input('ClientId','post')."' WHERE ClientId='".$oldId."'";  
                    self::db()->query($uSQL);
                    self::display_notice("Client #<a href='?page=".self::input('page','get')."&ClientId=".$oldId."'>".$oldId."</a> merged to #<a href='?page=".self::input('page','get')."&ClientId=".self::input('ClientId','post')."'>".self::input('ClientId','post')."</a>");
                }
            }  
            $_GET['ClientId']=self::input('ClientId','post'); 

        }
        if (self::input('f','get')=="AddClient"){
            $client=new self;
            if (self::input('dsearch','request') && !$client->ClientId && !$client->Name){
                $client->Name=self::input('dsearch','request');
            }          
            print "<h2>Add Client</h2>";            
            $client->edit_form();           
            return true;
        }elseif(self::input('f','get')=="summary_list" && self::input('dt','get') && self::input('df','get')){            
            self::summary_list(array("Date BETWEEN '".self::input('df','get')." 00:00:00' AND '".self::input('dt','get')." 23:59:59'"),self::input('Year','get'));
            return true;
        }elseif(self::input('Function','post')=='MergeConfirm'){ 
            $clientA=self::find(self::input('MergeFrom','post'));
            $clientB=self::find(self::input('MergedId','post'));
            if (!$clientB->ClientId){
                 self::display_error("Client ".self::input('MergedId','post')." not found.");
                 return false;
            }else{
                $clientB->merge_form_compare($clientA);
            }
            return true;
        }elseif(self::input('Function','get')=='MergeConfirm'){ 
            $clientA=self::find(self::input('MergeFrom','get'));
            $clientB=self::find(self::input('MergedId','get'));
            if (!$clientB->ClientId){
                 self::display_error("Client ".self::input('MergedId','get')." not found.");
                 return false;
            }else{
                $clientB->merge_form_compare($clientA);
            }
            return true;
        }elseif (self::input('ClientId','get')){
            if (self::input('f','get')=="YearReceipt"){
                $client=self::find(self::input('ClientId','request'));
                $client->year_receipt_form(self::input('Year','get'));
                return true;
            }
            
            if (self::input('Function','post')=="Save" && self::input('table','post')=="client"){
                $client=new self(self::input_model('post'));
                if ($client->save()){			
                    self::display_notice("Client #".$client->show_field("ClientId")." saved.");
                }
                
            }
            $client=self::find(self::input('ClientId','request'));	
            ?>
            <div>
               <?php 
                $client->client_header();
                if (self::input('edit','request')){                  
                    $client->edit_form();
                }else{             
                    $client->view();                    
                }
            ?></div><?php            
            return true;
        }elseif (self::input('Function','post')=="Save" && self::input('table','post')=="client"){
            $client=new self(self::input_model('post'));
            if ($client->save()){			
                self::display_notice("Client #".$client->show_field("ClientId")." saved.");
                $client->view();
            }
            return true;
            
        }elseif(self::input('Function','post')=='MakeClientChanges'){            
            self::db()->show_errors();
            foreach(self::input('changes','post') as $line){
                $v=explode("||",$line); //$clientId."||".$field."||".$value
                $changes[$v[0]][$v[1]]=$v[2];
            }
            if (sizeof($changes)>0){
                foreach($changes as $clientId=>$change){
                    $ulinks[]='<a target="lookup" href="?page='.self::input('page','get').'&ClientId='.$clientId.'">'.$clientId.'</a>';
                    self::db()->update(self::get_table_name(),$change,array("ClientId"=>$clientId));

                }
                self::display_notice(sizeof($changes)." Entries Updated: ".implode(" | ",$ulinks));
            }

        }else{
            return false;
        }
    }
    function client_header(){?>
        <form method="get">
        <input type="hidden" name="page" value="<?php print self::input('page','get')?>"/>
        <div><a href="<?php print esc_url('?page='.self::input('page','get'))?>">Home</a> 
        <?php if (self::input('edit','request')){?> | <a href="<?php print esc_url('?page=onlineclasspayments-index&ClientId='.$this->ClientId)?>">View Client</a> <?php }?>
         | Client Search: <input id="clientSearch" name="dsearch" value=""> <button>Go</button></div>        
    </form>                
    <h1>Client Profile #<?php print esc_html(self::input('ClientId','request').' '.$this->Name)?></h1>
    <?php
    }
    function name_combine(){
        if (trim($this->Name2)){
            $name1=explode(" ",trim($this->Name));
            $name2=explode(" ",trim($this->Name2));
            if (end($name1)==end($name2)){ //if they share a last name, combine it.
                $return=[];
                for($i=0;$i<sizeof($name1)-1;$i++){
                    $return[]=$name1[$i];
                }
                $return[]="&";
                for($i=0;$i<sizeof($name2);$i++){
                    $return[]=$name2[$i];
                }
                return implode(" ",$return);
            }else{
                return $this->Name." & ".$this->Name2;
            }
        }else{
            return $this->Name;
        }
    }

    function mailing_address($seperator="<br>",$include_name=true,$settings=[]){
        $validate=isset($settings['AddressValidate']) && $settings['AddressValidate']?true:false;
        $address="";
        //name_check_individual(
        if ($this->Address1) $address.=($validate?self::name_check_individual($this->Address1):$this->Address1).$seperator;
        if ($this->Address2) $address.=($validate?self::name_check_individual($this->Address2):$this->Address2).$seperator;
        if ($this->City || $this->Region){ 
            $address.=$this->City." ";
            if ($validate && $this->Region){
                switch($this->Country){
                    case "US": $address.=self::REGION_US[$this->Region]?$this->Region:"<span style='background-color:yellow; font-weight:bold;'>".$this->Region."</span>"; break;
                    case "CA": $address.=self::REGION_CA[$this->Region]?$this->Region:"<span style='background-color:yellow; font-weight:bold;'>".$this->Region."</span>"; break;
                    default:  $address.=$this->Region; break;
                }              
            }else{
                $address.=$this->Region;
            }            
            $address.=" ".$this->PostalCode;
        }
        if ($validate && $this->Country){
            if (self::COUNTRIES[$this->Country]){
                $address.=" ".$this->Country; 
            }else{
                $address.=" <span style='background-color:yellow; font-weight:bold;'>".$this->Country."</span>"; 
            }
        }else{
            if (isset($settings['DefaultCountry']) && $settings['DefaultCountry']==$this->Country){}
            elseif($address) $address.=" ".$this->Country;   
        }
       
        if (($address&&$include_name) || isset($settings['NameOnlyOkay'])){
            $nameLine=$this->name_combine();
            $address=$nameLine.(trim($address)?$seperator.$address:"");
        }
        return trim($address);
    }

    function name_check(){        
        return self::name_check_individual($this->Name).($this->Name2?" & ".self::name_check_individual($this->Name2):"");
    }

    static function name_check_individual($name){
        $newName=self::ucfirst_fixer($name);
        // $names=explode(" ",str_replace("'"," ",$name));
        // $alert=false;
        // foreach ($names as $n){
        //     if (ucfirst($n)!=$n){
        //         $alert=true;
        //     }
        // }       
        if ( $newName!=$name) return "<span style='background-color:yellow;'>".$name."</span>";
        else return $name;
    }
   
    public function phone(){
        return $this->phone_format($this->Phone);
    }
    
    static function year_list($settings=[]){       
        if (!$settings['orderBy']) $settings['orderBy']="D.Name, D.Name2, YEAR(DT.Date)";
        $total=0;
        if (!$settings['where']) $settings['where']=[];
        $settings['where'][]="Status>=0";
        $settings['where'][]="Type>=1";        
        $SQL="Select D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`,D.TypeId,YEAR(DT.Date) as Year, COUNT(*) as payment_count, SUM(Gross)  as Total 
        FROM ".self::get_table_name()." D INNER JOIN ".Payment::get_table_name()." DT ON D.ClientId=DT.ClientId 
        WHERE ".implode(" AND ",$settings['where'])
        ." Group BY D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`,D.TypeId,YEAR(DT.Date) "
        .(sizeof($settings['having'])>0?" HAVING ".implode(" AND ",$settings['having']):"")
        ." Order BY ".$settings['orderBy'];
        //print "<pre>".$SQL."</pre>";
        $results = self::db()->get_results($SQL);
        $q=[];
        foreach($results as $r){
            $q['yearList'][$r->ClientId][$r->Year]+=$r->Total;
            if (!$q['clients'][$r->ClientId]) $q['clients'][$r->ClientId]=new self($r);
            $q['year'][$r->Year]+=$r->Total;
        }
        if ($q['year']) ksort($q['year']);

        $clientTypes=ClientType::list_array();       
        ?><table class="dp">
            <thead>
                <tr><th>Client</th><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Type</th>
            <?php
            foreach($q['year'] as $y=>$total){
                print "<th>".$y."</th>";
            }
            ?></tr>
            </thead>
            <tbody>
            <?php
            foreach($q['clients'] as $id=>$years){
                $client=$q['clients'][$id];
                ### code filter if amount is given, if amount given in any year then show this row.
                if ($settings['amount']){
                    $pass=false;
                    foreach($q['yearList'][$id] as $amount){
                        if ($amount>=$settings['amount']){
                            $pass=true;
                            break;
                        }
                    }
                }else{
                    $pass=true;
                }
                if ($pass){ 
                    $clientList[]=$client->ClientId;                
                    ?>  
                    <tr>
                        <td><?php print wp_kses_post($client->show_field('ClientId'))?></td>
                        <td><?php print esc_html($client->name_combine())?></td>
                        <td><?php print wp_kses_post($client->display_email())?></td>    
                        <td><?php print esc_html($client->phone())?></td> 
                        <td><?php print esc_html($client->mailing_address(', ',false))?></td>
                        <td><?php print esc_html($clientTypes[$client->TypeId])?></td>
                        <?php
                        foreach($q['year'] as $y=>$total){
                            $q['total'][$y]+=$q['yearList'][$id][$y];
                            print "<td style='text-align:right;'>".($q['yearList'][$id][$y]?number_format($q['yearList'][$id][$y],2):"")."</td>";
                        }
                        ?></tr>        
                    </tr><?php
                }
           
            }?>
            </tbody>
            <tfoot><tr><td></td><td></td><td></td><td></td><td></td><td>Totals:</td><?php
            foreach($q['year'] as $y=>$total){
                print "<td style='text-align:right;'>".($q['total'][$y]?number_format($q['total'][$y],2):"")."</td>";
            }
            ?></tr></tfoot>
        </table>
        <?php 
        if (sizeof($clientList)>0) print "<div>Client Ids: ".implode(",",$clientList) ."</div>";   
    }

    static function summary_list($where=[],$year=null,$settings=[]){
        if (!$settings['orderBy']) $settings['orderBy']="SUM(Gross) DESC,COUNT(*) DESC";
        $total=0;
        $where[]="Status>=0";
        $where[]="Type>=1";
        $clientTypes=ClientType::list_array();
        $SQL="Select D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`, D.TypeId,COUNT(*) as payment_count, SUM(Gross)  as Total , MIN(DT.Date) as DateEarliest, MAX(DT.Date) as DateLatest 
        FROM ".self::get_table_name()." D INNER JOIN ".Payment::get_table_name()." DT ON D.ClientId=DT.ClientId 
        WHERE ".implode(" AND ",$where)
        ." Group BY D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`, D.TypeId ";
        if (isset($settings['having'])){
            if (is_array($settings['having'])){
                if (sizeof($settings['having'])>0){
                    $SQL.=" HAVING ".implode(" AND ",$settings['having']);
                }
            }elseif($settings['having']){
                $SQL.=" HAVING ".$settings['having'];
            }
        }
        $SQL.=" Order BY ".$settings['orderBy'];

        $results = self::db()->get_results($SQL);
        ?><div><a href="<?php print esc_url('?page='.self::input('page','get'))?>">Return</a></div><form method=post><input type="hidden" name="Year" value="<?php print esc_attr($year)?>"/>
        <table class="dp">
            <thead>
                <tr><th>Client</th><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Type</th><th>Count</th><th>Amount</th><th>First Payment</th><th>Last Payment</th></tr>
            </thead>
            <tbody><?php
        foreach ($results as $r){
            $client=new self($r);
            ?>
            <tr>
                <td><a target="client" href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$r->ClientId)?>"><?php print esc_html($r->ClientId)?></a></td>
                <td><?php print esc_html($client->name_check())?></td>
                <td><?php print wp_kses_post($client->display_email())?></td>    
                <td><?php print esc_html($client->phone())?></td> 
                <td><?php print esc_html($client->mailing_address(', ',false,array('AddressValidate'=>true)))?></td> 
                <td><?php print esc_html($clientTypes[$client->TypeId])?></td>       
                <td><?php print esc_html($r->payment_count)?></td>
                <td><?php print number_format($r->Total,2)?></td>
                <td><?php print date("Y-m-d", strtotime($r->DateEarliest))?></td>
                <td><?php print date("Y-m-d", strtotime($r->DateLatest))?></td>
            </tr><?php
            $total+=$r->Total;
        }?>
        </tbody>
        <tfoot>
        <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td style="text-align:right;"><?php print number_format($total,2)?></td><td></td><td></td></tr>
        </tfoot>
        </table>
                  
        <?php
        return;
    }

    static function summary_list_year($year){       
        ### Find what receipt haven't been sent yet
        $SQL="SELECT `ClientId`, `Type`, `Address`, `DateSent`,`Subject` FROM ".PaymentReceipt::get_table_name()." WHERE `KeyType`='YearEnd' AND `KeyId`='".$year."'";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $receipts[$r->ClientId][]=new PaymentReceipt($r);
        }
        ## Find NOT Tax Deductible entries
        $SQL="Select D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City,Region,PostalCode,Country, COUNT(*) as payment_count, SUM(Gross) as Total FROM ".self::get_table_name()." D INNER JOIN ".Payment::get_table_name()." DT ON D.ClientId=DT.ClientId WHERE YEAR(Date)='".$year."' AND  Status>=0 AND Type>=0 AND DT.TransactionType=1 Group BY D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City Order BY COUNT(*) DESC, SUM(Gross) DESC";  
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $NotTaxDeductible[$r->ClientId]=$r;
        }

        $SQL="Select D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,Address1,Address2,City,Region,PostalCode,Country, D.TypeId,COUNT(*) as payment_count, SUM(Gross) as Total FROM ".self::get_table_name()." D INNER JOIN ".Payment::get_table_name()." DT ON D.ClientId=DT.ClientId 
        WHERE YEAR(Date)='".$year."' AND  Status>=0 AND Type>=0 Group BY D.ClientId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City,Region,Country, D.TypeId Order BY COUNT(*) DESC, SUM(Gross) DESC";       
        $results = self::db()->get_results($SQL);
        ?><form method="post"><input type="hidden" name="Year" value="<?php print esc_attr($year);?>"/>
        <table class="dp"><tr><th>Client</th><th>Name</th><th>Email</th><th>Mailing</th><th>Count</th><th>Amount</th><th>Preview</th><th><input type="checkbox" checked onClick="toggleChecked(this,'emails[]');"/>
        <script>
            function toggleChecked(source,name){                
                checkboxes = document.getElementsByName(name);
                for(var i=0, n=checkboxes.length;i<n;i++) {
                    checkboxes[i].checked = source.checked;
                }                    
            }
        </script> E-mail</th><th><input type="checkbox" checked onClick="toggleChecked(this,'pdf[]');")/> PDF</th><th>Sent</th><th>Not Tax Deductible</th><th>Client Total</th></tr><?php
        $total=0;
        foreach ($results as $r){
            $client=new self($r);
            $clientTotal=$r->Total;
            ?>
            <tr><td><a target="client" href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$r->ClientId)?>"><?php print esc_html($r->ClientId)?></a> 
            <a target="client" href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$r->ClientId)?>&edit=t">edit</a></td>
            <td><?php print $client->name_check()?></td>
            <td><?php print wp_kses_post($client->display_email())?></td> 
            <td><?php print $client->mailing_address("<br>",false)?></td>             
            <td><?php print esc_html($r->payment_count)?></td>
            <td align=right><?php print number_format($r->Total,2)?></td><td><a target="client" href="<?php print esc_url('?page='.self::input('page','get').'&ClientId='.$r->ClientId.'&f=YearReceipt&Year='.$year)?>">Receipt</a></td>
            <td><?php
             if (filter_var($r->Email, FILTER_VALIDATE_EMAIL) && $r->EmailStatus>=0) {
                ?><input name="emails[]" type="checkbox" value="<?php print esc_attr($r->ClientId)?>" <?php print ($receipts[$r->ClientId] ?"":" checked")?>/><?php
             }
            ?></td>
            <td><?php
             //if ($r->Address1 && $r->City) {
                ?><input name="pdf[]" type="checkbox" value="<?php print esc_attr($r->ClientId)?>" <?php print ($receipts[$r->ClientId]?"":" checked")?>/><?php
             //}
            ?></td><td><?php
            print PaymentReceipt::displayReceipts($receipts[$r->ClientId]);
            ?></td>
            <td><?php 
                 if($NotTaxDeductible[$r->ClientId]){
                    print "Count: ".$NotTaxDeductible[$r->ClientId]->payment_count." Total: ".number_format($NotTaxDeductible[$r->ClientId]->Total,2);
                    $clientTotal+=$NotTaxDeductible[$r->ClientId]->Total;
                 }?>
             </td>
             <td align=right><?php print number_format($clientTotal,2);?></td>
            </tr><?php
            $total+=$clientTotal;
        }?><tr><td></td><td></td><td></td><td></td><td style="text-align:right;"><?php print number_format($total,2)?></td><td></td><td></td><td></td></table>
        Limit: <Input type="number" name="limit" value="<?php print self::input('limit')?>" style="width:50px;"/>
        <button type="submit" name="Function" value="SendYearEndEmail">Send Year End E-mails</button>
        <button type="submit" name="Function" value="SendYearEndPdf">Send Year End Pdf</button> <label><input type="checkbox" name="blankBack" value="t"> Print Blank Back</label>
        <label><input type="checkbox" name="preview" value="t"> Preview Only - Don't mark .pdf as sent</label>
        <div>
            <button name="Function" value="ExportClientList">Export Client List</button>
            <button name="Function" value="PrintYearEndLabels">Print Labels</button>
            Labels Start At: <strong>Column:</strong> (1-3) &#8594; <input name="col" type="number" value="1"  min="1" max="3" /> &#8595; <strong>Row:</strong> (1-10)<input name="row" type="number" value="1" min="1" max="10"   />
        <em>Designed for 1"x2.625" address label sheets -30 Labels total on 8.5"x11" Paper. When printing, make sure there is NO printer scaling.</div>
        </form>
        <?php
        return;
    }

    function receipt_table_generate($payments){
        if (sizeof($payments)==0) return "";
        $total=0;
        $ReceiptTable='<table border="1" cellpadding="4"><tr><th>Date</th><th>Reference</th><th>Amount</th></tr>';
        foreach($payments as $r){
            $lastCurrency=$r->Currency;
            $total+=$r->Gross; 
            $ReceiptTable.="<tr><td>".date("F j, Y",strtotime($r->Date))."</td><td>";
            $reference="";
            switch($r->PaymentSource){
                case 1:
                    $reference="Check".(is_numeric($r->TransactionID)?" #".$r->TransactionID:"");$ReceiptTable.=$r->Subject?" ".$r->Subject:"";
                    break;
                case 5:
                    $reference="Paypal".($r->Subject?": ".$r->Subject:"");
                    break;
                case 6: 
                    $reference="ACH/Wire".($r->Subject?": ".$r->Subject:($r->TransactionID?" #".$r->TransactionID:""));
                    break;
                default:  $reference= $r->Subject;
                break;                  
            } 
            if (!$r->Subject && $r->CategoryId  && $r->CategoryId<>0) $reference.=($reference?" - ":"").$r->show_field("CategoryId",['showId'=>false]) ;
            if ($r->TransactionType==3){
                $reference.=" (IRA QCD)";
            }
            $ReceiptTable.=$reference."</td><td align=\"right\">".trim(number_format($r->Gross,2)." ".$r->Currency).'</td></tr>';            
        }
        $ReceiptTable.="<tr><td colspan=\"2\"><strong>Total:</strong></td><td align=\"right\"><strong>".trim(number_format($total,2)." ".$lastCurrency)."</strong></td></tr></table>";
        return $ReceiptTable;
    }

    function year_receipt_email($year){
        $this->emailBuilder=new \stdClass();
        $page = PaymentTemplate::get_by_name('client-receiptyear'); 
        if (!$page){ ### Make the template page if it doesn't exist.
            self::make_receipt_year_template();
            $page = PaymentTemplate::get_by_name('client-receiptyear');  
            self::display_notice("Page /client-receiptyear created. <a target='edit' href='?page=onlineclasspayments-settings&tab=email&PaymentTemplateId=".$page->ID."&edit=t'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
        $nteTotal=$total=0;
        $taxDeductible=[];
        $NotTaxDeductible=[];
        $payments=Payment::get(array("ClientId=".$this->ClientId,"YEAR(Date)='".$year."'"),'Date');
        foreach($payments as $r){
            if (in_array($r->TransactionType,[0,3])){
                $taxDeductible[]=$r;
                $total+=$r->Gross;                
            }elseif($r->TransactionType==1){
                $NotTaxDeductible[]=$r;
                $nteTotal+=$r->Gross;
            }
        }
        $ReceiptTable="";
        if (sizeof($taxDeductible)>0){
            $ReceiptTable.=$this->receipt_table_generate($taxDeductible);
        }

        if (sizeof($NotTaxDeductible)>0){
            $plural=sizeof($NotTaxDeductible)==1?"":"s";
            
            $ReceiptTable.="<p>Additionally the following gift".$plural."/grant".$plural." totaling <strong>$".number_format($nteTotal,2)."</strong> ".($plural=="s"?"were":"was")." given for which you may have already received a tax deduction. Consult a tax professional on whether these gifts can be claimed:</p>";
            
            $ReceiptTable.=$this->receipt_table_generate($NotTaxDeductible);
        }
        if ($ReceiptTable=="") $ReceiptTable="<div><em>>";
        
        $organization=get_option( 'payment_Organization');
        if (!$organization) $organization=get_bloginfo('name');
        $subject=$page->post_title;
        $body=$page->post_content;

        ### replace custom variables.
        foreach(CustomVariables::variables as $var){
            if (substr($var,0,strlen("Quickbooks"))=="Quickbooks") continue;
            if (substr($var,0,strlen("Paypal"))=="Paypal") continue;
            $body=str_replace("##".$var."##", get_option( CustomVariables::base.'_'.$var),$body);
            $subject=str_replace("##".$var."##",get_option( CustomVariables::base.'_'.$var),$subject);                   
        }

        ### generated variables
        $body=str_replace("##Name##",$this->name_combine(),$body);
        $body=str_replace("##Year##",$year,$body);
        $body=str_replace("##PaymentTotal##","$".number_format($total,2),$body);
        $body=str_replace("<p>##ReceiptTable##</p>",$ReceiptTable,$body);
        $body=str_replace("##ReceiptTable##",$ReceiptTable,$body);
        $address=$this->mailing_address();
        if (!$address){ //remove P
            $body=str_replace("<p>##Address##</p>",$address,$body);
        }
        $body=str_replace("##Address##",$address,$body);

        $body=str_replace("##Date##",date("F j, Y"),$body);

        $body=str_replace("<!-- wp:paragraph -->",'',$body);
        $body=str_replace("<!-- /wp:paragraph -->",'',$body);
        $subject=trim(str_replace("##Year##",$year,$subject));
        $subject=trim(str_replace("##Organization##",$organization,$subject));
        $this->emailBuilder->subject=$subject;
        $this->emailBuilder->body=$body; 
        $this->emailBuilder->fontsize=$page->post_excerpt_fontsize;
        $this->emailBuilder->margin=$page->post_excerpt_margin; 
        
        $variableNotFilledOut=array();
        $pageHashes=explode("##",$body); 
        $c=0;
        foreach($pageHashes as $r){
            if ($c%2==1){
                if (strlen($r)<16){
                    $variableNotFilledOut[$r]=1;
                }
            }
            $c++;
        }
        if (sizeof($variableNotFilledOut)>0){
            self::display_error("The Following Variables need manually changed:<ul><li>##".implode("##</li><li>##",array_keys($variableNotFilledOut))."##</li></ul> Please <a target='pdf' href='?page=onlineclasspayments-settings&tab=email&PaymentTemplateId=".$this->emailBuilder->pageID."&edit=t'>correct template</a>.");
        }
    }

    function year_receipt_form($year){       
        $this->year_receipt_email($year);  
        $form="";      
        if (self::input('Function','post')=="SendYearReceipt" && self::input('Email','post')){
            $html=self::input('customMessage','post')?stripslashes_deep(self::input('customMessage','post')) :$this->emailBuilder->body;
            if (wp_mail($this->email_string_to_array(self::input('Email','post')), $this->emailBuilder->subject,$html,array('Content-Type: text/html; charset=UTF-8'))){ 
                $form.="<div class=\"notice notice-success is-dismissible\">E-mail sent to ".self::input('Email','post')."</div>";
                $dr=new PaymentReceipt(array("ClientId"=>$this->ClientId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"e","Address"=>self::input('Email','post'),"Subject"=>$this->emailBuilder->subject,"Content"=>$html,"DateSent"=>date("Y-m-d H:i:s")));
                $dr->save();
                self::display_notice($year." Year End Receipt Sent to: ".self::input('Email','post'));
            }
        }        
        $receipts=PaymentReceipt::get(array("ClientId='".$this->ClientId."'","`KeyType`='YearEnd'","`KeyId`='".$year."'"));
        $lastReceiptKey=is_array($receipts)?sizeof($receipts)-1:0;        
        
        
        $homeLinks="<a href='?page=".self::input('page','get')."'>Home</a> | <a href='?page=".self::input('page','get')."&ClientId=".$this->ClientId."'>Return to Client Overview</a>";

        if (self::input('reset','request')){
            $bodyContent=$this->emailBuilder->body;
        }else{
            $bodyContent=$receipts[$lastReceiptKey]->Content?$receipts[$lastReceiptKey]->Content:$this->emailBuilder->body;
            if ($receipts &&$bodyContent!=$this->emailBuilder->body){
                $homeLinks.= "| <a href='?page=onlineclasspayments-index&ClientId=".self::input('ClientId','request')."&f=YearReceipt&Year=".self::input('Year')."&reset=t'>Update/Reset Letter with latest information</a>";
            }
        }


        print "<div class='no-print'>".wp_kses_post($homeLinks)."</div>";
        print '<form method="post">';
        print "<h2>".esc_html($this->emailBuilder->subject)."</h2>";             
       

        wp_editor($bodyContent, 'customMessage',array("media_buttons" => false,"wpautop"=>false));

        
        ### Form View
        print '<div class="no-print"><hr>Send Receipt to: <input type="text" name="Email" value="'.esc_html(self::input('Email','post')?self::input('Email','post'):$this->Email).'"/><button type="submit" name="Function" value="SendYearReceipt">Send E-mail</button>
        <button type="submit" name="Function" value="YearEndReceiptPdf">Generate PDF</button>';
       
        print PaymentReceipt::show_results($receipts);
        print '</form>';
        if ($this->emailBuilder->pageID){
            print '<div><a target="pdf" href="'.esc_url('?page=onlineclasspayments-settings&tab=email&PaymentTemplateId='.$this->emailBuilder->pageID.'&edit=t').'">Edit Template</a> | <a href="'.esc_url('?page=onlineclasspayments-reports&ClientId='.$this->ClientId.'&f=YearReceipt&Year='.$year.'&resetLetter=t').'">Reset Letter</a></div>';      
        }

        return true;
    }

    function year_receipt_pdf($year,$customMessage=null){
        if (!Payment::pdf_class_check()) return false;        
        $this->year_receipt_email($year);
        ob_clean();
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $margin=($this->emailBuilder->margin?$this->emailBuilder->margin:.25)*72;
        $pdf->SetMargins($margin,$margin,$margin);
        $html="<h2>".$this->emailBuilder->subject."</h2>".$customMessage?$customMessage:$this->emailBuilder->body;
        $pdf->SetFont('helvetica', '', $this->emailBuilder->fontsize?$this->emailBuilder->fontsize:10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');  
        $file=$this->receipt_file_info($year);    
        

        $dr=new PaymentReceipt(array("ClientId"=>$this->ClientId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"m","Address"=>$this->mailing_address(),"Subject"=>$this->emailBuilder->subject,"Content"=>$html,"DateSent"=>date("Y-m-d H:i:s")));
		$dr->save();  
        if ($pdf->Output($file, 'D')){
            return true;
        }else{
            return false;
        }
    }

    static function YearEndReceiptMultiple($year,$clientIdPost,$limit,$blankBlack=false,$logReceipt=true){
        if (!$limit) $limit=1000;
        if (sizeof($clientIdPost)<$limit) $limit=sizeof($clientIdPost);
        for($i=0;$i<$limit;$i++){
            $clientIds[]=$clientIdPost[$i];
        }
        if (sizeof($clientIds)>0){
            $type=Payment::pdf_class_check();
            if ($type!='tcpdf'){
                self::display_error("TCPDF is required to generate this PDF.");
                wp_die();
            } 
            $clientList=self::get(array("ClientId IN ('".implode("','",$clientIds)."')"));
            ob_clean();
            $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);            
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false); 				
            foreach ($clientList as $client){
                $client->year_receipt_email($year);
                $pdf->AddPage();
                $margin=($client->emailBuilder->margin?$client->emailBuilder->margin:.25)*72;
                $pdf->SetMargins($margin,$margin,$margin);
                $pdf->SetFont('helvetica', '', $client->emailBuilder->fontsize?$client->emailBuilder->fontsize:12);                
                $pdf->writeHTML("<h2>".$client->emailBuilder->subject."</h2>".$client->emailBuilder->body, true, false, true, false, '');
                if ($blankBlack && $pdf->PageNo()%2==1){ //add page number check
                    $pdf->AddPage();
                }
                if ($logReceipt){
                    $dr=new PaymentReceipt(array("ClientId"=>$client->ClientId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"m","Address"=>$client->mailing_address(),"Subject"=>$client->emailBuilder->subject,"Content"=>"<h2>".$client->emailBuilder->subject."</h2>".$client->emailBuilder->body,"DateSent"=>date("Y-m-d H:i:s")));                            
                    $dr->save();
                }
            }                    
            $pdf->Output('YearEndReceipts'.$year.'.pdf', 'D');
            return true;
        }
    }

    static function YearEndLabels($year,$clientIdPost,$col_start=1,$row_start=1,$limit=100000){
        if (sizeof($clientIdPost)<$limit) $limit=sizeof($clientIdPost);
        $type=Payment::pdf_class_check();
        if ($type!='tcpdf'){
            self::display_error("TCPDF is required to generate this PDF.");
            wp_die();
        }     

        $clients=self::get(["ClientId IN ('".implode("','",$clientIdPost)."')"],"",['key'=>true]);        
        $a=[];
        $defaultCountry=CustomVariables::get_option("DefaultCountry");       
        foreach($clientIdPost as $id){
            if ($clients[$id]){
                $address=$clients[$id]->mailing_address("\n",true,['DefaultCountry'=>$defaultCountry]);
                if(!$address) $address=$clients[$id]->name_combine();
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
         /// $pdf_tmp->AddPage();
        // $pdf_tmp->SetCellPadding($pad);
        $pdf->SetCellPadding($pad);
        $pdf->SetAutoPageBreak(true);
        $pdf->SetMargins($margin['x'],$margin['y'],$margin['x']);
        // set document information
        $pdf->SetCreator('Client-Press Plugin');
        $pdf->SetAuthor('Client-Press');
        $pdf->SetTitle($year.'Year End Labels');	
        //$pdf->setCellHeightRatio(1.1);       
        $starti=($col_start>0?($col_start-1)%3:0)+($row_start>0?3*floor($row_start-1):0);
        $border=0; $j=0;
        for ($i=$starti;$i<sizeof($a)+$starti;$i++){
            $col=$i%3;
            $row=floor($i/3)%10;
            if ($i%30==0 && $j!=0){ $pdf->AddPage();}
            //$h=shrinkletters(2.625*$dpi,$dpi,$a[$j],12); //size cell			
            $pdf->MultiCell(2.625*$dpi,1*$dpi,$a[$j],$border,"L",0,0,$margin['x']+$col*2.75*$dpi,$margin['y']+$row*1*$dpi,true);
            $j++;		
        }	
        $pdf->Output("OnlineClassPaymentsYearEndLabels".$year.".pdf", 'D');

    }


    function receipt_file_info($year){            
        return substr(str_replace(" ","",get_bloginfo('name')),0,12)."-YearEndReceipt-D".$this->ClientId.'-'.$year.'.pdf';
    }

    static function autocomplete($query,$orderby=null){       
        $searchText = strtoupper($query);
        $where= array("(UPPER(Name) LIKE '%".$searchText."%' 
        OR UPPER(Name2)  LIKE '%".$searchText."%'
        OR UPPER(Email) LIKE '%".$searchText."%'
        OR UPPER(Phone) LIKE '%".$searchText."%')"
        ,"(MergedId =0 OR MergedId IS NULL)");
        //,Name2
		$SQL="SELECT ClientId,Name, Name2, Email, Phone FROM ".self::s()->get_table()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"").($orderby?" ORDER BY ".$orderby:"")." LIMIT 10";

		$all=self::db()->get_results($SQL);
        print wp_json_encode($all);
        exit();
        //wp_die(); 

		// foreach($all as $r){
        //     $return[]=$r->Name;
        // }
        // print wp_json_encode($return);
        // wp_die(); 
      
    }

    static function make_receipt_year_template(){
        $page = PaymentTemplate::get_by_name('client-receiptyear');  
        if (!$page){
            $tempLoc=onlineclasspayments_plugin_base_dir()."/resources/template_default_receipt_year.html";   
            $t=new PaymentTemplate();          
            $t->post_content=file_get_contents($tempLoc);            
            $t->post_title='##Organization## ##Year## Year End Receipts';
            $t->post_name='client-receiptyear';
            $t->post_excerpt='{"fontsize":"10","margin":".2"}';
            $t->save();
            return $t;
        }
    }

    static function find_duplicates_to_merge(){
        ### function to track down duplicates by email. Helpful for cleaningup DB if a script goes awry.
        $SQL="SELECT DN.* FROM ".self::get_table_name()." DN LEFT JOIN ".Payment::get_table_name()." DT ON DN.`ClientId`=DT.ClientID Where PaymentId IS NULL and MergedId=0;";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $stats['e'][strtolower($r->Email)]=$r;
            $stats['d'][$r->ClientId]=$r;
        }
        $SQL="Select * From ".self::get_table_name()." WHERE Email IN ('".implode("','",array_keys($stats['e']))."') AND ClientId NOT IN (".implode(',',array_keys($stats['d'])).")";
        //print $SQL;
        $results = self::db()->get_results($SQL);       
        ?><h3>Merger list</h3><table><?php
        
        foreach ($results as $r){
            $match= $stats['e'][strtolower($r->Email)];
            if ($match->Address1 && !$r->Address1){            
                ?><tr>
                    <td><a target='match' href="<?php print esc_url('?page=onlineclasspayments-index&ClientId='.$r->ClientId)?>"><?php print esc_html($r->ClientId)?></a> - <?php print  esc_html($r->Name)?></td><td><?php print esc_html($r->Address1)?></td>
                    <td><a target='match' href="<?php print esc_url('?page=onlineclasspayments-index&ClientId='.$match->ClientId)?>"><?php print esc_html($match->ClientId)?></a> -<?php print  esc_html($match->Name)?></td><td><?php print  esc_html($match->Address1)?></td>
                    <td><a target='match' href='<?php print esc_url('?page=onlineclasspayments-index&Function=MergeConfirm&MergeFrom='.$match->ClientId.'&MergedId='.$r->ClientId)?>'><-Merge</a></td></tr><?php
            }
            $current[$r->ClientId]=$r;
            $match->ClientId=$r->ClientId;
            $match->email=strtolower($match->email);
            $new[$r->ClientId]=$match;

        }
        ?></table>
        <?php        
        self::client_update_suggestion($current,$new);
    }

    // static function get_email_list(){
    //     $SQL="Select D.ClientId, D.Name, D.Name2,`Email`,COUNT(*) as payment_count, SUM(Gross) as Total,DATE(MIN(DT.`Date`)) as FirstPayment, DATE(MAX(DT.`Date`)) as LastPayment
    //     FROM ".self::get_table_name()." D INNER JOIN ".Payment::get_table_name()." DT ON D.ClientId=DT.ClientId 
    //     WHERE D.Email<>'' AND D.EmailStatus=1 AND D.MergedId=0        
    //     Group BY D.ClientId, D.Name, D.Name2,`Email` Order BY D.Name";
    //     $results = self::db()->get_results($SQL);
    //     $fp = fopen(onlineclasspayments_plugin_base_dir()."/resources/email_list.csv", 'w');
    //     fputcsv($fp, array_keys((array)$results[0]));//write first line with field names
    //     foreach ($results as $r){
    //         fputcsv($fp, (array)$r);
    //     }
    //     fclose($fp);
    // }

    static function get_mail_list($where=[]){
        $SQL="Select D.ClientId, D.Name, D.Name2, '' as NameCombined,D.Email,`Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`,D.AddressStatus,COUNT(*) as payment_count, SUM(Gross) as Total,DATE(MIN(DT.`Date`)) as FirstPayment, DATE(MAX(DT.`Date`)) as LastPayment
        FROM ".self::get_table_name()." D INNER JOIN ".Payment::get_table_name()." DT ON D.ClientId=DT.ClientId 
        WHERE ".(sizeof($where)>0?implode(" AND ",$where):" 1 ")."    
        Group BY D.ClientId, D.Name, D.Name2,`Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`,D.AddressStatus Order BY D.Name";   
        $results = self::db()->get_results($SQL);
        Payment::resultToCSV($results,array('name'=>'Clients','namecombine'=>true));       
    }

    static function merge_suggestions(){ //similar to find duplicates to merge... probably can consolidate
        $matchField=['Name','Name2','Email','Phone','Address1'];
        $SQL="Select D.ClientId, D.Name, D.Name2,D.Email,D.Phone,D.Address1,MergedId, COUNT(DT.PaymentId) as payment_count
        FROM ".self::get_table_name()." D LEFT JOIN ".Payment::get_table_name()." DT ON D.ClientId=DT.ClientId
        Group BY D.ClientId, D.Name, D.Name2,D.Email,D.Phone,D.Address1,MergedId Order BY  D.ClientId";
        $results = self::db()->get_results($SQL);
        $show=false;
        $merge=[];
        $cache=[];
        foreach ($results as $r){
            $clients[$r->ClientId]=$r;
            if ($r->MergedId>0){
                if ($r->payment_count>0){ 
                    $merge[$r->ClientId]=$r->MergedId;                   
                }
            }else{
                foreach($matchField as $field){
                    if (trim($r->$field)){
                        $val=preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($r->$field));
                        if (!$cache[$val] || !in_array($r->ClientId,$cache[$val])) $cache[$val][]=$r->ClientId;
                        if ($cache[$val] && sizeof($cache[$val])>1) $show=true;
                        if ($field=="Name"){
                            $nameParts=explode(" ",strtolower(trim(str_replace(" &","",$r->Name))));
                            if (sizeof($nameParts)>2){                                
                                $val=preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($nameParts[0].$nameParts[sizeof($nameParts)-1]));
                                if (!$cache[$val] || !in_array($r->ClientId,$cache[$val])) $cache[$val][]=$r->ClientId;
                                $val=preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($nameParts[1].$nameParts[sizeof($nameParts)-1]));
                                if (!$cache[$val] || !in_array($r->ClientId,$cache[$val])) $cache[$val][]=$r->ClientId;
                            }
                            
                        }
                    }
                }              
            }            
        }
        if (sizeof($merge)>0) $show=true;
        if ($show){
            ?><h2>Entries Found to Merge</h2>
            <table border=1><th>Client</th><th>Merge To</th></tr><?php
            foreach($merge as $from=>$to){
                print '<tr><td>';              
                print '<div><a target="client" href="?page=onlineclasspayments-index&ClientId='.$clients[$from]->ClientId.'">'.$clients[$from]->ClientId.'</a> '.$clients[$from]->Name.' (merged id: '.$clients[$from]->MergedId.') <a target="client" href="?page=onlineclasspayments-index&Function=MergeConfirm&MergeFrom='.$clients[$from]->ClientId.'&MergedId='.$clients[$to]->ClientId.'">Merge To -></a></div>';   
                          
                print '</td><td><a target="client" href="?page=onlineclasspayments-index&ClientId='.$clients[$to]->ClientId.'">'.$clients[$to]->ClientId.'</a> '.$clients[$to]->Name."</td></tr>";
            }
            $displayed=[];            
            foreach($cache as $key=>$a){
                if ($displayed[$clients[$a[$i]]->ClientId][$clients[$a[0]]->ClientId]) continue;
                if (sizeof($a)>1){             
                    print '<tr><td>';
                    for($i=1;$i<sizeof($a);$i++){
                        print '<div><a target="client" href="?page=onlineclasspayments-index&ClientId='.$clients[$a[$i]]->ClientId.'">'.$clients[$a[$i]]->ClientId.'</a> '.$clients[$a[$i]]->Name.($clients[$a[$i]]->Name2?" & ".$clients[$a[$i]]->Name2:"").' <a target="client" href="?page=onlineclasspayments-index&Function=MergeConfirm&MergeFrom='.$clients[$a[$i]]->ClientId.'&MergedId='.$clients[$a[0]]->ClientId.'">Merge To -></a></div>';   
                    }                 
                    print '</td><td><a target="client" href="?page=onlineclasspayments-index&ClientId='.$clients[$a[0]]->ClientId.'">'.$clients[$a[0]]->ClientId.'</a> '.$clients[$a[0]]->Name.($clients[$a[0]]->Name2?" & ".$clients[$a[0]]->Name2:"")."</td></tr>";
                    $displayed[$clients[$a[$i]]->ClientId][$clients[$a[0]]->ClientId]++;
                }
            }
            ?></table><?php
        }
    }

}