<?php
 class Restler { const VERSION = '2.0.2'; public $url; public $request_method; public $request_format; public $request_data=array(); public $cache_dir; public $base_dir; public $response = 'DefaultResponse'; public $response_format; protected $production_mode; protected $routes= array(); protected $format_map = array(); protected $service_class_instance; protected $service_method; protected $auth_classes = array(); protected $error_classes = array(); private $codes = array( 100 => 'Continue', 101 => 'Switching Protocols', 200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 306 => '(Unused)', 307 => 'Temporary Redirect', 400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Requested Range Not Satisfiable', 417 => 'Expectation Failed', 500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported' ); protected $cached; public function __construct ($production_mode = FALSE) { $this->production_mode = $production_mode; $this->cache_dir = getcwd(); $this->base_dir = RESTLER_PATH; } public function __destruct () { if ($this->production_mode && !$this->cached) { $this->saveCache(); } } public function refreshCache () { $this->routes = array(); $this->cached = FALSE; } public function setSupportedFormats () { $args = func_get_args(); $extensions = array(); foreach ($args as $class_name) { if(!is_string($class_name) || !class_exists($class_name)){ throw new Exception("$class_name is not a vaild Format Class."); } $obj = new $class_name; if(! $obj instanceof iFormat){ throw new Exception('Invalid format class; must implement '. 'iFormat interface'); } foreach ($obj->getMIMEMap() as $extension => $mime) { if(!isset($this->format_map[$extension])) $this->format_map[$extension]=$class_name; if(!isset($this->format_map[$mime])) $this->format_map[$mime]=$class_name; $extensions[".$extension"]=TRUE; } } $this->format_map['default']=$args[0]; $this->format_map['extensions']=array_keys($extensions); } public function addAPIClass($class_name, $base_path = NULL) { if(!class_exists($class_name)){ throw new Exception("API class $class_name is missing."); } $this->loadCache(); if(!$this->cached){ if(is_null($base_path))$base_path=strtolower($class_name); $base_path = trim($base_path,'/'); if(strlen($base_path)>0)$base_path .= '/'; $this->generateMap($class_name, $base_path); } } public function addAuthenticationClass ($class_name, $base_path = NULL) { $this->auth_classes[] = $class_name; $this->addAPIClass($class_name, $base_path); } public function addErrorClass ($class_name) { $this->error_classes[] = $class_name; } public function handleError ($status_code, $error_message = NULL) { $method = "handle$status_code"; $handled = FALSE; foreach ($this->error_classes as $class_name) { if (method_exists($class_name, $method)) { $obj = new $class_name(); $obj->restler = $this; $obj->$method(); $handled = TRUE; } } if($handled)return; $message = $this->codes[$status_code] . (!$error_message ? '' : ': ' . $error_message); $this->setStatus($status_code); $this->sendData( call_user_func( array($this->response, '__formatError'), $status_code, $message) ); } public function handle () { if(empty($this->format_map))$this->setSupportedFormats('JsonFormat'); $this->url = $this->getPath(); $this->request_method = $this->getRequestMethod(); $this->response_format = $this->getResponseFormat(); $this->request_format = $this->getRequestFormat(); if(is_null($this->request_format)){ $this->request_format = $this->response_format; } if($this->request_method == 'PUT' || $this->request_method == 'POST'){ $this->request_data = $this->getRequestData(); } $o = $this->mapUrlToMethod(); if(!isset($o->class_name)){ $this->handleError(404); }else{ try { if($o->method_flag){ $auth_method = '__isAuthenticated'; if(!count($this->auth_classes))throw new RestException(401); foreach ($this->auth_classes as $auth_class) { $auth_obj = new $auth_class(); $auth_obj->restler=$this; $this->applyClassMetadata($auth_class, $auth_obj, $o); if (!method_exists($auth_obj, $auth_method)) { throw new RestException(401, 'Authentication Class '. 'should implement iAuthenticate'); }elseif(!$auth_obj->$auth_method()){ throw new RestException(401); } } } $this->applyClassMetadata(get_class($this->request_format), $this->request_format, $o); $pre_process = '_'.$this->request_format->getExtension().'_'. $o->method_name; $this->service_method = $o->method_name; if($o->method_flag==2)$o=unprotect($o); $object = $this->service_class_instance = new $o->class_name(); $object->restler=$this; if(method_exists($o->class_name, $pre_process)) { call_user_func_array(array($object, $pre_process), $o->arguments); } switch ($o->method_flag) { case 3: $reflection_method = new ReflectionMethod($object, $o->method_name); $reflection_method->setAccessible(TRUE); $result = $reflection_method->invokeArgs($object, $o->arguments); break; case 2: case 1: default: $result = call_user_func_array(array($object, $o->method_name), $o->arguments); } } catch (RestException $e) { $this->handleError($e->getCode(), $e->getMessage()); } } if (isset($result) && $result !== NULL) { $this->sendData($result); } } public function sendData($data) { $data = $this->response_format->encode($data, !$this->production_mode); $post_process = '_'.$this->service_method .'_'. $this->response_format->getExtension(); if(isset($this->service_class_instance) && method_exists($this->service_class_instance,$post_process)){ $data = call_user_func(array($this->service_class_instance, $post_process), $data); } header("Cache-Control: no-cache, must-revalidate"); header("Expires: 0"); header('Content-Type: ' . $this->response_format->getMIME()); header("X-Powered-By: Luracast Restler v".Restler::VERSION); die($data); } public function setStatus($code) { header("{$_SERVER['SERVER_PROTOCOL']} $code ". $this->codes[strval($code)]); } public function removeCommonPath($first, $second, $char='/'){ $first = explode($char, $first); $second = explode($char, $second); while (count($second)){ if($first[0]==$second[0]){ array_shift($first); } else break; array_shift($second); } return implode($char, $first); } public function saveCache() { $file = $this->cache_dir . '/routes.php'; $s = '$o=array();'.PHP_EOL; foreach ($this->routes as $key => $value) { $s .= PHP_EOL.PHP_EOL.PHP_EOL."############### $key ###############" .PHP_EOL.PHP_EOL; $s .= '$o[\''.$key.'\']=array();'; foreach ($value as $ke => $va) { $s .= PHP_EOL.PHP_EOL."#==== $key $ke".PHP_EOL.PHP_EOL; $s .= '$o[\''.$key.'\'][\''.$ke.'\']='.str_replace(PHP_EOL, PHP_EOL."\t", var_export($va, TRUE)).';'; } } $s .= PHP_EOL.'return $o;'; $r=@file_put_contents($file, "<?php $s"); @chmod($file, 0777); if($r===FALSE)throw new Exception( "The cache directory located at '$this->cache_dir' needs to have ". "the permissions set to read/write/execute for everyone in order ". "to save cache and improve performance."); } protected function getPath () { $path = urldecode($this->removeCommonPath($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])); $path = preg_replace('/(\/*\?.*$)|(\/$)/', '', $path); $path = str_replace($this->format_map['extensions'], '', $path); return $path; } protected function getRequestMethod () { $method = $_SERVER['REQUEST_METHOD']; if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])){ $method = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']; } return $method; } protected function getRequestFormat () { $format=NULL; if(isset($_SERVER['CONTENT_TYPE'])){ $mime = explode(';', $_SERVER['CONTENT_TYPE']); $mime = $mime[0]; if($mime==UrlEncodedFormat::MIME){ $format = new UrlEncodedFormat(); }else{ if(isset($this->format_map[$mime])){ $format = $this->format_map[$mime]; $format = is_string($format) ? new $format: $format; $format->setMIME($mime); } } } return $format; } protected function getResponseFormat () { $format; $extension = explode('.', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)); $extension = array_pop($extension); if($extension && isset($this->format_map[$extension])){ $format = $this->format_map[$extension]; $format = is_string($format) ? new $format: $format; $format->setExtension($extension); return $format; } if(isset($_SERVER['HTTP_ACCEPT'])){ $accepts = explode(',', $_SERVER['HTTP_ACCEPT']); foreach ($accepts as $accept) { if($extension && isset($this->format_map[$accept])){ $format = $this->format_map[$accept]; $format = is_string($format) ? new $format: $format; $format->setMIME($accept); return $format; } } } $format = $this->format_map['default']; return is_string($format) ? new $format: $format; } protected function getRequestData() { try{ $r = file_get_contents('php://input'); if(is_null($r))return $_GET; $r =$this->request_format->decode($r); return is_null($r) ? array(): $r; } catch (RestException $e) { $this->handleError($e->getCode(), $e->getMessage()); } } protected function mapUrlToMethod () { if(!isset($this->routes[$this->request_method])){ return array(); } $urls = $this->routes[$this->request_method]; if(!$urls)return array(); $found = FALSE; $this->request_data += $_GET; $params = array('request_data'=>$this->request_data); $params += $this->request_data; foreach ($urls as $url => $call) { $call = (object)$call; if(strstr($url, ':')){ $regex = preg_replace('/\\\:([^\/]+)/', '(?P<$1>[^/]+)', preg_quote($url)); if (preg_match(":^$regex$:", $this->url, $matches)) { foreach ($matches as $arg => $match) { if (isset($call->arguments[$arg])){ $params[$arg] = $match; } } $found = TRUE; break; } }elseif ($url == $this->url){ $found = TRUE; break; } } if($found){ $p = $call->defaults; foreach ($call->arguments as $key => $value) { if(isset($params[$key]))$p[$value] = $params[$key]; } $call->arguments=$p; return $call; } } protected function applyClassMetadata($class_name, $instance, $method_info){ if(isset($method_info->metadata[$class_name]) && is_array($method_info->metadata[$class_name])){ foreach ($method_info->metadata[$class_name] as $property => $value){ if(property_exists($class_name, $property)){ $reflection_property = new ReflectionProperty($class_name, $property); $reflection_property->setValue($instance, $value); } } } } protected function loadCache() { if ($this->cached !== NULL) { return; } $file = $this->cache_dir . '/routes.php'; $this->cached = FALSE; if ($this->production_mode) { if (file_exists($file)) { $routes = include($file); } if (isset($routes) && is_array($routes)) { $this->routes = $routes; $this->cached = TRUE; } } else { } } protected function generateMap ($class_name, $base_path = "") { $reflection = new ReflectionClass($class_name); $class_metadata = parse_doc($reflection->getDocComment()); $methods = $reflection->getMethods( ReflectionMethod::IS_PUBLIC + ReflectionMethod::IS_PROTECTED); foreach ($methods as $method) { $doc = $method->getDocComment(); $arguments = array(); $defaults = array(); $metadata = $class_metadata+parse_doc($doc); $params = $method->getParameters(); $position=0; foreach ($params as $param){ $arguments[$param->getName()] = $position; $defaults[$position] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : NULL; $position++; } $method_flag = $method->isProtected() ? (isRestlerCompatibilityModeEnabled() ? 2 : 3) : (isset($metadata['protected']) ? 1 : 0); $call = array( 'class_name'=>$class_name, 'method_name'=>$method->getName(), 'arguments'=>$arguments, 'defaults'=>$defaults, 'metadata'=>$metadata, 'method_flag'=>$method_flag ); $method_url = strtolower($method->getName()); if (preg_match_all( '/@url\s+(GET|POST|PUT|DELETE|HEAD|OPTIONS)[ \t]*\/?(\S*)/s', $doc, $matches, PREG_SET_ORDER)) { foreach ($matches as $match) { $http_method = $match[1]; $url = rtrim($base_path . $match[2],'/'); $this->routes[$http_method][$url] = $call; } }elseif($method_url[0] != '_'){ if (preg_match_all('/^(GET|POST|PUT|DELETE|HEAD|OPTIONS)/i', $method_url, $matches)) { $http_method = strtoupper($matches[0][0]); $method_url = substr($method_url, strlen($http_method)); }else{ $http_method = 'GET'; } $url = $base_path. ($method_url=='index' || $method_url=='default' ? '' : $method_url); $url = rtrim($url,'/'); $this->routes[$http_method][$url] = $call; foreach ($params as $param){ if($param->getName()=='request_data'){ break; } $url .= $url=='' ? ':' : '/:'; $url .= $param->getName(); $this->routes[$http_method][$url] = $call; } } } } } if(version_compare(PHP_VERSION, '5.3.0') < 0){ require_once 'compat.php'; } class RestException extends Exception { public function __construct($http_status_code, $error_message = NULL) { parent::__construct($error_message, $http_status_code); } } interface iRespond { public function __formatResponse($result); public function __formatError($status_code, $message); } class DefaultResponse implements iRespond { function __formatResponse($result) { return $result; } function __formatError($statusCode, $message) { return array('error' => array('code' => $statusCode, 'message' => $message)); } } interface iAuthenticate { public function __isAuthenticated(); } interface iFormat { public function getMIMEMap(); public function setMIME($mime); public function getMIME(); public function setExtension($extension); public function getExtension(); public function encode($data, $human_readable=FALSE); public function decode($data); } class UrlEncodedFormat implements iFormat { const MIME = 'application/x-www-form-urlencoded'; const EXTENSION = 'post'; public function getMIMEMap() { return array(UrlEncodedFormat::EXTENSION=>UrlEncodedFormat::MIME); } public function getMIME(){ return UrlEncodedFormat::MIME; } public function getExtension(){ return UrlEncodedFormat::EXTENSION; } public function setMIME($mime){ } public function setExtension($extension){ } public function encode($data, $human_readable=FALSE){ return http_build_query($data); } public function decode($data){ parse_str($data,$r); return $r; } public function __toString(){ return $this->getExtension(); } } class JsonFormat implements iFormat { const MIME ='application/json'; const EXTENSION = 'json'; public function getMIMEMap() { return array(JsonFormat::EXTENSION=>JsonFormat::MIME); } public function getMIME(){ return JsonFormat::MIME; } public function getExtension(){ return JsonFormat::EXTENSION; } public function setMIME($mime){ } public function setExtension($extension){ } public function encode($data, $human_readable=FALSE){ return $human_readable ? $this->json_format(json_encode(object_to_array($data))) : json_encode(object_to_array($data)); } public function decode($data){ return object_to_array(json_decode($data)); } private function json_format($json) { $tab = "  "; $new_json = ""; $indent_level = 0; $in_string = FALSE; $len = strlen($json); for($c = 0; $c < $len; $c++) { $char = $json[$c]; switch($char) { case '{': case '[': if(!$in_string) { $new_json .= $char . "\n" . str_repeat($tab, $indent_level+1); $indent_level++; } else { $new_json .= $char; } break; case '}': case ']': if(!$in_string) { $indent_level--; $new_json .= "\n".str_repeat($tab, $indent_level).$char; } else { $new_json .= $char; } break; case ',': if(!$in_string) { $new_json .= ",\n" . str_repeat($tab, $indent_level); } else { $new_json .= $char; } break; case ':': if(!$in_string) { $new_json .= ": "; } else { $new_json .= $char; } break; case '"': if($c==0){ $in_string = TRUE; }elseif($c > 0 && $json[$c-1] != '\\') { $in_string = !$in_string; } default: $new_json .= $char; break; } } return $new_json; } public function __toString(){ return $this->getExtension(); } } class DocParser { private $params=array(); function parse($doc='') { if($doc==''){ return $this->params; } if(preg_match('#^/\*\*(.*)\*/#s', $doc, $comment) === false) return $this->params; $comment = trim($comment[1]); if(preg_match_all('#^\s*\*(.*)#m', $comment, $lines) === false) return $this->params; $this->parseLines($lines[1]); return $this->params; } private function parseLines($lines) { foreach($lines as $line) { $parsedLine = $this->parseLine($line); if($parsedLine === false && !isset($this->params['description'])) { if(isset($desc)){ $this->params['description'] = implode(PHP_EOL, $desc); } $desc = array(); } elseif($parsedLine !== false) { $desc[] = $parsedLine; } } $desc = implode(' ', $desc); if(!empty($desc))$this->params['long_description'] = $desc; } private function parseLine($line) { $line = trim($line); if(empty($line)) return false; if(strpos($line, '@') === 0) { if(strpos($line, ' ')>0){ $param = substr($line, 1, strpos($line, ' ') - 1); $value = substr($line, strlen($param) + 2); }else{ $param = substr($line, 1); $value = ''; } if($this->setParam($param, $value)) return false; } return $line; } private function setParam($param, $value) { if($param == 'param' || $param == 'return') $value = $this->formatParamOrReturn($value); if($param == 'class') list($param, $value) = $this->formatClass($value); if(empty($this->params[$param])) { $this->params[$param] = $value; } else if($param == 'param'){ $arr = array($this->params[$param], $value); $this->params[$param] = $arr; } else { $this->params[$param] = $value + $this->params[$param]; } return true; } private function formatClass($value) { $r = preg_split("[\(|\)]",$value); if(is_array($r)){ $param = $r[0]; parse_str($r[1],$value); foreach ($value as $key => $val) { $val = explode(',', $val); if(count($val)>1)$value[$key]=$val; } }else{ $param='Unknown'; } return array($param, $value); } private function formatParamOrReturn($string) { $pos = strpos($string, ' '); $type = substr($string, 0, $pos); return '(' . $type . ')' . substr($string, $pos+1); } } function parse_doc($php_doc_comment){ $p = new DocParser(); return $p->parse($php_doc_comment); $p = new Parser($php_doc_comment); return $p; $php_doc_comment = preg_replace("/(^[\\s]*\\/\\*\\*)
                                 |(^[\\s]\\*\\/)
                                 |(^[\\s]*\\*?\\s)
                                 |(^[\\s]*)
                                 |(^[\\t]*)/ixm", "", $php_doc_comment); $php_doc_comment = str_replace("\r", "", $php_doc_comment); $php_doc_comment = preg_replace("/([\\t])+/", "\t", $php_doc_comment); return explode("\n", $php_doc_comment); $php_doc_comment = trim(preg_replace('/\r?\n *\* */', ' ', $php_doc_comment)); return $php_doc_comment; preg_match_all('/@([a-z]+)\s+(.*?)\s*(?=$|@[a-z]+\s)/s', $php_doc_comment, $matches); return array_combine($matches[1], $matches[2]); } function object_to_array($object, $utf_encode=TRUE) { if(is_array($object) || is_object($object)) { $array = array(); foreach($object as $key => $value) { $value = object_to_array($value, $utf_encode); if($utf_encode && is_string($value)){ $value = utf8_encode($value); } $array[$key] = $value; } return $array; } return $object; } function autoload_formats($class_name) { $class_name=strtolower($class_name); $file = RESTLER_PATH."/$class_name/$class_name.php"; if (file_exists($file)) { require_once($file); } elseif (file_exists("$class_name.php")) { require_once ("$class_name.php"); } } spl_autoload_register('autoload_formats'); if(!function_exists('isRestlerCompatibilityModeEnabled')){ function isRestlerCompatibilityModeEnabled(){ return FALSE; } } define('RESTLER_PATH', dirname(__FILE__));