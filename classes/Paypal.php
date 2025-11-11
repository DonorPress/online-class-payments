<?php
namespace OnlineClassPayments;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Paypal extends ModelLite{
    var $token;
    var $url;
    var $error;

    public function __construct(){
        
    }

    public function get_token(){
        $this->destroy_session();        
        //unset($_SESSION['wp_paypal_access_token'],$_SESSION['wp_paypal_access_token_expires']);
        //Paypal token is cached as a SESSION variable to avoid the need to request a token multiple times.
        if($this->token){ //current classes token trumps anything stored in session. Not abosolutely necessary to do this.
        }elseif ($_SESSION['wp_paypal_access_token'] && date("Y-m-d H:i:s")<$_SESSION['wp_paypal_access_token_expires']){
            $this->token=$_SESSION['wp_paypal_access_token'];       
        }else{
            $this->destroy_session();
        }

        if ($this->token) return $this->token;
        $clientId=CustomVariables::get_option('PaypalClientId',true);
        $clientSecret=CustomVariables::get_option('PaypalSecret',true);        
        $args = array(
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
            'sslverify'       => true,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($clientId.':'.$clientSecret),
            ),
            'body' => array(
                'grant_type'=>'client_credentials'
            )            
        );
        $response = wp_remote_post( $this->get_url().'oauth2/token', $args,$clientId.':'.$clientSecret);
        if ( is_wp_error( $response ) ) {
            $this->error="Error from ".$this->get_url()."oauth2/token: ". $response->get_error_message();
            self::display_error($this->error);           
        }else {
            $json = json_decode($response['body']);
            if ($json->error){              
                $this->error="<strong>".$json->error.":</strong> ".$json->error_description;
                if ($json->error=="invalid_client"){
                    $this->error.=". Check your PaypalClientId and Paypal Secret. You may have to <a target='paypaltoken' href='https://developer.paypal.com/dashboard/applications/live'>create a new one here</a>. Once created, make sure it is <a target='paypaltoken' href='?page=onlineclasspayments-settings'>set here</a>.";
                }
                self::display_error($this->error);
            }else{
                $_SESSION['wp_paypal_access_token']=$json->access_token;
                $_SESSION['wp_paypal_access_token_expires']=date("Y-m-d H:i:s",strtotime("+".($json->expires_in-30)." seconds")); //30 seconds removed to avoid timeouts on longer queries that might be stacked.           
                $this->token =$json->access_token;
                return $this->token;
            }    
        }       
        return false;
    }    

    public function destroy_session(){
        unset($_SESSION['wp_paypal_access_token'],$_SESSION['wp_paypal_access_token_expires']);
    }

    public function get_url(){
        if (!$this->url) $this->url=CustomVariables::get_option('PaypalUrl',true);
        if (!$this->url) $this->url="https://api-m.paypal.com/v1/"; 
        return $this->url;       
    }

    public function get_transactions_date_range($start_date,$end_date=null){
        //API only allows for Date range  under 31 days. If it is greater the provided date range is greater, then this function chunks it into shorter date ranges 
        $response=null;
        $ts_start=strtotime($start_date);
        $ts_end=$end_date?strtotime($end_date):time();
        if ($ts_end-$ts_start>30*24*60*60){
            for($month=0;$month<ceil(($ts_end-$ts_start)/(30*24*60*60));$month++){
                //print "<strong>".$month."</strong> - ";
                $start=date("Y-m-d",$ts_start+$month*30*24*60*60);
                $end=date("Y-m-d",$ts_start+(($month+1)*30-1)*24*60*60);
                if ($end>date("Y-m-d",$ts_end)) $end=date("Y-m-d",$ts_end);
                if ( $start> $end) return;// shouldn't happen, but just in case 
                $response=$this->get_transactions_date_range($start,$end);
                if ($response)  $responses[]=$response;
            }
            ### combine results into one array.
            foreach($responses as $res){
                if (!$response) $response=$res;
                else{
                    foreach($res->transaction_details as $t){
                        $response->transaction_details[]=$t;
                    }
                    foreach($res->links as $l){
                        $response->links[]=$l;
                    }
                }
                
            }
            return $response;
        }
        
        $start_date=date("Y-m-d",$ts_start)."T00:00:00.000Z";
        $end_date=($end_date?date("Y-m-d",$ts_end):date("Y-m-d"))."T23:59:59.999Z";       
        $token=$this->get_token();
        if ($token){            
            $args = array(
                'method'      => 'GET',
                'timeout'     => 45,
                'redirection' => 10,
                'httpversion' => '1.1',
                'blocking'    => true,
                'sslverify'       => true,
                'headers' => array(
                    'Authorization' => 'Bearer '.$token,
                ),
                'body' => array(
                    'grant_type'=>'client_credentials'
                )            
            );
            $response = wp_remote_get( $this->get_url().'reporting/transactions?fields=transaction_info,payer_info,shipping_info,cart_info&start_date='.$start_date.'&end_date='.$end_date."&item_code=online", $args);
            if ( is_wp_error( $response ) ) {
                $this->error="Error from ".$this->get_url()."oauth2/token: ". $response->get_error_message();
                self::display_error($this->error);           
            }else {
                $json = json_decode($response['body']);
            } 
            if ($json->localizedMessage){
                self::display_error("Response Error: ".$json->localizedMessage);
            } 
            return $json;
        }
        return false;
    }

    public function process_response($response,$dateEnd=""){
        $process=$payments=$clients=$clientEmails=array();
        $process['time']=time();
        $paymentSkip=0;
        //This first loop caches results and puts them in Client and Payment objects, but does NOT save them yet.         
        foreach($response->transaction_details as $r){
            if ($r->cart_info->item_details[0]->item_code!="skype") continue; //only process online transactions
           
            $client=Client::from_paypal_api_detail($r);
            $clients[$client->SourceId]=$client;
            if ($r->payer_info->email_address){
                $clientEmails[$r->payer_info->email_address]=$r->payer_info->account_id; //potentially one e-mail could have multiple... this grabs the most recent.
            }           
            // if ($payments[$r->transaction_info->transaction_id]){
            //     self::display_error("Duplicate Transaction: ".$r->transaction_info->transaction_id." - using latest entry");
            // }
            $payments[$r->transaction_info->transaction_id]=Payment::from_paypal_api_detail($r);
            $process['RelevantTransactions'][$r->transaction_info->transaction_id]++;
        } 
        //dd($payments);       

        //Do a database check on clients to ensure no duplicates - If not found, insert.
        $SQL="SELECT * FROM ".Client::get_table_name()." WHERE (Source='paypal' AND SourceId IN ('".implode("','",array_keys($clients))."')) OR (Email<>'' AND Email IS NOT NULL AND Email IN ('".implode("','",array_keys($clientEmails))."'))";
        $results = self::db()->get_results($SQL);        
        foreach($results as $r){
            $clientOriginal[$r->ClientId]=$r;          
            $account_id=$clients[$r->SourceId]?$r->SourceId:$clientEmails[$r->Email];
            $client_id=$r->MergedId>0?$r->MergedId:$r->ClientId;
            if ($clients[$account_id]){
                $clients[$account_id]->ClientId=$client_id;
                $process['ClientsMatched'][$account_id]=$client_id;
            }           
        }       
        Client::client_update_suggestion($clientOriginal,$clients,$process['time']);

        ### need to do some sort of compare -> check out existing...
        foreach($clients as $account_id=>$client){            
            if (!$client->ClientId){ //new Client detected
                $client->save();
                $clients[$account_id]->ClientId=$client->ClientId;
                $process['ClientsAdded'][$account_id]=$client->ClientId;
            }
        }

        //Do database check on payments to ensure no duplicates.
        $SQL="SELECT * FROM ".Payment::get_table_name()." WHERE Source='paypal' AND TransactionID IN ('".implode("','",array_keys($payments))."')";
        $results = self::db()->get_results($SQL);       
        foreach($results as $r){
            if ($payments[$r->TransactionID]){ 
                $payments[$r->TransactionID]->PaymentId=$r->PaymentId;
                if ($r->SourceId==""){
                    $payments[$r->TransactionID]->UpdateSourceId=true;
                }
                //$payments[$r->TransactionID]->CreatedAt=$r->CreatedAt;
                $process['PaymentsMatched'][$r->TransactionID]=$r->PaymentId;                
            }
        }
        
        foreach($payments as $transaction_id=>$payment){
            if ($payment->PaymentId){  
                //print "<div>Found ". $payment->PaymentId."</div>";     
                if ($payment->UpdateSourceId){
                    //print "UpdateSource Id to: ".$payment->SourceId;
                    ### avoid a save... we don't want to overwrite everything in case manual adjustments were made. But update a few thigns we hadn't saved before. Can comment this out once DB is fixed.                    
                    self::db()->update($payment->get_table(),array('Source'=>'paypal','SourceId'=>$payment->SourceId),array('PaymentId'=>$payment->PaymentId));
                }
            }else{
                //print "<div>New". $payment->PaymentId."</div>"; 
                if($clients[$payment->SourceId]){
                    $payment->ClientId=$clients[$payment->SourceId]->ClientId;
                    //print "ClientId is: ".$payment->ClientId;
                    $payment->save($process['time']);
                    $process['PaymentsAdded'][$payment->TransactionID]=$payment->PaymentId;
                }else{
                    self::display_error("<div>Error: Client Id not found on Paypal Transaction: ".$payment->TransactionID." on SourceId: ".$payment->SourceId."</div>");
                }
            }
        }
        ### Set last Sync Date
        if ($dateEnd && $process['PaymentsAdded'] && sizeof($process['PaymentsAdded'])>0){
            CustomVariables::set_option('PaypalLastSyncDate',$dateEnd);
        }
        return $process;
    }

    static function is_setup(){ 
        if (CustomVariables::get_option('PaypalClientId') && CustomVariables::get_option('PaypalSecret')){
            return true;
        }else{
            return false;
        }
    }
    function syncDateResponse($from,$to=null){
        $PHPMemory=CustomVariables::get_option('PHPMemory');
        if ($PHPMemory && is_numeric($PHPMemory)){
            ini_set('memory_limit', $PHPMemory.'M');
        }
        if (!$to) $to=date("Y-m-d");   
        $response=$this->get_transactions_date_range($from,$to);
        $process=$this->process_response($response,$to);        
        if (!$process['time']) $process['time']=time();
        if (!is_numeric($process['time'])){
            $process['time']=strtotime($process['time']);
        }
        if ($response){
            if ($response->transaction_details){
                self::display_notice(
                    ($process['RelevantTransactions']?sizeof($process['RelevantTransactions']):"0")." relevant records retrieved. <ul>".
                    "<li>".($process['ClientsAdded']?sizeof($process['ClientsAdded']):"0")." New Client Entries Created.</li>".
                    ($process['PaymentsMatched'] && sizeof($process['PaymentsMatched'])>0?"<li>".sizeof($process['PaymentsMatched'])." payments already created.</li>":"").
                    ($process['PaymentsAdded'] && sizeof($process['PaymentsAdded'])>0?"<li>".sizeof($process['PaymentsAdded'])." new payments added.</li>":"").
                    "</ul>"
                ); //<a target='sendreceipts' href='?page=onlineclasspayments-reports&UploadDate=".urlencode(date("Y-m-d H:i:s",$process['time']))."'>View These Payments/Send Acknowledgements</a>
            }else{
                if ($response->message){                    
                    self::display_error("<strong>".$response->name."</strong> ".$response->message);
                    foreach($response->details as $d){
                        self::display_error("<strong>".$d->issue."</strong> ".$d->description);
                    }
                }
            }
        }
    }
}