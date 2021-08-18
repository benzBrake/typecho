<?php
/**
 * Typecho Blog Platform
 *
 * @copyright  Copyright (c) 2008 Typecho team (http://www.typecho.org)
 * @license    GNU General Public License 2.0
 * @version    $Id$
 */

define('__TYPECHO_FILTER_SUPPORTED__', function_exists('filter_var'));

/**
 * 服务器请求处理类
 *
 * @package Request
 */
class Typecho_Request
{
    /**
     * 内部参数
     *
     * @access private
     * @var array
     */
    private $_params = array();

    /**
     * 路径信息
     *
     * @access private
     * @var string
     */
    private $_pathInfo = NULL;

    /**
     * 服务端参数
     *
     * @access private
     * @var array
     */
    private $_server = array();

    /**
     * _requestUri  
     * 
     * @var string
     * @access private
     */
    private $_requestUri = NULL;

    /**
     * _requestRoot  
     * 
     * @var mixed
     * @access private
     */
    private $_requestRoot = NULL;

    /**
     * 获取baseurl
     * 
     * @var string
     * @access private
     */
    private $_baseUrl = NULL;

    /**
     * 客户端ip地址
     *
     * @access private
     * @var string
     */
    private $_ip = NULL;

    /**
     * 客户端字符串
     *
     * @access private
     * @var string
     */
    private $_agent = NULL;

    /**
     * 来源页
     *
     * @access private
     * @var string
     */
    private $_referer = NULL;

    /**
     * 单例句柄
     *
     * @access private
     * @var Typecho_Request
     */
    private static $_instance = NULL;

    /**
     * 全部的http数据
     *
     * @var bool|array
     */
    private static $_httpParams = false;

    
    /**
     * 域名前缀
     *
     * @var string
     */
    private static $_urlPrefix = NULL;

    /**
     * 当前过滤器
     *
     * @access private
     * @var array
     */
    private $_filter = array();

    /**
     * 支持的过滤器列表
     *
     * @access private
     * @var string
     */
    private static $_supportFilters = array(
        'int'       =>  'intval',
        'integer'   =>  'intval',
        'search'    =>  array('Typecho_Common', 'filterSearchQuery'),
        'xss'       =>  array('Typecho_Common', 'removeXSS'),
        'url'       =>  array('Typecho_Common', 'safeUrl'),
        'slug'      =>  array('Typecho_Common', 'slugName')
    );

    /**
     * 获取单例句柄
     *
     * @access public
     * @return Typecho_Request
     */
    public static function getInstance()
    {
        if (NULL === self::$_instance) {
            self::$_instance = new Typecho_Request();
        }

        return self::$_instance;
    }

    /**
     * 应用过滤器
     *
     * @access private
     * @param mixed $value
     * @return mixed
     */
    private function _applyFilter($value)
    {
        if ($this->_filter) {
            foreach ($this->_filter as $filter) {
                $value = is_array($value) ? array_map($filter, $value) :
                call_user_func($filter, $value);
            }

            $this->_filter = array();
        }

        return $value;
    }

    /**
     * 检查ip地址是否合法
     *
     * @param string $ip ip地址
     * @return boolean
     */
    private function _checkIp($ip)
    {
        if (__TYPECHO_FILTER_SUPPORTED__) {
            return false !== (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6));
        }

