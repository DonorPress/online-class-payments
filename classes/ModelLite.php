<?php
namespace OnlineClassPayments;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/*****
Model Lite Class to make saves/inserts/updates/forms a tad easier to develop

Requires the following constants as seen in this example:	
	protected $table = 'Client';
	protected $primaryKey = 'ClientId';
	### Fields that can be passed 
    protected $fillable = ["Name","Name2","Email","Phone","Address1","Address2","City","Region","PostalCode","Country"];	    
	### Default Values
	protected $attributes = [        
        'Country' => 'USA',
	];
	public $incrementing = true;
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt'; 
*****/

class ModelLite{
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';
	
	public function __construct($attributes = []){
        $this->fill((array)$attributes);
	}
	
	public function fill(array $attributes){
		$fields=$this->get_viewable_fields();
		
		if (is_array($attributes)){ ### flip array to object
			$attributes=(object)$attributes;
		}
		### Set default values if not passed in...
		if (is_array($this->attributes)){
			foreach ($this->attributes as $key=>$value){
				if (!isset($attributes->$key)){
					$attributes->$key=$value;
				}
			}
		}

		if (is_array($fields)){
			foreach ($fields as $field){
				if (!isset($attributes->$field)) continue;
				//I'm not positive why we have to strip slashes... but it fixes the issue.		
				$this->$field=stripslashes($attributes->$field);

				if (isset($this->fieldLimits)&&isset($this->fieldLimits[$field])){ //trim strings that are to long.
					$this->$field=substr($this->$field,0,$this->fieldLimits[$field]);
				}
			}
		}	
	}

	public function get_viewable_fields(){
		$fields=isset($this->fillable)?$this->fillable:[];
		$primaryKey=isset($this->primaryKey)?$this->primaryKey:null;
		
		### Add Primary Key to results
		if (is_array($primaryKey)){
			$fields=array_merge($primaryKey,$fields);
		}else{
			$fields=array_merge(array($primaryKey),$fields);
		}
		return $fields;
	}

	public function get_table($type="full"){
		$wpdb=self::db(); 
		$base=strtolower(($this->table ?? get_class($this)));
		if($type=="base"){
			return $base;
		}
		
		switch($base){
			case "posts": //using existing table in wordpress
				$pluginprefix='';
			break;
			default:
				$pluginprefix="onlinepayments_"; 
			break;
		}    
        return $wpdb->prefix.$pluginprefix.$base;
	}

	static public function get_base_table(){
		return self::s()->get_table('base');
	}
	
	static public function get_table_name($type="full"){	
        return self::s()->get_table($type);
    }
	static public function get_fillable(){
		return self::s()->fillable;
	}

	static public function db(){ //concept for right now... if we want to avoid global $wpdb, run everything this...
		//$wpdadb = WPDataAccess\Connection\WPDADB::get_db_connection( 'your-local-db-name' ); 
		global $wpdb;
		return $wpdb;
	}

	public function save($time=""){ //both insert and update routine. Creates new if no ReworkId passed in.
		if (!$time) $time=time();
		if (!is_numeric($time)) $time=strtotime($time);
		$wpdb=self::db();  
		$wpdb->show_errors();
		$keyField=$this->primaryKey;
		foreach ($this->fillable as $field){
			if (!isset($this->$field)) continue; //need to test, but if not set, don't override
			if (isset($this->fieldLimits)&&isset($this->fieldLimits[$field])){ //trim strings that are to long.
				$data[$field]=substr($this->$field,0,$this->fieldLimits[$field]);
			}else{
				$data[$field]=$this->$field;
			}	
		}
		if (static::UPDATED_AT && !$data[static::UPDATED_AT]){
			$data[static::UPDATED_AT]= date("Y-m-d H:i:s",$time);
		}

		if ($this->$keyField>0){
			if (!$wpdb->update($this->get_table(),$data,array($keyField=>$this->$keyField))){
				print $wpdb->print_error();
				self::dump($data,"WHERE ".$keyField."=".$this->$keyField);
			}
		}else{
			if (static::CREATED_AT && !$data[static::CREATED_AT]){
				$data[static::CREATED_AT]= date("Y-m-d H:i:s",$time);
			}	 	
			if (!$wpdb->insert($this->get_table(),$data)){				
				print $wpdb->print_error();
				self::dump($data);
			}			
			$this->$keyField=$wpdb->insert_id;
			$insert=true;
		}
		return $this;

	}

