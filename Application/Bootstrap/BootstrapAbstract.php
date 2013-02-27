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
 * @subpackage Bootstrap
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: BootstrapAbstract.php 24394 2011-08-21 13:57:08Z padraic $
 */

/**
 * Abstract base class for bootstrap classes
 * 实现两个接口
 * Zend_Application_Bootstrap_Bootstrapper, 定义classResource相关方法
 * Zend_Application_Bootstrap_ResourceBootstrapper, 定义pluginResource相关方法
 *
 * @uses       Zend_Application_Bootstrap_Bootstrapper
 * @uses       Zend_Application_Bootstrap_ResourceBootstrapper
 * @category   Zend
 * @package    Zend_Application
 * @subpackage Bootstrap
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
abstract class Zend_Application_Bootstrap_BootstrapAbstract
    implements Zend_Application_Bootstrap_Bootstrapper,
               Zend_Application_Bootstrap_ResourceBootstrapper
{
    /**
     * @var Zend_Application|Zend_Application_Bootstrap_Bootstrapper
     */
    protected $_application;

    /**
     * @var array Internal resource methods (resource/method pairs)
     */
    protected $_classResources;

    /**
     * @var object Resource container
     */
    protected $_container;

    /**
     * @var string
     */
    protected $_environment;

    /**
     * Flattened (lowercase) option keys used for lookups
     *
     * @var array
     */
    protected $_optionKeys = array();

    /**
     * @var array
     */
    protected $_options = array();

    /**
     * @var Zend_Loader_PluginLoader_Interface
     */
    protected $_pluginLoader;

    /**
     * @var array Class-based resource plugins
     */
    protected $_pluginResources = array();

    /**
     * @var array Initializers that have been run
     */
    protected $_run = array();

    /**
     * @var array Initializers that have been started but not yet completed (circular dependency detection)
     */
    protected $_started = array();

    /**
     * Constructor
     *
     * Sets application object, initializes options, and prepares list of
     * initializer methods.
     *
     * @param  Zend_Application|Zend_Application_Bootstrap_Bootstrapper $application
     * @return void
     * @throws Zend_Application_Bootstrap_Exception When invalid application is provided
     */
    public function __construct($application)
    {
        $this->setApplication($application);
        $options = $application->getOptions();
        $this->setOptions($options);
    }

    /**
     * Set class state
     *
     * @param  array $options
     * @return Zend_Application_Bootstrap_BootstrapAbstract
     */
    public function setOptions(array $options)
    {
        $this->_options = $this->mergeOptions($this->_options, $options);

        $options = array_change_key_case($options, CASE_LOWER);
        $this->_optionKeys = array_merge($this->_optionKeys, array_keys($options));

        $methods = get_class_methods($this);
        foreach ($methods as $key => $method) {
            $methods[$key] = strtolower($method);
        }

        /**
         * pluginpaths字段，配置自定义的pluginResources类的自动加载路径和前缀
         * pluginpath.Hitg_Application_Resource = "Hitg/Application/Resource" 或者
         * pluginpath.Hitg_App_Res = "Hitg/Application/Resource" 类名和路径不必一一对应 只要配置好了就成
         */
        if (array_key_exists('pluginpaths', $options)) {
            $pluginLoader = $this->getPluginLoader();

            foreach ($options['pluginpaths'] as $prefix => $path) {
                $pluginLoader->addPrefixPath($prefix, $path);
            }
            unset($options['pluginpaths']);
        }

        /**
         * 自动调用setXXX方法，设置相应的$option字段
         * 基于这一点，可以在启动类文件中定义相应的set{$optionKey}()方法，来使用application.ini中自定义的配置做一些自己想做的事情，很灵活
         * 
         * 并且如果设置了resources字段，则注册配置的pluginResources（db、cache、log这些...）
         */
        foreach ($options as $key => $value) {
            $method = 'set' . strtolower($key);

            if (in_array($method, $methods)) {
                $this->$method($value);
            } elseif ('resources' == $key) {
                foreach ($value as $resource => $resourceOptions) {
                    $this->registerPluginResource($resource, $resourceOptions);
                }
            }
        }
        return $this;
    }

    /**
     * Get current options from bootstrap
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
     * Get class resources (as resource/method pairs)
     * 
     * classResources，即在bootstrap类中方法名以"_init"开头的方法
     * 这里将他们收集到$this->_classResources数组中缓存起来（因为运行时，bootstrap类方法是不会改变的），下标是去掉"_init"前缀的部分作为classResources资源名，
     * 值就直接是方法名
     *
     * Uses get_class_methods() by default, reflection on prior to 5.2.6,
     * as a bug prevents the usage of get_class_methods() there.
     *
     * @return array
     */
    public function getClassResources()
    {
        if (null === $this->_classResources) {
            if (version_compare(PHP_VERSION, '5.2.6') === -1) {
                $class        = new ReflectionObject($this);
                $classMethods = $class->getMethods();
                $methodNames  = array();

                foreach ($classMethods as $method) {
                    $methodNames[] = $method->getName();
                }
            } else {
                $methodNames = get_class_methods($this);
            }

            $this->_classResources = array();
            foreach ($methodNames as $method) {
                if (5 < strlen($method) && '_init' === substr($method, 0, 5)) {
                    $this->_classResources[strtolower(substr($method, 5))] = $method;
                }
            }
        }

        return $this->_classResources;
    }

    /**
     * Get class resource names
     *
     * @return array
     */
    public function getClassResourceNames()
    {
        $resources = $this->getClassResources();
        return array_keys($resources);
    }

    /**
     * Register a new resource plugin
     * 注册资源插件，存入$this->_pluginResources数组中，下标是资源名称，值为资源实例或者资源配置选项
     * 
     * 如果参数$resource是资源的实例对象，则会调用$this->_resolvePluginResourceName方法，来获取该资源名称作为数组下标。获取的规则是依次检测1、是否设置了$resource->_explicitType
     * 2、获取资源类全名（get_class()），然后检查其前缀是否在$this->_pluginLoader中注册，若是则去掉前缀只保留短资源类名，若否则使用类全名。此时，数组值即为该资源实例。
     * ###
     * 但是这种情况会忽略第二个参数$options，我想应该也支持此参数，调用一下$resource->setOptions($options)，支持直接注册资源实例的同时，还可以传入一些特殊配置选项。可以在bootstrap类
     * 中重装此方法。
     * ###
     * 
     * 如果参数$resource是一个字符串，则将之看做资源名看待作为数组下标，然后把$options参数作为相应的字段值，保存，暂不实例化该资源
     *
     * @param  string|Zend_Application_Resource_Resource $resource
     * @param  mixed  $options
     * @return Zend_Application_Bootstrap_BootstrapAbstract
     * @throws Zend_Application_Bootstrap_Exception When invalid resource is provided
     */
    public function registerPluginResource($resource, $options = null)
    {
        if ($resource instanceof Zend_Application_Resource_Resource) {
            $resource->setBootstrap($this);
            $pluginName = $this->_resolvePluginResourceName($resource);
            $this->_pluginResources[$pluginName] = $resource;
            return $this;
        }

        if (!is_string($resource)) {
            throw new Zend_Application_Bootstrap_Exception('Invalid resource provided to ' . __METHOD__);
        }

        $this->_pluginResources[$resource] = $options;
        return $this;
    }

    /**
     * Unregister a resource from the bootstrap
     *
     * @param  string|Zend_Application_Resource_Resource $resource
     * @return Zend_Application_Bootstrap_BootstrapAbstract
     * @throws Zend_Application_Bootstrap_Exception When unknown resource type is provided
     */
    public function unregisterPluginResource($resource)
    {
        if ($resource instanceof Zend_Application_Resource_Resource) {
            if ($index = array_search($resource, $this->_pluginResources, true)) {
                unset($this->_pluginResources[$index]);
            }
            return $this;
        }

        if (!is_string($resource)) {
            throw new Zend_Application_Bootstrap_Exception('Unknown resource type provided to ' . __METHOD__);
        }

        $resource = strtolower($resource);
        if (array_key_exists($resource, $this->_pluginResources)) {
            unset($this->_pluginResources[$resource]);
        }

        return $this;
    }

    /**
     * Is the requested plugin resource registered?
     *
     * @param  string $resource
     * @return bool
     */
    public function hasPluginResource($resource)
    {
        return (null !== $this->getPluginResource($resource));
    }

    /**
     * Get a registered plugin resource
     * 
     * 太乱了这个方法
     * 
     *
     * @param  string $resourceName
     * @return Zend_Application_Resource_Resource
     */
    public function getPluginResource($resource)
    {
        if (array_key_exists(strtolower($resource), $this->_pluginResources)) {
            $resource = strtolower($resource);
            if (!$this->_pluginResources[$resource] instanceof Zend_Application_Resource_Resource) {
                $resourceName = $this->_loadPluginResource($resource, $this->_pluginResources[$resource]);
                if (!$resourceName) {
                    throw new Zend_Application_Bootstrap_Exception(sprintf('Unable to resolve plugin "%s"; no corresponding plugin with that name', $resource));
                }
                $resource = $resourceName;
            }
            return $this->_pluginResources[$resource];
        }

        /**
         * 下面这段代码，未免有点过度设计了
         * 这一坨代码，用处就是当要获取的pluginResource未注册在$this->_pluginResources时，检查一下是否$resource是否等于$this->_resolvePluginResourceName($pluginResource)
         * 因为，pluginResourceName有三种可能，优先使用顺序是:1、$pluginResourceName->_explicitType值，即在pluginResource类中加入这么一个公共成员变量，用以明确指定其名字，而非通过
         * $this->registerPluginResource注册的名字！！（这特么不没事儿找事儿吗？）；2、pluginResource的short name，即除了前缀以外的名字，一般就是现在使用的注册的名字了~（最好这样一致起来）；
         * 3、类全名！！    下面这一坨代码，就是处理1、3这两种情况的，允许通过$pluginResourceName->_explicitType和$pluginResource的类名获取pluginResource，但是代价却是要遍历真整个注册的
         * plugin数组并且实例化，直到找到为止，让按需实例化plugin成为不可能！ 因此，慎用这种方式~
         * 
         * 还有一种情况，看上面的条件语句array_key_exists(strtolower($resource), $this->_pluginResources)，就是要获取的$resourceName按小写来（其实就是忽略大小写），与name相关的
         * 方法如resolvePluginResourceName、 unRegsiterPluginResource都对pluginName做了strtolower处理，但是唯独registerPluginResource没有做这个处理，这样注册到plugin数组
         * 后，如果大小写不一样，同样会进入下面的代码！ 如前端控制器， 资源类是Zend_Application_Resource_Frontcontroller，ini文件配置的是frontController，就出现了这种情况
         * 
         * Zend_Loader_Pluginloader::load方法加载plugin类时，对类名是做了ucfirst(strtolower($name))处理，即不管资源名由几个单词组成，只有首字母大写（相应类名也是这样的规范），
         * 因此重载一下registerPluginResource方法吧！
         */
        foreach ($this->_pluginResources as $plugin => $spec) {
            if ($spec instanceof Zend_Application_Resource_Resource) {
                $pluginName = $this->_resolvePluginResourceName($spec);
                if (0 === strcasecmp($resource, $pluginName)) {
                    unset($this->_pluginResources[$plugin]);
                    $this->_pluginResources[$pluginName] = $spec;
                    return $spec;
                }
                continue;
            }

            if (false !== $pluginName = $this->_loadPluginResource($plugin, $spec)) {
                if (0 === strcasecmp($resource, $pluginName)) {
                    return $this->_pluginResources[$pluginName];
                }
                continue;
            }

            if (class_exists($plugin)
            && is_subclass_of($plugin, 'Zend_Application_Resource_Resource')
            ) { //@SEE ZF-7550
                $spec = (array) $spec;
                $spec['bootstrap'] = $this;
                $instance = new $plugin($spec);
                $pluginName = $this->_resolvePluginResourceName($instance);
                unset($this->_pluginResources[$plugin]);
                $this->_pluginResources[$pluginName] = $instance;

                if (0 === strcasecmp($resource, $pluginName)) {
                    return $instance;
                }
            }
        }

        return null;
    }

    /**
     * Retrieve all plugin resources
     *
     * @return array
     */
    public function getPluginResources()
    {
        foreach (array_keys($this->_pluginResources) as $resource) {
            $this->getPluginResource($resource);
        }
        return $this->_pluginResources;
    }

    /**
     * Retrieve plugin resource names
     *
     * @return array
     */
    public function getPluginResourceNames()
    {
        $this->getPluginResources();
        return array_keys($this->_pluginResources);
    }

    /**
     * Set plugin loader for loading resources
     *
     * @param  Zend_Loader_PluginLoader_Interface $loader
     * @return Zend_Application_Bootstrap_BootstrapAbstract
     */
    public function setPluginLoader(Zend_Loader_PluginLoader_Interface $loader)
    {
        $this->_pluginLoader = $loader;
        return $this;
    }

    /**
     * Get the plugin loader for resources
     * bootstrap里的pluginLoader其实为了获取pluginResources用的
     * 可以在bootstrap类中重载此方法，以改变默认使用的pluginLoader
     * 
     * 源码中，这个get方法没有调用上面相应的set方法，其实是不规范的~~
     *
     * @return Zend_Loader_PluginLoader_Interface
     */
    public function getPluginLoader()
    {
        if ($this->_pluginLoader === null) {
            $options = array(
                'Zend_Application_Resource'  => 'Zend/Application/Resource',
                'ZendX_Application_Resource' => 'ZendX/Application/Resource'
            );

            $this->_pluginLoader = new Zend_Loader_PluginLoader($options);
        }

        return $this->_pluginLoader;
    }

    /**
     * Set application/parent bootstrap
     *
     * @param  Zend_Application|Zend_Application_Bootstrap_Bootstrapper $application
     * @return Zend_Application_Bootstrap_BootstrapAbstract
     */
    public function setApplication($application)
    {
        if (($application instanceof Zend_Application)
            || ($application instanceof Zend_Application_Bootstrap_Bootstrapper)
        ) {
            if ($application === $this) {
                throw new Zend_Application_Bootstrap_Exception('Cannot set application to same object; creates recursion');
            }
            $this->_application = $application;
        } else {
            throw new Zend_Application_Bootstrap_Exception('Invalid application provided to bootstrap constructor (received "' . get_class($application) . '" instance)');
        }
        return $this;
    }

    /**
     * Retrieve parent application instance
     *
     * @return Zend_Application|Zend_Application_Bootstrap_Bootstrapper
     */
    public function getApplication()
    {
        return $this->_application;
    }

    /**
     * Retrieve application environment
     *
     * @return string
     */
    public function getEnvironment()
    {
        if (null === $this->_environment) {
            $this->_environment = $this->getApplication()->getEnvironment();
        }
        return $this->_environment;
    }

    /**
     * Set resource container
     *
     * By default, if a resource callback has a non-null return value, this
     * value will be stored in a container using the resource name as the
     * key.
     *
     * Containers must be objects, and must allow setting public properties.
     *
     * @param  object $container
     * @return Zend_Application_Bootstrap_BootstrapAbstract
     */
    public function setContainer($container)
    {
        if (!is_object($container)) {
            throw new Zend_Application_Bootstrap_Exception('Resource containers must be objects');
        }
        $this->_container = $container;
        return $this;
    }

    /**
     * Retrieve resource container
     * 
     * ###
     * 这里默认的（资源）container，使用的是Zend_registry实例，但不是单例（注意new Zend_Registry()，而不是Zend_Registry::getInstance()）
     * 因此，如果不改变这个方法，在程序其他地方取得解析资源配置后然后存储在这里的资源，只能通过$bootstrap类的getResource()方法进而通过getContainer()然后获取。
     * 也就是说，程序其他地方想取得配置好的某个资源，但是却无法获取bootstrap实例或者获取很麻烦，就没有啥子办法了~~~
     * 这样做的原因，或许是防止程序其他地方使用Zend_Registry时意外覆盖已存储的资源吧
     * 
     * 想改变这个默认行为，可以在bootstrap类中重载此方法，修改相应代码为setContainer(Zend_Registry::getInstance())，世界一下简单多了，无论何时，不论何地，
     * Zend_Registry::getInstance()->get($resourceName);搞定
     * ###
     * 
     * @return object
     */
    public function getContainer()
    {
        if (null === $this->_container) {
            $this->setContainer(new Zend_Registry());
        }
        return $this->_container;
    }

    /**
     * Determine if a resource has been stored in the container
     *
     * During bootstrap resource initialization, you may return a value. If
     * you do, it will be stored in the {@link setContainer() container}.
     * You can use this method to determine if a value was stored.
     *
     * @param  string $name
     * @return bool
     */
    public function hasResource($name)
    {
        $resource  = strtolower($name);
        $container = $this->getContainer();
        return isset($container->{$resource});
    }

    /**
     * Retrieve a resource from the container
     *
     * During bootstrap resource initialization, you may return a value. If
     * you do, it will be stored in the {@link setContainer() container}.
     * You can use this method to retrieve that value.
     *
     * If no value was returned, this will return a null value.
     *
     * @param  string $name
     * @return null|mixed
     */
    public function getResource($name)
    {
        $resource  = strtolower($name);
        $container = $this->getContainer();
        if ($this->hasResource($resource)) {
            return $container->{$resource};
        }
        return null;
    }

    /**
     * Implement PHP's magic to retrieve a ressource
     * in the bootstrap
     *
     * @param string $prop
     * @return null|mixed
     */
    public function __get($prop)
    {
        return $this->getResource($prop);
    }

    /**
     * Implement PHP's magic to ask for the
     * existence of a ressource in the bootstrap
     *
     * @param string $prop
     * @return bool
     */
    public function __isset($prop)
    {
        return $this->hasResource($prop);
    }

    /**
     * Bootstrap individual, all, or multiple resources
     *
     * Marked as final to prevent issues when subclassing and naming the
     * child class 'Bootstrap' (in which case, overriding this method
     * would result in it being treated as a constructor).
     *
     * If you need to override this functionality, override the
     * {@link _bootstrap()} method.
     *
     * @param  null|string|array $resource
     * @return Zend_Application_Bootstrap_BootstrapAbstract
     * @throws Zend_Application_Bootstrap_Exception When invalid argument was passed
     */
    final public function bootstrap($resource = null)
    {
        $this->_bootstrap($resource);
        return $this;
    }

    /**
     * Overloading: intercept calls to bootstrap<resourcename>() methods
     *
     * @param  string $method
     * @param  array  $args
     * @return void
     * @throws Zend_Application_Bootstrap_Exception On invalid method name
     */
    public function __call($method, $args)
    {
        if (9 < strlen($method) && 'bootstrap' === substr($method, 0, 9)) {
            $resource = substr($method, 9);
            return $this->bootstrap($resource);
        }

        throw new Zend_Application_Bootstrap_Exception('Invalid method "' . $method . '"');
    }

    /**
     * Bootstrap implementation
     *
     * This method may be overridden to provide custom bootstrapping logic.
     * It is the sole method called by {@link bootstrap()}.
     *
     * @param  null|string|array $resource
     * @return void
     * @throws Zend_Application_Bootstrap_Exception When invalid argument was passed
     */
    protected function _bootstrap($resource = null)
    {
        if (null === $resource) {
            foreach ($this->getClassResourceNames() as $resource) {
                $this->_executeResource($resource);
            }

            foreach ($this->getPluginResourceNames() as $resource) {
                $this->_executeResource($resource);
            }
        } elseif (is_string($resource)) {
            $this->_executeResource($resource);
        } elseif (is_array($resource)) {
            foreach ($resource as $r) {
                $this->_executeResource($r);
            }
        } else {
            throw new Zend_Application_Bootstrap_Exception('Invalid argument passed to ' . __METHOD__);
        }
    }

    /**
     * Execute a resource
     *
     * Checks to see if the resource has already been run. If not, it searches
     * first to see if a local method matches the resource, and executes that.
     * If not, it checks to see if a plugin resource matches, and executes that
     * if found.
     *
     * Finally, if not found, it throws an exception.
     * 
     * 这个方法还是蛮巧妙的！
     * 设置了两个标志位数组：$this_run存储已经运行过的资源，首先检查要执行的资源是否已在$this->_run数组中，在的话直接返回，防止意外重复执行
     * $this->_started存储当前正在执行但是尚未执行完毕的资源。因为某个classResource的执行体内部，可能会需要先启动其他的classResource或者pluginResource（依赖），如果
     * classResource1依赖classResource2，而classResource2又意外的依赖classResource1，这样就出现了执行classResource1时要调用classResource2，classResource2执行又去
     * 调用classResource1的情况，即无限死循环，这个标志位数组就是为了检测这种异常情况的，一个资源执行时，将其在$this->_started数组中相应标志位置true，执行完再注销掉
     * 
     * ###
     * 虽然classResources和pluginResources虽然是存储在两个数组中的，但是运行完后却都存在了$this->_run数组中，下标就是各自的资源名。这里，因为_bootstrap方法中是首先执行classResources的，
     * 所以，两种resource同名时（说白了就是bootstrap类中_initXXX的XXX也是application.ini所配置的一个资源名），classResource会覆盖掉pluginResource
     * ###
     * 
     * 两种resource执行完时，若有返回值返回，则会被存在Container中（关于Container的OOXX，详见getContainer()方法注释）
     *
     * @param  string $resource
     * @return void
     * @throws Zend_Application_Bootstrap_Exception When resource not found
     */
    protected function _executeResource($resource)
    {
        $resourceName = strtolower($resource);

        if (in_array($resourceName, $this->_run)) {
            return;
        }

        if (isset($this->_started[$resourceName]) && $this->_started[$resourceName]) {
            throw new Zend_Application_Bootstrap_Exception('Circular resource dependency detected');
        }

        $classResources = $this->getClassResources();
        if (array_key_exists($resourceName, $classResources)) {
            $this->_started[$resourceName] = true;
            $method = $classResources[$resourceName];
            $return = $this->$method();
            unset($this->_started[$resourceName]);
            $this->_markRun($resourceName);

            if (null !== $return) {
                $this->getContainer()->{$resourceName} = $return;
            }

            return;
        }

        if ($this->hasPluginResource($resource)) {
            $this->_started[$resourceName] = true;
            $plugin = $this->getPluginResource($resource);
            $return = $plugin->init();
            unset($this->_started[$resourceName]);
            $this->_markRun($resourceName);

            if (null !== $return) {
                $this->getContainer()->{$resourceName} = $return;
            }

            return;
        }

        throw new Zend_Application_Bootstrap_Exception('Resource matching "' . $resource . '" not found');
    }

    /**
     * Load a plugin resource
     * 
     * 加载pluginResource的优先顺序是，后注册的prefix优先，后注册的path优先
     * 具体细节见 @link Zend_Loader_PluginLoader::load方法（有两处关键的array_reverse）
     *
     * @param  string $resource
     * @param  array|object|null $options
     * @return string|false
     */
    protected function _loadPluginResource($resource, $options)
    {
        $options   = (array) $options;
        $options['bootstrap'] = $this;
        $className = $this->getPluginLoader()->load(strtolower($resource), false);

        if (!$className) {
            return false;
        }

        $instance = new $className($options);

        unset($this->_pluginResources[$resource]);

        if (isset($instance->_explicitType)) {
            $resource = $instance->_explicitType;
        }
        $resource = strtolower($resource);
        $this->_pluginResources[$resource] = $instance;

        return $resource;
    }

    /**
     * Mark a resource as having run
     *
     * @param  string $resource
     * @return void
     */
    protected function _markRun($resource)
    {
        if (!in_array($resource, $this->_run)) {
            $this->_run[] = $resource;
        }
    }

    /**
     * Resolve a plugin resource name
     *
     * Uses, in order of preference
     * - $_explicitType property of resource
     * - Short name of resource (if a matching prefix path is found)
     * - class name (if none of the above are true)
     *
     * The name is then cast to lowercase.
     *
     * @param  Zend_Application_Resource_Resource $resource
     * @return string
     */
    protected function _resolvePluginResourceName($resource)
    {
        if (isset($resource->_explicitType)) {
            $pluginName = $resource->_explicitType;
        } else  {
            $className  = get_class($resource);
            $pluginName = $className;
            $loader     = $this->getPluginLoader();
            foreach ($loader->getPaths() as $prefix => $paths) {
                if (0 === strpos($className, $prefix)) {
                    $pluginName = substr($className, strlen($prefix));
                    $pluginName = trim($pluginName, '_');
                    break;
                }
            }
        }
        $pluginName = strtolower($pluginName);
        return $pluginName;
    }
}
