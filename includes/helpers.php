<?php
namespace ReedCRM;

if ( ! defined( 'ABSPATH' ) ) exit;

function sanitize_api_key($key){
    return sanitize_text_field(trim($key));
}
