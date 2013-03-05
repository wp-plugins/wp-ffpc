<?php
/**
 * part of WordPress plugin WP-FFPC
 */

/* check for WP cache enabled*/
if ( !WP_CACHE )
	return false;

/* check for config */
if (!isset($wp_ffpc_config))
	return false;

/* request uri */
$wp_ffpc_uri = $_SERVER['REQUEST_URI'];
/* query string */
$wp_ffpc_qs = strpos($wp_ffpc_uri, '?');

/* no cache for uri with query strings, things usually go bad that way */
if ($wp_ffpc_qs !== false)
	return false;

/* no cache for post request (comments, plugins and so on) */
if ($_SERVER["REQUEST_METHOD"] == 'POST')
	return false;

/**
 * Try to avoid enabling the cache if sessions are managed
 * with request parameters and a session is active
 */
if (defined('SID') && SID != '')
	return false;

/* no cache for pages starting with /wp- like WP admin */
if (strpos($wp_ffpc_uri, '/wp-') !== false)
	return false;

/* no cache for robots.txt */
if ( strpos($wp_ffpc_uri, 'robots.txt') )
	return false;

/* multisite files can be too large for memcached */
if (function_exists('is_multisite') && is_multisite() && strpos($wp_ffpc_uri, '/files/') )
	return false;


/* no cache for logged in users */
if (!$wp_ffpc_config['cache_loggedin']) {
	foreach ($_COOKIE as $n=>$v) {
		// test cookie makes to cache not work!!!
		if ($n == 'wordpress_test_cookie') continue;
		// wp 2.5 and wp 2.3 have different cookie prefix, skip cache if a post password cookie is present, also
		if ( (substr($n, 0, 14) == 'wordpressuser_' || substr($n, 0, 10) == 'wordpress_' || substr($n, 0, 12) == 'wp-postpass_') && !$wp_ffpc_config['cache_loggedin'] ) {
			return false;
		}
	}
}

global $wp_ffpc_backend_status;
$wp_ffpc_backend_status = wp_ffpc_init( );

/* check alive status of backend */
if ( !$wp_ffpc_backend_status )
	return false;

/* use the full accessed URL string as key, same will be generated by nginx as well
   we need a data and a meta key: data is string only with content, meta is not used in nginx */
global $wp_ffpc_data_key_protocol;
$wp_ffpc_data_key_protocol = empty ( $_SERVER['HTTPS'] ) ? 'http://' : 'https://';

global $wp_ffpc_data_key;
$wp_ffpc_data_key = $wp_ffpc_config['prefix_data'] . $wp_ffpc_data_key_protocol . $_SERVER['HTTP_HOST'] . $wp_ffpc_uri;
global $wp_ffpc_meta_key;
$wp_ffpc_meta_key = $wp_ffpc_config['prefix_meta'] . $wp_ffpc_data_key_protocol . $_SERVER['HTTP_HOST'] . $wp_ffpc_uri;

/* search for valid meta entry */
global $wp_ffpc_meta;
$wp_ffpc_meta = wp_ffpc_get ( $wp_ffpc_meta_key );

/* meta is corrupted or empty */
if ( !$wp_ffpc_meta ) {
	wp_ffpc_start();
	return;
}

/* search for valid data entry */
global $wp_ffpc_data;
$uncompress = ( isset($wp_ffpc_meta['compressed']) ) ? $wp_ffpc_meta['compressed'] : false;
$wp_ffpc_data = wp_ffpc_get ( $wp_ffpc_data_key , $uncompress );

/* data is corrupted or empty */
if ( !$wp_ffpc_data ) {
	wp_ffpc_start();
	return;
}

/* 404 status cache */
if ($wp_ffpc_meta['status'] == 404) {
	header("HTTP/1.1 404 Not Found");
	flush();
	die();
}

/* server redirect cache */
if ($wp_ffpc_meta['redirect_location']) {
	header('Location: ' . $wp_ffpc_meta['redirect_location']);
	flush();
	die();
}

/* page is already cached on client side (chrome likes to do this, anyway, it's quite efficient) */
if (array_key_exists("HTTP_IF_MODIFIED_SINCE", $_SERVER) && !empty($wp_ffpc_meta['lastmodified']) ) {
	$if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]));
	/* check is cache is still valid */
	if ( $if_modified_since >= $wp_ffpc_meta['lastmodified'] ) {
		header("HTTP/1.0 304 Not Modified");
		flush();
		die();
	}
}

/* data found & correct, serve it */
header('Content-Type: ' . $wp_ffpc_meta['mime']);
/* don't allow browser caching of page */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
header('Pragma: no-cache');
/* expire at this very moment */
header('Expires: ' . gmdate("D, d M Y H:i:s", time() ) . " GMT");

/* if shortlinks were set */
if (!empty ( $wp_ffpc_meta['shortlink'] ) )
	header('Link:<'. $wp_ffpc_meta['shortlink'] .'>; rel=shortlink');

/* if last modifications were set (for posts & pages) */
if ( !empty($wp_ffpc_meta['lastmodified']) )
	header('Last-Modified: ' . gmdate("D, d M Y H:i:s", $wp_ffpc_meta['lastmodified'] ). " GMT");

/* only set when not multisite, fallback to HTTP HOST */
$wp_ffpc_pingback_url = (empty( $wp_ffpc_config['url'] )) ? $_SERVER['HTTP_HOST'] : $wp_ffpc_config['url'];

