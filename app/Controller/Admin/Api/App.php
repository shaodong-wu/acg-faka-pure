<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class App extends Manage
{
    #[Inject]
    private \App\Service\App $app;

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function uninstall(): array
    {
        //卸载插件
        $pluginKey = (string)$_POST['plugin_key'];
        $type = (int)$_POST['type'];

        if ($type == 0) {
            \Kernel\Util\Plugin::runHookState($pluginKey, \Kernel\Annotation\Plugin::UNINSTALL);
        }

        $this->app->uninstallPlugin($pluginKey, $type);

        ManageLog::log($this->getManage(), "卸载了应用({$pluginKey})");
        return $this->json(200, "卸载完成");
    }
}