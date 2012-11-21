<?php
/*
Plugin Name: bbPress Topic/Post Converter
Description: Allow bbPress moderators to convert topics to posts, and posts to topics. 
Version: 0.1
Author: Jennifer M. Dodd
Author URI: http://uncommoncontent.com/
Text Domain: bbpress-topic-post-converter
*/ 

/*
	Copyright 2012 Jennifer M. Dodd <jmdodd@gmail.com>

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/


if ( ! defined( 'ABSPATH' ) ) exit;


define( 'UCC_BTPC_DIR', plugin_dir_path( __FILE__ ) );


// Only load if bbPress is active and user can moderate. 
if ( in_array( 'bbpress/bbpress.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) { 
	include( UCC_BTPC_DIR . '/includes/ucc-btpc-loader.php' );
	new UCC_bbPress_Topic_Post_Converter;
}
