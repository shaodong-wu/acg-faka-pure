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
     * @param string $key
     * @param int $type
     */
    public function uninstallPlugin(string $key, int $type): void;
}