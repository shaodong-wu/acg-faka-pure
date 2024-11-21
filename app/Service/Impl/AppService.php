<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Service\App;
use App\Util\File;
use App\Util\Str;
use App\Util\Zip;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Kernel\Annotation\Inject;
use Kernel\Consts\Base;
use Kernel\Exception\JSONException;
use Kernel\Util\Context;
use Kernel\Util\SQL;

/**
 * Class AppService
 * @package App\Service\Impl
 */
class AppService implements App
{

    #[Inject]
    private Client $client;

    /**
     * @param string $uri
     * @param array $data
     * @param array|null $cookies
     * @return mixed
     * @throws JSONException
     */
    private function post(string $uri, array $data = [], ?array &$cookies = null): mixed
    {
        try {
            $form = [
                "form_params" => $data,
                "verify" => false
            ];
            if (is_array($cookies)) {
                $form["cookies"] = CookieJar::fromArray([
                    "GOLANG_ID" => $cookies['GOLANG_ID']
                ], parse_url(self::APP_URL)['host']);
            }
            $response = $this->client->post(self::APP_URL . $uri, $form);
            if ($cookies !== null) {
                $cookie = implode(";", (array)$response->getHeader("Set-Cookie"));
                $explode = explode(";", $cookie);
                $cookies = [];
                foreach ($explode as $item) {
                    $it = explode("=", $item);
                    $cookies[trim((string)$it[0])] = trim((string)$it[1]);
                }
            }
            $res = (array)json_decode((string)$response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new JSONException("应用商店请求错误");
        }
        if ($res['code'] != 200) {
            throw new JSONException($res['msg']);
        }

        return $res['data'];
    }

    /**
     * @param string $uri
     * @param array $data
     * @return mixed
     * @throws GuzzleException
     * @throws JSONException
     */
    private function storeRequest(string $uri, array $data = []): mixed
    {
        $store = config("store");
        $data['sign'] = Str::generateSignature($data, (string)$store["app_key"]);
        $response = $this->client->post(self::APP_URL . $uri, [
            "form_params" => $data,
            "headers" => ["appId" => (int)$store['app_id'], "appKey" => Context::get(Base::LOCK)],
            "verify" => false
        ]);
        $res = (array)json_decode((string)$response->getBody()->getContents(), true);

        if ($res['code'] != 200) {
            throw new JSONException($res['msg']);
        }
        return $res['data'];
    }

    /**
     * @param string $uri
     * @param array $data
     * @return array|null
     * @throws GuzzleException
     */
    private function storeDownload(string $uri, array $data = []): ?string
    {
        $store = config("store");
        $data['sign'] = Str::generateSignature($data, (string)$store["app_key"]);

        $path = BASE_PATH . "/kernel/Install/OS/";
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $fileName = md5((string)time()) . ".zip";
        $fileHandle = fopen($path . $fileName, "w+");
        $response = $this->client->post(self::APP_URL . $uri, [
            "form_params" => $data,
            "verify" => false,
            "headers" => ["appId" => (int)$store['app_id'], "appKey" => Context::get(Base::LOCK)],
            RequestOptions::SINK => $fileHandle
        ]);

        if ($response->getStatusCode() === 200) {
            return $fileName;
        }

        return null;
    }

    /**
     * @param string $key
     * @param int $type 插件类型
     * @param int $pluginId
     * @throws GuzzleException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function installPlugin(string $key, int $type, int $pluginId): void
    {
        //默认位置，通用插件
        $pluginPath = BASE_PATH . "/app/Plugin/{$key}/";
        $fileInit = file_exists($pluginPath . "/Config/Info.php");
        if ($type == 1) {
            //支付插件
            $pluginPath = BASE_PATH . "/app/Pay/{$key}/";
            $fileInit = file_exists($pluginPath . "/Config/Info.php");
        } elseif ($type == 2) {
            //网站模板
            $pluginPath = BASE_PATH . "/app/View/User/Theme/{$key}/";
            $fileInit = file_exists($pluginPath . "/Config.php");
        }

        if (!is_dir($pluginPath)) {
            mkdir($pluginPath, 0777, true);
        }

        if ($fileInit) {
            throw new JSONException("该插件已被安装，请勿重复安装");
        }

        $storeDownload = $this->storeDownload("/store/install", [
            "plugin_id" => $pluginId
        ]);
        if (!$storeDownload) {
            throw new JSONException("安装失败，请联系技术人员");
        }
        //下载完成，开始安装
        $src = BASE_PATH . "/kernel/Install/OS/{$storeDownload}";
        if (!Zip::unzip($src, $pluginPath)) {
            throw new JSONException("安装失败，请检查是否有写入权限");
        }
        //安装完成，删除src
        unlink($src);
        //判断目标目录是否有install.sqll
        $installSql = $pluginPath . "install.sql";
        if (file_exists($installSql)) {
            $database = config("database");
            SQL::import($installSql, $database['host'], $database['database'], $database['username'], $database['password'], $database['prefix']);
        }

        if ($type == 0) {
            //安装
            \Kernel\Util\Plugin::runHookState($key, \Kernel\Annotation\Plugin::INSTALL);
        }
    }

    /**
     * @param string $key
     * @param int $type
     * @param int $pluginId
     * @throws GuzzleException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function updatePlugin(string $key, int $type, int $pluginId): void
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
        if (!is_dir($pluginPath)) {
            throw new JSONException("该插件还未安装，请先安装插件后再进行更新");
        }
        $storeDownload = $this->storeDownload("/store/update", [
            "plugin_id" => $pluginId
        ]);
        if (!$storeDownload) {
            throw new JSONException("更新失败，请联系技术人员");
        }
        //下载完成，开始安装
        $src = BASE_PATH . "/kernel/Install/OS/{$storeDownload}";
        if (!Zip::unzip($src, $pluginPath)) {
            throw new JSONException("更新失败，请检查是否有写入权限");
        }
        //更新完成，删除src
        unlink($src);
        //判断目标目录是否有update.sql
        $updateSql = $pluginPath . "update.sql";
        if (file_exists($updateSql)) {
            $database = config("database");
            SQL::import($updateSql, $database['host'], $database['database'], $database['username'], $database['password'], $database['prefix']);
        }

        if ($type == 0) {
            \Kernel\Util\Plugin::runHookState($key, \Kernel\Annotation\Plugin::UPGRADE);
        }
    }

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

    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function purchaseRecords(int $pluginId): array
    {
        return $this->storeRequest("/store/records", [
            "plugin_id" => $pluginId
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function unbind(int $authId): array
    {
        return $this->storeRequest("/store/unbind", [
            "auth_id" => $authId
        ]);
    }

    /**
     * @throws JSONException
     */
    public function install(): void
    {
        $this->post("/open/project/install", ["key" => "faka"]);
    }

    /**
     * @param string $type
     * @return array
     * @throws JSONException
     */
    public function captcha(string $type): array
    {
        $cookie = [];
        $result = (array)$this->post("/auth/captcha", [
            "type" => $type
        ], $cookie);
        $result["cookie"] = $cookie;
        return $result;
    }

    /**
     * @param string $username
     * @param string $password
     * @param string $captcha
     * @param array $cookie
     * @return array
     * @throws JSONException
     */
    public function register(string $username, string $password, string $captcha, array $cookie): array
    {
        return (array)$this->post("/auth/register", [
            "captcha" => $captcha,
            "username" => $username,
            "password" => $password
        ], $cookie);
    }

    /**
     * @throws JSONException
     */
    public function login(string $username, string $password): array
    {
        return (array)$this->post("/auth/login", [
            "username" => $username,
            "password" => $password
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function plugins(array $data): array
    {
        return $this->storeRequest("/store/plugins", $data);
    }

    /**
     * @param array $data
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function developerPlugins(array $data): array
    {
        return $this->storeRequest("/developer/plugins", $data);
    }


    /**
     * @param array $data
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function developerCreatePlugin(array $data): array
    {
        return $this->storeRequest("/developer/create", $data);
    }

    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function purchase(int $type, int $pluginId, int $payType): array
    {
        return $this->storeRequest("/store/purchase", [
            "type" => $type,
            "payType" => $payType,
            "plugin_id" => $pluginId,
            "return" => \App\Util\Client::getUrl() . "/admin/store/home"
        ]);
    }

    /**
     * @param array $data
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function developerCreateKit(array $data): array
    {
        return $this->storeRequest("/developer/createKit", $data);
    }


    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function developerDeletePlugin(array $data): array
    {
        return $this->storeRequest("/developer/deletePlugin", $data);
    }

    /**
     * @param array $data
     * @return array
     * @throws JSONException
     */
    public function upload(array $data): array
    {
        try {
            $form = [
                "multipart" => $data,
                "verify" => false
            ];
            $response = $this->client->post(self::APP_URL . "/open/project/upload", $form);
            $res = (array)json_decode((string)$response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new JSONException("应用商店连接失败");
        }
        if ($res['code'] != 200) {
            throw new JSONException($res['msg']);
        }
        return $res['data'];
    }

    /**
     * @param array $data
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function developerUpdatePlugin(array $data): array
    {
        return $this->storeRequest("/developer/createUpdate", $data);
    }

    /**
     * @param array $data
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function developerPluginPriceSet(array $data): array
    {
        return $this->storeRequest("/developer/priceSet", $data);
    }

    /**
     * @param int $authId
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function bindLevel(int $authId): array
    {
        return $this->storeRequest("/store/bindLevel", ["auth_id" => $authId]);
    }


    /**
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function levels(): array
    {
        return $this->storeRequest("/store/levels");
    }
}