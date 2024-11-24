<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Util\Client;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class)]
class Store extends Manage
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->render("店铺共享", "Shared/Store.html");
    }
}