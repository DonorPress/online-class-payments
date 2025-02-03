<?php
/*
Plugin Name: Online Class Payment
Description: A plugin to track paypal payments made for Online Classes
Version: 0.1
Author: Denver Steiner
*/


// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

### recommended to run "composer install" on the plugin directory to add PDF and other functionality, but not required
if (file_exists(__DIR__ . '/vendor/autoload.php')){
	require_once __DIR__ . '/vendor/autoload.php';
}

use OnlineClassPayments\CustomVariables; 
use OnlineClassPayments\Paypal;
use OnlineClassPayments\Client; 
use OnlineClassPayments\Payment;

// it inserts the entry in the admin menu
add_action('admin_menu', 'onlineclasspayments_plugin_create_menu_entry');
register_activation_hook( __FILE__, 'onlineclasspayments_plugin_create_tables');

function onlineclasspayments_header_check() {
    /* Place things here that need loaded in header before page loads - like .pdf exports, .csv dumps, .json etc.*/
    if (CustomVariables::input('Function')){

    }
    wp_enqueue_style('onlineclasspaymentsPluginStylesheet', plugins_url( '/css/style.css', __FILE__ ), false);
	//wp_enqueue_style('onlineclasspaymentsPluginAutoComplete', plugins_url( '/css/autocomplete.min.css', __FILE__ ), false);
	//wp_enqueue_script('onlineclasspaymentsPluginDefault', plugins_url( '/js/onlineclasspayments.js', __FILE__ ), false);
}

//wp_register_style( 'onlineclasspaymentsPluginStylesheet', plugins_url( '/css/style.css', __FILE__ ) );
add_action( 'admin_init', 'onlineclasspayments_header_check',1);


// creating the menu entries
function onlineclasspayments_plugin_create_menu_entry() {
// icon image path that will appear in the menu
    $icon = plugins_url('/images/skype-brands-solid.svg', __FILE__);
    //add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
    // adding the main manu entry
    add_menu_page('Online Classes', 'Online Classes', 'edit_posts', 'onlineclasspayments-index', 'onlineclasspayments_show_index', $icon);
    // adding the sub menu entry
    add_submenu_page( 'onlineclasspayments-index', 'Reports', 'Reports', 'edit_posts', 'onlineclasspayments-reports', 'onlineclasspayments_show_reports',2 );	

    if (Paypal::is_setup()) add_submenu_page( 'onlineclasspayments-index', 'Paypal', 'Paypal', 'edit_posts', 'onlineclasspayments-paypal', 'onlineclasspayments_show_paypal',4);
    add_submenu_page( 'onlineclasspayments-index', 'Settings', 'Settings', 'edit_posts', 'onlineclasspayments-settings', 'onlineclasspayments_show_settings',5);
}

// function triggered in add_menu_page
function onlineclasspayments_show_index() {
    include('onlineclasspayments-index.php');
}

// function triggered in add_submenu_page
function onlineclasspayments_show_reports() {
    include('onlineclasspayments-reports.php');
}

function onlineclasspayments_show_settings() {
    include('onlineclasspayments-settings.php');
}

function onlineclasspayments_show_paypal() {
    include('onlineclasspayments-paypal.php');
}


function onlineclasspayments_plugin_base_dir(){
    return str_replace("\\","/",dirname(__FILE__));
}

function onlineclasspayments_tables(){
    return ["Client","Payment","PaymentReceipt"];
}

function onlineclasspayments_plugin_create_tables() {	
    $tableNames=onlineclasspayments_tables();
    foreach($tableNames as $table){
        $class="OnlineClassPayments\\".$table; 
        $class::create_table();
    }
}

function onlineclasspayments_upload_dir( $dirs ) { 
    //keep uploads in their own onlineclasspayments directory, outside normal uploads
    $dirs['subdir'] = '/onlineclasspayments';
    $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
    $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];
    return $dirs;
}

function onlineclasspayments_nuke(){
	CustomVariables::nuke_it(['droptable'=>"t",'dropfields'=>"t",'rebuild'=>"t"]);
}

//adapted from: https://www.php-fig.org/psr/psr-4/examples/ -> used to autolad the onlineclasspayments namespace
spl_autoload_register(function ($class) {

    // project-specific namespace prefix
    $prefix = 'OnlineClassPayments\\';

    // base directory for the namespace prefix
    $base_dir = __DIR__ . '/classes/';

    // does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // get the relative class name
    $relative_class = substr($class, $len);

    // replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // if the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});