	public function delete(){
		$wpdb=self::db();  
		$keyField=$this->primaryKey;
		if ($this->$keyField){
			 $wpdb->delete($this->get_table(),array($keyField=>$this->$keyField));
			 return true;
		}
		return false;
	}

	public function view(){ //single entry->View fields
		//print "varview";
		$this->var_view();
		//print_r($this);
	}

	public function var_view(){
		?><table class="dp"><?php
		$fields=$this->get_viewable_fields();
		foreach($fields as $f){			
			if ($this->$f){
				?><tr>
					<td><strong><?php print esc_html($f)?></strong></td>
					<td><?php print wp_kses_post($this->show_field($f))?></td>
				</tr><?php
			}
		}
		?></table>
		<?php
	}

	static public function get_key($row){ //maybe discontiue this and replace with flat_key();
		$key=[];
		foreach(self::s()->duplicateCheck as $field){
			$value=$row->$field;
			switch($field){
				case "Gross":
					$value=(float)$value*1;
				break;
				default:
					$value=strtoupper($value);
				break;
			}
			$key[]=$value;
		}
		return implode("|",$key);
	}

	public function flat_key($keys=[]){ //takes key fields and flattens them for the sake of comparison
        if (sizeof($keys)==0) $keys=$this->flat_key;
		$return="";
        foreach($keys as $key){
           $return.=($return?"|":"").preg_replace("/[^a-zA-Z0-9 ]+/", "",strtolower($this->$key));
        }
        return $return;        
    }

	static public function replace_into_list($q){
		### Adds a check to avoid duplicate rows basd on 
		$wpdb=self::db();  
		### Cache all existing entries. If DB gets to big, this might use to much memory, and may want to do individual queries.
		$dupFieldsCheck=self::s()->duplicateCheck;		
        $result= $wpdb->get_results("Select ".implode(", ",$dupFieldsCheck).", Count(*) as C FROM ".self::s()->get_table()." Group By  ".implode(", ",$dupFieldsCheck));
        foreach ($result as $row){
			$existingEntries[self::get_key($row)]=$row->C;
		}

        if (sizeof($q)>0){		
			$iSQL=[];
			$skipped=0;
            foreach ($q as $row){
				if ($existingEntries[self::get_key($row)]){ //skip entry already exists
					$skipped++;
				}else{
					$items=[];
					foreach ($row->fillable as $f){				
						$items[]="'".($row->$f?addslashes($row->$f):"")."'";
					}
					$iSQL[]="(".implode(", ",$items).")";
				}
            }
			if (sizeof($iSQL)>0){
				$SQL="INSERT INTO ".self::s()->get_table()." (`".implode("`,`",$row->fillable)."`) VALUES ".implode(", ",$iSQL);
				$result= $wpdb->query($SQL);
			}
			return array('inserted'=>sizeof($iSQL),'skipped'=>$skipped,'insertResult'=>$result);
        }
    }

	static public function s(){
		// A way to retreive protectic variables from a static call.
		// example: self::s()->get_table()
        $instance = new static;
        return $instance;
	}
	
	static public function dump(...$objs){
		foreach($objs as $obj){
			if(function_exists('dump')){
				dump($obj);
			}else{
				print "<pre>"; var_dump($obj); print "</pre>";
			}
		}
	}
	public static function dd(...$obj){
		self::dump($obj);
		exit();
	}

