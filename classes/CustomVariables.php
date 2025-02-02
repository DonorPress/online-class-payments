<?php
namespace OnlineClassPayments;;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly      
/* Utilizes Wordpresses Built in custom variables typically in the wp_options table.
* Use wordpress funcitons to edit these
* all custom variables shoudl have a "base" of onlineclasspayments', example 'onlineclasspayments_Organization'
*/

class CustomVariables extends ModelLite
{  
    const base = 'onlineclasspayments';
    const variables = ["Organization","ContactName","ContactTitle","ContactEmail","PaypalLastSyncDate","DefaultCountry"];	
    const variables_protected = ["PaypalClientId","PaypalSecret"];

    const variables_manual=[];
    const partialTables = [
        ['TABLE'=>'posts','WHERE'=>"post_type='onlineclasspayments'",'COLUMN_IGNORE'=>'ID'],
        ['TABLE'=>'options','WHERE'=>"option_name LIKE 'onlineclasspayments_%'",'COLUMN_IGNORE'=>'option_id']
    ];

    static function get_option($option,$decode=false){
        $result=get_option(self::base."_".$option);
        if($decode){
            return self::decode($result);
        }else{
            return $result;
        }
    }

    static function set_option($var,$value,$encode=false){
        update_option(self::base."_".$var, $encode? self::encode($value):$value, true);       
    }

    static function encode($value){
        return base64_encode($value);
    }

    static function decode($value){
        return base64_decode($value);
    }

    static public function form(){
        $wpdb=self::db();  
        $vals=self::get_custom_variables();  
        ?>
        <h2>Edit Online Payment Variables</h2>
        <form method="post">
        <input type="hidden" name="table" value="CustomVariables"/>
            <table>
                <?php
                foreach(self::variables as $var){                    
                    $fullVal=self::base."_".$var;
                    //$c->$var=get_option($fullVal);
                    ?>
                    <tr><td><input type="hidden" name="<?php print esc_attr($var)?>_id" value="<?php print esc_attr($vals->$fullVal?$vals->$fullVal->option_id:"")?>"/><?php print esc_html($var)?></td>
                    <td>
                    <?php 
                    switch($var){                       
                        default:?>
                            <input name="<?php print esc_attr($var)?>" value="<?php print isset($vals->$fullVal)?$vals->$fullVal->option_value:""?>"/>
                        <?php
                        }
                        ?>
                        <input type="hidden" name="<?php print esc_attr($var)?>_was" value="<?php print esc_attr($vals->$fullVal?$vals->$fullVal->option_value:"")?>"/>
                    </td></tr>
                    <?php
                }
                ?>                
                </table>
                <h3>Protected Variables (encoded)</h3>
                <div>Paypal Integration Link: <a target="paypal" href="https://developer.paypal.com/dashboard/applications/live">https://developer.paypal.com/dashboard/applications/live</a> - (1) login into your paypal account (2) create an app using "live" (3) make sure Transaction search is checked 
                (4) and paste in credentials below.</div>
                <div>By entering a value, it will override what is currently there. Values are encrypted on the database.</div>
                <table>
                <?php
                foreach(self::variables_protected as $var){                    
                    $fullVal=self::base."_".$var;
                    //$c->$var=get_option($fullVal);
                    ?>
                    <tr><td><input type="hidden" name="<?php print esc_attr($var)?>_id" value="<?php print esc_attr($vals->$fullVal?$vals->$fullVal->option_id:"")?>"/><?php print esc_html($var)?></td><td><input name="<?php print esc_attr($var)?>" value=""/>
                    <?php print isset($vals->$fullVal)?"<span style='color:green;'> - set</span> ":" <span style='color:red;'>- not set</span>";
                    ?></td></tr>
                  <?php
                }
                ?>
                </table>           
            </table>           
            
            <button type="submit" class="primary" name="Function" value="Save">Save</button>
        </form>
        <?php
        if (CustomVariables::get_option('QuickbooksClientId',true) && QuickBooks::qb_api_installed()){
            self::display_notice("Allow Redirect access in the <a target='quickbooks' href='https://developer.intuit.com/app/developer/dashboard'>QuickBook API</a> for: ".QuickBooks::redirect_url());
        }
        print "<div><strong>Plugin base dir:</strong> ".onlineclasspayments_plugin_base_dir()."</div>";       
        
    }
    
    static function get_custom_variables(){
        $wpdb=self::db();  
        $results=$wpdb->get_results("SELECT `option_id`, `option_name`, `option_value`, `autoload` FROM `".$wpdb->prefix."options` WHERE `option_name` LIKE '".self::base."_%'");
        $c=new self();
        foreach($results as $r){
            $field=$r->option_name;
            $c->$field=$r;
        }
        return $c;
    }    
    
    static function get_org(){
        $org=self::get_option('Organization');
        if (!$org) $org=get_bloginfo('name');
        return $org;
    }    

    static function mysql_escape_mimic($inp) { //https://www.php.net/manual/en/function.mysql-real-escape-string.php
        if(is_array($inp)) return array_map(__METHOD__, $inp);   
         if(!empty($inp) && is_string($inp)) {
            return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);    
        }    
        return $inp;    
    }    


    static public function request_handler(){
        $wpdb=self::db();      

        if (self::input('Function','post') == 'Save' && self::input('table','post')=="CustomVariables"){
            foreach(self::variables as $var){
                self::evaluate_post_save($var);   
            }
            foreach(self::variables_protected as $var){
                if (self::input($var,'post')!=""){
                    self::evaluate_post_save($var,true);                   
                }
            }

            foreach(self::variables_manual as $var){
                self::evaluate_post_save($var);                
            }

            ### handle Quickbook Settings - number of fields could change.
            if (self::input('QBPaymentMethod_1','post')){ //assumes locally there is always a one.
                foreach(Payment::s()->tinyIntDescriptions["PaymentSource"] as $key=>$label){                  
                    self::evaluate_post_save("QBPaymentMethod_".$key);
                }

            }
        }
    }

    static public function evaluate_post_save($var,$encode=false){
        $value=self::input($var,'post');
        if ($value!=self::input($var.'_was','post')){
            if (self::input($var.'_id','post')){             
                print "update ".$var."<br>";
                update_option( self::base."_".$var, $encode?self::encode($value):$value, true);
            }else{
                print "insert ".$var." <br>";              
                add_option(self::base."_".$var, $encode?self::encode($value):$value);
            }
        }  
    } 
}