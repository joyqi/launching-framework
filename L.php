<?php

/**
 * L  
 * 
 * @copyright Copyright (c) 2012 Typecho Team. (http://typecho.org)
 * @author Joyqi <magike.net@gmail.com> 
 * @license GNU General Public License 2.0
 */
class L
{
    /**
     * _params 
     * 
     * @var array
     * @access priate
     */
    private static $_params = array();

    /**
     * _plugins
     * 
     * @var array
     * @access private
     */
    private static $_plugins = array();

    /**
     * 判断是否符合条件
     * 
     * @param string $rule
     * @access public
     * @return boolean
     */
    public static function is($rule)
    {
        $parts = parse_url($rule);

        if (isset($parts['scheme'])) {
            $schemes = explode('+', $params['scheme']);
            foreach ($schemes as $scheme) {
                if (!self::isScheme($scheme)) {
                    return false;
                }
            }
        }

        if (isset($parts['path'])) {
            $params = array();
            $path = preg_replace_callback('/\[([_0-9a-z]+)\]/i', function ($matches) use (&$params) {
                $params[] = $matches[1];
                return '%';
            }, $parts['path']);
            $path = str_replace('%', '([^\/]+)', preg_quote($path));

            if (preg_match('|^' . $path . '$|', self::getRequest('path'), $matches)) {
                array_shift($matches);

                if (!empty($params)) {
                    self::$_params = array_combine($params, $matches);
                }
            } else {
                return false;
            }
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);

            if ($params) {
                foreach ($params as $key => $val) {
                    if (empty($val) ? NULL === self::get($key) : ($val != self::get($key))) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * 绑定条件处理
     * 
     * @param array $map 
     * @param mixed $default
     * @static
     * @access public
     * @return void
     */
    public static function bind(array $map, $default = 404)
    {
        $result = $default;

        foreach ($map as $rule => $action) {
            if (self::is($rule)) {
                try {

                    if (is_array($action)) {
                        list ($class, $method) = $action;

                        if (is_object($class)) {
                            $object = $class;
                            $result = $object->{$method};
                        } else {
                            $ref = new ReflectionClass($class);
                            if ($ref->getMethod($method)->isStatic()) {
                                $result = call_user_func($action);
                            } else {
                                $object = new $class();
                                $result = $object->{$method}();
                            }
                        } 
                    } else if (is_callable($action)) {
                        $result = call_user_func($action);
                    }

                } catch (Exception $e) {
                    $result = array(500, $e);
                    break;
                }

                break;
            }
        }

        if (!is_array($result)) {
            $result = array($result);
        }

        // 对template做特殊处理以简化返回
        if ('template' == $result[0] && !isset($result[2]) && isset($object)) {
            $result[2] = $object;
        }

        return $result;
    }

    /**
     * handle
     * 
     * @param array $result 
     * @param array $handlers 
     * @static
     * @access public
     * @return void
     */
    public static function handle(array $result, array $handlers = array())
    {
        $defaultHandlers = array(
            // 301 跳转
            301         =>  function ($url) {
                header('Location: ' . $url, false, 301);
            },

            // 302 跳转
            302         =>  function ($url) {
                header('Location: ' . $url, false, 302);
            },

            // 403 禁止访问
            403         =>  function ($file = '') {
                header('HTTP/1.1 403 ACCESS DENIED', true, 403);

                if (empty($file)) {
                    echo '<h1>403</h1>';
                } else {
                    require $file;
                }
            },

            // 404 页面不存在
            404         =>  function ($file = '') {
                header('HTTP/1.1 404 Not Found', true, 404);

                if (empty($file)) {
                    echo '<h1>404</h1>';
                } else {
                    require $file;
                }
            },

            // 500 服务器内部错误
            500         =>  function ($e, $file = '') {
                header('HTTP/1.1 500 Internal Server Error', true, 500);
                
                if (empty($file)) {
                    echo '<h1>' . $e . '</h1>';
                } else {
                    require $file;
                }
            },

            // 返回来源页面
            'back'      =>  function () {
                $referer = L::getClient('referer');

                if (!empty($referer)) {
                    header('Location: ' . $referer, false, 302);
                }
            },

            // 输出json
            'json'      =>  function ($data) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-cache');

                echo json_encode($data);
            },

            // 输出jsonp
            'jsonp'     =>  function ($data, $callback = 'callback') {
                header('Content-Type: text/javascript; charset=UTF-8');
                header('Cache-Control: no-cache');
    
                $callback = L::get($callback, 'jsonp');
                echo $callback . '(' . json_encode($data) . ')';
            },

            // 输出html模板
            'template'  =>  function ($file, $data, $base = '') {
                global $template;
                
                $template = function ($file, array $custom = NULL) use ($data, $base) {
                    global $template;

                    if (is_object($data)) {
                        $vars = get_object_vars($data);
                
                        if (!empty($custom)) {
                            $vars = array_merge($vars, $custom);
                        }
                
                        extract($vars);
                        unset($vars);
                    } else {
                        if (!empty($custom)) {
                            $data = array_merge($data, $custom);
                        }
                
                        extract($data);
                    }

                    require (empty($base) ? '' : $base . '/') . $file;
                };

                header('Content-Type: text/html; charset=UTF-8');
                $template($file);
            }
        );

        $name = array_shift($result);
        $args = $result;

        if (isset($handlers[$name])) {
            $handler = $handlers[$name];
            
            if (is_array($handler)) {
                foreach ($handler as $key => $val) {
                    $args[$key] = $val;
                }

                ksort($args);
            } else if (is_callable($handler)) {
                $defaultHandlers[$name] = $handler;
            }
        }

        if (isset($defaultHandlers[$name])) {
            call_user_func_array($defaultHandlers[$name], $args);
        }
    }

    /**
     * 自动加载
     * 
     * @param mixed $path 
     * @param mixed $namespace 
     * @static
     * @access public
     * @return void
     */
    public static function autoload($path, $namespace = NULL)
    {
        spl_autoload_register(function ($class) use ($path, $namespace) {
            if (!empty($namespace)) {
                if (0 == strpos(ltrim($class, '\\'), $namespace . '\\')) {
                    $class = substr(ltrim($class, '\\'), strlen($namespace) + 1);
                } else {
                    return;
                }
            }

            $file = $path . '/' . str_replace(array('_', '\\'), '/', $class) . '.php';
            if (file_exists($file)) {
                include_once $file;
            }
        });
    }

    /**
     * 判断是否符合协议规则 
     * 
     * @param string $scheme 
     * @access public
     * @return boolean
     */
    public static function isScheme($scheme)
    {
        $scheme = strtolower($scheme);

        switch ($scheme) {
            case 'get':
            case 'post':
            case 'delete':
            case 'put':
                return strtoupper($scheme) == self::getClient('method');
            case 'upload':
                return !empty($_FILES);
            case 'ajax':
                return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 'XMLHttpRequest' == $_SERVER['HTTP_X_REQUESTED_WITH'];
            case 'https':
                return (!empty($_SERVER['HTTPS']) && 'off' != strtolower($_SERVER['HTTPS'])) 
                    || (!empty($_SERVER['SERVER_PORT']) && 443 == $_SERVER['SERVER_PORT']);
            case 'mobile':
                $userAgent = self::getClient('agent');
                return preg_match('/android.+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $userAgent) || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(di|rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($userAgent, 0, 4));
            default:
                return false;
        }
    }

    /**
     * 获取客户端数据
     * 
     * @param mixed $name 
     * @access public
     * @return void
     */
    public static function getClient($name)
    {
        if ('ip' == $name) {
            switch (true) {
                case !empty($_SERVER['HTTP_X_FORWARDED_FOR']):
                    $ip = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
                    return array_shift($ip);
                case !empty($_SERVER['HTTP_CLIENT_IP']):
                    return $_SERVER['HTTP_CLIENT_IP'];
                case !empty($_SERVER['REMOTE_ADDR']):
                    return $_SERVER['REMOTE_ADDR'];
                default:
                    break;
            }
        } else if ('agent' == $name && !empty($_SERVER['HTTP_USER_AGENT'])) {
            return $_SERVER['HTTP_USER_AGENT'];
        } else if ('method' == $name && !empty($_SERVER['REQUEST_METHOD'])) {
            return strtoupper($_SERVER['REQUEST_METHOD']);
        } else if ('referer' == $name && !empty($_SERVER['HTTP_REFERER'])) {
            return $_SERVER['HTTP_REFERER'];
        } else if ('langs' == $name && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            if (preg_match_all("/[a-z-]+/i", $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches)) {
                return array_map('strtolower', $matches[0]);
            } else {
                return array('en');
            }
            
        }

        return false;
    }

    /**
     * getRequest 
     * 
     * @param mixed $name 
     * @static
     * @access public
     * @return void
     */
    public static function getRequest($name)
    {
        if ('uri' == $name) {
            static $requestUri;

            if (!empty($requestUri)) {
                return $requestUri;
            }

            //处理requestUri
            $requestUri = '/';

            if (isset($_SERVER['HTTP_X_REWRITE_URL'])) { // check this first so IIS will catch
                $requestUri = $_SERVER['HTTP_X_REWRITE_URL'];
            } elseif (
                // IIS7 with URL Rewrite: make sure we get the unencoded url (double slash problem)
                isset($_SERVER['IIS_WasUrlRewritten'])
                && $_SERVER['IIS_WasUrlRewritten'] == '1'
                && isset($_SERVER['UNENCODED_URL'])
                && $_SERVER['UNENCODED_URL'] != ''
                ) {
                    $requestUri = $_SERVER['UNENCODED_URL'];
            } elseif (isset($_SERVER['REQUEST_URI'])) {
                $requestUri = $_SERVER['REQUEST_URI'];
                if (isset($_SERVER['HTTP_HOST']) && strstr($requestUri, $_SERVER['HTTP_HOST'])) {
                    $parts = @parse_url($requestUri);

                    if (false !== $parts) {
                        $requestUri  = (empty($parts['path']) ? '' : $parts['path'])
                            . ((empty($parts['query'])) ? '' : '?' . $parts['query']);
                    }
                }
            } elseif (isset($_SERVER['ORIG_PATH_INFO'])) { // IIS 5.0, PHP as CGI
                $requestUri = $_SERVER['ORIG_PATH_INFO'];
                if (!empty($_SERVER['QUERY_STRING'])) {
                    $requestUri .= '?' . $_SERVER['QUERY_STRING'];
                }
            }

            return $requestUri;
        } else if ('base' == $name) {
            static $finalBaseUrl;
            
            if (!empty($finalBaseUrl)) {
                return $finalBaseUrl;
            }

            //处理baseUrl
            $filename = (isset($_SERVER['SCRIPT_FILENAME'])) ? basename($_SERVER['SCRIPT_FILENAME']) : '';

            if (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === $filename) {
                $baseUrl = $_SERVER['SCRIPT_NAME'];
            } elseif (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) === $filename) {
                $baseUrl = $_SERVER['PHP_SELF'];
            } elseif (isset($_SERVER['ORIG_SCRIPT_NAME']) && basename($_SERVER['ORIG_SCRIPT_NAME']) === $filename) {
                $baseUrl = $_SERVER['ORIG_SCRIPT_NAME']; // 1and1 shared hosting compatibility
            } else {
                // Backtrack up the script_filename to find the portion matching
                // php_self
                $path    = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
                $file    = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '';
                $segs    = explode('/', trim($file, '/'));
                $segs    = array_reverse($segs);
                $index   = 0;
                $last    = count($segs);
                $baseUrl = '';
                do {
                    $seg     = $segs[$index];
                    $baseUrl = '/' . $seg . $baseUrl;
                    ++$index;
                } while (($last > $index) && (false !== ($pos = strpos($path, $baseUrl))) && (0 != $pos));
            }

            // Does the baseUrl have anything in common with the request_uri?
            $finalBaseUrl = NULL;
            $requestUri = self::getRequest('uri');

            if (0 === strpos($requestUri, $baseUrl)) {
                // full $baseUrl matches
                $finalBaseUrl = $baseUrl;
            } else if (0 === strpos($requestUri, dirname($baseUrl))) {
                // directory portion of $baseUrl matches
                $finalBaseUrl = rtrim(dirname($baseUrl), '/');
            } else if (!strpos($requestUri, basename($baseUrl))) {
                // no match whatsoever; set it blank
                $finalBaseUrl = '';
            } else if ((strlen($requestUri) >= strlen($baseUrl))
                && ((false !== ($pos = strpos($requestUri, $baseUrl))) && ($pos !== 0)))
            {
                // If using mod_rewrite or ISAPI_Rewrite strip the script filename
                // out of baseUrl. $pos !== 0 makes sure it is not matching a value
                // from PATH_INFO or QUERY_STRING
                $baseUrl = substr($requestUri, 0, $pos + strlen($baseUrl));
            }

            $finalBaseUrl = (NULL === $finalBaseUrl) ? rtrim($baseUrl, '/') : $finalBaseUrl;
            return $finalBaseUrl;
        } else if ('root' == $name) {
            static $root;

            if (!empty($root)) {
                return $root;
            }

            $root = rtrim((self::isScheme('https') ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST']
                . self::getRequest('base'), '/') . '/';

            $pos = strrpos($root, '.php/');
            if ($pos) {
                $root = dirname(substr($root, 0, $pos));
            }

            $root = rtrim($root, '/');
            return $root;
        } else if ('url' == $name) {
            static $requestUrl;
            
            if (!empty($requestUrl)) {
                return $requestUrl;
            }

            $scheme = self::isScheme('https') ? 'https' : 'http';
            $requestUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . self::getRequest('uri');
            return $requestUrl;
        } else if ('path' == $name) {
            static $pathInfo;

            if (!empty($pathInfo)) {
                return $pathInfo;
            }

            //参考Zend Framework对pahtinfo的处理, 更好的兼容性
            $pathInfo = NULL;

            //处理requestUri
            $requestUri = self::getRequest('uri');
            $finalBaseUrl = self::getRequest('base');

            // Remove the query string from REQUEST_URI
            if ($pos = strpos($requestUri, '?')) {
                $requestUri = substr($requestUri, 0, $pos);
            }

            if ((NULL !== $finalBaseUrl)
                && (false === ($pathInfo = substr($requestUri, strlen($finalBaseUrl)))))
            {
                // If substr() returns false then PATH_INFO is set to an empty string
                $pathInfo = '/';
            } elseif (NULL === $finalBaseUrl) {
                $pathInfo = $requestUri;
            }

            if (!empty($pathInfo)) {
                //针对iis的utf8编码做强制转换
                //参考http://docs.moodle.org/ja/%E5%A4%9A%E8%A8%80%E8%AA%9E%E5%AF%BE%E5%BF%9C%EF%BC%9A%E3%82%B5%E3%83%BC%E3%83%90%E3%81%AE%E8%A8%AD%E5%AE%9A
                if (!empty($inputEncoding) && !empty($outputEncoding) &&
                (stripos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false
                || stripos($_SERVER['SERVER_SOFTWARE'], 'ExpressionDevServer') !== false)) {
                    if (function_exists('mb_convert_encoding')) {
                        $pathInfo = mb_convert_encoding($pathInfo, $outputEncoding, $inputEncoding);
                    } else if (function_exists('iconv')) {
                        $pathInfo = iconv($pathInfoEncoding, $outputEncoding, $pathInfo);
                    }
                }
            } else {
                $pathInfo = '/';
            }

            $pathInfo = '/' . ltrim(urldecode($pathInfo), '/');
            return $pathInfo;
        }

        return false;
    }

    /**
     * 获取前端传递参数
     * 
     * @param string $name 参数值 
     * @param mixed $default 
     * @param mixed $realKey
     * @access public
     * @return mixed
     */
    public static function get($name, $default = NULL, &$realKey = NULL)
    {
        if (is_array($name)) {
            $result = array();
            
            foreach ($name as $key => $val) {
                $param = is_int($key) ? self::get($val, NULL, $paramKey) 
                    : self::get($key, $val, $paramKey);

                $result[$paramKey] = $param;
            }
        }

        static $params;

        if (NULL === $params) {
            $params = array_merge($_GET, $_POST, self::$_params);
        }

        $realKey = $name;
        $filters = array();

        if (strpos($name, '#')) {
            list($realKey, $filters) = explode('#', $name, 2);
            $filters = explode(',', $filters);
        }

        $param = isset($params[$realKey]) ? $params[$realKey] : $default;

        foreach ($filters as $filter) {
            $param = $filter($param);
        }

        return $param;
    }

    /**
     * html  
     * 
     * @param mixed $tag 
     * @param mixed $attributes 
     * @param mixed $meta 
     * @static
     * @access public
     * @return void
     */
    public static function html($tag, $attributes = NULL, $meta = NULL)
    {
        $short = array(
            'icon'      =>  array('link', 'rel=shortcut+icon'),
            'css'       =>  array('link', 'rel=stylesheet'),
            'js'        =>  array('script', 'type=text/javascript'),
            'search'    =>  array('link', 'rel=search&type=application/opensearchdescription%2Bxml'),
            'rss1'      =>  array('link', 'rel=alternate&type=application/rdf%2Bxml'),
            'rss2'      =>  array('link', 'rel=alternate&type=application/rss%2Bxml'),
            'atom'      =>  array('link', 'rel=alternate&type=application/atom%2Bxml'),
            'scale1'    =>  array('meta', 'name=viewport', 'width=device-width, initial-scale=1, maximum-scale=1')
        );
        
        $preAttrs = array();
        if (isset($short[$tag])) {
            $define = $short[$tag];
            list ($tag, $attrs) = $define;

            if (is_string($attrs)) {
                parse_str($attrs, $preAttrs);
            } else {
                $preAttrs = (array) $attrs;
            }

            if (isset($define[2]) && NULL === $meta) {
                $meta = $define[2];
            }
        }

        $schemes = array(
            array('link', 'img', 'meta'),
            array(
                'select'    =>  function ($html, array $meta) {
                    $html .= '>';
                    foreach ($meta as $key => $val) {
                        $html .= "<option value=\"{$key}\">{$val}</option>";
                    }

                    return $html . '</select>';
                },

                'input'     =>  function ($html, $meta) {
                    return $html . ' value="' . htmlspecialchars($meta) . '" />';
                },

                'meta'      =>  function ($html, $meta) {
                    return $html . ' content="' . htmlspecialchars($meta) . '" />';
                }
            )
        );

        $html = "<{$tag}";

        if (!empty($attributes)) {
            if (is_string($attributes)) {
                parse_str($attributes, $attrs);
            } else {
                $attrs = (array) $attributes;
            }

            $attrs = array_merge($preAttrs, $attrs);

            foreach ($attrs as $key => $val) {
                $html .= " {$key}=\"{$val}\"";
            }
        }

        if (in_array($schemes[0], $tag)) {
            return $html . ' />';
        } else if (isset($schemes[1][$tag])) {
            return $schemes[1][$tag]($html, $meta);
        } else {
            return $html . '>' . htmlspecialchars($meta) . "</{$tag}>";
        }
    }

    /**
     * plugin 
     * 
     * @param mixed $name 
     * @param mixed $callback 
     * @static
     * @access public
     * @return void
     */
    public static function plugin($name, $callback)
    {
        self::$_plugins[$name] = $callback;
    }

    /**
     * __callStatic  
     * 
     * @param mixed $name 
     * @param mixed $args 
     * @static
     * @access public
     * @return void
     */
    public static function __callStatic($name, $args)
    {
        if (isset(self::$_plugins[$name])) {
            return call_user_func_array(self::$_plugins[$name], $args);
        }
    }
}

