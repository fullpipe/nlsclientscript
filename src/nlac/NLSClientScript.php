<?php

namespace nlac;

use JShrink\Minifier;

/**
 * NLSClientScript 7.0.0-beta.
 *
 * a Yii CClientScript extension for
 * - preventing multiple loading of javascript files
 * - merging, caching registered javascript and css files
 *
 * The extension is based on the great idea of Eirik Hoem, see
 * http://www.eirikhoem.net/blog/2011/08/29/yii-framework-preventing-duplicate-jscss-includes-for-ajax-requests/
 */

/**
 * @author nlac
 */
class NLSClientScript extends \CClientScript
{
    /**
     * - only merges if there are more than mergeAbove file registered to be included at a position
     * - applies for both css and js processing
     * - it doesn't consider @imports inside css files, only counts the top-level files and compares the sum with $mergeAbove.
     *
     * @param int $mergeAbove
     */
    public $mergeAbove = 0;

    /**
     * merge or not the registered script files, defaults to false.
     *
     * @param boolean $mergeJs
     */
    public $mergeJs = false;

    /**
     * Merge/compress js on every request - for debug purposes only.
     */
    public $forceMergeJs = false;

    /**
     * minify or not the merged js file, defaults to false.
     *
     * @param boolean $compressMergedJs
     */
    public $compressMergedJs = false;

    /**
     * merge or not the registered css files, defaults to false.
     *
     * @param boolean $mergeCss
     */
    public $mergeCss = false;

    /**
     * Merge/compress css on every request - for debug purposes only.
     */
    public $forceMergeCss = false;

    /**
     * if true, it downloads all resources (web fonts, images etc) into the /assets dir
     * if false (default), the merged css will use absolute urls pointing to the original resources.
     *
     * @param boolean $downloadCssResources
     */
    public $downloadCssResources = false;

    /**
     * @param boolean $compressMergedCss
     *                                   minify or not the merged css file, defaults to false
     */
    public $compressMergedCss = false;

    /**
     * regex for php. the matched URLs won't be filtered.
     *
     * @param string $mergeJsExcludePattern
     */
    public $mergeJsExcludePattern = null;

    /**
     * regex for php. the matched URLs will be filtered.
     *
     * @param string $mergeJsIncludePattern
     */
    public $mergeJsIncludePattern = null;

    /**
     * regex for php. the matched URLs won't be filtered.
     *
     * @param string $mergeCssExcludePattern
     */
    public $mergeCssExcludePattern = null;

    /**
     * regex for php. the matched URLs will be filtered.
     *
     * @param string $mergeCssIncludePattern
     */
    public $mergeCssIncludePattern = null;

    /**
     * if true then js files will be merged even if the request rendering the view is ajax
     * (if $mergeJs and $mergeAbove conds are satisfied)
     * defaults to false - no js merging if the view is requested by ajax.
     *
     * @param boolean $mergeIfXhr
     */
    public $mergeIfXhr = false;

    /**
     * Optional, version of the application.
     * If set to not empty, will be appended to the merged js/css urls (helps to handle cached resources).
     *
     * @param string $appVersion
     */
    public $appVersion = '';

    /**
     * @param int $curlTimeOut
     *
     * @see http://php.net/manual/en/function.curl-setopt.php
     */
    public $curlTimeOut = 15;

    /**
     * @param int $curlConnectionTimeOut
     *
     * @see http://php.net/manual/en/function.curl-setopt.php
     */
    public $curlConnectionTimeOut = 15;

    protected $separated = array();

    /**
     * Base dir to save all stuffs.
     */
    protected $workingDirPath = null;
    protected $workingDirUrl = null;

    /**
     * CURL resource.
     */
    protected $ch = null;

    /**
     * @var NLSDownloader
     */
    protected $downloader;

    /**
     * @var NLSCssMerge
     */
    protected $cssMerger;