	static function ucfirst_fixer($txt){
        $breakCharacters=array(" ","'","`","-","—");
        $txt=strtolower(trim($txt));
        $alwaysLower=array("and","the","of","in","for","to","an","on","at","by","with","from","into","over","under","upon");
        $alwaysUpper=array("ii","iii","iv","v","vi","vii","viii","ix","x","xi","xii","xiii","xiv","xv",'xvi','xvii','xviii','xix','xx','po','pob','cpa','cfo','ceo','cfo');
        foreach($breakCharacters as  $char){
            $words=explode($char,$txt);
            $pieces=array();
            foreach($words as $word){
                if (in_array($word,$alwaysLower)){
                    $pieces[]=$word;
                }elseif(in_array($word,$alwaysUpper)){
                    $pieces[]=strtoupper($word);
                }else{
                    $pieces[]=ucfirst($word);
                }
            }
            $txt=implode($char,$pieces);
        }
        return $txt;
    }

	public static function get_by_id($id,$settings=false){		
		return  self::find($id);
	}

	public static function first($where=array(),$orderby="",$settings=[]){
		$settings['limit']=1;
		$first=self::get($where,$orderby,$settings);
		if (is_array($first) && sizeof($first)>0) return $first[key($first)];
		else return false;
	}

	public static function find($id){
		$where[]=self::s()->primaryKey."='".$id."'";
		$SQL="SELECT * FROM ".self::s()->get_table()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"");			
		return  new static((array) self::db()->get_row($SQL));		
	}
	
	public static function get($where=array(),$orderby="",$settings=false){
		if ($where && !is_array($where)) $where=[$where];	
		$SQL="SELECT "
		.(isset($settings['select'])?$settings['select']:"*")
		." FROM ".self::s()->get_table()." "
		.(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"")
		.($orderby!=""?" ORDER BY ".$orderby:"").(isset($settings['limit'])?" LIMIT ".$settings['limit']:"");
		$all=self::db()->get_results($SQL);
		$return=[];
		foreach($all as $r){
			$obj=new static($r,$settings);
			if (isset($settings['key'])){
				$primaryField=$obj->primaryKey;
				$return[$r->$primaryField]=$obj;
			}else{
				$return[]=$obj;
			}
		}
		
		return $return;
	}

	public function key_flat(){
		//return key value. If key is mutiple fields, then it seperates by pipes "|". 
		$keyField=$this->primaryKey;
		if (is_array($keyField)){
			$key="";
			foreach($keyField as $kf){
				$key.=($key?"|":"").$this->$kf;
			}
		}else $key=$this->$keyField;
		return $key;
	}

	public static function show_results($results,$noResultMessage="<div class=\"notice notice-warning\">No Results Found</div>",$fieldList=[]){		
		$fields=$fieldList?$fieldList:self::s()->get_viewable_fields();	
		if (!$results || sizeof($results)==0){
			return "<div><em>".$noResultMessage."</em></div>"; 
		}
		ob_start(); 
		?>
		<script>
			function toggleDisplay(id){
				var x = document.getElementById(id);
				if (x.style.display === "none") {
					x.style.display = "block";
				} else {
					x.style.display = "none";
				}
			}
		</script>	
		<table class="dp"><?php
			$i=0;
			foreach($results as $r){
				if ($i==0){
					?><tr><?php
					foreach ($fields as $field){?><th><?php print esc_html($field)?></th><?php }
					?></tr><?php
				}
				?><tr><?php
				foreach ($fields as $field){
					?><td><?php print wp_kses_post($r->show_field($field))?></td><?php 
				
				}
				?></tr><?php
				$i++;
			}
		?></table><?php
		return ob_get_clean(); 
	}

	public function phone_format($phone){
        return preg_replace('~.*(\d{3})[^\d]{0,7}(\d{3})[^\d]{0,7}(\d{4}).*~', '($1) $2-$3', $phone);
    }
	
	public function show_field($fieldName,$settings=[]){
		if (!isset($this->$fieldName)) return "";
		$v=$this->$fieldName;
		switch($fieldName){	
			case "PaymentId":
				return '<a '.(isset($settings['target'])?'target="'.$settings['target'].'"':"").'href="?page=onlineclasspayments-index&PaymentId='.$v.'">'.$v.'</a>';
			break;
			case "MergedId":
				return '<a '.(isset($settings['target'])?'target="'.$settings['target'].'"':"").'href="?page=onlineclasspayments-index&ClientId='.$v.'">'.$v.'</a>';
			case "ClientId":
				return '<a '.(isset($settings['target'])?'target="'.$settings['target'].'"':"").'href="?page=onlineclasspayments-index&ClientId='.$v.'">'.$v.'</a>'.(isset($settings['donationlink'])?' <a href="?page=onlineclasspayments-index&ClientId='.$v.'&f=AddPayment">+ Payment</a>':"");
			break;		
			case "Phone":
				return $this->phone_format($v);
			break;
			case "Gross":
			case "Fee":
			case "Net":
				return $v?number_format($v,2):"";
			break;			
			case "Date":
					return str_replace(" 00:00:00","",$v);
				break;
			case "FromEmailAddress":
			case "ToEmailAddress":
			case "Email":
				return $this->display_email($fieldName); //'<a href="mailto:'.$v.'?Subject=">'.$v.'</a>';
			break;
            default:
				if (isset($this->tinyIntDescriptions[$fieldName])){
					$label=self::s()->tinyIntDescriptions[$fieldName][$v];								
					if ($label){	
						return $v." - ".$label;
					}elseif ($fieldName=="Type" && $this->TypeOther){
							return ($settings['idShow']?$v." - ":"").$this->TypeOther;						
					}
				}
				return $v;				
			break;
		}
	}

	static public function get_tiny_description($fieldName,$v){
		return self::s()->tinyIntDescriptions[$fieldName][$v];
	}

	public function display_key(){
		$primaryKey=$this->primaryKey;
		return '<a href="?page=onlineclasspayments-index&'.$primaryKey.'='.$this->$primaryKey.'">'.$this->$primaryKey."</a> ";
	}

	public function display_email($fieldName='Email'){
		$return="";
		if (trim($this->$fieldName)){
			$emails=explode(";",$this->$fieldName);
			$count=0;
			foreach($emails as $email){
				$email=trim($email);
				if ($count>0) $return.="; ";
				if ($this->EmailStatus<0 || !filter_var($email, FILTER_VALIDATE_EMAIL)){
					$return.= '<span style="text-decoration:line-through; color:red;">'.$email.'</span>';
				}else{
					$return.= '<a href="mailto:'.$email.'">'.$email.'</a>';
				}
				$count++;
			}	
			return $return;		
		}		
	}
	
	public function edit_form(){
		$primaryKey=$this->primaryKey;
		?><form method="post" action="<?php print esc_url('?page='.self::input('page','get').'&'.$primaryKey.'='.$this->$primaryKey)?>">
		<input type="hidden" name="table" value="<?php print esc_attr($this->table)?>"/>
		<input type="hidden" name="<?php print esc_attr($primaryKey)?>" value="<?php print esc_attr($this->$primaryKey)?>"/>
	
		<table><?php
		foreach($this->fillable as $field){
			$type="text";
			if (isset($this->fieldLimits)&&isset($this->fieldLimits[$field])){ //trim strings that are to long.
				$maxlength=$this->fieldLimits[$field];
			}else{
				$maxlength=false;
			}
            if (strpos($field,"Date")>-1){
                $type="date";
			}
			if ($field=="Date") $this->Date=substr($this->$field,0,10); //$type="datetime-local";
			?><tr><td align=right><?php print esc_html($field)?></td><td><?php

			$select=false;
			if (isset($this->tinyIntDescriptions[$field])) $select=$this->tinyIntDescriptions[$field];			

			if ($select){
				?><select name="<?php print esc_attr($field)?>"><?php
					foreach($select as $key=>$label){
						?><option value="<?php print esc_attr($key)?>"<?php print ($key==$this->$field?" selected":"")?>><?php print $key." - ".$label?></option><?php
					}
					if (!$select[$this->$field]){
						?><option value="<?php print esc_attr($this->$field)?>" selected><?php print $this->$field." - Not Set"?></option><?php
					}
					?></select><?php
			}else{
				?><input type="<?php print esc_attr($type)?>" name="<?php print esc_attr($field)?>" value="<?php print esc_attr($this->$field)?>"<?php
				if ($maxlength) print ' maxlength="'.$maxlength.'"';?>/><?php
			}	
		
			
			?></td></tr><?php
			}
		?><tr><tr><td colspan=2>
		<button type="submit" class="primary" name="Function" value="Save">Save</button>
		<button type="submit" name="Function" class="secondary" value="Cancel" formnovalidate>Cancel</button>
        <?php 
        if ($this->$primaryKey){
            ?> <button type="submit" name="Function" value="Delete">Delete</button><?php
        }
        ?>
		</td></tr>
		</table>
		</form><?php
	}

	static public function display_notice($html){
		print "<div class=\"notice notice-success is-dismissible\">".wp_kses_post($html)."</div>";
	}

	static public function display_warning($html){
		print "<div class=\"notice notice-warning is-dismissible\">".wp_kses_post($html)."</div>";
	}

	static public function display_error($html){
		print "<div class=\"notice notice-error is-dismissible\">".wp_kses_post($html)."</div>";
	}

	static public function show_tabs($tabs){
		$active_tab=self::input('tab','request')?self::input('tab','request'):key($tabs);
		?>
		<div class="dp-tab-links">
			<?php foreach ($tabs as $tab=>$label){
				print '<a href="'.esc_url('?page='.self::input('page','get').'&tab='.$tab).'" class="tab'.($active_tab==$tab?" active":"").'">'.esc_html($label).'</a>';			
			}?>
		</div>
		<?php
		return $active_tab;
		
	}

	static public function upload_dir(){		
		$uploadDir=onlineclasspayments_upload_dir(wp_get_upload_dir());
        return $uploadDir['path']."/";
    }


	static function input($field,$type='request'){
		switch ($type){
			case 'get': return isset($_GET[$field])?self::input_sanitize($_GET[$field]):null; break;
			case 'post': return isset($_POST[$field])?self::input_sanitize($_POST[$field]):null; break;
			default:
				return isset($_REQUEST[$field])?self::input_sanitize($_REQUEST[$field]):null;
			break;
		}		
	}

	static function input_sanitize($value){
		if (is_array($value)){
			$return=[];
			foreach($value as $k=>$v){				
				$return[$k]=self::input_sanitize($v);
			}
			return $return;

		} 
		else return wp_kses_post($value);
	}

	static function input_model($type='request'){
		$fillable=self::s()->fillable;
		if (self::s()->primaryKey)$fillable[]=self::s()->primaryKey;
		$attributes=[];
		foreach($fillable as $field){
			$attributes[$field]=self::input($field,$type);
		}
		return $attributes;
	}
	
	static function resultToCSV($results,$settings=array()){
		if (sizeof($results)==0){
			print "No Results Found";
			return;
		}
		if (!$settings['name']) $settings['name']="OnlinePayments";
		header("Content-Transfer-Encoding: UTF-8");
        header('Content-type: application/csv');
        header('Pragma: no-cache');
        header('Content-Disposition: attachment; filename='.$settings['name'].'.csv');
        $fp = fopen('php://memory', 'r+');
		if ($settings['namecombine']){
			$results[0]->NameCombined="ColumnAdd"; //make sure it appears as a header column;
		}
        fputcsv($fp, array_keys((array)$results[0]));//write first line with field names
        foreach ($results as $r){			
            fputcsv($fp, (array)$r);
        }
        rewind($fp);
         $csv_line = stream_get_contents($fp);
         print $csv_line;
         exit();
	}

	public function email_string_to_array($string){
		$tos=array();
		if (is_array($string)){
			$emails=$string;
		}else{
			$string=str_replace(",",";",$string);
			$emails=explode(";",$string);
		}		
		foreach($emails as $email){
			$email=sanitize_email($email);
			if ($email)	$tos[]=$email;
		}
		return $tos;
	}

	const COUNTRIES = array(
		'AF' => 'Afghanistan', 'AX' => 'Aland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa', 'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua And Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia And Herzegovina', 'BW' => 'Botswana', 'BV' => 'Bouvet Island', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei Darussalam', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CX' => 'Christmas Island', 'CC' => 'Cocos (Keeling) Islands', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo, Democratic Republic', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'CI' => 'Cote D\'Ivoire', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FK' => 'Falkland Islands (Malvinas)', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia', 'TF' => 'French Southern Territories', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti', 'HM' => 'Heard Island & Mcdonald Islands', 'VA' => 'Holy See (Vatican City State)', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran, Islamic Republic Of', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle Of Man', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KR' => 'Korea', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Lao People\'s Democratic Republic', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libyan Arab Jamahiriya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macao', 'MK' => 'Macedonia', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia, Federated States Of', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'AN' => 'Netherlands Antilles', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestinian Territory, Occupied', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico', 'QA' => 'Qatar', 'RE' => 'Reunion', 'RO' => 'Romania', 'RU' => 'Russian Federation', 'RW' => 'Rwanda', 'BL' => 'Saint Barthelemy', 'SH' => 'Saint Helena', 'KN' => 'Saint Kitts And Nevis', 'LC' => 'Saint Lucia', 'MF' => 'Saint Martin', 'PM' => 'Saint Pierre And Miquelon', 'VC' => 'Saint Vincent And Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome And Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'GS' => 'South Georgia And Sandwich Isl.', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SJ' => 'Svalbard And Jan Mayen', 'SZ' => 'Swaziland', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syrian Arab Republic', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad And Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'TC' => 'Turks And Caicos Islands', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States', 'UM' => 'United States Outlying Islands', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Viet Nam', 'VG' => 'Virgin Islands, British', 'VI' => 'Virgin Islands, U.S.', 'WF' => 'Wallis And Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe'
	);

	CONST REGION_US = array(
		"AK"=>"Alaska","AL"=>"Alabama","AR"=>"Arkansas","AS"=>"American Samoa","AZ"=>"Arizona","CA"=>"California","CO"=>"Colorado","CT"=>"Connecticut","DC"=>"District of Columbia","DE"=>"Delaware","FL"=>"Florida","GA"=>"Georgia","GU"=>"Guam","HI"=>"Hawaii","IA"=>"Iowa","ID"=>"Idaho","IL"=>"Illinois","IN"=>"Indiana","KS"=>"Kansas","KY"=>"Kentucky","LA"=>"Louisiana","MA"=>"Massachusetts","MD"=>"Maryland","ME"=>"Maine","MI"=>"Michigan","MN"=>"Minnesota","MO"=>"Missouri","MS"=>"Mississippi","MT"=>"Montana","NC"=>"North Carolina","ND"=>"North Dakota","NE"=>"Nebraska","NH"=>"New Hampshire","NJ"=>"New Jersey","NM"=>"New Mexico","NV"=>"Nevada","NY"=>"New York","OH"=>"Ohio","OK"=>"Oklahoma","OR"=>"Oregon","PA"=>"Pennsylvania","PR"=>"Puerto Rico","RI"=>"Rhode Island","SC"=>"South Carolina","SD"=>"South Dakota","TN"=>"Tennessee","TX"=>"Texas","UT"=>"Utah","VA"=>"Virginia","VI"=>"Virgin Islands","VT"=>"Vermont","WA"=>"Washington","WI"=>"Wisconsin","WV"=>"West Virginia","WY"=>"Wyoming"
	);

	const REGION_CA = array(
		'AB' => "Alberta",'BC' => "British Columbia",'MB' => "Manitoba",'NB' => "New Brunswick",'NL' => "Newfoundland",'NT' => "Northwest Territories",'NS' => "Nova Scotia",'NU' => "Nunavut",'ON' => "Ontario",'PE' => "Prince Edward Island",'QC' => "Quebec",'SK' => "Saskatchewan",'YT' => "Yukon"
	  );


}
?>
