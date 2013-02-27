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
 * @version    $Id: Bootstrap.php 23775 2011-03-01 17:25:24Z ralph $
 */

/**
 * Concrete base class for bootstrap classes
 *
 * Registers and utilizes Zend_Controller_Front by default.
 *
 * @uses       Zend_Application_Bootstrap_Bootstrap
 * @category   Zend
 * @package    Zend_Application
 * @subpackage Bootstrap
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Application_Bootstrap_Bootstrap
    extends Zend_Application_Bootstrap_BootstrapAbstract
{
    /**
     * Application resource namespace
     * @var false|string
     */
    protected $_appNamespace = false;

    /**
     * Application resource autoloader
     * @var Zend_Loader_Autoloader_Resource
     */
    protected $_resourceLoader;

    /**
     * Constructor
     * ###
     * 此类一般作为自定义bootstrap类的父类使用
     * 但是这个父类的构造函数里面的代码如下面的分析所示，是有垃圾代码需要删除掉的，可以又要调用此类的父类构造函数，因此你在自定义bootstrap类中，是无法回避此构造函数的。
     * 如果想修改的话，拷贝此类的代码到一个新类，修改构造函数行为，然后自定义的bootstrap类继承新类即可
     * ###
     *
     * Ensure FrontController resource is registered
     *
     * @param  Zend_Application|Zend_Application_Bootstrap_Bootstrapper $application
     * @return void
     */
    public function __construct($application)
    {
        parent::__construct($application);

        /**
         * ###
         * 下面这段代码是完全无用的
         * 因为parent::__construct($application);方法中，有一种自动调用bootstrap类中set{$optionKey}()方法的机制（Zend_Application_Bootstrap_BootstrapAbstract Line138）
         * 那里的$optionKey就是从传过去的$application中取到的，这里再次判断$application中有无resourceloader有的话又去调用setOptions()方法，而setOptions()方法又要去自动调用
         * set{$optionKey}()方法，所以就重复了！！因此无论如何，下面这段代码(if(){})是毫无意义的！！！可删除
         * 
         * 值得提到的另一点是，$application中的$options一般是解析自配置文件，配置文件中只能提供字符串形式的配置，且$application中没有特殊处理resourceloader字段的配置来实例化相应的resourceLoader实例
         * 保存到resourceloader字段，但是可但是，这个类中setResourceLoader(Zend_Loader_Autoloader_Resource $loader)方法却又偏偏限制了参数的类型必须是一个类，这样配置中一旦含有resourceloader
         * 字段，那么就会自动调用setResourceLoader方法，然后检测到参数为字符串类型不符合，那么程序就直接运行不下去了...那么按现在这种情况，application.ini中就不能配置resourceloader字段咯
         * 
         * 如果想修改默认的resourceloader，可以在bootstrap类中重载getResourceLoader()方法，去设置默认的自定义resourceLoader，可以在application.ini中设置自定义的配置，然后重载的get方法通过
         * getOption($key)方法获取相应自定义配置（$key不能是resourceloader），然后自定义默认resourceloader。
         * 
         * resourceloader一般是用来自加载application目录下（或者配置到其他目录下）的model、viewHelper、filter这些东西的，用不到的话，可以application.ini中不设置appnamespace字段，因为
         * 这里$this->getResourceLoader();的调用，里面是先判断有无设置appnamespace
         * ###
         */
//         if ($application->hasOption('resourceloader')) {
//             $this->setOptions(array(
//                 'resourceloader' => $application->getOption('resourceloader')
//             ));
//         }
        $this->getResourceLoader();

        /**
         * 下面这段if代码，本意是要保证FrontController资源启动，但是它并没有能保证，因为registerPluginResource只是创建资源对象，但是没有执行$this->_executeResource($resource)
         * 然后就没有运行pluginResource类中的init()方法，然后就不会将frontController资源返回来存入Container中，但是可但是，问题来了，boot以后，开始run时，获取资源都是直接从
         * Container中获取的，获取不到就算没有，也不再去查找此资源有无注册，所以下面run()方法，如果没有bootstrap frontController资源的话，$front   = $this->getResource('FrontController');
         * 就获取$front = null了。。。就悲了个催了！ 
         * 换成下面的代码解决这个问题！不过最好不让代码去做这种“检测不到一个必须的东东然后自己可怜巴巴滴去实现它”的事情，会增加开销的，因为代码会很不高兴，后果。。。！！
         * PS:这样看来，这个构造函数的源码中，貌似只有两行代码有点用。。。我了个擦！！
         * 
         * ###
         * 事情还没有结束，关键的灵感忽然来了~~
         * 
         * 在改进了按需启动后，这段if代码，便可以保证没有即使配置frontController时，FrontController资源的正常使用了！！！
         * ###
         */
//         if (!$this->hasPluginResource('FrontController')) {
//             $this->registerPluginResource('FrontController');
//         }

        /**
         * 在改进资源按需启动后，完全可以去掉这个“保证frontController资源启动的代码了”
         */
// 		if(!$this->hasResource('FrontController')){
// 	        if (!$this->hasPluginResource('FrontController')) {
// 	            $this->registerPluginResource('FrontController');
// 	        }
// 	        $this->bootstrap('FrontController');
// 		}
    }

    /**
     * Run the application
     *
     * Checks to see that we have a default controller directory. If not, an
     * exception is thrown.
     *
     * If so, it registers the bootstrap with the 'bootstrap' parameter of
     * the front controller, and dispatches the front controller.
     *
     * @return mixed
     * @throws Zend_Application_Bootstrap_Exception
     */
    public function run()
    {
        $front   = $this->getResource('FrontController');
        $default = $front->getDefaultModule();
        if (null === $front->getControllerDirectory($default)) {
            throw new Zend_Application_Bootstrap_Exception(
                'No default controller directory registered with front controller'
            );
        }

        $front->setParam('bootstrap', $this);
        $response = $front->dispatch();
        if ($front->returnResponse()) {
            return $response;
        }
    }

    /**
     * Set module resource loader
     * 因为这里限制了参数必须是Zend_Loader_Autoloader_Resource类型的，所以显然不可以通过在application.ini中的resourceloader字段来配置一个类名来
     * 设置（会自动调用相应的setXXX()方法，见Zend_Loader_Autoloader_Resource::setOptions Line138）
     * 如果想设置默认的resourceLoader，可以在bootstrap类中重载getResourceLoader()方法，在里面重新设置自己想要设置的默认值，见下面的getResourceLoader()方法
     * 
     * 当然，也可以在bootstrap类的构造函数中，先调用setResourceLoader()方法设置一个默认的实例，但是这样做不优雅
     * 由于bootstrap的$options是从已经解析好的Zend_Application类的$options中来的，所以你也可以重载Zend_Application的相应方法，处理来自application.ini中有关
     * resourceloader的配置，然后将resourceloader这个字段的值保存成Zend_Loader_Autoloader_Resource的实例，这样当bootstrap解析此字段的时候，就会触发调用setXXX()方法
     * 来设置。。。显然，这个思路就更跑偏了~~~ 但是，可以说明zf是多么的可灵活定制！！！
     *
     * @param  Zend_Loader_Autoloader_Resource $loader
     * @return Zend_Application_Module_Bootstrap
     */
    public function setResourceLoader(Zend_Loader_Autoloader_Resource $loader)
    {
        $this->_resourceLoader = $loader;
        return $this;
    }

    /**
     * Retrieve module resource loader
     * 
     *
     * @return Zend_Loader_Autoloader_Resource
     */
    public function getResourceLoader()
    {
        if ((null === $this->_resourceLoader)
            && (false !== ($namespace = $this->getAppNamespace()))
        ) {
            $r    = new ReflectionClass($this);
            $path = $r->getFileName();
            $this->setResourceLoader(new Zend_Application_Module_Autoloader(array(
                'namespace' => $namespace,
                'basePath'  => dirname($path),
            )));
        }
        return $this->_resourceLoader;
    }

    /**
     * Get application namespace (used for module autoloading)
     *
     * @return string
     */
    public function getAppNamespace()
    {
        return $this->_appNamespace;
    }

    /**
     * Set application namespace (for module autoloading)
     *
     * @param  string
     * @return Zend_Application_Bootstrap_Bootstrap
     */
    public function setAppNamespace($value)
    {
        $this->_appNamespace = (string) $value;
        return $this;
    }
}