    public function init()
    {
        parent::init();

        //set root working dir
        $this->workingDirPath = rtrim(\Yii::app()->assetManager->basePath, '/') . '/nls';
        $this->workingDirUrl = rtrim(\Yii::app()->assetManager->baseUrl, '/') . '/nls';
        if (!file_exists($this->workingDirPath)) {
            mkdir($this->workingDirPath);
        }

        //setup downloader
        $serverBase = \Yii::app()->getRequest()->getHostInfo();

        $this->downloader = new NLSDownloader(array('serverBaseUrl' => $serverBase, 'appBaseUrl' => $serverBase . \Yii::app()->getRequest()->getBaseUrl(), 'curlConnectionTimeOut' => $this->curlConnectionTimeOut, 'curlTimeOut' => $this->curlTimeOut));

        //setup css merger
        $this->cssMerger = new NLSCssMerge(array('downloadResources' => $this->downloadCssResources, 'downloadResourceRootPath' => $this->workingDirPath . '/resources', 'downloadResourceRootUrl' => $this->workingDirUrl . '/resources', 'minify' => $this->compressMergedCss, 'closeCurl' => false), $this->downloader);
    }

    protected function addAppVersion($url)
    {
        if (!empty($this->appVersion) && !NLSUtils::isAbsoluteUrl($url)) {
            $url = NLSUtils::addUrlParams($url, array('nlsver' => $this->appVersion));
        }

        return $url;
    }

    /**
     * Generates the file name of a resource.
     */
    protected function hashedName($name, $ext = 'js')
    {
        $r = 'nls' . crc32($name . $this->appVersion);
        if ($ext == 'css' && $this->downloadCssResources) {
            $r .= '.dcr';
        }
        if (($ext == 'js' && $this->compressMergedJs) || ($ext == 'css' && $this->compressMergedCss)) {
            $r .= '.min';
        }
        $r .= '.' . $ext;

        return $r;
    }

    /**
     * Simple string hash, same implemented also in the js part.
     */
    protected function h($s)
    {
        $h = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h = (($h << 5) - $h) + ord($s[$i]);
            $h &= 1073741823;
        }

