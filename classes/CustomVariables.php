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

    static function restore($file,$chunksize=500){ 
        global $wpdb,$donor_press_db_version;
        //$currentversion=$donor_press_db_version;
        if ($lines = file($file)){                   
            foreach($lines as $line){
                if (trim($line)=="") continue;
                $json=json_decode($line);
                $json->TABLE=strtolower($json->TABLE); 
                if ($json->PLUGIN=="OnlineClassPayments" && $json->VERSION){
                    $version=$json->VERSION;
                    if (version_compare($donor_press_db_version, $version, '<')){
                        print self::display_error("Current installed DB Version of Donor Press is ".$donor_press_db_version." which is less than the this backup: ".$version.". Please upgrade Donorpress to ".$version." before restoring backup.");
                        return;
                    }
                    update_option('donor_press_db_version',$version);
                    //dump("version",$version,$donor_press_db_version,get_option('donor_press_db_version'));
                }elseif ($json->TABLE && sizeof($json->RECORDS)>0){
                    $tablename=$wpdb->prefix.(!in_array($json->TABLE,['options','posts'])?'onlineclasspayments_':"").$json->TABLE;
                    print "<h2>Restoring to ".$tablename." ".sizeof($json->RECORDS)." records.</h2>";
                    //$json->COLUMNS;
                    $columns=[];
                    $results=$wpdb->get_results("SELECT COLUMN_NAME,IS_NULLABLE,DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE table_name = '".$tablename."'");
                     foreach($results as $r){
                        $columns[$r->COLUMN_NAME]=$r;
                     }
                    
                    $chunk=array_chunk($json->RECORDS,$chunksize); //insert 500 rows at a time                    
                    foreach($chunk as $rows){
                         //todo in the future check $version against upgrade table, and shift columns/fields if necessary.
                        $iSQL="INSERT INTO ".$tablename." (".implode(",",$json->COLUMNS).") VALUES \r\n";
                        $r=0;
                        foreach($rows as $row){
                            $c=0;
                            if ($r>0) $iSQL.=",\r\n";                            
                            $iSQL.="(";                           
                            foreach($row as $col){
                                if ($c>0) $iSQL.=", ";
                                $column=$json->COLUMNS[$c];
                                switch( $columns[$column]->DATA_TYPE){
                                    case "int":
                                        if (!$col && $columns[$column]->IS_NULLABLE=="YES") $iSQL.="NULL";
                                        else $iSQL.="'".self::mysql_escape_mimic($col)."'";
                                    break;
                                    default:
                                        $iSQL.="'".self::mysql_escape_mimic($col)."'";
                                    break;
                                }                              
                                
                                $c++;
                            }
                            $iSQL.=")";
                            $r++;
                        }
                        //if ($json->TABLE=="onlineclasspayments_category") print $iSQL;
                        print "<div>Table: ".$tablename." - Chunk size: ".sizeof($rows)."</div>";
                        //if (in_array($json->TABLE,['options','posts'])) print "<pre>".$iSQL."</pre>";
                        $wpdb->query($iSQL);

                    }
                }                          
            }
        }
        if ($version && $donor_press_db_version!=$version){            
            print self::display_notice("Attempting upgrade of backup version of: ".$version." to current Donor Press version of: ".$donor_press_db_version);            
            onlineclasspayments_upgrade();
        }
    }    
    
    static function get_org(){
        $org=self::get_option('Organization');
        if (!$org) $org=get_bloginfo('name');
        return $org;
    }

    static function backup($download=false){               
        //Backups up all donor related tables and partial tables on posts and options   
        global $wpdb;    
        global $donor_press_db_version;
        $org= mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', self::get_org());
        $fileName="OnlineClassPaymentsBackup-".str_replace(" ","_",$org).date("YmdHis").".json";
        $contents=wp_json_encode(["PLUGIN"=>"OnlineClassPayments","DBPREFIX"=>$wpdb->prefix,"VERSION"=>$donor_press_db_version,"ORG"=>self::get_org(),"URL"=>get_bloginfo('url')])."\n";
        foreach(onlineclasspayments_tables() as $table){
            $records=[];  
            $class="OnlineClassPayments\\".$table;                     
            $SQL="Select * FROM ".$class::get_table_name();
            if(!$download) print "Backing up TABLE: ".$class::get_table_name()."<br>";           
            $results=$wpdb->get_results($SQL);
            foreach ($results as $r){ 
                $c=(array)$r;                
                $cols=array_keys($c);               
                $records[]=array_values($c) ;  
            }
            $contents.=wp_json_encode(["TABLE"=>$class::get_base_table(),"TABLE_SRC"=>$class::get_table_name(),'COLUMNS'=>$cols,'RECORDS'=>$records])."\n";
        }    
       
        foreach(self::partialTables as $a){
            $records=[];
            $SQL="Select * FROM ".$wpdb->prefix.$a['TABLE']." WHERE ".($a['WHERE']?$a['WHERE']:1);
            if(!$download)print "Backing up QUERY: ". $SQL."<br>";
            $results=$wpdb->get_results($SQL);
            foreach ($results as $r){
                $c=(array)$r;
                if ($a['COLUMN_IGNORE']){                    
                    unset($c[$a['COLUMN_IGNORE']]); //currently only used for id field, but can be modified if multiple fields need supported.
                }
                $a['COLUMNS']=array_keys($c);
                $a['RECORDS'][]=array_values($c);
            }                      
            $contents.=wp_json_encode($a)."\n";
        }       
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename='.$fileName); //.basename($filePath)
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header("Content-Type: text/plain");
        echo $contents;
        exit;        
    }

    static function mysql_escape_mimic($inp) { //https://www.php.net/manual/en/function.mysql-real-escape-string.php
        if(is_array($inp)) return array_map(__METHOD__, $inp);   
         if(!empty($inp) && is_string($inp)) {
            return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);    
        }    
        return $inp;    
    }

    static public function nuke_confirm(){
        ?><h2>You are about to remove all donor/donations records from the database</h2>
        <div style='color:red;'>This will be permanent. Please back up anything important before proceeding.</div>
        <form method="post" action="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get'))?>">
            <!-- <label><input type="checkbox" name="backup" value="t" checked>Backup Existing Donor Press Records to a flat file</label><br>   -->
            <label><input type="checkbox" name="droptable" value="t" checked>Drop All Tables Data and Structures (Donor, Donation, Categories)</label><br>           
            <label><input type="checkbox" name="dropfields" value="t" checked>Drop All Custom Fields (Letter Templates, settings)</label><br>
            <label><input type="checkbox" name="rebuild" value="true" checked>Recreate Tables (will be blank)</input></label><br>

            <button name="Function" value="NukeIt">Nuke It</button>
            <button>Cancel</button>
        </form>
        <?php
    }

    static public function nuke_it($post){
        if ($post['backup']) self::backup(); //back it up to flat file before nuke
        if ($post['droptable']){
            foreach(onlineclasspayments_tables() as $table){
                $class="OnlineClassPayments\\".$table; 
                $SQL= "DROP TABLE IF EXISTS ".$class::get_table_name();
                print $SQL."<br>";
                self::db()->query($SQL);
            }
        }
        if($post['dropfields']){
            foreach(self::partialTables as $a){   
                $SQL="DELETE FROM ".self::db()->prefix.$a['TABLE']." WHERE ".($a['WHERE']?$a['WHERE']:1);
                print $SQL."<br>";       
                self::db()->query($SQL);
            }
        }

        if ($post['rebuild']){ 
            onlineclasspayments_plugin_create_tables();
            print "TABLES Rebuilt";    
        }

    }   


    static public function request_handler(){
        $wpdb=self::db();      
        switch(self::input('Function','post')){
            case 'BackupOnlineClassPayments': 
                self::backup();
                break;
            case 'RestoreOnlineClassPayments':
                if ($_FILES["fileToUpload"]["tmp_name"]){
                    //self::backup(); //backup current first.                    
                    donorpress_nuke(); //clear out current files
                    self::restore($_FILES["fileToUpload"]["tmp_name"]);  
                    print self::display_notice("Restore Complete");
                    return true;
                    break;
                }elseif($_FILES["fileToUpload"]["error"]){                    
                    self::display_error("Error on file upload. If the file is over ".ini_get("upload_max_filesize")." then you will have to update your server upload limit.");
                }
                break;
            case 'NukeOnlineClassPayments':
                self::nuke_confirm();
                return true;
                break;
            case 'NukeIt':
               self::nuke_it([
                'backup'=>self::input('backup','post'),
                'droptable'=>self::input('droptable','post'),
                'dropfields'=>self::input('dropfields','post'),
                'rebuild'=>self::input('rebuild','post')                
               ]);
               print self::display_notice("Site Nuked. Data erased");
                break;
            // case 'LoadTestData':
            //     if (donorpress_load_test_data(self::input('records','post'))){
            //         print self::display_notice("Test Data Loaded. ".self::input('records','post')." Records Created");
            //     }               
            //     break;            
        }
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