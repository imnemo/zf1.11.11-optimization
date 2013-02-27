<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Application
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Application.php 24101 2011-06-01 02:21:15Z adamlundrigan $
 */

/**
 * @category   Zend
 * @package    Zend_Application
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Application
{
    /**
     * Autoloader to use
     *
     * @var Zend_Loader_Autoloader
     */
    protected $_autoloader;

    /**
     * Bootstrap
     *
     * @var Zend_Application_Bootstrap_BootstrapAbstract
     */
    protected $_bootstrap;

    /**
     * Application environment
     *
     * @var string
     */
    protected $_environment;

    /**
     * Flattened (lowercase) option keys
     *
     * @var array
     */
    protected $_optionKeys = array();

    /**
     * Options for Zend_Application
     *
     * @var array
     */
    protected $_options = array();

    /**
     * Constructor
     *
     * Initialize application. Potentially initializes include_paths, PHP
     * settings, and bootstrap class.
     *
     * @param  string                   $environment
     * @param  string|array|Zend_Config $options String path to configuration file, or array/Zend_Config of configuration options
     * @throws Zend_Application_Exception When invalid options are provided
     * @return void
     */
    public function __construct($environment, $options = null)
    {
        $this->_environment = (string) $environment;

        require_once 'Zend/Loader/Autoloader.php';
        $this->_autoloader = Zend_Loader_Autoloader::getInstance();

        /**
         * 配置选项支持数组、Zend_Config实例对象，zf中很多方法的$options参数都是这样支持
         */
        if (null !== $options) {
            if (is_string($options)) {
                $options = $this->_loadConfig($options);
            } elseif ($options instanceof Zend_Config) {
                $options = $options->toArray();
            } elseif (!is_array($options)) {
                throw new Zend_Application_Exception('Invalid options provided; must be location of config file, a config object, or an array');
            }
            $this->setOptions($options);
        }
    }

    /**
     * Retrieve current environment
     *
     * @return string
     */
    public function getEnvironment()
    {
        return $this->_environment;
    }

    /**
     * Retrieve autoloader instance
     *
     * @return Zend_Loader_Autoloader
     */
    public function getAutoloader()
    {
        return $this->_autoloader;
    }

    /**
     * Set application options
	 * 处理自配置文件（若有设置），将配置存储到$_options变量中，然后对特殊配置进行额外处理，如设置php.ini，include_path虽然也是php ini，但是不需要简单覆盖而是添加
     *
     * @param  array $options
     * @throws Zend_Application_Exception When no bootstrap path is provided
     * @throws Zend_Application_Exception When invalid bootstrap information are provided
     * @return Zend_Application
     */
    public function setOptions(array $options)
    {
    	/**
    	 * $options支持一个"config"字段，可以设置多个配置文件路径，每个配置文件也都可以分区，最后会合并到一块儿的（注意合并时，后面的文件中的配置会覆盖前面的文件中相同的配置项的值）
    	 * 自配置文件解析合并完成后，会把主配置文件（即传进来的$options）并入，即主配置文件中的配置选项还是会覆盖子配置文件相应的项
    	 * 例子：
    	 * 	application.ini中的内容：
    	 * 		[production]
    	 * 		config.one = /path/to/first.ini
    	 * 		config.two = /path/to/second.ini
    	 * 		...
    	 * 		
    	 * 		phpSettings.
    	 * 		...
    	 * 	那么constructor中解析出来就是如下内容：
    	 * 	$options = array(
    	 * 				'config' => array(
    	 * 							'one' => '/path/to/first.ini',
    	 * 							'two' => '/path/to/second.ini',
    	 * 							...
    	 * 						),
    	 * 				'phpSettings' => array(
    	 * 								...
    	 * 							),
    	 * 			);
    	 * 	这样first.ini second.ini中的配置会合并，且second会覆盖first，合并的结果会被application.ini覆盖合并
    	 * 	
    	 * 	如果设置了config字段，解析完子配置后，我想应该unset了这个字段，因为public/index.php中，如果生产环境下会把zf运行时配置缓存起来，若缓存的配置中也有config，那么还是会去解析子配置文件
    	 * 	可以在设置配置缓存时，判断下有无config字段，若有就删除
    	 */
        if (!empty($options['config'])) {
            if (is_array($options['config'])) {
            	//先合并子配置文件，后面的覆盖前面
                $_options = array();
                foreach ($options['config'] as $tmp) {
                    $_options = $this->mergeOptions($_options, $this->_loadConfig($tmp));
                }
                //将主配置文件并入，并覆盖合并的子配置
                $options = $this->mergeOptions($_options, $options);
            } else {
                $options = $this->mergeOptions($this->_loadConfig($options['config']), $options);
            }
        }

        $this->_options = $options;

        $options = array_change_key_case($options, CASE_LOWER);

        $this->_optionKeys = array_keys($options);

        /**
         * phpsettings开头的，可以直接配置php.ini选项
         * 后面直接跟原生php.ini配置名/值
         */
        if (!empty($options['phpsettings'])) {
            $this->setPhpSettings($options['phpsettings']);
        }

        /**
         * includepaths设置include_path,加到已有include path之前
         */
        if (!empty($options['includepaths'])) {
            $this->setIncludePaths($options['includepaths']);
        }

        /**
         * autoloadernamespaces设置自加载类命名空间
         */
        if (!empty($options['autoloadernamespaces'])) {
            $this->setAutoloaderNamespaces($options['autoloadernamespaces']);
        }

       	/**
       	 * 可以在ini配置里，设置zf库文件路径和版本，方便切换
       	 * 
       	 * 配置key = autoloaderzfpath，是zf存放目录，该目录下放着各个版本的zf目录，每个版本的zf目录下library目录放库文件。
       	 * 		zf目录名格式就是Zend_Loader_Autoloader::_getAvailableVersions方法里定义的
       	 * 		'/^(?:ZendFramework-)?(\d+\.\d+\.\d+((a|b|pl|pr|p|rc)\d+)?)(?:-minimal)?$/i'，
       	 * 		其实就是在官网下载下来的压缩文件解压后目录：ZendFramework-1.11.11， ZendFramework-2.0.0beta3 等，不过看上面正则，没有beta版本号的匹配，可以自己修改加上						
       	 * 配置key = autoloaderzfversion，是指定使用的版本，即zf目录里的版本信息，(\d+\.\d+\.\d+((a|b|pl|pr|p|rc)\d+)?)只这一部分有效
       	 * 
       	 * 指定版本信息时，可以只指定某一部分版本，如只指定mayor部分，或者mayor.minor，这样会自动匹配该层版本下最新的
       	 * 若autoloaderzfversion = latest，则使用所有的里面最新的版本
       	 * 
       	 * 自定义的类库可以放在autoloaderzfpath下
       	 */
        if (!empty($options['autoloaderzfpath'])) {
            $autoloader = $this->getAutoloader();
            if (method_exists($autoloader, 'setZfPath')) {
                $zfPath    = $options['autoloaderzfpath'];
                $zfVersion = !empty($options['autoloaderzfversion'])
                           ? $options['autoloaderzfversion']
                           : 'latest';
                $autoloader->setZfPath($zfPath, $zfVersion);
            }
        }

        /**
         * 若启动类名是bootstrap，则配置中可以只给出启动类文件的路径
         * 若没有bootstrap的配置选项，则zf会默认使用Zend_Application_Bootstrap_Bootstrap作为启动类，但是这只保证基本程序运行，没有classResources，其他也是默认 行为
         */
        if (!empty($options['bootstrap'])) {
            $bootstrap = $options['bootstrap'];

            if (is_string($bootstrap)) {
                $this->setBootstrap($bootstrap);
            } elseif (is_array($bootstrap)) {
                if (empty($bootstrap['path'])) {
                    throw new Zend_Application_Exception('No bootstrap path provided');
                }

                $path  = $bootstrap['path'];
                $class = null;

                if (!empty($bootstrap['class'])) {
                    $class = $bootstrap['class'];
                }

                $this->setBootstrap($path, $class);
            } else {
                throw new Zend_Application_Exception('Invalid bootstrap information provided');
            }
        }

        return $this;
    }

    /**
     * Retrieve application options (for caching)
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->_options;
    }

    /**
     * Is an option present?
     *
     * @param  string $key
     * @return bool
     */
    public function hasOption($key)
    {
        return in_array(strtolower($key), $this->_optionKeys);
    }

    /**
     * Retrieve a single option
     *
     * @param  string $key
     * @return mixed
     */
    public function getOption($key)
    {
        if ($this->hasOption($key)) {
            $options = $this->getOptions();
            $options = array_change_key_case($options, CASE_LOWER);
            return $options[strtolower($key)];
        }
        return null;
    }

    /**
     * Merge options recursively
     * 递归深度合并两个数组，array2的值会覆盖array1的相应值，即数组的key以array1为准，数组的值以array2为准
     * 此方法可以抽取为一个静态方法供其他地方使用
     *
     * @param  array $array1
     * @param  mixed $array2
     * @return array
     */
    public function mergeOptions(array $array1, $array2 = null)
    {
        if (is_array($array2)) {
            foreach ($array2 as $key => $val) {
                if (is_array($array2[$key])) {
                    $array1[$key] = (array_key_exists($key, $array1) && is_array($array1[$key]))
                                  ? $this->mergeOptions($array1[$key], $array2[$key])
                                  : $array2[$key];
                } else {
                    $array1[$key] = $val;
                }
            }
        }
        return $array1;
    }

    /**
     * Set PHP configuration settings
     * 递归设置php ini选项，支持fisrt.second.third = value的配置
     * 此方法可以抽取为一个静态方法供其他地方使用
     *
     * @param  array $settings
     * @param  string $prefix Key prefix to prepend to array values (used to map . separated INI values)
     * @return Zend_Application
     */
    public function setPhpSettings(array $settings, $prefix = '')
    {
        foreach ($settings as $key => $value) {
            $key = empty($prefix) ? $key : $prefix . $key;
            if (is_scalar($value)) {
                ini_set($key, $value);
            } elseif (is_array($value)) {
                $this->setPhpSettings($value, $key . '.');
            }
        }

        return $this;
    }

    /**
     * Set include path
     * 将新增的包含路径添加到php ini配置的include_path的前面
     * 注意PATH_SEPARATOR，路径分隔符，windows下是分号";"，而*nix系统下是冒号":"
     * 此方法可以抽取为一个静态方法供其他地方使用
     *
     * @param  array $paths
     * @return Zend_Application
     */
    public function setIncludePaths(array $paths)
    {
        $path = implode(PATH_SEPARATOR, $paths);
        set_include_path($path . PATH_SEPARATOR . get_include_path());
        return $this;
    }

    /**
     * Set autoloader namespaces
     *
     * @param  array $namespaces
     * @return Zend_Application
     */
    public function setAutoloaderNamespaces(array $namespaces)
    {
        $autoloader = $this->getAutoloader();

        foreach ($namespaces as $namespace) {
            $autoloader->registerNamespace($namespace);
        }

        return $this;
    }

    /**
     * Set bootstrap path/class
     * 若只设置了路径而无类名，默认使用"bootstrap"类名
     *
     * @param  string $path
     * @param  string $class
     * @return Zend_Application
     */
    public function setBootstrap($path, $class = null)
    {
        // setOptions() can potentially send a null value; specify default
        // here
        if (null === $class) {
            $class = 'Bootstrap';
        }

        if (!class_exists($class, false)) {
            require_once $path;
            if (!class_exists($class, false)) {
                throw new Zend_Application_Exception('Bootstrap class not found');
            }
        }
        $this->_bootstrap = new $class($this);

        if (!$this->_bootstrap instanceof Zend_Application_Bootstrap_Bootstrapper) {
            throw new Zend_Application_Exception('Bootstrap class does not implement Zend_Application_Bootstrap_Bootstrapper');
        }

        return $this;
    }

    /**
     * Get bootstrap object
     * 配置中若没有设置bootstrap，则默认使用Zend_Application_Bootstrap_Bootstrap，而自定义的bootstrap一般是继承此类的，
     * 或者继承抽象类Zend_Application_Bootstrap_BootstrapAbstract，或者必须实现了Zend_Application_Bootstrap_Bootstrapper接口（定义classResources接口），
     * 因为上面setBootstrap()方法中会检查，然后一般需要而非必须实现Zend_Application_Bootstrap_ResourceBootstrapper接口（定义pluginResources接口）
     *
     * @return Zend_Application_Bootstrap_BootstrapAbstract
     */
    public function getBootstrap()
    {
        if (null === $this->_bootstrap) {
            $this->_bootstrap = new Zend_Application_Bootstrap_Bootstrap($this);
        }
        return $this->_bootstrap;
    }

    /**
     * Bootstrap application
     * 直接调用bootstrap实例的bootstrap方法启动资源，资源参数默认为null，表示全部启动配置中的资源
     * 
     * 若某个工程只需启动一部分资源，则可以使用数组把资源名传进来，提高运行效率。其他资源可以再按需启动
     * 但是多数情况下，可能提供“不启动哪些资源”的功能可能更有用，如log，我可以默认暂时先不启动，使用时按需启动一下。
     *
     * @param  null|string|array $resource
     * @return Zend_Application
     */
    public function bootstrap($resource = null)
    {
        $this->getBootstrap()->bootstrap($resource);
        return $this;
    }

    /**
     * Run the application
     *
     * @return void
     */
    public function run()
    {
        $this->getBootstrap()->run();
    }

    /**
     * Load configuration file of options
     *
     * @param  string $file
     * @throws Zend_Application_Exception When invalid configuration file is provided
     * @return array
     */
    protected function _loadConfig($file)
    {
        $environment = $this->getEnvironment();
        $suffix      = pathinfo($file, PATHINFO_EXTENSION);
        //如果后缀是disk，则先去掉“disk”，然后继续获取后缀名。即支持application.ini.disk这样的文件名，同样会获取到正确的“ini”
        $suffix      = ($suffix === 'dist')
                     ? pathinfo(basename($file, ".$suffix"), PATHINFO_EXTENSION)
                     : $suffix;

        //支持ini xml json yaml||yml php||inc(这两种其实就是直接返回一个options数组php文件)
        switch (strtolower($suffix)) {
            case 'ini':
                $config = new Zend_Config_Ini($file, $environment);
                break;

            case 'xml':
                $config = new Zend_Config_Xml($file, $environment);
                break;

            case 'json':
                $config = new Zend_Config_Json($file, $environment);
                break;

            case 'yaml':
            case 'yml':
                $config = new Zend_Config_Yaml($file, $environment);
                break;

            case 'php':
            case 'inc':
                $config = include $file;
                if (!is_array($config)) {
                    throw new Zend_Application_Exception('Invalid configuration file provided; PHP file does not return array value');
                }
                return $config;
                break;

            default:
                throw new Zend_Application_Exception('Invalid configuration file provided; unknown config type');
        }

        return $config->toArray();
    }
}