        return $h;
    }

    protected function _mergeJs($pos)
    {
        $smap = null;

        if (\Yii::app()->request->isAjaxRequest) {
            //do not merge for ajax requests
            if (!$this->mergeIfXhr) {
                return;
            }

            if ($smap = @$_REQUEST['nlsc_map']) {
                $smap = @json_decode($smap);
            }
        }

        if ($this->mergeJs && !empty($this->scriptFiles[$pos]) && count($this->scriptFiles[$pos]) > $this->mergeAbove) {
            $finalScriptFiles = array();
            $name = "/** Content:\r\n";
            $scriptFiles = array();

            //from yii 1.1.14 $scriptFile can be an array
            foreach ($this->scriptFiles[$pos] as $src => $scriptFile) {
                $absUrl = $this->addAppVersion($this->downloader->toAbsUrl($src));

                if ($this->mergeJsExcludePattern && preg_match($this->mergeJsExcludePattern, $absUrl)) {
                    $finalScriptFiles[$src] = $scriptFile;
                    continue;
                }

                if ($this->mergeJsIncludePattern && !preg_match($this->mergeJsIncludePattern, $absUrl)) {
                    $finalScriptFiles[$src] = $scriptFile;
                    continue;
                }

                $h = $this->h($absUrl);
                if ($smap && in_array($h, $smap)) {
                    continue;
                }

                //storing hash
                $scriptFiles[$absUrl] = $h;

                $name .= $src . "\r\n";
            }

            if (count($scriptFiles) <= $this->mergeAbove) {
                return;
            }

            $name .= "*/\r\n";
            $hashedName = $this->hashedName($name, 'js');
            $path = $this->workingDirPath . '/' . $hashedName;
            $path = preg_replace('#\\?.*$#', '', $path);
            $url = $this->workingDirUrl . '/' . $hashedName;

            if ($this->forceMergeJs || !file_exists($path)) {
                $merged = '';

                foreach ($scriptFiles as $absUrl => $h) {
                    $ret = $this->downloader->get($absUrl);
                    $merged .= ($ret . ";\r\n");
                }

                $this->downloader->close();

                if ($this->compressMergedJs) {
                    $merged = Minifier::minify($merged);
                }

                file_put_contents($path, $name . $merged);
            }

            $finalScriptFiles[$url] = $url;
            $this->scriptFiles[$pos] = $finalScriptFiles;
        }
    }

    protected function _mergeCss()
    {
        if ($this->mergeCss && !empty($this->cssFiles)) {
            $newCssFiles = array();
            $names = array();
            $files = array();
            foreach ($this->cssFiles as $url => $media) {
                $absUrl = $this->addAppVersion($this->downloader->toAbsUrl($url));

                if ($this->mergeCssExcludePattern && preg_match($this->mergeCssExcludePattern, $absUrl)) {
                    $newCssFiles[$url] = $media;
                    continue;
                }

                if ($this->mergeCssIncludePattern && !preg_match($this->mergeCssIncludePattern, $absUrl)) {
                    $newCssFiles[$url] = $media;
                    continue;
                }

                if (!isset($names[$media])) {
                    $names[$media] = "/** Content:\r\n";
                }
                $names[$media] .= ($url . "\r\n");

                if (!isset($files[$media])) {
                    $files[$media] = array();
                }
                $files[$media][$absUrl] = $media;
            }

            //merging css files by "media"
            foreach ($names as $media => $name) {
                if (count($files[$media]) <= $this->mergeAbove) {
                    $newCssFiles = array_merge($newCssFiles, $files[$media]);
                    continue;
                }

                $name .= "*/\r\n";
                $hashedName = $this->hashedName($name, 'css');
                $path = $this->workingDirPath . '/' . $hashedName;
                $path = preg_replace('#\\?.*$#', '', $path);
                $url = $this->workingDirUrl . '/' . $hashedName;

                if ($this->forceMergeCss || !file_exists($path)) {
                    $merged = '';
                    foreach ($files[$media] as $absUrl => $media) {
                        $css = "/* $absUrl */\r\n" . $this->cssMerger->process($absUrl);

                        $merged .= ($css . "\r\n");
                    }

                    $this->downloader->close();

                    file_put_contents($path, $name . $merged);
                }
                 //if

                $newCssFiles[$url] = $media;
            }
             //media

            $this->cssFiles = $newCssFiles;
        }
    }

    //If someone needs to access these, can be useful
    public function getScriptFiles()
    {
        return $this->scriptFiles;
    }
    public function getCssFiles()
    {
        return $this->cssFiles;
    }

    public function renderHead(&$output)
    {
        //merging
        if ($this->mergeJs) {
            $this->_mergeJs(self::POS_HEAD);
        }
        if ($this->mergeCss) {
            $this->_mergeCss();
        }

        parent::renderHead($output);
    }

    public function renderBodyBegin(&$output)
    {

        //merging
        if ($this->mergeJs) {
            $this->_mergeJs(self::POS_BEGIN);
        }

        parent::renderBodyBegin($output);
    }

    public function renderBodyEnd(&$output)
    {

        //merging
        if ($this->mergeJs) {
            $this->_mergeJs(self::POS_END);
        }

        parent::renderBodyEnd($output);
    }

    public function registerScriptFile($url, $position = null, array $htmlOptions = array())
    {
        $url = $this->addAppVersion($url);

        return parent::registerScriptFile($url, $position, $htmlOptions);
    }

    public function registerCssFile($url, $media = '')
    {
        return parent::registerCssFile($this->addAppVersion($url), $media);
    }

    /**
     * {@inheritdoc}
     */
    public function registerCoreScript($name)
    {
        if (isset($this->coreScripts[$name])) {
            return $this;
        }

        if (isset($this->packages[$name])) {
            $package = $this->packages[$name];
        } else {
            if ($this->corePackages === null) {
                $this->corePackages = require YII_PATH . '/web/js/packages.php';
            }
            if (isset($this->corePackages[$name])) {
                $package = $this->corePackages[$name];
            }
        }

        if (isset($package)) {
            if (isset($this->separated[$name])) {
                return $this;
            }

            foreach ($this->separated as $cs) {
                if (isset($cs->corePackages[$name])) {
                    return $this;
                }
            }

            if (!empty($package['depends'])) {
                foreach ($package['depends'] as $p) {
                    $this->registerCoreScript($p);
                }
            }

            $this->coreScripts[$name] = $package;
            $this->hasScripts = true;
            $params = func_get_args();
            $this->recordCachingAction('clientScript', 'registerCoreScript', $params);

            if (isset($package['separate']) && $package['separate']) {
                $this->separated[$name] = clone $this;
                $this->reset();
            }
        } elseif (YII_DEBUG) {
            throw new CException('There is no CClientScript package: ' . $name);
        } else {
            Yii::log('There is no CClientScript package: ' . $name, CLogger::LEVEL_WARNING, 'system.web.CClientScript');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function render(&$output)
    {
        foreach ($this->separated as $cs) {
            $cs->render($output);
        }

        parent::render($output);
    }

    /**
     * Clone NLSClientScript.
     */
    public function __clone()
    {
        $this->separated = array();
    }
}
