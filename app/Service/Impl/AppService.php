<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Service\App;
use App\Util\File;

/**
 * Class AppService
 * @package App\Service\Impl
 */
class AppService implements App
{
    /**
     * 卸载
     * @param string $key
     * @param int $type
     */
    public function uninstallPlugin(string $key, int $type): void
    {
        //默认位置，通用插件
        $pluginPath = BASE_PATH . "/app/Plugin/{$key}/";
        if ($type == 1) {
            //支付插件
            $pluginPath = BASE_PATH . "/app/Pay/{$key}/";
        } elseif ($type == 2) {
            //网站模板
            $pluginPath = BASE_PATH . "/app/View/User/Theme/{$key}/";
        }
        if (is_dir($pluginPath)) {
            //开始卸载
            File::delDirectory($pluginPath);
        }
    }
}