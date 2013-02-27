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
 * @subpackage Resource
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Frontcontroller.php 23775 2011-03-01 17:25:24Z ralph $
 */

/**
 * @see Zend_Application_Resource_ResourceAbstract
 */
require_once 'Zend/Application/Resource/ResourceAbstract.php';


/**
 * Front Controller resource
 *
 * @category   Zend
 * @package    Zend_Application
 * @subpackage Resource
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Application_Resource_Frontcontroller extends Zend_Application_Resource_ResourceAbstract
{
    /**
     * @var Zend_Controller_Front
     */
    protected $_front;

    /**
     * Initialize Front Controller
     *
     * @return Zend_Controller_Front
     */
    public function init()
    {
        $front = $this->getFrontController();
		
        foreach ($this->getOptions() as $key => $value) {
            switch (strtolower($key)) {
            	/**
            	 * 如果是controllerdirectory = "A directory", 则然后按单模块处理：
            	 * 		Zend_Controller_Dispatcher_Standard::$_controllerDirectory['default'] = "A Controller directory" 
            	 * 如果是controllerdirectory.moduleName1 = "One Controller directory"
            	 * 	   controllerdirectory.moduleName2 = "two Controller directory"
            	 * 		则按多模块处理：
            	 * 		Zend_Controller_Dispatcher_Standard::$_controllerDirectory['moduleName1'] = "One Controller directory"
            	 * 		Zend_Controller_Dispatcher_Standard::$_controllerDirectory['moduleName2'] = "Two Controller directory"
            	 */
                case 'controllerdirectory':
                    if (is_string($value)) {
                        $front->setControllerDirectory($value);
                    } elseif (is_array($value)) {
                        foreach ($value as $module => $directory) {
                            $front->addControllerDirectory($directory, $module);
                        }
                    }
                    break;

                /**
                 * 设置存放模块的目录下控制器目录的名称
                 */
                case 'modulecontrollerdirectoryname':
                    $front->setModuleControllerDirectoryName($value);
                    break;

                /**
                 * 设置模块存放目录，自动查找获取有效模块并且配置模块下控制器路径（_controllerDirectory）
                 * $front->addModuleDirectory($value);处理时，是在设置的目录下查找有效的模块目录，然后把找到的目录名与各自目录下控制器路径通过
                 * $front->addControllerDirectory($directory, $module);配置起来，不用挨个设置模块
                 */
                case 'moduledirectory':
                    if (is_string($value)) {
                        $front->addModuleDirectory($value);
                    } elseif (is_array($value)) {
                        foreach($value as $moduleDir) {
                            $front->addModuleDirectory($moduleDir);
                        }
                    }
                    break;

                /**
                 * 下面三个配置默认模块、控制器、动作方法的名称
                 * 具体是在Zend_Controller_Dispatcher_Standard实例中配置的
                 */
                case 'defaultcontrollername':
                    $front->setDefaultControllerName($value);
                    break;

                case 'defaultaction':
                    $front->setDefaultAction($value);
                    break;

                case 'defaultmodule':
                    $front->setDefaultModule($value);
                    break;

                /**
                 * Full Url = Scheme + Host + Port + REQUEST_URI + Query + Hash
                 * 而在zf中 REQUEST_URI = baseUrl + Module + Controller + Action + Params...
                 * 不设置baseUrl的话，就会按上面的规则自动检测
                 * 
                 * 先存放在Zend_Controller_Front::_baseUrl中
                 * @see Zend_Controller_Front::dispatch方法，会在设置了request实例后，调用其setBaseUrl方法，
                 */
                case 'baseurl':
                    if (!empty($value)) {
                        $front->setBaseUrl($value);
                    }
                    break;

                /**
                 * Set parameters to pass to action controller constructors
                 */
                case 'params':
                    $front->setParams($value);
                    break;

                /**
                 * 配置前端控制器插件
                 * 注意可以指定插件的stackindex
                 * @see Zend_Controller_Plugin_Broker::registerPlugin 可以看到具体细节
                 * 
                 * 插件注册到plugin broker后，检测是否设置了request 和 response实例，是的话，就把两个实例注册到插件中去
                 * @link Zend_Controller_Front::dispatch方法中，设置了request和response实例后，就把两个实例注册到插件中去
                 */
                case 'plugins':
//                 	var_dump($value);
                    foreach ((array) $value as $pluginClass) {
                        $stackIndex = null;
                        if(is_array($pluginClass)) {
                            $pluginClass = array_change_key_case($pluginClass, CASE_LOWER);
                            if(isset($pluginClass['class']))
                            {
                                if(isset($pluginClass['stackindex'])) {
                                    $stackIndex = $pluginClass['stackindex'];
                                }

                                $pluginClass = $pluginClass['class'];
                            }
                        }

                        $plugin = new $pluginClass();
                        $front->registerPlugin($plugin, $stackIndex);
                    }
                    break;

                /**
                 * bootstrap->run()是否返回响应内容
                 * @see Zend_Application_Bootstrap_Bootstrap::run
                 */
                case 'returnresponse':
                    $front->returnResponse((bool) $value);
                    break;

                /**
                 * Set whether exceptions encounted in the dispatch loop should be thrown
     			 * or caught and trapped in the response object.
     			 * 
     			 * Default behaviour is to trap them in the response object; call this
     			 * method to have them thrown.
                 */
                case 'throwexceptions':
                    $front->throwExceptions((bool) $value);
                    break;

                /**
                 * 注册动作助手插件，后注册的会优先使用
                 */
                case 'actionhelperpaths':
//                 	var_dump($value);
                    if (is_array($value)) {
                        foreach ($value as $helperPrefix => $helperPath) {
                            Zend_Controller_Action_HelperBroker::addPath($helperPath, $helperPrefix);
                        }
                    }
                    break;

                default:
                	/**
                	 * 其他的都一并当做param传给动作控制器
                	 */
                    $front->setParam($key, $value);
                    break;
            }
        }

        if (null !== ($bootstrap = $this->getBootstrap())) {
            $this->getBootstrap()->frontController = $front;
        }

        return $front;
    }

    /**
     * Retrieve front controller instance
     *
     * @return Zend_Controller_Front
     */
    public function getFrontController()
    {
        if (null === $this->_front) {
            $this->_front = Zend_Controller_Front::getInstance();
        }
        return $this->_front;
    }
}