        return preg_match("/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/", $ip)
            || preg_match("/^[0-9a-f:]+$/i", $ip);
    }

    /**
     * 检查ua是否合法
     *
     * @param string $agent ua字符串
     * @return boolean
     */
    private function _checkAgent($agent)
    {
        return preg_match("/^[_a-z0-9- ,:;=#@\.\(\)\/\+\*\?]+$/i", $agent);
    } 

    /**
     * 初始化变量
     */
    public function __construct()
    {
        if (false === self::$_httpParams) {
            self::$_httpParams = array_filter(array_merge($_POST, $_GET),
                array('Typecho_Common', 'checkStrEncoding'));
        }
    }

    /**
     * 获取url前缀 
     * 
     * @access public
     * @return string
     */
    public static function getUrlPrefix()
    {
        if (empty(self::$_urlPrefix)) {
            if (defined('__TYPECHO_URL_PREFIX__')) {
                self::$_urlPrefix = __TYPECHO_URL_PREFIX__;
            } else if (!defined('__TYPECHO_CLI__')) {
                self::$_urlPrefix = (self::isSecure() ? 'https' : 'http') . '://' 
                    . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']);
            }
        }

        return self::$_urlPrefix;
    }

    /**
     * 判断是否为https
     *
     * @access public
     * @return boolean
     */
    public static function isSecure()
    {
        return (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && !strcasecmp('https', $_SERVER['HTTP_X_FORWARDED_PROTO']))
            || (!empty($_SERVER['HTTP_X_FORWARDED_PORT']) && 443 == $_SERVER['HTTP_X_FORWARDED_PORT'])
            || (!empty($_SERVER['HTTPS']) && 'off' != strtolower($_SERVER['HTTPS']))
            || (!empty($_SERVER['SERVER_PORT']) && 443 == $_SERVER['SERVER_PORT'])
            || (defined('__TYPECHO_SECURE__') && __TYPECHO_SECURE__);
    }

    /**
     * 设置过滤器
     *
     * @access public
     * @return Typecho_Request
     */
    public function filter()
    {
        $filters = func_get_args();

        foreach ($filters as $filter) {
            $this->_filter[] = is_string($filter) && isset(self::$_supportFilters[$filter])
            ? self::$_supportFilters[$filter] : $filter;
        }

        return $this;
    }

    /**
     * 获取实际传递参数(magic)
     *
     * @access public
     * @param string $key 指定参数
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * 判断参数是否存在
     *
     * @access public
     * @param string $key 指定参数
     * @return boolean
     */
    public function __isset($key)
    {
        return isset(self::$_httpParams[$key])
        || isset($this->_params[$key]);
    }

    /**
     * 获取实际传递参数
     *
     * @access public
     * @param string $key 指定参数
     * @param mixed $default 默认参数 (default: NULL)
     * @return mixed
     */
    public function get($key, $default = NULL)
    {
        switch (true) {
            case isset($this->_params[$key]):
                $value = $this->_params[$key];
                break;
            case isset(self::$_httpParams[$key]):
                $value = self::$_httpParams[$key];
                break;
            default:
                $value = $default;
                break;
        }

        $value = !is_array($value) && strlen($value) > 0 ? $value : $default;
        return $this->_applyFilter($value);
    }

    /**
     * 获取一个数组
     *
     * @param $key
     * @return array
     */
    public function getArray($key)
    {
        $result = isset($this->_params[$key]) ? $this->_params[$key] :
            (isset(self::$_httpParams[$key]) ? self::$_httpParams[$key] : array());

        $result = is_array($result) ? $result
            : (strlen($result) > 0 ? array($result) : array());
        return $this->_applyFilter($result);
    }

    /**
     * 从参数列表指定的值中获取http传递参数
     *
     * @access public
     * @param mixed $params 指定的参数
     * @return array
     */
    public function from($params)
    {
        $result = array();
        $args = is_array($params) ? $params : func_get_args();

        foreach ($args as $arg) {
            $result[$arg] = $this->get($arg);
        }

        return $result;
    }

    /**
     * 设置http传递参数
     *
     * @access public
     * @param string $name 指定的参数
     * @param mixed $value 参数值
     * @return void
     */
    public function setParam($name, $value)
    {
        if (Typecho_Common::checkStrEncoding($value)) {
            $this->_params[$name] = $value;
        }
    }

    /**
     * 设置多个参数
     *
     * @access public
     * @param mixed $params 参数列表
     * @return void
     */
    public function setParams($params)
    {
        //处理字符串
        if (!is_array($params)) {
            parse_str($params, $out);
            $params = $out;
        }

        $this->_params = array_merge($this->_params,
            array_filter($params, array('Typecho_Common', 'checkStrEncoding')));
    }

    /**
     * getRequestRoot 
     * 
     * @access public
     * @return string
     */
    public function getRequestRoot()
    {
        if (NULL === $this->_requestRoot) {
            $root = rtrim(self::getUrlPrefix() . $this->getBaseUrl(), '/') . '/';
            
            $pos = strrpos($root, '.php/');
            if ($pos) {
                $root = dirname(substr($root, 0, $pos));
            }

            $this->_requestRoot = rtrim($root, '/');
        }

        return $this->_requestRoot;
    }

    /**
     * 获取当前请求url
     * 
     * @access public
     * @return string
     */
    public function getRequestUrl()
    {
        return self::getUrlPrefix() . $this->getRequestUri();
    }

    /**
     * 获取请求地址
     * 
     * @access public
     * @return string
     */
    public function getRequestUri()
    {
        if (!empty($this->_requestUri)) {
            return $this->_requestUri;
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
            $parts       = @parse_url($requestUri);
            
            if (isset($_SERVER['HTTP_HOST']) && strstr($requestUri, $_SERVER['HTTP_HOST'])) {
                if (false !== $parts) {
                    $requestUri  = (empty($parts['path']) ? '' : $parts['path'])
                                 . ((empty($parts['query'])) ? '' : '?' . $parts['query']);
                }
            } elseif (!empty($_SERVER['QUERY_STRING']) && empty($parts['query'])) {
                // fix query missing
                $requestUri .= '?' . $_SERVER['QUERY_STRING'];
            }
        } elseif (isset($_SERVER['ORIG_PATH_INFO'])) { // IIS 5.0, PHP as CGI
            $requestUri = $_SERVER['ORIG_PATH_INFO'];
            if (!empty($_SERVER['QUERY_STRING'])) {
                $requestUri .= '?' . $_SERVER['QUERY_STRING'];
            }
        }

        return $this->_requestUri = $requestUri;
    }

    /**
     * getBaseUrl  
     * 
     * @access public
     * @return string
     */
    public function getBaseUrl()
    {
        if (NULL !== $this->_baseUrl) {
            return $this->_baseUrl;
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
        $requestUri = $this->getRequestUri();

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

        return ($this->_baseUrl = (NULL === $finalBaseUrl) ? rtrim($baseUrl, '/') : $finalBaseUrl);
    }

    /**
     * 根据当前uri构造指定参数的uri
     *
     * @access public
     * @param mixed $parameter 指定的参数
     * @return string
     */
    public function makeUriByRequest($parameter = NULL)
    {
        /** 初始化地址 */
        $requestUri = $this->getRequestUrl();
        $parts = parse_url($requestUri);

        /** 初始化参数 */
        if (is_string($parameter)) {
            parse_str($parameter, $args);
        } else if (is_array($parameter)) {
            $args = $parameter;
        } else {
            return $requestUri;
        }

        /** 构造query */
        if (isset($parts['query'])) {
            parse_str($parts['query'], $currentArgs);
            $args = array_merge($currentArgs, $args);
        }
        $parts['query'] = http_build_query($args);

        /** 返回地址 */
        return Typecho_Common::buildUrl($parts);
    }

    /**
     * 获取当前pathinfo
     *
     * @access public
     * @param string $inputEncoding 输入编码
     * @param string $outputEncoding 输出编码
     * @return string
     */
    public function getPathInfo($inputEncoding = NULL, $outputEncoding = NULL)
    {
        /** 缓存信息 */
        if (NULL !== $this->_pathInfo) {
            return $this->_pathInfo;
        }

        //参考Zend Framework对pahtinfo的处理, 更好的兼容性
        $pathInfo = NULL;

        //处理requestUri
        $requestUri = $this->getRequestUri();
        $finalBaseUrl = $this->getBaseUrl();

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
                    $pathInfo = iconv($inputEncoding, $outputEncoding, $pathInfo);
                }
            }
        } else {
            $pathInfo = '/';
        }

        // fix issue 456
        return ($this->_pathInfo = '/' . ltrim(urldecode($pathInfo), '/'));
    }

    /**
     * 设置服务端参数
     *
     * @access public
     * @param string $name 参数名称
     * @param mixed $value 参数值
     * @return void
     */
    public function setServer($name, $value = NULL)
    {
        if (NULL == $value) {
            if (isset($_SERVER[$name])) {
                $value = $_SERVER[$name];
            } else if (isset($_ENV[$name])) {
                $value = $_ENV[$name];
            }
        }

        $this->_server[$name] = $value;
    }

    /**
     * 获取环境变量
     *
     * @access public
     * @param string $name 获取环境变量名
     * @return string
     */
    public function getServer($name)
    {
        if (!isset($this->_server[$name])) {
            $this->setServer($name);
        }

        return $this->_server[$name];
    }

    /**
     * 设置ip地址
     *
     * @access public
     * @param string $ip
     */
    public function setIp($ip = NULL)
    {
        if (!empty($ip)) {
            $this->_ip = $ip;
        } else {
            switch (true) {
                case defined('__TYPECHO_IP_SOURCE__') && NULL !== $this->getServer(__TYPECHO_IP_SOURCE__):
                    list($this->_ip) = array_map('trim', explode(',', $this->getServer(__TYPECHO_IP_SOURCE__)));
                    break;
                case NULL !== $this->getServer('REMOTE_ADDR'):
                    $this->_ip = $this->getServer('REMOTE_ADDR');
                    break;
                case NULL !== $this->getServer('HTTP_CLIENT_IP'):
                    $this->_ip = $this->getServer('HTTP_CLIENT_IP');
                    break;
                default:
                    break;
            }
        }

        if (empty($this->_ip) || !self::_checkIp($this->_ip)) {
            $this->_ip = 'unknown';
        }
    }

    /**
     * 获取ip地址
     *
     * @access public
     * @return string
     */
    public function getIp()
    {
        if (NULL === $this->_ip) {
            $this->setIp();
        }

        return $this->_ip;
    }

    /**
     * 设置客户端
     *
     * @access public
     * @param string $agent 客户端字符串
     * @return void
     */
    public function setAgent($agent = NULL)
    {
        $agent = (NULL === $agent) ? $this->getServer('HTTP_USER_AGENT') : $agent;
        $this->_agent = self::_checkAgent($agent) ? $agent : '';
    }

    /**
     * 获取客户端
     *
     * @access public
     * @return string
     */
    public function getAgent()
    {
        if (NULL === $this->_agent) {
            $this->setAgent();
        }

        return $this->_agent;
    }

    /**
     * 设置来源页
     *
     * @access public
     * @param string $referer 客户端字符串
     * @return void
     */
    public function setReferer($referer = NULL)
    {
        $this->_referer = (NULL === $referer) ? $this->getServer('HTTP_REFERER') : $referer;
    }

    /**
     * 获取客户端
     *
     * @access public
     * @return string
     */
    public function getReferer()
    {
        if (NULL === $this->_referer) {
            $this->setReferer();
        }

        return $this->_referer;
    }

    /**
     * 判断是否为get方法
     *
     * @access public
     * @return boolean
     */
    public function isGet()
    {
        return 'GET' == $this->getServer('REQUEST_METHOD');
    }

    /**
     * 判断是否为post方法
     *
     * @access public
     * @return boolean
     */
    public function isPost()
    {
        return 'POST' == $this->getServer('REQUEST_METHOD');
    }

    /**
     * 判断是否为put方法
     *
     * @access public
     * @return boolean
     */
    public function isPut()
    {
        return 'PUT' == $this->getServer('REQUEST_METHOD');
    } 

    /**
     * 判断是否为ajax
     *
     * @access public
     * @return boolean
     */
    public function isAjax()
    {
        return 'XMLHttpRequest' == $this->getServer('HTTP_X_REQUESTED_WITH');
    }

    /**
     * 判断输入是否满足要求
     *
     * @access public
     * @param mixed $query 条件
     * @return boolean
     */
    public function is($query)
    {
        $validated = false;

        /** 解析串 */
        if (is_string($query)) {
            parse_str($query, $params);
        } else if (is_array($query)) {
            $params = $query;
        }

        /** 验证串 */
        if ($params) {
            $validated = true;
            foreach ($params as $key => $val) {
                $validated = empty($val) ? $this->__isset($key) : ($val == $this->get($key));

                if (!$validated) {
                    break;
                }
            }
        }

        return $validated;
    }
}
