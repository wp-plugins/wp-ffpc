<?php
/*
Plugin Name: WP-FFPC
Version: 1.0
Plugin URI: http://petermolnar.eu/wordpress/wp-ffpc
Description: WordPress cache plugin for memcached & nginx - unbeatable speed
Author: Peter Molnar
Author URI: http://petermolnar.eu/
License: GPL2
*/

/*  Copyright 2010-2013 Peter Molnar  (email : hello@petermolnar.eu )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

		function get_options ( ) {
			$defaults = array (
				// TODO: unix socket?
				'memcached_hosts'=>'127.0.0.1:11211',

				'expire'=>300,
				'invalidation_method'=>0,

				'prefix_meta' =>'meta-',
				'prefix_data' =>'data-',

				'default_charset' => 'utf-8',

				'pingback'=> false,

				'log_info' => false,
				'log' => true,


				'cache_type' => 'memcached',

				'cache_loggedin' => false,
				'nocache_home' => false,
				'nocache_feed' => false,
				'nocache_archive' => false,
				'nocache_single' => false,
				'nocache_page' => false,

				'sync_protocols' => false,
				'persistent' => false,

				'response_header' = false,
			);

?>