/* pingback additional header */
if ($wp_ffpc_config['pingback_status'])
	header('X-Pingback: ' . $wp_ffpc_pingback_url . '/xmlrpc.php' );

/* for debugging */
if ($wp_ffpc_config['debug'])
	header('X-Cache-Engine: WP-FFPC with ' . $wp_ffpc_config['cache_type']);

/* HTML data */
echo $wp_ffpc_data;
flush();
die();

/**
 *  FUNCTIONS
 */

/**
 * starts caching
 *
 */
function wp_ffpc_start( ) {
	ob_start('wp_ffpc_callback');
}

/**
 * write cache function, called when page generation ended
 */
function wp_ffpc_callback($buffer) {
	global $wp_ffpc_config;
	global $wp_ffpc_data;
	global $wp_ffpc_meta;
	global $wp_ffpc_redirect;
	global $wp_ffpc_meta_key;
	global $wp_ffpc_data_key;

	/* no is_home = error */
	if (!function_exists('is_home'))
		return $buffer;

	/* no <body> close tag = not HTML, don't cache */
	if (strpos($buffer, '</body>') === false)
		return $buffer;

	/* reset meta to solve conflicts */
	$wp_ffpc_meta = array();

	/* WP is sending a redirect */
	if ($wp_ffpc_redirect) {
			$wp_ffpc_meta['redirect_location'] = $wp_ffpc_redirect;
			wp_ffpc_write();
			return $buffer;
	}

	/* trim unneeded whitespace from beginning / ending of buffer */
	$buffer = trim($buffer);

	/* Can be a trackback or other things without a body.
	   We do not cache them, WP needs to get those calls. */
	if (strlen($buffer) == 0)
		return '';

	if ( is_home() )
		$wp_ffpc_meta['type'] = 'home';
	elseif (is_feed() )
		$wp_ffpc_meta['type'] = 'feed';
	elseif ( is_archive() )
		$wp_ffpc_meta['type'] = 'archive';
	elseif ( is_single() )
		$wp_ffpc_meta['type'] = 'single';
	else if ( is_page() )
		$wp_ffpc_meta['type'] = 'page';
	else
		$wp_ffpc_meta['type'] = 'unknown';

	/* check if caching is disabled for page type */
	$nocache_key = 'nocache_'. $wp_ffpc_meta['type'];

	if ( $wp_ffpc_config[$nocache_key] == 1 ) {
		return $buffer;
	}

	if ( is_404() )
		$wp_ffpc_meta['status'] = 404;

	/* feed is xml, all others forced to be HTML */
	if ( is_feed() )
		$wp_ffpc_meta['mime'] = 'text/xml;charset=';
	else
		$wp_ffpc_meta['mime'] = 'text/html;charset=';

	/* set mimetype */
	$wp_ffpc_meta['mime'] = $wp_ffpc_meta['mime'] . $wp_ffpc_config['charset'];

	/* get shortlink, if possible */
	if (function_exists('wp_get_shortlink'))
	{
		$shortlink = wp_get_shortlink( );
		if (!empty ( $shortlink ) )
			$wp_ffpc_meta['shortlink'] = $shortlink;
	}

	/* try if post is available
		if made with archieve, last listed post can make this go bad
	*/
	global $post;
	if ( !empty($post) && ( $wp_ffpc_meta['type'] == 'single' || $wp_ffpc_meta['type'] == 'page' ) && !empty ( $post->post_modified_gmt ) )
	{
		/* get last modification data */
		$wp_ffpc_meta['lastmodified'] = strtotime ( $post->post_modified_gmt );
	}

	/* APC compression */
	$compress = ( ($wp_ffpc_config['cache_type'] == 'apc') && $wp_ffpc_config['apc_compress'] ) ? true : false;
	$wp_ffpc_meta['compressed'] = $compress;

	/* sync all http and https requests if enabled */
	if ( !empty($wp_ffpc_config['sync_protocols']) )
	{
		if ( !empty( $_SERVER['HTTPS'] ) )
		{
			$sync_from = 'http://' . $_SERVER['SERVER_NAME'];
			$sync_to = 'https://' . $_SERVER['SERVER_NAME'];
		}
		else
		{
			$sync_from = 'https://' . $_SERVER['SERVER_NAME'];
			$sync_to = 'http://' . $_SERVER['SERVER_NAME'];
		}

		$buffer = str_replace ( $sync_from, $sync_to, $buffer );
	}

	/* set meta */
	wp_ffpc_set ( $wp_ffpc_meta_key, $wp_ffpc_meta );

	/* set meta per entry for nginx */
	/*
	foreach ( $wp_ffpc_meta as $subkey => $subdata )
	{
		$subkey = str_replace ( $wp_ffpc_config['prefix_meta'], $wp_ffpc_config['prefix_meta'] . $subkey . "-", $wp_ffpc_meta_key );
		wp_ffpc_set ( $subkey, $subdata );
	}
	*/

	/* set data */
	//$data = $buffer;
	wp_ffpc_set ( $wp_ffpc_data_key, $buffer, $compress );

	/* vital for nginx, make no problem at other places */
	header("HTTP/1.1 200 OK");

	/* echoes HTML out */
	return $buffer;
}

?>
