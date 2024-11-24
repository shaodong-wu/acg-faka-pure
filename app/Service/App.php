<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Interface App
 * @package App\Service
 */
interface App
{
    /**
     * 应用商店地址
     */
    const APP_URL = BASE_APP_SERVER;
    const MAIN_SERVER = "https://store.acgshe.com";
    const STANDBY_SERVER1 = "https://standby.acgshe.com";
    const STANDBY_SERVER2 = "https://store.acgshop.net";
    const GENERAL_SERVER = "https://general.acgshe.com";

    /**
     * @param string $key
     * @param int $type
     */
    public function uninstallPlugin(string $key, int $type): void;
}