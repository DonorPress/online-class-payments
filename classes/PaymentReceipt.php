<?php
namespace OnlineClassPayments;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly      

class PaymentReceipt extends ModelLite {
    protected $table = 'payment_receipt';
	protected $primaryKey = 'ReceiptId';
	### Fields that can be passed 
    protected $fillable = ["ClientId","KeyType","KeyId","Type","Address","DateSent","Subject","Content"];	    
	### Default Values
	protected $attributes = [        
        'KeyType' => 'Payment',
        'tType'=> 'e',
	];
	//public $incrementing = true;
    const CREATED_AT = 'DateSent';
    const UPDATED_AT = 'DateSent';

    protected $tinyIntDescriptions=[
        "Type"=>["e"=>"Email","p"=>"Pdf/Postal Mail"],        
    ];
  
    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
          $sql="CREATE TABLE IF NOT EXISTS `".self::get_table_name()."` (
            `ReceiptId` int(11) NOT NULL AUTO_INCREMENT,
            `ClientId` int(11) NOT NULL COMMENT 'Payment/YearEnd',
            `KeyType` varchar(20) NOT NULL,
            `KeyId` int(11) NOT NULL COMMENT 'PaymentId or Year',
            `Type` varchar(1) NOT NULL COMMENT 'e=email p=postal mail',
            `Address` text NOT NULL COMMENT 'email or postal address',
            `DateSent` datetime NOT NULL,
            `Subject` varchar(256),
            `Content` text,
            PRIMARY KEY (`ReceiptId`)
            )"; 
          dbDelta( $sql );
    }

    function show_type(){
        if ($this->Type=="e") return "Email"; 
        elseif ($this->Type=="m") return "Mail";
        else return $this->Type;
    }

    static function displayReceipts($array,$columns=[]){
        if (!$array || sizeof($array)==0) return;
        $return="<table><tr><th>Type</th><th>Address</th><th>DateSent</th><th>Subject</th></tr>";
        foreach($array as $r){
            $return.="<tr><td>".$r->show_type()."</td><td>".$r->Address."</td><td>".$r->DateSent."</td><td>".$r->Subject."</td></tr>";
        }
        $return.="</table>";
        return $return;
    }
}