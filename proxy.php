<?php
/**
 * AJAX Cross Domain (PHP) Proxy 0.8
 *    by Iacovos Constantinou (http://www.iacons.net)
 * 
 * Released under CC-GNU GPL
 *
 *
 * This version of php-cross-domain-proxy has been forked and modified from it's original source by Jeff Baker (http://a.ntivir.us).
 * This may include undocumented changes, but for the most part, please refer to the original repository for details about this file.
 * Original Repository: https://github.com/softius/php-cross-domain-proxy
 * Forked Repository: https://github.com/sinerasis/php-cross-domain-proxy
 *
 */

/*** CONFIGURATION/SETTINGS ***/

// Enable/disable filter for cross domain requests. Recommended: true
define('CSAJAX_FILTERS', true);

// If true, $valid_requests should hold domains (example: a.example.com, b.example.com, example.com)
// If false, $valid_requests should hold entire URLs without parameters (example: http://example.com/this/is/long/url/)
// Recommended: false (for security reasons - do not forget that anyone can access your proxy)
define('CSAJAX_FILTER_DOMAIN', false);

// Enable/disable debug messages
define('CSAJAX_DEBUG', false);

// Array of valid domains
$valid_requests = array(
	// 'example.com'
);

/*** END CONFIGURATION/SETTINGS ***/

// output debug messages
function csajax_debug_message($message) {
	if(CSAJAX_DEBUG) {
		print $message . PHP_EOL;
	}
}

// identify request headers
$request_headers = array();
foreach($_SERVER as $key=>$value) {
	if(substr($key, 0, 5) == 'HTTP_') {
		$headername = str_replace('_', ' ', substr($key, 5));
		$headername = str_replace(' ', '-', ucwords(strtolower($headername)));
		if(!in_array($headername, array('Host', 'X-Proxy-Url'))) {
			$request_headers[] = "$headername: $value";
		}
	}
}

// identify request method, url and params
$request_method = $_SERVER['REQUEST_METHOD'];
if('GET' == $request_method) {
	$request_params = $_GET;
} else if('POST' == $request_method) {
	$request_params = $_POST;
	
	if(empty( $request_params)) {
		$data = file_get_contents('php://input');
		
		if(!empty($data)) {
			$request_params = $data;
		}
	}
} else if('PUT' == $request_method || 'DELETE' == $request_method) {
	$request_params = file_get_contents('php://input');
} else {
	$request_params = null;
}

// Get URL from `csurl` in GET or POST data, before falling back to X-Proxy-URL header.
$request_url = isset($_REQUEST['csurl']) ? urldecode($_REQUEST['csurl']) : urldecode($_SERVER['HTTP_X_PROXY_URL']);
$p_request_url = parse_url($request_url);

// csurl may exist in GET request methods
if(is_array($request_params) && array_key_exists('csurl', $request_params)) {
	unset($request_params['csurl']);
}

// ignore requests for proxy :)
if(preg_match('!' . $_SERVER['SCRIPT_NAME'] . '!', $request_url) || empty($request_url) || count($p_request_url) == 1) {
	csajax_debug_message('Invalid request - make sure that csurl variable is not empty');
	exit;
}

// check against valid requests
if(CSAJAX_FILTERS) {
	$parsed = $p_request_url;
	
	if(CSAJAX_FILTER_DOMAIN) {
		if (!in_array( $parsed['host'], $valid_requests)) {
			csajax_debug_message('Invalid domain - ' . $parsed['host'] . ' does not included in valid requests');
			exit;
		}
	} else {
		$check_url = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
		$check_url .= isset($parsed['user']) ? $parsed['user'] . ($parsed['pass'] ? ':' . $parsed['pass'] : '') . '@' : '';
		$check_url .= isset($parsed['host']) ? $parsed['host'] : '';
		$check_url .= isset($parsed['port']) ? ':' . $parsed['port'] : '';
		$check_url .= isset($parsed['path']) ? $parsed['path'] : '';
		
		if(!in_array($check_url, $valid_requests)) {
			csajax_debug_message('Invalid domain - ' . $request_url . ' does not included in valid requests');
			exit;
		}
	}
}

// append query string for GET requests
if ($request_method == 'GET' && count($request_params) > 0 && (!array_key_exists('query', $p_request_url) || empty($p_request_url['query']))) {
	$request_url .= '?' . http_build_query($request_params);
}

// let the request begin
$ch = curl_init($request_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);   // (re-)send headers
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);	 // return response
curl_setopt($ch, CURLOPT_HEADER, true);	   // enabled response headers

// add data for POST, PUT or DELETE requests
if('POST' == $request_method) {
	if(isset($_FILES) && !empty($_FILES)) {
		// if we have files, we want to include them also
		foreach($_FILES as $name=>$details) {
			// tells curl where our files are
			$request_params[$name] = '@' . $details['tmp_name'];
		}
		// by leaving our params as an array, the request is automatically switched to multipart/form-data later on
		$post_data = $request_params;
	} else {
		$post_data = is_array($request_params) ? http_build_query($request_params) : $request_params;
	}
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS,  $post_data);
} else if('PUT' == $request_method || 'DELETE' == $request_method) {
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_method);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $request_params);
}

// retrieve response (headers and content)
$response = curl_exec($ch);
curl_close($ch);

// split response to header and content
list($response_headers, $response_content) = preg_split('/(\r\n){2}/', $response, 2);

// (re-)send the headers
$response_headers = preg_split('/(\r\n){1}/', $response_headers);

foreach ($response_headers as $key => $response_header) {
	// Rewrite the `Location` header, so clients will also use the proxy for redirects.
	if(preg_match('/^Location:/', $response_header)) {
		list($header, $value) = preg_split('/: /', $response_header, 2);
		$response_header = 'Location: ' . $_SERVER['REQUEST_URI'] . '?csurl=' . $value;
	}
	if(!preg_match('/^(Transfer-Encoding):/', $response_header)) {
		header($response_header, false);
	}
}

// finally, output the content
print($response_content);

?>