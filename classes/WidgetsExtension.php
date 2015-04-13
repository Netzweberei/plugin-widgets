<?php
/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace herbie\plugin\widgets\classes;

use Pimple;
use Pimple\Container;
use Herbie;
use Herbie\Loader;
use Herbie\Twig;
use Twig_Autoloader;
use Twig_SimpleFunction;
use Twig_Environment;
use Twig_Extension_Debug;
use Twig_Loader_Chain;
use Twig_Loader_Filesystem;

class WidgetsExtension extends \Twig_Extension
{
    /**
    * @var Application
    */
    protected $app;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $webPath;

    /**
     * @var string
     */
    protected $pagePath;

    /**
     * @var string
     */
    protected $cachePath = 'cache';

    /**
     * @var string
     */
    protected $uriPrefix = '_';

    /**
     * @param Application $app
     */
    public function __construct($app)
    {
        if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

        $this->app = $app;
        $this->basePath = $app['request']->getBasePath() . DS;
        $this->webPath = rtrim(dirname($_SERVER['SCRIPT_FILENAME']), DS);
        $this->pagePath = rtrim($app['config']->get('pages.path').$_SERVER['REQUEST_URI'], DS);
        $this->cachePath = $app['config']->get('widgets.cachePath', 'cache');
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'widgets';
    }

    /**
     * @return array
     */
    public function getFunctions()
    {
        return [
            new \Twig_SimpleFunction('widget', array($this, 'widgetFunction'), ['is_safe' => ['html']])
        ];
    }

    /**
     * @param string|int $segmentId
     * @param bool $wrap
     * @return string
     */
    public function widgetFunction($path = null, $slide = 0, $wrap = null)
    {
        $content = $this->renderWidget($path, $slide);
        if (empty($wrap)) {
            return $content;
        }
        return sprintf('<div class="widget-%s">%s</div>', $path, $content);
    }

    public function renderWidget($widgetName, $slide = 0, $fireEvents = false)
    {

        $alias = $this->app['alias'];
        $twig  = $this->app['twig'];
        $path  = $this->app['menu']->getItem($this->app['route'])->getPath();

        $_subtemplateDir = false;
        $_subtemplatePaths = [];
        $_curDir = dirname($alias->get($path));
        $_widgetDir = $this->uriPrefix.strtolower($widgetName);
        $slide = (int) $slide;

        do {
            $_pageDir = $_curDir;
            $_subtemplateDir = $this->isWidget($_pageDir.DS.$_widgetDir);
            $_curDir = substr($_curDir, 0, strrpos($_curDir, DS));
            $slide--;
        }
        while(!$_subtemplateDir && $slide > 0);

        if(!$_subtemplateDir) {
            return null;
        }

        // @Todo: Do we really need this?
        //$_overrideSubtemplatePath = $alias->get('@site/widgets/'.$_widgetDir.'/.layout');
        //if(file_exists($_overrideSubtemplatePath)){
        //    $_subtemplatePaths[] = $_overrideSubtemplatePath;
        //}
        $_subtemplatePaths[] = $_subtemplateDir;

        $pageLoader = $twig->environment->getLoader();
        $pageData = $this->app['page']->toArray();

        $widgetLoader = new Twig_Loader_Filesystem($_subtemplatePaths);
        $widgetPage = new \Herbie\Loader\PageLoader($alias);
        $widgetPath = $_pageDir.DS.$_widgetDir.DS.'index.md';
        $widgetData = $widgetPage->load($widgetPath);

        $twig->environment->setLoader($widgetLoader);
        $this->app['page']->setData($widgetData['data']);
        $this->app['page']->setSegments($widgetData['segments']);

        $twiggedWidget = strtr($twig->render('index.html', array(
            'abspath' => dirname($_subtemplateDir).'/'
        ) ), array(
            './' => substr(dirname($_subtemplateDir), strlen($this->app['webPath'])).'/'
        ));

        if($fireEvents){
            $this->app['events']->dispatch('onWidgetLoaded', new \Herbie\Event([
                'widgetData'        => $widgetData,
                'widgetTemplateDir' => $_subtemplateDir,
                'widgetPath'        => $widgetPath,
                'pageLoader'        => &$pageLoader
            ]));
        }

        $twig->environment->setLoader($pageLoader);
        $this->app['page']->setData($pageData['data']);
        $this->app['page']->setSegments($pageData['segments']);

        if($fireEvents) {
            $this->app['events']->dispatch('onWidgetGenerated', new \Herbie\Event([
                'widgetData'        => $widgetData,
                'widgetTemplateDir' => $_subtemplateDir,
                'widgetPath'        => $widgetPath,
                'pageLoader'        => &$pageLoader
            ]));
        }

        return $twiggedWidget;
    }

    public function getAvailableWidgets($path=null)
    {
        $ret = [];
        $alias = $this->app['alias'];

        $_curPath  = $path ? $path : dirname($this->app['menu']->getItem($this->app['route'])->getPath());
        $_curDir = $alias->get($_curPath);

        foreach(new \DirectoryIterator($_curDir) as $fileinfo){
            $_fileName = $fileinfo->getFileName();
            $_pathName = $fileinfo->getPathName();
            if(
                substr($_fileName, 0, strlen($this->uriPrefix))==$this->uriPrefix
                && $this->isWidget($_pathName)
            ){
                $ret['available'][] = [
                    'name' => substr($_fileName, strlen($this->uriPrefix)),
                    'icon' => 'widget',
                    'type' => $_fileName,
                    'uri'  => $_fileName
                ];
            }
        }

        return $ret;
    }

    public function doCopyWidget($widget = false, $from = null, $to = null){

        $alias  = $this->app['alias'];

        $_widget = $this->uriPrefix.$widget;
        $_from   = $alias->get($from);

        if(!empty($_from) && $this->isWidget($_from.DS.$_widget)!== false) {
            // do something...
            return true;
        }

        return false;
    }

    protected function isWidget($path)
    {
        $_subtemplateDir = $path.DS.'.layout';
        if(!is_dir($_subtemplateDir)){
            return false;
        }
        return $_subtemplateDir;
    }
}
