<?php
if(!defined('ABSPATH'))exit;
function sfv3_setup(){add_theme_support('post-thumbnails');add_theme_support('title-tag');register_nav_menus(['primary'=>'منوی اصلی']);}
add_action('after_setup_theme','sfv3_setup');
function sfv3_assets(){wp_enqueue_style('sfv3-style',get_stylesheet_uri(),[], '3.0.0');}
add_action('wp_enqueue_scripts','sfv3_assets');
