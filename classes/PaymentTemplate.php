<?php
namespace OnlineClassPayments;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
class PaymentTemplate extends ModelLite {

    protected $table = 'posts';
	protected $primaryKey = 'ID';
	### Fields that can be passed 
    protected $fillable = ["ID","post_type","post_content","post_title","post_name","post_excerpt","post_date","post_author"];	  
    protected $settings =["fontsize"=>12,"margin"=>.25];
    
	### Default Values
	protected $attributes = [        
        'post_type' => 'onlineclasspayments',
        'post_status'=> 'private',
        'comment_status'=>'closed',
        'ping_status'=>'closed'
    ];    
    
	const CREATED_AT = 'post_date';
	const UPDATED_AT = 'post_modified'; 

    static public function get_by_name($name){
        $temp=self::get(array("post_name='".$name."'","post_type='onlineclasspayments'"));
        return $temp[0];
    }

    
    public static function find($id){
        $r=parent::find($id);
        ### post_excerpt is used to store settings. Use this to break apart to field post_excerpt_settingname. example: post_excerpt_fontsize,post_excerpt_margin
        $r->settings_decode();          
        return $r;
    }
    
    public static function get($where=array(),$orderby="",$settings=false){
        $result=parent::get($where,$orderby,$settings);
        ### post_excerpt is used to store settings. Use this to break apart to field post_excerpt_settingname. example: post_excerpt_fontsize,post_excerpt_margin
        foreach($result as $r){
            $r->settings_decode();
            $return[]=$r;            
        }        
        return $return;
    }

    public function settings_decode(){
        if ($this->post_excerpt){
            $settings=json_decode($this->post_excerpt);
        }else{
            $settings=$this->settings;
        }
        foreach($settings as $f=>$v){
            $field="post_excerpt_".$f;
            $this->$field=$v;
        }
    }

    public function edit(){
        $primaryKey=$this->primaryKey;
        if ($this->ID){            
        ?><h2>Editing Client Template #<?php print $this->ID;?></h2>
        <?php 
        }else{
            ?><h2>New Client Template <?php print self::input('CopyPaymentTemplateId','get')?" Copy of #".self::input('CopyPaymentTemplateId','get'):""?></h2>
        <?php
        }?>
        <form method="post" action="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get').'&'.$primaryKey.'='.$this->$primaryKey)?>">
		<input type="hidden" name="table" value="<?php print esc_attr($this->table)?>"/>
		<input type="hidden" name="<?php print esc_attr($primaryKey)?>" value="<?php print esc_attr($this->$primaryKey)?>"/>
        <input type="hidden" name="post_type" value="onlineclasspayments"/>
        <input type="hidden" name="post_author" value="<?php print esc_attr($this->post_author?$this->post_author:get_current_user_id())?>"/>
        <input type="hidden" name="post_date" value="<?php print esc_attr($this->post_date?$this->post_date:date("Y-m-d H:i:s"))?>"/>

        
        <table>
            <tr><td align="right"><strong>Template Title</strong></td><td><input style="width: 300px" type="text" name="post_name" value="<?php print esc_attr($this->post_name)?>"> <em>Example: client-default</em></td></tr>
            <tr><td align="right"><strong>Subject</strong></td><td><input style="width: 600px" type="text" name="post_title" value="<?php print esc_attr($this->post_title)?>"> <em>Appears in the subject of an e-mail, but does not print on .pdf letter export</em></td></tr>
            <tr><td align="right"><strong>Default Font Size:</strong></td><td><input type="number" step=".1" name="post_excerpt_fontsize" value="<?php print esc_attr($this->post_excerpt_fontsize?$this->post_excerpt_fontsize:12)?>"> <em>Default font size to use on letter</em></td></tr>
            <tr><td align="right"><strong>Default Margin:</strong></td><td><input type="number" step=".01" name="post_excerpt_margin" value="<?php print esc_attr($this->post_excerpt_margin?$this->post_excerpt_margin:.25)?>"> <em>Margin in Inches</em></td></tr>
            <tr><td  colspan="2"><div><strong>Message:</strong></div><?php            
            wp_editor($this->post_content, 'post_content',array("media_buttons" => false,"wpautop"=>false));
            ?></td></tr>
            
            <tr><td colspan="2">
                <button type="submit" class="primary" name="Function" value="Save">Save</button>
                <button type="submit" name="Function" class="secondary" value="Cancel" formnovalidate="">Cancel</button>
                
                <button type="submit" name="Function" class="secondary" value="pdfTemplatePreview" formnovalidate="">Preview</button>

                <?php if ($this->$primaryKey){ ?>
                <button type="submit" name="Function" value="Delete">Delete</button>
                <?php }?>
                </td></tr>
		    </table>
                <div>The following are Available Tags for Client Templates:</div>
                <table class="dp">
                <tr><th>Tag</th><th>Description/Example</th></tr>
                <tr><th colspan=2>Client Fields</th></tr>   
                <tr><td>##Name##</td><td>Client Name(s)</td></tr>
                <tr><td>##Address##</td><td>If filled out</td></tr>
                <tr><th colspan=2>Payment Response Fields</th></tr>
                <tr><td>##Gross##</td><td>Total Amount (before frees removed)</td></tr>                
                <tr><td>##Date##</td><td>Date Sent or on Check</td></tr>
                <tr><td>##ItemName##</td><td>Item Name</td></tr>
                <tr><td>##ItemDescription##</td><td>Item Descrtion</td></tr>
                <tr><td>##ItemQuantity##</td><td>Item Quantity</td></tr>
                <tr><td>##Year##</td><td>Year Sent or on Check</td></tr>                

                <tr><th colspan=2>Setting Fields - <a target="settings" href="?page=onlineclasspayments-settings&tab=cv">edit these here</a></th></tr>
                <?php
                foreach(CustomVariables::variables as $var){
                    if (substr($var,0,strlen("Quickbooks"))=="Quickbooks") continue;
                    if (substr($var,0,strlen("Paypal"))=="Paypal") continue;                    
                    ?>
                <tr><td>##<?php print esc_html($var)?>##</td><td>Currently Set to: <strong><?php print get_option( CustomVariables::base.'_'.$var)?></strong></td></tr>
                <?php }
                ?>
                </table>


        <?php        
    }

