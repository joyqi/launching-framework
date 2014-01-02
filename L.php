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
     * _hooks  
     * 
     * @var array
     * @access private
     */
    private static $_hooks = array();

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
     * route
     * 
     * @param array $map 
     * @static
     * @access public
     * @return void
     */
    public static function route(array $map)
    {
        $result = 'notFound';

        foreach ($map as $rule => $action) {
            if (self::is($rule)) {
                if (is_array($action)) {
                    list ($class, $method) = $action;

                    try {
                        $ref = new ReflectionClass($class);
                        if ($ref->getMethod($method)->isStatic()) {
                            $result = call_user_func($action);
                        } else {
                            $object = new $class();
                            $result = $object->{$method}();
                        }
                    } catch (ReflectionException e) {
                        break;
                    }
                } else if (is_callable($action)) {
                    $result = call_user_func($action);
                }

                break;
            }
        }

        L::hook(__METHOD__, $result);
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
            case 'ajax':
                return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 'XMLHttpRequest' == $_SERVER['HTTP_X_REQUESTED_WITH'];
            case 'https':
                return (!empty($_SERVER['HTTPS']) && 'off' != strtolower($_SERVER['HTTPS'])) 
                    || (!empty($_SERVER['SERVER_PORT']) && 443 == $_SERVER['SERVER_PORT']);
            default:
                return L::hook(__METHOD__, $scheme);
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
        }

        return L::hook(__METHOD__, $name);
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

        return L::hook(__METHOD__, $name);
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
     * hook 
     * 
     * @param mixed $prefix 
     * @param mixed $name 
     * @static
     * @access public
     * @return void
     */
    public static function hook($prefix, $name)
    {
        $method = $prefix . ucfirst($name);
        $args = func_get_args();
        $args = array_slice($args, 2);

        if (isset(self::$_hooks[$method])) {
            return call_user_func_array(self::$_hooks[$method], $args);
        }

        return false;
    }

    /**
     * hook  
     * 
     * @param mixed $class 
     * @static
     * @access public
     * @return void
     */
    public static function import($class)
    {}
}