    public function pdf_preview(){
        $type=Payment::pdf_class_check();
        if ($type!='tcpdf'){
            self::display_error("TCPDF is required to generate this PDF.");
            wp_die();
        }
        ob_clean();
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $margin=($this->post_excerpt_margin?$this->post_excerpt_margin:.25)*72;
        $pdf->SetMargins($margin,$margin,$margin);
        $pdf->SetFont('helvetica', '', ($this->post_excerpt_fontsize?$this->post_excerpt_fontsize:12));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);         
        $pdf->AddPage();

        $pdf->writeHTML($this->post_content, true, false, true, false, '');       
        if ($pdf->Output($this->post_name.".pdf", 'D')){
            return true;
        }else return false;
    }

    public function post_to_settings($type='post'){
        $prefix='post_excerpt_';
        $setting=[];
        foreach($this->settings as $setting=>$default){
            $v=self::input($prefix.$setting,$type);
            $settings[$setting]=$v?$v:$default;
        }
        
        return wp_json_encode($settings);
    }    

    static public function request_handler(){        
        $wpdb=self::db();  
        if (self::input('Function','post') == 'Save' && self::input('table','post')=="posts" && self::input('post_type','post') == 'onlineclasspayments'){
            $template=new self(self::input_model('post'));
            $template->post_excerpt=$template->post_to_settings('post');          
            $template->post_modified=time();
            if ($template->save()){
                self::display_notice("Template #".$template->ID." ".$template->post_name." saved.");
               // $template->full_view();
                //return true;
            }

        }elseif (self::input('PaymentTemplateId','get')){
            $t=self::find(self::input('PaymentTemplateId','get'));
            if (self::input('edit','get')=="t"){  
                //self::dump($t) ;                     
                $t->edit();
            }else{
                $t->view();
            }           
            return true;
        }elseif(self::input('CopyPaymentTemplateId','get')){
            $t=self::find(self::input('CopyPaymentTemplateId','get'));
            $t->ID='';
            $t->post_name.='-copy'.self::input('CopyPaymentTemplateId','get');
            $t->edit();           
            return true;
        }elseif(self::input('CreatePaymentTemplateId','get')){
            $t=new self();            
            $t->edit();           
            return true;
        }    
    }
  
    static function list(){       
        $wpdb=self::db();          
        $SQL="SELECT * FROM ".self::get_table_name()." WHERE post_type='onlineclasspayments' AND post_parent=0 Order BY post_name,post_title";
        $results = $wpdb->get_results($SQL);        
        ?><h2>Template List</h2>
        <div><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get'))?>&CreatePaymentTemplateId=t&edit=t">Add Blank Template</a></div>
        <table class="dp"><tr><th>Template</th><th>Subject</th><th>Body</th><th></th></tr>
        <?php
        foreach ($results as $r){ 
            ?><tr><td><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get').'&PaymentTemplateId='.$r->ID)?>&edit=t"><?php print esc_html($r->post_name)?></a></td>
                <td><?php print esc_html($r->post_title)?></td>
                <td><?php print substr(strip_tags($r->post_content,array('<p>','<br>')),0,160)?>...</td>
                <td><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get').'&CopyPaymentTemplateId='.$r->ID)?>&edit=t">Copy</a></td>
            </tr><?php
        }
        ?></table><?php
    }
}