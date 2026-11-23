<?php
namespace addons\rainyun\controller;

use app\admin\controller\PluginAdminBaseController;
use think\Db;
class AdminIndexController extends PluginAdminBaseController
{
    # 配置
    private $_config = [];

    private $lang;

    public function initialize()
    {
        parent::initialize();
        $pluginConfig = $this->getPlugin()->getConfig();
        \addons\rainyun\RainyunPlugin::ensureSettingsTable($pluginConfig["description_template"] ?? "");
        $settings = Db::name("rainyun_settings")->where("id", 1)->find();
        $this->_config = [
            "description_template" => $this->normalizeDescriptionTemplate(
                $settings["description_template"] ?? \addons\rainyun\RainyunPlugin::DEFAULT_DESCRIPTION_TEMPLATE
            ),
            "auto_update_region_data" => (int)($settings["auto_update_region_data"] ?? 1),
        ];

        $lang = request()->languagesys;
        if (empty($lang)){
            $lang=configuration("language")?configuration("language"):config("default_lang");
        }
        if ($lang == 'CN'){
            $lang = 'chinese';
        }elseif ($lang == 'US'){
            $lang = 'english';
        }elseif ($lang == 'HK'){
            $lang = 'chinese_tw';
        }
        $this->lang = $lang;
    }

    private function normalizeDescriptionTemplate($template)
    {
        $template = (string)$template;
        if(strpos($template, "<") === false && stripos($template, "&lt;") !== false){
            $template = html_entity_decode($template, ENT_QUOTES | ENT_HTML5, "UTF-8");
        }
        return $template;
    }

    # 分区数据远程地址
    const REGION_DATA_URL = "https://raw.githubusercontent.com/runyutech/rain_to_mofang/main/rainyun/data/data.json";

    # 当前请求是否已经尝试更新过分区数据
    private $_regionDataUpdated = false;

    private function getRegionDataFile()
    {
        return WEB_ROOT."plugins/addons/rainyun/data/data.json";
    }

    /**
     * 下载最新的分区数据并覆盖本地 data/data.json
     */
    private function downloadRegionData($timeout = 60)
    {
        if(!function_exists("curl_init")){
            throw new \RuntimeException("服务器未启用 curl 扩展，无法下载分区数据");
        }
        $file = $this->getRegionDataFile();
        $dir = dirname($file);
        if(!is_dir($dir) || !is_writable($dir)){
            throw new \RuntimeException("data 目录不存在或不可写");
        }
        $tmp = $file.".".uniqid("", true).".tmp";
        $fp = @fopen($tmp, "wb");
        if(!$fp){
            throw new \RuntimeException("无法创建临时文件，请检查 data 目录权限");
        }
        $ch = curl_init(self::REGION_DATA_URL);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(5, (int)$timeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, "MoHua-Rainyun-Data");
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if(!$result || $status !== 200){
            @unlink($tmp);
            throw new \RuntimeException("下载分区数据失败".($error !== "" ? "：".$error : "（HTTP ".$status."）"));
        }
        $decoded = json_decode((string)@file_get_contents($tmp), true);
        if(!is_array($decoded)){
            @unlink($tmp);
            throw new \RuntimeException("下载的分区数据格式无效");
        }
        if(!@rename($tmp, $file)){
            @unlink($tmp);
            throw new \RuntimeException("写入 data/data.json 失败，请检查目录权限");
        }
        @chmod($file, 0644);
        clearstatcache(true, $file);
        $this->_regionDataUpdated = true;
        return $decoded;
    }

    /**
     * 读取本地分区数据；开启自动更新时先下载最新数据（下载失败则继续使用本地数据）
     */
    private function getRegionListData($forceUpdate = false)
    {
        $autoUpdate = (int)($this->_config["auto_update_region_data"] ?? 1) === 1;
        if(($forceUpdate || $autoUpdate) && !$this->_regionDataUpdated){
            try{
                // 自动更新失败不阻塞拉取，继续使用本地文件
                $this->downloadRegionData($forceUpdate ? 60 : 15);
            }catch(\Exception $e){
                if($forceUpdate) throw $e;
            }
        }
        $file = $this->getRegionDataFile();
        if(!is_file($file)){
            throw new \RuntimeException("本地分区数据 data/data.json 不存在");
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if(!is_array($data)){
            throw new \RuntimeException("本地分区数据 data/data.json 格式无效");
        }
        return $data;
    }

    private function getApiRegionName($region, $line, array $regionList)
    {
        $region = (string)$region;
        $line = (string)$line;
        $names = $regionList["name"][$region] ?? [];
        if(is_array($names)){
            if($line !== "" && isset($names[$line]) && is_scalar($names[$line]) && trim((string)$names[$line]) !== "") return trim((string)$names[$line]);
            if(isset($names[""]) && is_scalar($names[""]) && trim((string)$names[""]) !== "") return trim((string)$names[""]);
        }elseif(is_string($names) && trim($names) !== ""){
            return trim($names);
        }
        return "";
    }

    private function getSubmittedRegionNames()
    {
        $post = request()->post(false);
        $submitted = is_array($post) ? ($post["region_names"] ?? []) : [];
        if(is_string($submitted)){
            $submitted = json_decode($submitted, true);
        }
        if(!is_array($submitted)) return [];
        return array_values(array_filter($submitted, function($item){
            return is_array($item)
                && isset($item["region"], $item["line"], $item["name"])
                && is_scalar($item["region"])
                && is_scalar($item["line"])
                && is_scalar($item["name"])
                && trim((string)$item["name"]) !== ""
                && strlen(trim((string)$item["name"])) <= 300;
        }));
    }

    private function getSubmittedRegionName($region, $line, array $submitted)
    {
        foreach ($submitted as $item){
            if((string)$item["region"] === (string)$region && (string)$item["line"] === (string)$line){
                return trim((string)$item["name"]);
            }
        }
        return "";
    }

    private function getRegionDisplayName($region, $line, array $regionList, array $submitted = [])
    {
        $apiName = $this->getApiRegionName($region, $line, $regionList);
        return $apiName !== "" ? $apiName : $this->getSubmittedRegionName($region, $line, $submitted);
    }

    private function getRegionIpTypeLabel($region, $ipType, array $regionList)
    {
        $ipTypes = $regionList["ip_type"] ?? [];
        if(is_array($ipTypes)){
            if(isset($ipTypes[$region]) && is_array($ipTypes[$region])){
                if(array_key_exists((string)$ipType, $ipTypes[$region])){
                    return (string)$ipTypes[$region][$ipType];
                }
            }
            if(array_key_exists((string)$ipType, $ipTypes) && is_string($ipTypes[$ipType])){
                return (string)$ipTypes[$ipType];
            }
        }
        if($ipType === "" || $ipType === null) return "普通IP";
        return (string)$ipType;
    }

    private function getDefaultRegionIpType($region, $line, array $ipPrices, array $regionList)
    {
        $region = (string)$region;
        $line = (string)$line;
        $ipTypes = $regionList["ip_type"] ?? [];
        $regionIpTypes = is_array($ipTypes) && isset($ipTypes[$region]) && is_array($ipTypes[$region])
            ? $ipTypes[$region]
            : [];

        if($regionIpTypes){
            if($line !== ""){
                foreach ($regionIpTypes as $key => $label){
                    if((string)$key === "") continue;
                    if(stripos((string)$key, $line) !== false || stripos((string)$line, (string)$key) !== false){
                        if(array_key_exists((string)$key, $ipPrices)) return (string)$key;
                    }
                }
            }
        }

        $legacyMap = [
            "cn-gz1" => "gz_aliyunip",
            "cn-sy1" => $line === "bgp" ? "sy_bgpip" : "",
            "cn-nb1" => $line === "bgp" ? "nb_bgpip" : "",
            "cn-sz1" => $line === "bgp" ? "sz_txip" : "",
        ];
        $candidate = $legacyMap[$region] ?? "";
        if($candidate !== "" && array_key_exists($candidate, $ipPrices)) return $candidate;

        if(array_key_exists("", $ipPrices)) return "";

        if($regionIpTypes){
            foreach ($regionIpTypes as $key => $label){
                if(array_key_exists((string)$key, $ipPrices)){
                    $key = (string)$key;
                    if($key === "" || $key === "ipv6") continue;
                    return $key;
                }
            }
        }

        foreach (array_keys($ipPrices) as $key){
            return (string)$key;
        }
        return "";
    }

    private function updateUnknownRegionGroupName($groupId, $region, $line, array $regionList, array $submitted)
    {
        if($this->getApiRegionName($region, $line, $regionList) !== "") return true;
        $name = $this->getSubmittedRegionName($region, $line, $submitted);
        if($name === "" || (int)$groupId <= 0) return false;
        $group = Db::name("product_groups")->where("id", (int)$groupId)->find();
        if(!$group) return false;
        if((string)$group["name"] === $name) return true;
        return Db::name("product_groups")
            ->where("id", (int)$groupId)
            ->update(["name" => $name, "update_time" => time()]) !== false;
    }

    private function getUnknownPlanRegions(array $ids, array $plans, array $regionList, array $submitted = [])
    {
        $unknown = [];
        foreach ($ids as $id){
            $plan = $plans[(string)$id] ?? null;
            if(!is_array($plan)) continue;
            $region = (string)($plan["region"] ?? "");
            $line = (string)($plan["line"] ?? "");
            if($this->getApiRegionName($region, $line, $regionList) !== "") continue;
            if($this->getSubmittedRegionName($region, $line, $submitted) !== "") continue;
            $key = hash("sha256", $region."\0".$line);
            $unknown[$key] = [
                "key" => $key,
                "region" => $region,
                "line" => $line,
                "label" => trim($region.($line !== "" ? " / ".$line : "")),
            ];
        }
        return array_values($unknown);
    }

    private function hasValidSelectedIds($ids)
    {
        if(!is_array($ids) || count($ids) === 0) return false;
        foreach ($ids as $id){
            if(!is_scalar($id) || trim((string)$id) === "") return false;
        }
        return true;
    }

    private function findNavigationItem(array $nodes, $navItemId)
    {
        foreach ($nodes as $node){
            if(!is_array($node)) continue;
            if((string)($node["id"] ?? "") === (string)$navItemId && (int)($node["nav_type"] ?? 0) === 2){
                return true;
            }
            if(isset($node["son"]) && is_array($node["son"]) && $this->findNavigationItem($node["son"], $navItemId)){
                return true;
            }
        }
        return false;
    }

    private function findProductServicesNavigationId(array $nodes)
    {
        foreach ($nodes as $node){
            if(!is_array($node)) continue;
            if((int)($node["nav_type"] ?? -1) === 0
                && ($node["nav_type_name"] ?? "") === "系统页面"
                && ($node["name"] ?? "") === "产品与服务"){
                return (int)($node["id"] ?? 0);
            }
            if(isset($node["son"]) && is_array($node["son"])){
                $id = $this->findProductServicesNavigationId($node["son"]);
                if($id > 0) return $id;
            }
        }
        return 0;
    }

    private function getRequestedNavigationSelection(array $ids, array $products, $productName)
    {
        $requiresNavigation = false;
        foreach ($ids as $id){
            if(!isset($products[(string)$id])){
                $requiresNavigation = true;
                break;
            }
        }
        if(!$requiresNavigation) return ["menu_id" => 0, "nav_item_id" => 0];

        $menuId = input("post.nav_menu_id", "");
        $navItemId = input("post.nav_item_id", "");
        if(!is_scalar($menuId) || ($menuId !== "" && !ctype_digit((string)$menuId))) return false;
        if(!is_scalar($navItemId)) return false;
        $menuId = $menuId === "" ? 0 : (int)$menuId;
        if($menuId <= 0 || trim((string)$navItemId) === "") return false;
        $menu = Db::name("menus")->where("id", $menuId)->where("type", 1)->find();
        if(!$menu) return false;
        $navList = json_decode((string)($menu["nav_list"] ?? ""), true);
        if(!is_array($navList)) return false;
        $navItemId = (string)$navItemId;
        if(ctype_digit($navItemId)){
            $navItemId = (int)$navItemId;
            if($navItemId <= 0 || !$this->findNavigationItem($navList, $navItemId)) return false;
            return ["menu_id" => $menuId, "nav_item_id" => $navItemId, "create_mode" => ""];
        }
        if(!in_array($navItemId, ["auto_service", "auto_root"], true)) return false;
        $servicesId = $this->findProductServicesNavigationId($navList);
        if(($navItemId === "auto_service" && $servicesId <= 0)
            || ($navItemId === "auto_root" && $servicesId > 0)){
            return false;
        }
        return [
            "menu_id" => $menuId,
            "nav_item_id" => 0,
            "create_mode" => $navItemId,
            "product_name" => (string)$productName,
            "services_id" => $servicesId,
        ];
    }

    private function compressProductDescriptionToSingleLine($description)
    {
        $description = (string)$description;
        $description = preg_replace('/\s*(<[^>]+>|&lt;.*?&gt;)\s*/is', '$1', $description);
        return trim($description);
    }

    private function renderProductDescription($defaultDescription, array $plan, $regionName = "", array $extra = [])
    {
        $template = trim((string)($this->_config["description_template"] ?? ""));
        if($template === "") return $defaultDescription;

        $trafficBase = $plan["traffic_base_gb"] ?? 0;
        $trafficPrices = [];
        $trafficPriceData = $plan["traffic_price"] ?? [];
        if(is_array($trafficPriceData)){
            foreach ($trafficPriceData as $amount => $price){
                $trafficPrices[] = $amount." GB：".$price." 元";
            }
        }
        $gpuMemory = isset($plan["gpu_memory_size"]) && (float)$plan["gpu_memory_size"] > 0
            ? round((float)$plan["gpu_memory_size"] / 1024, 2)
            : "";
        $variables = array_merge([
            "region" => $regionName,
            "line" => $plan["line"] ?? "",
            "plan" => $plan["chinese"] ?? ($plan["name"] ?? ""),
            "cpu" => $plan["cpu"] ?? "",
            "memory" => $plan["memory"] ?? "",
            "memory_unit" => "GB",
            "gpu_memory" => $gpuMemory,
            "gpu_memory_unit" => "GB",
            "net_in" => $plan["net_in"] ?? "",
            "net_out" => $plan["net_out"] ?? "",
            "storage" => $plan["storage"] ?? "",
            "traffic_base_gb" => $trafficBase,
            "traffic_price" => implode("，", $trafficPrices),
            "traffic_type" => (float)$trafficBase > 0 ? "流量叠加型" : "流量不限型",
            "machine" => $plan["machine"] ?? "",
            "machine_name" => "",
            "cpu_name" => "",
            "memory_type" => "",
            "machine_info" => "",
            "disk_info" => "",
        ], $extra);
        $template = preg_replace_callback('/\{\{#if\s+([a-zA-Z0-9_]+)\s*\}\}(.*?)\{\{\/if\}\}/s', function($matches) use ($variables){
            return !empty($variables[$matches[1]] ?? null) ? $matches[2] : "";
        }, $template);
        $replacements = [];
        foreach ($variables as $key => $value){
            $replacements["{{".$key."}}"] = in_array($key, ["machine_info", "disk_info"], true)
                ? (string)$value
                : htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        }
        return strtr($template, $replacements);
    }

    private function appendProductToNavigationItem(array &$nodes, $navItemId, $productId)
    {
        foreach ($nodes as &$node){
            if(!is_array($node)) continue;
            if((string)($node["id"] ?? "") === (string)$navItemId && (int)($node["nav_type"] ?? 0) === 2){
                $relatedIds = array_values(array_filter(array_map("intval", explode(",", (string)($node["relid"] ?? "")))));
                if(!in_array((int)$productId, $relatedIds, true)){
                    $relatedIds[] = (int)$productId;
                }
                $node["relid"] = ",".implode(",", $relatedIds);
                unset($node);
                return true;
            }
            if(isset($node["son"]) && is_array($node["son"]) && $this->appendProductToNavigationItem($node["son"], $navItemId, $productId)){
                unset($node);
                return true;
            }
        }
        unset($node);
        return false;
    }

    private function appendProductToNavigationName(array &$nodes, $name, $productId, &$navItemId)
    {
        foreach ($nodes as &$node){
            if(!is_array($node)) continue;
            if((int)($node["nav_type"] ?? 0) === 2 && (string)($node["name"] ?? "") === (string)$name){
                $relatedIds = array_values(array_filter(array_map("intval", explode(",", (string)($node["relid"] ?? "")))));
                if(!in_array((int)$productId, $relatedIds, true)) $relatedIds[] = (int)$productId;
                $node["relid"] = ",".implode(",", $relatedIds);
                $navItemId = (int)($node["id"] ?? 0);
                unset($node);
                return true;
            }
            if(isset($node["son"]) && is_array($node["son"]) && $this->appendProductToNavigationName($node["son"], $name, $productId, $navItemId)){
                unset($node);
                return true;
            }
        }
        unset($node);
        return false;
    }

    private function addProductToNavigation(array $selection, $productId, $groupName, $groupLang, $siteUrl)
    {
        if(isset($selection["product_name"]) && (string)$selection["product_name"] !== (string)$groupName) return false;
        $menuId = (int)$selection["menu_id"];
        $navItemId = (int)($selection["nav_item_id"] ?? 0);
        $menu = Db::name("menus")->where("id", $menuId)->where("type", 1)->find();
        if(!$menu) return false;
        $navList = json_decode((string)($menu["nav_list"] ?? ""), true);
        if(!is_array($navList)) return false;

        if($navItemId > 0){
            if(!$this->appendProductToNavigationItem($navList, $navItemId, $productId)) return false;
        }else{
            $createMode = (string)($selection["create_mode"] ?? "");
            if(!in_array($createMode, ["auto_service", "auto_root"], true)) return false;
            if($this->appendProductToNavigationName($navList, $groupName, $productId, $navItemId)){
                return Db::name("menus")->where("id", $menuId)->update(["nav_list" => json_encode($navList)]) !== false;
            }
            $parentId = $createMode === "auto_service" ? (int)($selection["services_id"] ?? 0) : 0;
            if($createMode === "auto_service" && $parentId <= 0) return false;
            $navId = Db::name("nav")->insertGetId([
                "name" => $groupName,
                "url" => $siteUrl,
                "pid" => $parentId,
                "nav_type" => "2",
                "menuid" => "1",
                "lang" => $groupLang,
                "menu_type" => "1",
            ]);
            if(!$navId) return false;
            $serviceUrl = "service?groupid=".$navId;
            if(Db::name("nav")->where("id", $navId)->update(["url" => $serviceUrl]) === false) return false;
            $node = [
                "url" => $serviceUrl,
                "name" => $groupName,
                "pid" => $parentId,
                "order" => "0",
                "fa_icon" => "",
                "nav_type" => "2",
                "relid" => ",".(int)$productId,
                "menuid" => "0",
                "lang" => $groupLang,
                "menu_type" => "1",
                "id" => (int)$navId,
                "muti" => 1,
                "senior" => "1",
                "templatePage" => "service_cloud",
                "orderFuc" => "0",
                "is_custom" => "0",
                "orderFucUrl" => rtrim($siteUrl, "/")."/cart",
                "is_display" => "0",
                "other_name" => "",
                "nav_type_name" => "产品管理",
            ];
            if($parentId > 0){
                if(!$this->appendNavigationChild($navList, $parentId, $node)) return false;
            }else{
                $navList[] = $node;
            }
        }
        return Db::name("menus")
            ->where("id", $menuId)
            ->update(["nav_list" => json_encode($navList)]) !== false;
    }

    private function appendNavigationChild(array &$nodes, $parentId, array $child)
    {
        foreach ($nodes as &$node){
            if(!is_array($node)) continue;
            if((string)($node["id"] ?? "") === (string)$parentId){
                if(!isset($node["son"]) || !is_array($node["son"])) $node["son"] = [];
                $node["son"][] = $child;
                unset($node);
                return true;
            }
            if(isset($node["son"]) && is_array($node["son"]) && $this->appendNavigationChild($node["son"], $parentId, $child)){
                unset($node);
                return true;
            }
        }
        unset($node);
        return false;
    }

    public function rbmlist()
    {
        $this->assign('Title','裸金屬物理機');
        return $this->fetch('/rbmlist');
    }
    
    public function rbmoperateapi()
    {
        $navigation_warning = false;
        $servers_data = \think\Db::name('servers')  
	                ->where('type', 'rainyunrbm')  
	                ->field('hostname, password, gid')  
	                ->select();
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rbm/models",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(0)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $planres = $res["data"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rbm/discount-percent?type=create",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(1)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $default = $res["data"]["default"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rbm/os-templates",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(2)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $os_list = $res["data"];
        $productsData = \think\Db::name('products')  
            ->where('server_group', $servers_data[0]["gid"])  
            ->select()  
            ->toArray();
        $products = [];
        foreach ($productsData as $item){
            $products[$item["config_option2"]] = $item;
        }
        $plan = [];
        foreach ($planres as $item){
            if($item["is_selling"]){
                
            }
            $plan[$item["id"]] = $item;
        }
        try{
            $region_list = $this->getRegionListData();
        }catch(\Exception $e){
            return json(["code"=>500,"msg"=>"读取分区数据失败：".$e->getMessage()], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $IdList = input("post.id");
        if(!$this->hasValidSelectedIds($IdList)) return json(["code"=>400,"msg"=>"请选择有效的同步套餐"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $region_names = $this->getSubmittedRegionNames();
        $unknown_regions = $this->getUnknownPlanRegions($IdList, $plan, $region_list, $region_names);
        if(count($unknown_regions) > 0) return json(["code"=>400,"msg"=>"请先填写未识别分区名称","data"=>["unknown_regions"=>$unknown_regions]], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $navigation_selection = $this->getRequestedNavigationSelection($IdList, $products, "裸金属物理机");
        if($navigation_selection === false) return json(["code"=>400,"msg"=>"新商品必须选择有效的会员中心导航选项"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        foreach ($IdList as $item){
            if(isset($plan[$item])){
                $this_region = $plan[$item]["region"];
                if(isset($products[$item])){
                    if(!$this->updateUnknownRegionGroupName($products[$item]["gid"], $this_region, $plan[$item]["line"], $region_list, $region_names)){
                        return json(["code"=>500,"msg"=>"更新未知分区商品分组名称失败"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                    }
                    $trafficit = "";
                    $trafficib = "";
                    if($plan[$item]["traffic_base_gb"] != 0){
                        $istraffic = " 流量型";
                        $trafficit = '<div class="mb-50"><svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-info feather feather-cloud-rain"><line x1="16" y1="13" x2="16" y2="21"></line><line x1="8" y1="13" x2="8" y2="21"></line><line x1="12" y1="15" x2="12" y2="23"></line><path d="M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"></path></svg> 流量 </div>';
                        $traffic_plan = [];
                        foreach ($plan[$item]["traffic_price"] as $key=>$value){
                            $traffic_plan[] = "[".$key."G ".$value."元"."]";
                        }
                        $trafficib = '<div class="mb-50"><b class="text-dark">'.$plan[$item]["traffic_base_gb"].'</b> GB <i data-container="body" data-toggle="popover" data-placement="top" data-content="上下行公网共同流量，每月自动叠加，次月不清零，可自定义限流，流量用完后限至5M，额外流量可按'.implode(',', $traffic_plan).'充值。"><i class="bx bx-help-circle"></i></i></div>';
                    }
                    if($plan[$item]["machine"] === "1270v5"){
                        $cpu_name = $region_list["RbmCpuInfo"][$plan[$item]["machine"]]["name"];
                        $memory_type = $region_list["RbmCpuInfo"][$plan[$item]["machine"]]["memory_type"];
                    }else{
                        $cpu_name = $region_list["RbmCpuInfo"][$plan[$item]["plan_name"]]["name"];
                        $memory_type = $region_list["RbmCpuInfo"][$plan[$item]["plan_name"]]["memory_type"];
                    }
                    $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                    $description_html = $this->renderProductDescription($description_html, $plan[$item], $region_name, [
                        "memory_unit" => "GB",
                        "machine_name" => $region_list["Rbm_machine"][$plan[$item]["machine"]] ?? $plan[$item]["machine"],
                        "cpu_name" => $cpu_name,
                        "memory_type" => $memory_type,
                    ]);
                    $data = [
                        "name"=>$region_name." ".$plan[$item]["chinese"]." ".($region_list["Rbm_machine"][$plan[$item]["machine"]]?:$plan[$item]["machine"]).$istraffic,
                        "description"=>$this->compressProductDescriptionToSingleLine($description_html),
                        "qty"=>$plan[$item]["available_stock"],
                        "type"=>"bareMetal",
                        "config_option1"=>"",
                        "config_option2"=>$plan[$item]["id"],
                        "config_option3"=>"",
                        "config_option4"=>"",
                        "config_option5"=>"",
                        "config_option6"=>"",
                        "config_option7"=>"",
                        "config_option8"=>"",
                        "config_option9"=>"",
                        "config_option10"=>"",
                        "config_option11"=>"",
                        "config_option12"=>"",
                        "config_option13"=>"",
                        "config_option14"=>"",
                        "update_time"=>time(),
                        "is_truename"=>$region_list["is_truename"][$this_region]?:true,
                    ];
                    $result = Db::name('products')
                        ->where('id', $products[$item]["id"])
                        ->update($data);
                    $ontrial_pricing = 1;
                    if($plan[$item]["cpu"] <= 2){
                        $ontrial_pricing = 1;
                    }else if($plan[$item]["cpu"] <= 4){
                        $ontrial_pricing = 8;
                    }else if($plan[$item]["cpu"] <= 8){
                        $ontrial_pricing = 14;
                    }else{
                        $ontrial_pricing = 28;
                    }
                    $data = [
                        "ontrial"=>$ontrial_pricing,
                        "monthly"=>$plan[$item]["price"]*($default["1"]?:1),//月付
                        "quarterly"=>$plan[$item]["price"]*($default["3"]?:1)*3,//季付
                        "semiannually"=>$plan[$item]["price"]*($default["6"]?:1)*6,//半年付
                        "annually"=>$plan[$item]["price"]*($default["12"]?:1)*12,//年付
                    ];
                    $result = Db::name('pricing')
                        ->where('type', "product")
                        ->where('relid', $products[$item]["id"])
                        ->update($data);
                    $getpcgidres = Db::name('product_config_links')
                        ->where('pid', $products[$item]["id"])
                        ->find();
                    $result = Db::name('product_config_options')
                        ->where('gid', $getpcgidres["gid"])
                        ->select()
                        ->toArray();
                    foreach ($result as $ite){
                        $optionParts = explode("|", $ite["option_name"]);
                        if(!empty($optionParts) && $optionParts[0] === "with_eip_num"){
                            $getpcosid = Db::name('product_config_options_sub')
                                ->where('config_id', $ite["id"])
                                ->select()
                                ->toArray();
                            foreach ($getpcosid as $it){
                                if($it["qty_minimum"] > 1){
                                    $data = [
                                        "monthly"=>$plan[$item]["per_ip_price"]*($default["1"]?:1),//月付
                                        "quarterly"=>$plan[$item]["per_ip_price"]*($default["3"]?:1)*3,//季付
                                        "semiannually"=>$plan[$item]["per_ip_price"]*($default["6"]?:1)*6,//半年付
                                        "annually"=>$plan[$item]["per_ip_price"]*($default["12"]?:1)*12,//年付
                                    ];
                                }else{
                                    $data = [
                                        "monthly"=>0,//月付
                                        "quarterly"=>0,//季付
                                        "semiannually"=>0,//半年付
                                        "annually"=>0,//年付
                                    ];
                                }
                                $result = Db::name('pricing')
                                    ->where('type', "configoptions")
                                    ->where('relid', $it["id"])
                                    ->update($data);
                            }
                            break;
                        }
                    }
                    $data = ["code"=>200,"msg"=>"操作完成"];
                }else{
                    $samegid = [];
                    foreach ($plan as $key=>$value){
                        if($value['region'] == $this_region && $value['line'] == $plan[$item]["line"]){
                            $samepid = $products[$key]["id"];
                            $gid = $products[$key]["gid"];
                            break;
                        }
                    }
                    if(empty($gid)){
                        //选定一级组
                        foreach ($products as $it){
                            $result = Db::name('product_groups')
                                ->where('id', $it["gid"])
                                ->find();
                            $firstgid = $result['gid'];
                            break;
                        }
                        if(empty($firstgid)){
                            $result = Db::name('product_groups')
                                ->where('name', "裸金属物理机")
                                ->find();
                            if($result){
                                $firstgid = $result["id"];
                            }else{
                                $product_first_groups = [
                                        "name"=>"裸金属物理机",
                                        "hidden"=>0,
                                        "create_time"=>time(),
                                        "update_time"=>time(),
                                        "is_upstream"=>0,
                                        "zjmf_api_id"=>0,
                                    ];
                                $firstgid = Db::name('product_first_groups')->insertGetId($product_first_groups);
                                if(!$firstgid){
                                    return json(["code"=>500,"msg"=>"插入数据库失败"], 500, ['Content-Type' => 'application/json; charset=UTF-8']);
                                }
                            }
                        } 
                        $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                        $product_groups = [
                                "name"=>$region_name,
                                "type"=>1,
                                "create_time"=>time(),
                                "update_time"=>time(),
                                "gid"=>$firstgid,
                                "tpl_type"=>"default",
                            ];
                        $gid = Db::name('product_groups')->insertGetId($product_groups);
                    }else{
                        $result = Db::name('product_groups')
                            ->where('id', $gid)
                            ->find();
                        $firstgid = $result['gid'];
                    }
                    if(!$this->updateUnknownRegionGroupName($gid, $this_region, $plan[$item]["line"], $region_list, $region_names)){
                        return json(["code"=>500,"msg"=>"更新未知分区商品分组名称失败"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                    }
                    $trafficit = "";
                    $trafficib = "";
                    if($plan[$item]["traffic_base_gb"] != 0){
                        $istraffic = " 流量型";
                        $trafficit = '<div class="mb-50"><svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-info feather feather-cloud-rain"><line x1="16" y1="13" x2="16" y2="21"></line><line x1="8" y1="13" x2="8" y2="21"></line><line x1="12" y1="15" x2="12" y2="23"></line><path d="M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"></path></svg> 流量 </div>';
                        $traffic_plan = [];
                        foreach ($plan[$item]["traffic_price"] as $key=>$value){
                            $traffic_plan[] = "[".$key."G ".$value."元"."]";
                        }
                        $trafficib = '<div class="mb-50"><b class="text-dark">'.$plan[$item]["traffic_base_gb"].'</b> GB <i data-container="body" data-toggle="popover" data-placement="top" data-content="上下行公网共同流量，每月自动叠加，次月不清零，可自定义限流，流量用完后限至5M，额外流量可按'.implode(',', $traffic_plan).'充值。"><i class="bx bx-help-circle"></i></i></div>';
                    }
                    if($plan[$item]["machine"] === "1270v5"){
                        $cpu_name = $region_list["RbmCpuInfo"][$plan[$item]["machine"]]["name"];
                        $memory_type = $region_list["RbmCpuInfo"][$plan[$item]["machine"]]["memory_type"];
                    }else{
                        $cpu_name = $region_list["RbmCpuInfo"][$plan[$item]["plan_name"]]["name"];
                        $memory_type = $region_list["RbmCpuInfo"][$plan[$item]["plan_name"]]["memory_type"];
                    }
                    $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                    $description_html = $this->renderProductDescription($description_html, $plan[$item], $region_name, [
                        "memory_unit" => "GB",
                        "machine_name" => $region_list["Rbm_machine"][$plan[$item]["machine"]] ?? $plan[$item]["machine"],
                        "cpu_name" => $cpu_name,
                        "memory_type" => $memory_type,
                    ]);
                    $new_products = [
                            "type"=>"bareMetal",
                            "gid"=>$gid,
                            "name"=>$region_name." ".$plan[$item]["chinese"]." ".($region_list["Rbm_machine"][$plan[$item]["machine"]]?:$plan[$item]["machine"]).$istraffic,
                            "description"=>$this->compressProductDescriptionToSingleLine($description_html),
                            "host"=>'{"show":"1","modify":0,"prefix":"mcs","rule":{"upper":"1","lower":"1","num":"1","len_num":"10"}}',
                            "is_domain"=>0,
                            "hidden"=>0,
                            "password"=>'{"show":"0","modify":0,"rule":{"len_num":"20","upper":"1","lower":"1","num":"1","special":"1"}}',
                            "show_domain_options"=>0,
                            "welcome_email"=>0,
                            "stock_control"=>1,
                            "qty"=>$plan[$item]["available_stock"],
                            "prorata_billing"=>0,
                            "prorata_date"=>0,
                            "prorata_charge_next_month"=>0,
                            "pay_type"=>'{"pay_type":"recurring","pay_hour_cycle":"720","pay_day_cycle":"30","pay_ontrial_status":1,"pay_ontrial_cycle":"1","pay_ontrial_num":"999","pay_ontrial_condition":["email","phone","realname"],"pay_ontrial_cycle_type":"day","pay_ontrial_num_rule":"1","clientscount_rule":"0"}',
                            "pay_method"=>'prepayment',
                            "allow_qty"=>1,
                            "server_type"=>"",
                            "auto_setup"=>"payment",
                            "server_group"=>$servers_data[0]["gid"],
                            "config_option1"=>"",
                            "config_option2"=>$plan[$item]["id"],
                            "config_option3"=>"",
                            "config_option4"=>"",
                            "config_option5"=>"",
                            "config_option6"=>"",
                            "config_option7"=>"",
                            "config_option8"=>"",
                            "config_option9"=>"",
                            "config_option10"=>"",
                            "config_option11"=>"",
                            "config_option12"=>"",
                            "config_option13"=>"",
                            "config_option14"=>"",
                            "config_option15"=>"",
                            "config_option16"=>"",
                            "config_option17"=>"",
                            "config_option18"=>"",
                            "config_option19"=>"",
                            "config_option20"=>"",
                            "config_option21"=>"",
                            "config_option22"=>"",
                            "config_option23"=>"",
                            "config_option24"=>"",
                            "billing_cycle_upgrade"=>"",
                            "overages_enabled"=>"",
                            "affiliate_pay_type"=>"default",
                            "create_time"=>time(),
                            "update_time"=>time(),
                            "auto_create_config_options"=>0,
                            "groupid"=>3,
                            "api_type"=>"normal",
                            "app_type"=>"",
                            "location_version"=>0,
                            "upstream_version"=>0,
                            "is_truename"=>$region_list["is_truename"][$this_region]?:true,
                            "product_shopping_url"=>"",
                            "product_group_url"=>"",
                            "cancel_control"=>1,
                            "order"=>100,
                            "upstream_auto_setup"=>"",
                            "upstream_cycle"=>"",
                        ];
                    $pid = Db::name('products')->insertGetId($new_products);
                    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) || (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https');
                    $protocol = $isHttps ? 'https://' : 'http://';
                    $url = $protocol . $_SERVER['HTTP_HOST'];
                    $data = [
                        "product_shopping_url"=>$url."/cart?action=configureproduct&amp;pid=".$pid,
                        "product_group_url"=>$url."/cart?gid=".$gid."&amp;fid=".$firstgid,
                    ];
                    $products[$plan[$item]["id"]] = array_merge($new_products,$data,["id"=>$pid]);
                    $result = Db::name('products')
                        ->where('id', $pid)
                        ->update($data);
                    $ontrial_pricing = 28;
                    $new_pricing = ["type"=>"product","currency"=>1,"relid"=>$pid,
                        "ontrial"=>$ontrial_pricing,//试用
                        "monthly"=>$plan[$item]["price"]*($default["1"]?:1),//月付
                        "quarterly"=>$plan[$item]["price"]*($default["3"]?:1)*3,//季付
                        "semiannually"=>$plan[$item]["price"]*($default["6"]?:1)*6,//半年付
                        "annually"=>$plan[$item]["price"]*($default["12"]?:1)*12,//年付
                        "onetime"=>-1.00,"hour"=>-1.00,"day"=>-1.00,"biennially"=>-1.00,"triennially"=>-1.00,"fourly"=>-1.00,"fively"=>-1.00,"sixly"=>-1.00,"sevenly"=>-1.00,"eightly"=>-1.00,"ninely"=>-1.00,"tenly"=>-1.00,
                        
                    ];
                    Db::name('pricing')->insert($new_pricing);
                    $new_product_config_groups = [
                        "name"=>$new_products["name"],
                        "description"=>$new_products["name"],
                    ];
                    $product_config_groups_id = Db::name('product_config_groups')->insertGetId($new_product_config_groups);
                    $new_product_config_links = [
                        "gid"=>$product_config_groups_id,
                        "pid"=>$pid,
                    ];
                    $product_config_links_id = Db::name('product_config_links')->insertGetId($new_product_config_links);
                    $new_eip_product_config_options = [
                        "gid"=>$product_config_groups_id,
                        "option_name"=>"with_eip_num|IP数量",
                        "qty_minimum"=>1,
                        "qty_maximum"=>30,
                        "option_type"=>4,
                        "upgrade"=>1,
                        "qty_stage"=>1,
                    ];
                    $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                    if($ip_type !== "") $new_eip_product_config_options["option_name"] = "with_eip_num_$ip_type|IP数量-".$this->getRegionIpTypeLabel($plan[$item]["region"], $ip_type, $region_list);
                    $product_config_options_eipid = Db::name('product_config_options')->insertGetId($new_eip_product_config_options);
                    $new_os_product_config_options = [
                        "gid"=>$product_config_groups_id,
                        "option_name"=>"os_id|操作系统",
                        "option_type"=>5,
                        "upgrade"=>0,
                        "qty_stage"=>1,
                    ];
                    $product_config_options_osid = Db::name('product_config_options')->insertGetId($new_os_product_config_options);
                    $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"qty_minimum"=>1,"qty_maximum"=>30,"option_name"=>"with_eip_num|IP数量","sort_order"=>1];
                    if($ip_type !== "") $new_product_config_options_sub["option_name"] = "with_eip_num_$ip_type|IP数量-".$this->getRegionIpTypeLabel($plan[$item]["region"], $ip_type, $region_list);
                    $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                    $ip_price = isset($plan[$item]["ip_prices"][$ip_type]) ? $plan[$item]["ip_prices"][$ip_type] : 0;
                    $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                        "monthly"=>$ip_price*($default["1"]?:1),//月付
                        "quarterly"=>$ip_price*($default["3"]?:1)*3,//季付
                        "semiannually"=>$ip_price*($default["6"]?:1)*6,//半年付
                        "annually"=>$ip_price*($default["12"]?:1)*12,//年付
                    ];
                    Db::name('pricing')->insert($new_pricing);
                    
                    foreach ($os_list as $it){
                        
                        if($it["region"] == $this_region && (empty($it["machine"]) || in_array($plan[$item]["machine"],explode(',', $it["machine"])))){
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_osid,"sort_order"=>$region_list["OsOrder"][$it["icon"]]?:0,"option_name"=>$it["id"]."|".ucfirst($it["icon"])."^".$it["chinese_name"]];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                            Db::name('pricing')->insert($new_pricing);
                        }
                    }
                    if(($navigation_selection["nav_item_id"] > 0 || !empty($navigation_selection["create_mode"]))
                        && !$this->addProductToNavigation($navigation_selection, $pid, "裸金属物理机", '{"chinese":"裸金属物理机","chinese_tw":"裸金屬物理機","english":"Bare Metal"}', $url)){
                        $navigation_warning = true;
                    }
                    $data = ["code"=>200,"msg"=>"操作完成"];
                }
            }else{
                return json(["code"=>404,"msg"=>"ID不存在"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
        }
        if($navigation_warning && isset($data["code"]) && $data["code"] === 200){
            $data["msg"] = "操作完成，但未添加前台导航，请检查本次操作的导航选择";
        }
        return json($data, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
    
    public function rbmlistproducts(){
        $gid = \think\Db::name('servers')  
            ->where('type', 'rainyunrbm')  
                    ->value('gid');
            $productsData = \think\Db::name('products')  
                ->where('server_group', $gid)  
                ->select()  
                ->toArray();
        $data = ["code"=>200,"data"=>$productsData];
        return json($data, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
    
    public function rbmlistapi(){
	    $servers_data = \think\Db::name('servers')  
	                ->where('type', 'rainyunrbm')  
	                ->field('hostname, password, gid')  
	                ->select();
        $plan =  $this->mh_curl($servers_data[0]["hostname"]."/product/rbm/models",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        
        return json($plan, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * 对接模块定义：key => 目录/模块名、服务器组名称、GitHub 仓库
     */
    private function getModuleDefinitions()
    {
        return [
            "rcs" => [
                "type" => "rainyunrcs",
                "name" => "雨云云服务器",
                "repo" => "runyutech/mofang-rcs",
            ],
            "rgs" => [
                "type" => "rainyunrgs",
                "name" => "雨云游戏云",
                "repo" => "runyutech/mofang-rgs",
            ],
            "rbm" => [
                "type" => "rainyunrbm",
                "name" => "雨云裸金属物理机",
                "repo" => "runyutech/mofang-rbm",
            ],
        ];
    }

    private function getModuleStatusList()
    {
        $list = [];
        foreach ($this->getModuleDefinitions() as $key => $module){
            $file = WEB_ROOT."plugins/servers/".$module["type"]."/".$module["type"].".php";
            $installed = is_file($file);
            $configured = false;
            $version = "";
            if($installed){
                $version = $this->readModuleVersion($file);
                $configured = (bool)Db::name("servers")->where("type", $module["type"])->find();
            }
            $list[] = [
                "key" => $key,
                "type" => $module["type"],
                "name" => $module["name"],
                "installed" => $installed,
                "configured" => $configured,
                "version" => $version,
            ];
        }
        return $list;
    }

    /**
     * 读取模块文件里 MetaData 函数返回的 version（没有则返回空字符串）
     */
    private function readModuleVersion($file)
    {
        if(!is_file($file)) return "";
        $content = @file_get_contents($file);
        if($content === false) return "";
        return $this->parseModuleVersion($content);
    }

    private function parseModuleVersion($content)
    {
        $content = (string)$content;
        if(preg_match('/function\s+[A-Za-z0-9_]*MetaData\s*\([^)]*\)\s*\{(.{0,4000}?)\}/is', $content, $matches)){
            $content = $matches[1];
        }
        if(preg_match('/[\'"]version[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i', $content, $matches)){
            return trim($matches[1]);
        }
        return "";
    }

    /**
     * 下载模块最新 release.zip 到 plugins/servers 目录下的临时文件
     */
    private function downloadModulePackage(array $module)
    {
        if(!function_exists("curl_init")){
            throw new \RuntimeException("服务器未启用 curl 扩展，无法下载模块");
        }
        $url = "https://github.com/".$module["repo"]."/releases/latest/download/release.zip";
        $targetDir = rtrim(WEB_ROOT."plugins/servers", "/\\");
        if(!is_dir($targetDir) || !is_writable($targetDir)){
            throw new \RuntimeException("plugins/servers 目录不存在或不可写");
        }
        $zipPath = $targetDir."/.".$module["type"]."_".uniqid("", true).".zip";
        $fp = @fopen($zipPath, "wb");
        if(!$fp){
            throw new \RuntimeException("无法创建临时文件，请检查 plugins/servers 目录权限");
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, "MoHua-Rainyun-Module");
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if(!$result || $status !== 200){
            @unlink($zipPath);
            throw new \RuntimeException("下载模块失败".($error !== "" ? "：".$error : "（HTTP ".$status."）"));
        }
        if(filesize($zipPath) < 100){
            @unlink($zipPath);
            throw new \RuntimeException("下载的模块文件无效");
        }
        return $zipPath;
    }

    /**
     * 读取压缩包内模块文件的版本
     */
    private function readPackageModuleVersion($zipPath, array $module)
    {
        $mainFile = $module["type"]."/".$module["type"].".php";
        if(class_exists("\\ZipArchive")){
            $zip = new \ZipArchive();
            if($zip->open($zipPath) !== true){
                throw new \RuntimeException("模块压缩包无法打开");
            }
            $content = $zip->getFromName($mainFile);
            $zip->close();
            if($content === false){
                throw new \RuntimeException("模块压缩包内容不完整");
            }
            return $this->parseModuleVersion($content);
        }
        if(function_exists("exec")){
            $output = [];
            $code = 0;
            exec("unzip -p ".escapeshellarg($zipPath)." ".escapeshellarg($mainFile), $output, $code);
            if($code !== 0){
                throw new \RuntimeException("模块压缩包内容不完整");
            }
            return $this->parseModuleVersion(implode("\n", $output));
        }
        throw new \RuntimeException("服务器未启用 zip 扩展，无法读取模块压缩包");
    }

    /**
     * 把压缩包解压到 public/plugins/servers（覆盖同名文件）
     */
    private function extractModulePackage($zipPath, array $module)
    {
        $targetDir = rtrim(WEB_ROOT."plugins/servers", "/\\");
        if(!is_dir($targetDir) || !is_writable($targetDir)){
            throw new \RuntimeException("plugins/servers 目录不存在或不可写");
        }
        if(class_exists("\\ZipArchive")){
            $zip = new \ZipArchive();
            if($zip->open($zipPath) !== true){
                throw new \RuntimeException("模块压缩包无法打开");
            }
            for($i = 0; $i < $zip->numFiles; $i++){
                $name = $zip->getNameIndex($i);
                if($name === false) continue;
                $name = str_replace("\\", "/", $name);
                if($name === "" || $name[0] === "/" || preg_match('#(^|/)\.\.(/|$)#', $name)){
                    $zip->close();
                    throw new \RuntimeException("模块压缩包路径不安全");
                }
            }
            $extracted = $zip->extractTo($targetDir);
            $zip->close();
            if(!$extracted){
                throw new \RuntimeException("模块解压失败，请检查目录权限");
            }
            return;
        }
        if(function_exists("exec")){
            $output = [];
            $code = 0;
            exec("unzip -o -q ".escapeshellarg($zipPath)." -d ".escapeshellarg($targetDir), $output, $code);
            if($code !== 0){
                throw new \RuntimeException("模块解压失败，请确认服务器已安装 unzip");
            }
            return;
        }
        throw new \RuntimeException("服务器未启用 zip 扩展，无法解压模块");
    }

    /**
     * 首次安装时创建服务器组与服务器配置
     */
    private function createModuleServer(array $module, $apiKey)
    {
        $password = function_exists("aesPasswordEncode") ? aesPasswordEncode($apiKey) : $apiKey;
        Db::startTrans();
        try{
            $groupId = Db::name("server_groups")->insertGetId([
                "name" => $module["name"],
            ]);
            if(!$groupId){
                throw new \RuntimeException("创建服务器组失败");
            }
            $inserted = Db::name("servers")->insert([
                "gid" => $groupId,
                "name" => $module["name"],
                "ip_address" => "0",
                "hostname" => "https://api.v2.rainyun.com",
                "password" => $password,
                "accesshash" => "",
                "server_type" => "normal",
                "type" => $module["type"],
            ]);
            if(!$inserted){
                throw new \RuntimeException("创建服务器配置失败");
            }
            Db::commit();
            return $groupId;
        }catch(\Exception $e){
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 一键安装/更新雨云对接模块
     */
    public function installmodule()
    {
        $definitions = $this->getModuleDefinitions();
        $key = input("post.type", "");
        if(!is_string($key) || !isset($definitions[$key])){
            return json(["code"=>400, "msg"=>"模块类型无效"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $module = $definitions[$key];
        $apiKey = trim((string)input("post.api_key", ""));
        if(strlen($apiKey) > 500){
            return json(["code"=>400, "msg"=>"API 密钥长度无效"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $serverPhp = WEB_ROOT."plugins/servers/".$module["type"]."/".$module["type"].".php";
        $installed = is_file($serverPhp);
        $localVersion = $installed ? $this->readModuleVersion($serverPhp) : "";
        $configured = (bool)Db::name("servers")->where("type", $module["type"])->find();
        if(!$configured && $apiKey === ""){
            return json(["code"=>400, "msg"=>"首次安装请填写雨云 API 密钥"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }

        $zipPath = "";
        try{
            $zipPath = $this->downloadModulePackage($module);
            $remoteVersion = $this->readPackageModuleVersion($zipPath, $module);
            if($installed && $localVersion !== "" && $remoteVersion !== ""
                && version_compare($remoteVersion, $localVersion, "<=")){
                @unlink($zipPath);
                if(!$configured){
                    try{
                        $this->createModuleServer($module, $apiKey);
                        $configured = true;
                    }catch(\Exception $e){
                        return json([
                            "code" => 500,
                            "msg" => "模块已是最新版本，但写入服务器配置失败：".$e->getMessage(),
                        ], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                    }
                }
                return json([
                    "code" => 200,
                    "msg" => "当前已是最新版本（".$localVersion."），无需更新",
                    "data" => ["installed"=>true, "configured"=>$configured, "version"=>$localVersion, "updated"=>false],
                ], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
            $this->extractModulePackage($zipPath, $module);
            @unlink($zipPath);
            $zipPath = "";
        }catch(\Exception $e){
            if($zipPath !== "" && is_file($zipPath)) @unlink($zipPath);
            return json(["code"=>500, "msg"=>$e->getMessage()], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }

        if(!is_file($serverPhp)){
            return json(["code"=>500, "msg"=>"模块文件解压失败，请检查目录权限"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $newVersion = $this->readModuleVersion($serverPhp);
        if(!$configured && $apiKey !== ""){
            try{
                $this->createModuleServer($module, $apiKey);
                $configured = true;
            }catch(\Exception $e){
                return json([
                    "code" => 500,
                    "msg" => "模块已".($installed ? "更新" : "安装")."，但写入服务器配置失败：".$e->getMessage(),
                ], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
        }
        $msg = $installed ? "更新完成" : "安装完成";
        if($newVersion !== "") $msg .= "（版本 ".$newVersion."）";
        if(!$configured) $msg .= "，未检测到对应的服务器配置，请重新安装并填写 API 密钥";
        return json([
            "code" => 200,
            "msg" => $msg,
            "data" => ["installed"=>true, "configured"=>$configured, "version"=>$newVersion, "updated"=>true],
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
    
    public function status()
    {
        $this->assign('Title','模块状态');
        $this->assign("ModuleList", $this->getModuleStatusList());
        return $this->fetch('/status');
    }

    public function settings()
    {
        $this->assign("Title", "插件设置");
        $this->assign("DescriptionTemplate", htmlspecialchars((string)($this->_config["description_template"] ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"));
        $this->assign("AutoUpdateRegionData", (int)($this->_config["auto_update_region_data"] ?? 1));
        $dataFile = $this->getRegionDataFile();
        $this->assign("RegionDataUpdateTime", is_file($dataFile) ? date("Y-m-d H:i:s", filemtime($dataFile)) : "未知");
        return $this->fetch("/settings");
    }

    public function saveSettings()
    {
        $descriptionTemplate = request()->post("description_template", "");
        if(!is_string($descriptionTemplate)){
            return json(["code"=>400, "msg"=>"设置内容格式无效"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        if(strlen($descriptionTemplate) > 20000){
            return json(["code"=>400, "msg"=>"设置内容超出长度限制"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $descriptionTemplate = $this->normalizeDescriptionTemplate($descriptionTemplate);
        if(trim($descriptionTemplate) === ""){
            $descriptionTemplate = \addons\rainyun\RainyunPlugin::DEFAULT_DESCRIPTION_TEMPLATE;
        }
        $autoUpdateRegionData = (string)input("post.auto_update_region_data", "0") === "1" ? 1 : 0;
        $settings = Db::name("rainyun_settings")->where("id", 1)->find();
        $data = [
            "description_template" => $descriptionTemplate,
            "auto_update_region_data" => $autoUpdateRegionData,
            "update_time" => time(),
        ];
        $saved = $settings
            ? Db::name("rainyun_settings")->where("id", 1)->update($data) !== false
            : Db::name("rainyun_settings")->insert(array_merge(["id" => 1], $data)) !== false;
        if(!$saved){
            return json(["code"=>500, "msg"=>"保存设置失败"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $this->_config["description_template"] = $descriptionTemplate;
        $this->_config["auto_update_region_data"] = $autoUpdateRegionData;
        return json(["code"=>200, "msg"=>"设置已保存"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * 手动更新分区数据
     */
    public function pullregiondata()
    {
        try{
            $this->downloadRegionData();
        }catch(\Exception $e){
            return json(["code"=>500, "msg"=>"更新分区数据失败：".$e->getMessage()], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $file = $this->getRegionDataFile();
        $data = json_decode((string)@file_get_contents($file), true);
        return json([
            "code" => 200,
            "msg" => "分区数据已更新",
            "data" => [
                "update_time" => is_file($file) ? date("Y-m-d H:i:s", filemtime($file)) : "",
                "groups" => is_array($data) ? count($data) : 0,
            ],
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function checkregionsapi()
    {
        $type = input("post.type", "");
        $isRgpu = input("post.is_rgpu", "") === "true";
        $config = [
            "rbm" => ["server" => "rainyunrbm", "path" => "/product/rbm/models", "selling_only" => false],
            "rgs" => ["server" => "rainyunrgs", "path" => "/product/rgs/plans", "selling_only" => true],
            "rcs" => ["server" => "rainyunrcs", "path" => "/product/rcs/plans".($isRgpu ? "?is_rgpu=true" : ""), "selling_only" => true],
        ];
        if(!is_string($type) || !isset($config[$type])){
            return json(["code"=>400, "msg"=>"同步类型无效"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $ids = input("post.id", []);
        if(!$this->hasValidSelectedIds($ids)){
            return json(["code"=>400, "msg"=>"请选择有效的同步套餐"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $ids = array_values(array_unique(array_map("strval", $ids)));
        $server = Db::name("servers")
            ->where("type", $config[$type]["server"])
            ->field("hostname, password")
            ->find();
        if(!$server){
            return json(["code"=>500, "msg"=>"未找到对应的雨云服务器配置"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $plansUrl = $server["hostname"].$config[$type]["path"];
        $planResponse = $this->mh_curl($plansUrl, null, 30, "GET", ["Content-Type: application/json; charset=utf-8", "x-api-key: ".$server["password"]]);
        if(($planResponse["code"] ?? 0) != 200){
            return json(["code"=>500, "msg"=>"获取套餐列表失败"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        if(!is_array($planResponse["data"] ?? null)){
            return json(["code"=>500, "msg"=>"雨云套餐数据格式无效"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $plans = [];
        foreach ($planResponse["data"] as $plan){
            if(!is_array($plan) || !isset($plan["id"])) continue;
            if($config[$type]["selling_only"] && empty($plan["is_selling"])) continue;
            $plans[(string)$plan["id"]] = $plan;
        }
        foreach ($ids as $id){
            if(!isset($plans[$id])){
                return json(["code"=>400, "msg"=>"所选套餐已不可用，请刷新列表后重试"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
        }
        try{
            $regionList = $this->getRegionListData();
        }catch(\Exception $e){
            return json(["code"=>500, "msg"=>"读取分区数据失败：".$e->getMessage()], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $unknownRegions = $this->getUnknownPlanRegions($ids, $plans, $regionList);
        return json(["code"=>200, "data"=>["unknown_regions"=>$unknownRegions]], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
    
    public function rcslist()
    {
        if(!file_exists("./plugins/servers/rainyunrcs/rainyunrcs.php")){
            return '<script>window.location.replace("'.shd_addon_url('Rainyun://AdminIndex/status').'");</script>';
        }
        $this->assign('Title','云服务器列表');
        $this->assign('IsRgpu','false');
        return $this->fetch('/rcslist');
    }

    public function rgpulist()
    {
        if(!file_exists("./plugins/servers/rainyunrcs/rainyunrcs.php")){
            return '<script>window.location.replace("'.shd_addon_url('Rainyun://AdminIndex/status').'");</script>';
        }
        $this->assign('Title','显卡云列表');
        $this->assign('IsRgpu','true');
        return $this->fetch('/rcslist');
    }
    
    public function rgslist()
    {
        if(!file_exists("./plugins/servers/rainyunrgs/rainyunrgs.php")){
            return '<script>window.location.replace("'.shd_addon_url('Rainyun://AdminIndex/status').'");</script>';
        }
        $this->assign('Title','游戏云列表');
        return $this->fetch('/rgslist');
    }
    public function rgslistapi()
    {
	    $servers_data = \think\Db::name('servers')  
	                ->where('type', 'rainyunrgs')  
	                ->field('hostname, password, gid')  
	                ->select();
        $plan =  $this->mh_curl($servers_data[0]["hostname"]."/product/rgs/plans",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        
        return json($plan, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function rgslistproducts()
    {
        $gid = \think\Db::name('servers')  
            ->where('type', 'rainyunrgs')  
                    ->value('gid');
            $productsData = \think\Db::name('products')  
                ->where('server_group', $gid)  
                ->select()  
                ->toArray();
        $data = ["code"=>200,"data"=>$productsData];
        return json($data, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function rgsoperateapi()
    {
        $navigation_warning = false;
	    $servers_data = \think\Db::name('servers')  
	                ->where('type', 'rainyunrgs')  
	                ->field('hostname, password, gid')  
	                ->select();
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rgs/plans",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(0)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $planres = $res["data"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rgs/discount-percent?type=create",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(1)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $default = $res["data"]["default"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rgs/os-templates",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(2)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $os_list = $res["data"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rgs/egg",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(3)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $egg_list = $res["data"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rgs/egg_type",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(4)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $egg_type = $res["data"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rgs/egg_server",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(5)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $egg_server = $res["data"];
        $productsData = \think\Db::name('products')  
            ->where('server_group', $servers_data[0]["gid"])  
            ->select()  
            ->toArray();
        $products = [];
        foreach ($productsData as $item){
            $products[$item["config_option9"]] = $item;
        }
        $plan = [];
        foreach ($planres as $item){
            if($item["is_selling"]){
                $plan[$item["id"]] = $item;
            }
        }
        try{
            $region_list = $this->getRegionListData();
        }catch(\Exception $e){
            return json(["code"=>500,"msg"=>"读取分区数据失败：".$e->getMessage()], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        // 预先整理游戏（蛋）数据，供新建与更新复用
        $egg_group_map = [];
        $egg_server_map = [];
        $egg_type_map = [];
        $egg_type_server_map = [];
        if(is_array($egg_list)){
            $egg_sorted = $egg_list;
            usort($egg_sorted, function($a, $b) {
                return $a['order'] - $b['order'];
            });
            foreach ($egg_sorted as $i){
                if(!$i["is_hidden"]) $egg_group_map[$i["egg_group"]][] = $i;
            }
        }
        if(is_array($egg_server)){
            foreach ($egg_server as $i){
                $egg_server_map[$i["egg_name"]][] = $i;
            }
        }
        if(is_array($egg_type)){
            foreach ($egg_type as $i){
                $egg_type_server_map[$i["env"]['SERVER_TYPE']] = true;
                // is_hidden 为 true 的版本不同步（数据库已有的会在同步时隐藏）
                if(!empty($i["is_hidden"])) continue;
                $egg_type_map[$i["env"]['SERVER_TYPE']][] = $i;
            }
        }
        $IdList = input("post.id");
        if(!$this->hasValidSelectedIds($IdList)) return json(["code"=>400,"msg"=>"请选择有效的同步套餐"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $region_names = $this->getSubmittedRegionNames();
        $unknown_regions = $this->getUnknownPlanRegions($IdList, $plan, $region_list, $region_names);
        if(count($unknown_regions) > 0) return json(["code"=>400,"msg"=>"请先填写未识别分区名称","data"=>["unknown_regions"=>$unknown_regions]], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $navigation_selection = $this->getRequestedNavigationSelection($IdList, $products, "游戏云");
        if($navigation_selection === false) return json(["code"=>400,"msg"=>"新商品必须选择有效的会员中心导航选项"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $data = ["code"=>200,"msg"=>"操作完成"];
        foreach ($IdList as $item){
            if(isset($plan[$item])){
                $this_region = $plan[$item]["region"];
                if(isset($products[$item])){
                    if(!$this->updateUnknownRegionGroupName($products[$item]["gid"], $this_region, $plan[$item]["line"], $region_list, $region_names)){
                        return json(["code"=>500,"msg"=>"更新未知分区商品分组名称失败"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                    }
                    // 已拉取过的产品：同步基础信息、价格、配置选项与游戏数据
                    $pid = $products[$item]["id"];
                    $subtype_name = $region_list["subtype"][$plan[$item]["subtype"]]?:$plan[$item]["subtype"];
                    $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                    $machine_info = $region_list["Rgs_machine"][$plan[$item]["machine"]]["info"] ?? "";
                    $data = [
                        "name"=>$region_name." ".$plan[$item]["chinese"]." ".$subtype_name,
                        "description"=>$this->compressProductDescriptionToSingleLine($machine_info),
                        "qty"=>$plan[$item]["available_stock"],
                        "config_option8"=>$plan[$item]["subtype"],
                        "config_option9"=>$plan[$item]["id"],
                        "is_truename"=>$region_list["is_truename"][$this_region]?:true,
                    ];
                    // 只有发生变化时才更新产品基础信息
                    $product_changed = false;
                    foreach ($data as $field => $val){
                        $old_val = isset($products[$item][$field]) ? $products[$item][$field] : null;
                        if(in_array($field, ["qty","config_option9","is_truename"])){
                            if((int)$old_val !== (int)$val) { $product_changed = true; break; }
                        }else{
                            if((string)$old_val !== (string)$val) { $product_changed = true; break; }
                        }
                    }
                    if($product_changed){
                        $data["update_time"] = time();
                        Db::name('products')
                            ->where('id', $pid)
                            ->update($data);
                    }

                    // 整理套餐配置，用于重新计算配置项价格
                    $config_array = [];
                    if(isset($plan[$item]["config"]) && is_array($plan[$item]["config"])){
                        foreach($plan[$item]["config"] as $i){
                            $config_array[$i["cpu"]] = $i;
                        }
                        ksort($config_array);
                    }
                    if(isset($config_array[0]) && !isset($config_array[0]["base_price"])){
                        $config_array[0]["base_price"] = $config_array[0]["cpu"]*$plan[$item]["cpu_price"]+$config_array[0]["memory"]*$plan[$item]["memory_price"]+$config_array[0]["net_out"]*$plan[$item]["net_out_price"]+$config_array[0]["base_disk"]*$plan[$item]["base_disk_price"]+$config_array[0]["data_disk"]*$plan[$item]["data_disk_price"];
                    }

                    $config_link = Db::name('product_config_links')
                        ->where('pid', $pid)
                        ->find();
                    $config_options = [];
                    if($config_link){
                        $config_options = Db::name('product_config_options')
                            ->where('gid', $config_link["gid"])
                            ->select()
                            ->toArray();
                        // 一次性把配置项读入内存，后续同步不再逐条查询
                        $this->rgsLoadEggCache($config_link["gid"], $config_options);
                    }

                    $nn = 0;
                    foreach ($config_options as $ite){
                        $optionParts = explode("|", $ite["option_name"]);
                        $optionKey = $optionParts[0];
                        $sub_list = $this->rgsEggSubsOfOption($ite["id"]);
                        if($optionKey === "cpu" && isset($config_array[0])){
                            // CPU核心：按配置表重新计算差价，并与创建时一致做整体偏移
                            $sub_prices = [];
                            foreach ($sub_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $i = (int)$option_name_parts[0];
                                $j = $i;
                                while($j >= 0 && !isset($config_array[$j])) $j--;
                                if($j < 0 || !isset($config_array[$j])) continue;
                                $this_config = $config_array[$j];
                                $base_price = $this_config["base_price"] + ($i - $this_config["cpu"])*$plan[$item]["cpu_price"];
                                $prices = $base_price - $config_array[0]["base_price"] - ($this_config["memory"] - $config_array[0]["memory"])*$plan[$item]["memory_price"] - ($this_config["net_out"] - $config_array[0]["net_out"])*$plan[$item]["net_out_price"];
                                $ontrial_pricing = 1;
                                if($i >= 6){
                                    $ontrial_pricing = 6;
                                }elseif($i >= 4){
                                    $ontrial_pricing = 2;
                                }
                                $sub_prices[$it["id"]] = ["price"=>$prices,"ontrial"=>$ontrial_pricing];
                            }
                            foreach ($sub_prices as $v){
                                if($v["price"] < $nn) $nn = $v["price"];
                            }
                            foreach ($sub_prices as $sub_id => $v){
                                $price = $v["price"];
                                if($nn < 0) $price = $price + abs($nn);
                                $this->rgsSaveSubPricing($sub_id, [
                                    "ontrial"=>$v["ontrial"],
                                    "monthly"=>$price*($default["1"]?:1),//月付
                                    "quarterly"=>$price*($default["3"]?:1)*3,//季付
                                    "semiannually"=>$price*($default["6"]?:1)*6,//半年付
                                    "annually"=>$price*($default["12"]?:1)*12,//年付
                                ]);
                            }
                        }else if($optionKey === "memory" && isset($config_array[0])){
                            // 内存差价
                            foreach ($sub_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $i = (int)$option_name_parts[0];
                                $prices = ($i - $config_array[0]["memory"])*$plan[$item]["memory_price"];
                                $this->rgsSaveSubPricing($it["id"], [
                                    "monthly"=>$prices*($default["1"]?:1),//月付
                                    "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                                    "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                                    "annually"=>$prices*($default["12"]?:1)*12,//年付
                                ]);
                            }
                        }else if($optionKey === "base_disk" && isset($config_array[0])){
                            // 系统盘差价
                            foreach ($sub_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $i = (int)$option_name_parts[0];
                                $prices = ($i - $config_array[0]["base_disk"])*$plan[$item]["base_disk_price"];
                                $this->rgsSaveSubPricing($it["id"], [
                                    "monthly"=>$prices*($default["1"]?:1),//月付
                                    "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                                    "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                                    "annually"=>$prices*($default["12"]?:1)*12,//年付
                                ]);
                            }
                        }else if($optionKey === "data_disk" && isset($config_array[0])){
                            // 数据盘差价
                            foreach ($sub_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $i = (int)$option_name_parts[0];
                                $prices = ($i - $config_array[0]["data_disk"])*$plan[$item]["data_disk_price"];
                                $this->rgsSaveSubPricing($it["id"], [
                                    "monthly"=>$prices*($default["1"]?:1),//月付
                                    "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                                    "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                                    "annually"=>$prices*($default["12"]?:1)*12,//年付
                                ]);
                            }
                        }else if($optionKey === "net_out" && isset($config_array[0])){
                            foreach ($sub_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $i = (int)$option_name_parts[0];
                                $prices = ($i - $config_array[0]["net_out"])*$plan[$item]["net_out_price"];
                                $this->rgsSaveSubPricing($it["id"], [
                                    "monthly"=>$prices*($default["1"]?:1),//月付
                                    "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                                    "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                                    "annually"=>$prices*($default["12"]?:1)*12,//年付
                                ]);
                            }
                        }else if(strpos($optionKey, "with_eip_num") === 0){
                            // 独立IP数量差价
                            $ip_type = $this->getDefaultRegionIpType($this_region, $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                            $ip_price = isset($plan[$item]["ip_prices"][$ip_type])?$plan[$item]["ip_prices"][$ip_type]:0;
                            $exist_ip_n = [];
                            foreach ($sub_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $n = (int)$option_name_parts[0];
                                $exist_ip_n[$n] = true;
                                $this->rgsSaveSubPricing($it["id"], [
                                    "monthly"=>$ip_price*$n*($default["1"]?:1),//月付
                                    "quarterly"=>$ip_price*$n*($default["3"]?:1)*3,//季付
                                    "semiannually"=>$ip_price*$n*($default["6"]?:1)*6,//半年付
                                    "annually"=>$ip_price*$n*($default["12"]?:1)*12,//年付
                                ]);
                            }
                            // 可购买IP数量增加时补全对应的规格
                            if(isset($region_list["ip_n"][$this_region])){
                                $ip_n = (int)$region_list["ip_n"][$this_region];
                                for ($n = 1; $n <= $ip_n; $n++){
                                    if(isset($exist_ip_n[$n])) continue;
                                    $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId([
                                        "config_id"=>$ite["id"],
                                        "option_name"=>$n."|".$n."个",
                                        "sort_order"=>$n,
                                    ]);
                                    Db::name('pricing')->insert([
                                        "type"=>"configoptions",
                                        "currency"=>1,
                                        "relid"=>$new_product_config_options_sub_id,
                                        "monthly"=>$ip_price*$n*($default["1"]?:1),//月付
                                        "quarterly"=>$ip_price*$n*($default["3"]?:1)*3,//季付
                                        "semiannually"=>$ip_price*$n*($default["6"]?:1)*6,//半年付
                                        "annually"=>$ip_price*$n*($default["12"]?:1)*12,//年付
                                    ]);
                                }
                            }
                        }else if($optionKey === "os_id" && $plan[$item]["subtype"] == "kvm"){
                            // 操作系统：隐藏失效镜像并补充新增镜像
                            $valid_os = [];
                            foreach ($os_list as $os_item){
                                if(!$os_item["is_available"]) continue;
                                if($os_item["region"] != $this_region) continue;
                                if(substr($os_item["name"], 0, 7) == "btpanel") continue;
                                if($os_item["os_type"] == "windows" && $plan[$item]["chinese"] == "KVM 入门版") continue;
                                if($os_item["os_type"] == "darwin" && ($plan[$item]["cpu"] < 4 || $plan[$item]["memory"] < 8192)) continue;
                                if($plan[$item]["machine"] == "G6P40"){
                                    if(substr($os_item["name"], -4) != "_gpu") continue;
                                }elseif($plan[$item]["machine"] == "EPYC3080"){
                                    if(substr($os_item["name"], -7) != "gpu3080") continue;
                                }else{
                                    if(substr($os_item["name"], -4) == "_gpu" || substr($os_item["name"], -7) == "gpu3080") continue;
                                }
                                $valid_os[$os_item["id"]] = $os_item;
                            }
                            $exist_os_map = [];
                            foreach ($sub_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $n = (int)$option_name_parts[0];
                                if(isset($exist_os_map[$n])){
                                    if((int)$it["hidden"] !== 1){
                                        Db::name('product_config_options_sub')
                                            ->where('id', $it["id"])
                                            ->update(['hidden' => 1]);
                                    }
                                    continue;
                                }
                                $exist_os_map[$n] = true;
                                if(!isset($valid_os[$n])){
                                    if((int)$it["hidden"] !== 1){
                                        Db::name('product_config_options_sub')
                                            ->where('id', $it["id"])
                                            ->update(['hidden' => 1]);
                                    }
                                }else{
                                    $target_sort = $region_list["OsOrder"][$valid_os[$n]["icon"]]?:0;
                                    if((int)$it["hidden"] !== 0 || (int)$it["sort_order"] !== (int)$target_sort){
                                        Db::name('product_config_options_sub')
                                            ->where('id', $it["id"])
                                            ->update([
                                                'hidden' => 0,
                                                'sort_order' => $target_sort,
                                            ]);
                                    }
                                }
                            }
                            foreach ($valid_os as $os_id => $os_item){
                                if(isset($exist_os_map[$os_id])) continue;
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId([
                                    "config_id"=>$ite["id"],
                                    "sort_order"=>$region_list["OsOrder"][$os_item["icon"]]?:0,
                                    "option_name"=>$os_item["id"]."|".ucfirst($os_item["icon"])."^".$os_item["chinese_name"],
                                ]);
                                Db::name('pricing')->insert([
                                    "type"=>"configoptions",
                                    "currency"=>1,
                                    "relid"=>$new_product_config_options_sub_id,
                                ]);
                                $exist_os_map[$os_id] = true;
                            }
                        }else if($optionKey === "egg" && $plan[$item]["subtype"] == "k8s_panel"){
                            // 面板服：同步新增的游戏分类、游戏、服务端与版本
                            $egg_option_id = $ite["id"];
                            // 清理旧的顶层在线模式选项（现改为跟在版本后面，只有我的世界才显示）
                            $legacy_online_opts = Db::name('product_config_options')
                                ->where('gid', $config_link["gid"])
                                ->where('option_name', 'like', 'online_mode|%')
                                ->select()
                                ->toArray();
                            foreach ($legacy_online_opts as $legacy_online_opt){
                                if(!empty($legacy_online_opt["linkage_level"])) continue;
                                $this->rgsDeleteConfigOption($legacy_online_opt["id"]);
                            }
                            // 已有的游戏分类子项（按分类ID去重，重复的删除）
                            usort($sub_list, function($a, $b){ return $a["id"] - $b["id"]; });
                            $group_sub_map = [];
                            foreach ($sub_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $group_key = (string)$option_name_parts[0];
                                if(!isset($group_sub_map[$group_key])){
                                    $group_sub_map[$group_key] = $it;
                                    continue;
                                }
                                // 重复分类：保留内容更完整的一支，其余连同选项树一起删除
                                $keep_sub = $group_sub_map[$group_key];
                                $keep_score = $this->rgsEggGroupSubCount($egg_option_id, $keep_sub["id"]);
                                $this_score = $this->rgsEggGroupSubCount($egg_option_id, $it["id"]);
                                if($this_score > $keep_score){
                                    $drop_sub = $keep_sub;
                                    $group_sub_map[$group_key] = $it;
                                }else{
                                    $drop_sub = $it;
                                }
                                $drop_opts = $this->rgsFindEggGroupOptions($egg_option_id, $drop_sub["id"]);
                                foreach ($drop_opts as $drop_opt){
                                    $this->rgsDeleteConfigOption($drop_opt["id"]);
                                }
                                $this->rgsDeleteConfigOptionSub($drop_sub["id"]);
                            }
                            // 分类按平台 order 倒序排序（无变化不写库）
                            foreach ($egg_group_map as $k => $v){
                                $group_key = (string)$k;
                                if(!isset($group_sub_map[$group_key])) continue;
                                $target_sort = 0 - intval($v[0]["order"]);
                                if((int)$group_sub_map[$group_key]["sort_order"] === $target_sort) continue;
                                Db::name('product_config_options_sub')
                                    ->where('id', $group_sub_map[$group_key]["id"])
                                    ->update(['sort_order' => $target_sort]);
                                $group_sub_map[$group_key]["sort_order"] = $target_sort;
                            }
                            // 已有的 egg_group 选项，按所属分类子项ID建立映射（重复的选项树删除）
                            $group_option_map = [];
                            $group_option_list = $this->rgsEggChildOptions($egg_option_id, 'egg_group|');
                            usort($group_option_list, function($a, $b){ return $a["id"] - $b["id"]; });
                            foreach ($group_option_list as $gopt){
                                $gopt_subs = $this->rgsEggSubsOfOption($gopt["id"]);
                                $gsub = !empty($gopt_subs) ? $gopt_subs[0] : null;
                                if(empty($gsub)) continue;
                                $level = explode("-", $gsub["linkage_level"]);
                                if(!isset($level[1])) continue;
                                $map_key = (string)$level[1];
                                if(!isset($group_option_map[$map_key])){
                                    $group_option_map[$map_key] = $gopt;
                                    continue;
                                }
                                // 同一分类存在多个 egg_group 选项：保留子项多的
                                $keep_opt = $group_option_map[$map_key];
                                if($this->rgsConfigOptionSubCount($gopt["id"]) > $this->rgsConfigOptionSubCount($keep_opt["id"])){
                                    $this->rgsDeleteConfigOption($keep_opt["id"]);
                                    $group_option_map[$map_key] = $gopt;
                                }else{
                                    $this->rgsDeleteConfigOption($gopt["id"]);
                                }
                            }
                            foreach ($egg_group_map as $k => $v){
                                $k = (string)$k;
                                if(isset($group_sub_map[$k])){
                                    $group_sub = $group_sub_map[$k];
                                }else{
                                    $group_sub_id = Db::name('product_config_options_sub')->insertGetId([
                                        "config_id"=>$egg_option_id,
                                        "option_name"=>$k."|".$v[0]["title"],
                                        "sort_order"=>0 - intval($v[0]["order"]),
                                    ]);
                                    Db::name('product_config_options_sub')
                                        ->where('id', $group_sub_id)
                                        ->update(['linkage_level' => '0-'.$group_sub_id]);
                                    Db::name('pricing')->insert([
                                        "type"=>"configoptions",
                                        "currency"=>1,
                                        "relid"=>$group_sub_id,
                                    ]);
                                    $group_sub = ["id"=>$group_sub_id];
                                    $group_sub_map[$k] = $group_sub;
                                }
                                $group_sub_id = $group_sub["id"];
                                if(isset($group_option_map[$group_sub_id])){
                                    $group_opt = $group_option_map[$group_sub_id];
                                }else{
                                    $group_opt_id = Db::name('product_config_options')->insertGetId([
                                        "gid"=>$config_link["gid"],
                                        "option_name"=>"egg_group|分类",
                                        "option_type"=>20,
                                        "upgrade"=>1,
                                    ]);
                                    Db::name('product_config_options')
                                        ->where('id', $group_opt_id)
                                        ->update([
                                            'linkage_level' => '0-'.$egg_option_id.'-'.$group_opt_id,
                                            'linkage_pid' => $egg_option_id,
                                            'linkage_top_pid' => $egg_option_id,
                                        ]);
                                    $group_opt = ["id"=>$group_opt_id];
                                    $group_option_map[$group_sub_id] = $group_opt;
                                }
                                $group_opt_id = $group_opt["id"];
                                // 已有的游戏子项（按名称去重，重复的删除）
                                $egg_sub_map = [];
                                $this_egg_sub_list = $this->rgsEggSubsByLinkage($group_sub_id);
                                usort($this_egg_sub_list, function($a, $b){ return $a["id"] - $b["id"]; });
                                foreach ($this_egg_sub_list as $it){
                                    $option_name_parts = explode("|", $it["option_name"]);
                                    $egg_key = (string)$option_name_parts[0];
                                    if(!isset($egg_sub_map[$egg_key])){
                                        $egg_sub_map[$egg_key] = $it;
                                        continue;
                                    }
                                    // 重复游戏：保留服务端内容更完整的一支，其余连同选项树一起删除
                                    $keep_sub = $egg_sub_map[$egg_key];
                                    $keep_score = $this->rgsEggServerSubCount($group_opt_id, $keep_sub["id"]);
                                    $this_score = $this->rgsEggServerSubCount($group_opt_id, $it["id"]);
                                    if($this_score > $keep_score){
                                        $drop_sub = $keep_sub;
                                        $egg_sub_map[$egg_key] = $it;
                                    }else{
                                        $drop_sub = $it;
                                    }
                                    $drop_opts = $this->rgsFindEggServerOptions($group_opt_id, $drop_sub["id"]);
                                    foreach ($drop_opts as $drop_opt){
                                        $this->rgsDeleteConfigOption($drop_opt["id"]);
                                    }
                                    $this->rgsDeleteConfigOptionSub($drop_sub["id"]);
                                }
                                // 游戏按平台 order 倒序排序（无变化不写库）
                                foreach ($v as $it){
                                    if(!isset($egg_sub_map[$it["name"]])) continue;
                                    $target_sort = 0 - intval($it["order"]);
                                    if((int)$egg_sub_map[$it["name"]]["sort_order"] === $target_sort) continue;
                                    Db::name('product_config_options_sub')
                                        ->where('id', $egg_sub_map[$it["name"]]["id"])
                                        ->update(['sort_order' => $target_sort]);
                                    $egg_sub_map[$it["name"]]["sort_order"] = $target_sort;
                                }
                                // 清理指向已删除游戏的孤儿服务端选项
                                $kept_egg_sub_ids = [];
                                foreach ($egg_sub_map as $kept_egg_sub){
                                    $kept_egg_sub_ids[(string)$kept_egg_sub["id"]] = true;
                                }
                                $this_group_server_opts = $this->rgsEggChildOptions($group_opt_id, 'egg_server|');
                                foreach ($this_group_server_opts as $sopt){
                                    $sopt_subs = $this->rgsEggSubsOfOption($sopt["id"]);
                                    if(empty($sopt_subs)) continue;
                                    $linked_egg_sub_id = null;
                                    foreach ($sopt_subs as $sopt_sub){
                                        $sopt_level = explode("-", $sopt_sub["linkage_level"]);
                                        if(isset($sopt_level[2]) && $sopt_level[2] !== ""){
                                            $linked_egg_sub_id = (string)$sopt_level[2];
                                            break;
                                        }
                                    }
                                    if($linked_egg_sub_id === null) continue;
                                    if(!isset($kept_egg_sub_ids[$linked_egg_sub_id])){
                                        $this->rgsDeleteConfigOption($sopt["id"]);
                                    }
                                }
                                // 收集没有子项的服务端选项，新增游戏时优先复用，避免重复创建空选项
                                $free_server_opts = [];
                                $this_group_server_opts = $this->rgsEggChildOptions($group_opt_id, 'egg_server|');
                                foreach ($this_group_server_opts as $sopt){
                                    if($this->rgsConfigOptionSubCount($sopt["id"]) === 0){
                                        $free_server_opts[] = $sopt;
                                    }
                                }
                                foreach ($v as $it){
                                    if(isset($egg_sub_map[$it["name"]])){
                                        $egg_sub = $egg_sub_map[$it["name"]];
                                    }else{
                                        $egg_sub_id = Db::name('product_config_options_sub')->insertGetId([
                                            "config_id"=>$group_opt_id,
                                            "sort_order"=>0 - intval($it["order"]),
                                            "option_name"=>$it["name"]."|".$it["desc"],
                                        ]);
                                        Db::name('product_config_options_sub')
                                            ->where('id', $egg_sub_id)
                                            ->update([
                                                'linkage_level' => '0-'.$group_sub_id.'-'.$egg_sub_id,
                                                'linkage_pid' => $group_sub_id,
                                                'linkage_top_pid' => $group_sub_id,
                                            ]);
                                        Db::name('pricing')->insert([
                                            "type"=>"configoptions",
                                            "currency"=>1,
                                            "relid"=>$egg_sub_id,
                                        ]);
                                        $egg_sub = ["id"=>$egg_sub_id];
                                        $egg_sub_map[$it["name"]] = $egg_sub;
                                    }
                                    $egg_sub_id = $egg_sub["id"];
                                    // 已有的服务端选项（每个游戏对应一个 egg_server 选项，重复时保留子项最多的）
                                    $server_opts = $this->rgsFindEggServerOptions($group_opt_id, $egg_sub_id);
                                    $server_opt = null;
                                    if(!empty($server_opts)){
                                        foreach ($server_opts as $cand_opt){
                                            if(empty($server_opt) || $this->rgsConfigOptionSubCount($cand_opt["id"]) > $this->rgsConfigOptionSubCount($server_opt["id"])){
                                                $server_opt = $cand_opt;
                                            }
                                        }
                                        foreach ($server_opts as $dup_opt){
                                            if((string)$dup_opt["id"] === (string)$server_opt["id"]) continue;
                                            $this->rgsDeleteConfigOption($dup_opt["id"]);
                                        }
                                    }
                                    if(empty($server_opt)){
                                        // 优先复用本分组内空闲的空服务端选项
                                        if(!empty($free_server_opts)){
                                            $server_opt = array_shift($free_server_opts);
                                        }
                                    }
                                    if(empty($server_opt)){
                                        $server_opt_id = Db::name('product_config_options')->insertGetId([
                                            "gid"=>$config_link["gid"],
                                            "option_name"=>"egg_server|服务端",
                                            "option_type"=>20,
                                            "upgrade"=>1,
                                        ]);
                                        Db::name('product_config_options')
                                            ->where('id', $server_opt_id)
                                            ->update([
                                                'linkage_level' => '0-'.$egg_option_id.'-'.$group_opt_id.'-'.$server_opt_id,
                                                'linkage_pid' => $group_opt_id,
                                                'linkage_top_pid' => $egg_option_id,
                                            ]);
                                        $server_opt = ["id"=>$server_opt_id];
                                    }
                                    $server_opt_id = $server_opt["id"];
                                    // 已有的服务端子项（按名称去重，重复的删除）
                                    $server_sub_map = [];
                                    $this_server_sub_list = $this->rgsEggSubsOfOption($server_opt_id);
                                    usort($this_server_sub_list, function($a, $b){ return $a["id"] - $b["id"]; });
                                    foreach ($this_server_sub_list as $sit){
                                        $option_name_parts = explode("|", $sit["option_name"]);
                                        $server_key = (string)$option_name_parts[0];
                                        if(!isset($server_sub_map[$server_key])){
                                            $server_sub_map[$server_key] = $sit;
                                            continue;
                                        }
                                        // 重复服务端：保留版本内容更完整的一支，其余连同选项树一起删除
                                        $keep_sub = $server_sub_map[$server_key];
                                        $keep_score = $this->rgsEggTypeSubCount($server_opt_id, $keep_sub["id"]);
                                        $this_score = $this->rgsEggTypeSubCount($server_opt_id, $sit["id"]);
                                        if($this_score > $keep_score){
                                            $drop_sub = $keep_sub;
                                            $server_sub_map[$server_key] = $sit;
                                        }else{
                                            $drop_sub = $sit;
                                        }
                                        $drop_opts = $this->rgsFindEggTypeOptions($server_opt_id, $drop_sub["id"]);
                                        foreach ($drop_opts as $drop_opt){
                                            $this->rgsDeleteConfigOption($drop_opt["id"]);
                                        }
                                        $this->rgsDeleteConfigOptionSub($drop_sub["id"]);
                                    }
                                    // 服务端按平台 order 升序排序，order<=0 视为未设置排到最后（无变化不写库）
                                    $server_sort_value_map = [];
                                    if(isset($egg_server_map[$it["name"]])){
                                        $server_sort_index = 0;
                                        foreach ($egg_server_map[$it["name"]] as $sort_si){
                                            $server_key = (string)$sort_si["server"];
                                            $target_sort = $this->rgsServerSortOrder($sort_si["order"], $server_sort_index);
                                            $server_sort_index++;
                                            $server_sort_value_map[$server_key] = $target_sort;
                                            if(!isset($server_sub_map[$server_key])) continue;
                                            if((int)$server_sub_map[$server_key]["sort_order"] === (int)$target_sort) continue;
                                            Db::name('product_config_options_sub')
                                                ->where('id', $server_sub_map[$server_key]["id"])
                                                ->update(['sort_order' => $target_sort]);
                                            $server_sub_map[$server_key]["sort_order"] = $target_sort;
                                        }
                                    }
                                    // 清理指向已删除服务端的孤儿版本选项
                                    $kept_server_sub_ids = [];
                                    foreach ($server_sub_map as $kept_server_sub){
                                        $kept_server_sub_ids[(string)$kept_server_sub["id"]] = true;
                                    }
                                    $this_server_type_opts = $this->rgsEggChildOptions($server_opt_id, 'egg_type_id|');
                                    foreach ($this_server_type_opts as $topt){
                                        $topt_subs = $this->rgsEggSubsOfOption($topt["id"]);
                                        if(empty($topt_subs)) continue;
                                        $linked_server_sub_id = null;
                                        foreach ($topt_subs as $topt_sub){
                                            $topt_level = explode("-", $topt_sub["linkage_level"]);
                                            if(isset($topt_level[3]) && $topt_level[3] !== ""){
                                                $linked_server_sub_id = (string)$topt_level[3];
                                                break;
                                            }
                                        }
                                        if($linked_server_sub_id === null) continue;
                                        if(!isset($kept_server_sub_ids[$linked_server_sub_id])){
                                            $this->rgsDeleteConfigOption($topt["id"]);
                                        }
                                    }
                                    // 收集没有子项的版本选项，新增服务端时优先复用，避免重复创建空选项
                                    $free_type_opts = [];
                                    $this_server_type_opts = $this->rgsEggChildOptions($server_opt_id, 'egg_type_id|');
                                    foreach ($this_server_type_opts as $topt){
                                        if($this->rgsConfigOptionSubCount($topt["id"]) === 0){
                                            $free_type_opts[] = $topt;
                                        }
                                    }
                                    if(!isset($egg_server_map[$it["name"]])) continue;
                                    foreach ($egg_server_map[$it["name"]] as $si){
                                        $server_key = (string)$si["server"];
                                        if(isset($server_sub_map[$server_key])){
                                            $server_sub = $server_sub_map[$server_key];
                                        }else{
                                            $server_sub_id = Db::name('product_config_options_sub')->insertGetId([
                                                "config_id"=>$server_opt_id,
                                                "sort_order"=>(isset($server_sort_value_map[$server_key]) ? $server_sort_value_map[$server_key] : $this->rgsServerSortOrder($si["order"], 0)),
                                                "option_name"=>$si["server"],
                                            ]);
                                            Db::name('product_config_options_sub')
                                                ->where('id', $server_sub_id)
                                                ->update([
                                                    'linkage_level' => '0-'.$group_sub_id.'-'.$egg_sub_id.'-'.$server_sub_id,
                                                    'linkage_pid' => $egg_sub_id,
                                                    'linkage_top_pid' => $group_sub_id,
                                                ]);
                                            Db::name('pricing')->insert([
                                                "type"=>"configoptions",
                                                "currency"=>1,
                                                "relid"=>$server_sub_id,
                                            ]);
                                            $server_sub = ["id"=>$server_sub_id];
                                            $server_sub_map[$server_key] = $server_sub;
                                        }
                                        $server_sub_id = $server_sub["id"];
                                        // 已有的版本选项（每个服务端对应一个 egg_type_id 选项，重复时保留子项最多的）
                                        $type_opts = $this->rgsFindEggTypeOptions($server_opt_id, $server_sub_id);
                                        $type_opt = null;
                                        if(!empty($type_opts)){
                                            foreach ($type_opts as $cand_opt){
                                                if(empty($type_opt) || $this->rgsConfigOptionSubCount($cand_opt["id"]) > $this->rgsConfigOptionSubCount($type_opt["id"])){
                                                    $type_opt = $cand_opt;
                                                }
                                            }
                                            foreach ($type_opts as $dup_opt){
                                                if((string)$dup_opt["id"] === (string)$type_opt["id"]) continue;
                                                $this->rgsDeleteConfigOption($dup_opt["id"]);
                                            }
                                        }
                                        if(empty($type_opt)){
                                            // 优先复用本服务端下空闲的空版本选项
                                            if(!empty($free_type_opts)){
                                                $type_opt = array_shift($free_type_opts);
                                            }
                                        }
                                        if(empty($type_opt)){
                                            $type_opt_id = Db::name('product_config_options')->insertGetId([
                                                "gid"=>$config_link["gid"],
                                                "option_name"=>"egg_type_id|版本",
                                                "option_type"=>20,
                                                "upgrade"=>1,
                                            ]);
                                            Db::name('product_config_options')
                                                ->where('id', $type_opt_id)
                                                ->update([
                                                    'linkage_level' => '0-'.$egg_option_id.'-'.$group_opt_id.'-'.$server_opt_id.'-'.$type_opt_id,
                                                    'linkage_pid' => $server_opt_id,
                                                    'linkage_top_pid' => $egg_option_id,
                                                ]);
                                            $type_opt = ["id"=>$type_opt_id];
                                        }
                                        $type_opt_id = $type_opt["id"];
                                        // 已有的版本子项（按版本ID去重，重复的删除）
                                        $type_sub_map = [];
                                        $this_type_sub_list = $this->rgsEggSubsOfOption($type_opt_id);
                                        usort($this_type_sub_list, function($a, $b){ return $a["id"] - $b["id"]; });
                                        foreach ($this_type_sub_list as $tit){
                                            $option_name_parts = explode("|", $tit["option_name"]);
                                            $type_key = (string)$option_name_parts[0];
                                            if(isset($type_sub_map[$type_key])){
                                                $this->rgsDeleteConfigOptionSub($tit["id"]);
                                                continue;
                                            }
                                            $type_sub_map[$type_key] = $tit;
                                        }
                                        // 版本 is_hidden 同步：接口 is_hidden=true 或接口已不存在的版本隐藏，其余恢复正常（无变化不写库）
                                        if(isset($egg_type_server_map[$si["server"]])){
                                            $valid_type_ids = [];
                                            if(isset($egg_type_map[$si["server"]])){
                                                foreach ($egg_type_map[$si["server"]] as $valid_tie){
                                                    $valid_type_ids[(string)$valid_tie["id"]] = true;
                                                }
                                            }
                                            foreach ($type_sub_map as $type_key => $this_type_sub){
                                                if(!is_array($this_type_sub)) continue;
                                                $target_hidden = isset($valid_type_ids[$type_key]) ? 0 : 1;
                                                if((int)$this_type_sub["hidden"] === (int)$target_hidden) continue;
                                                Db::name('product_config_options_sub')
                                                    ->where('id', $this_type_sub["id"])
                                                    ->update(['hidden' => $target_hidden]);
                                                $type_sub_map[$type_key]["hidden"] = $target_hidden;
                                            }
                                        }
                                        // 版本按接口返回顺序倒序排列（接口 order 可能重复，这里按位置编号）
                                        if(isset($egg_type_map[$si["server"]])){
                                            $type_total = count($egg_type_map[$si["server"]]);
                                            $type_index = 0;
                                            foreach ($egg_type_map[$si["server"]] as $sort_tie){
                                                $type_key = (string)$sort_tie["id"];
                                                $type_sort_order = $type_total - $type_index;
                                                $type_index++;
                                                if(!isset($type_sub_map[$type_key])) continue;
                                                if((int)$type_sub_map[$type_key]["sort_order"] === (int)$type_sort_order) continue;
                                                Db::name('product_config_options_sub')
                                                    ->where('id', $type_sub_map[$type_key]["id"])
                                                    ->update(['sort_order' => $type_sort_order]);
                                                $type_sub_map[$type_key]["sort_order"] = $type_sort_order;
                                            }
                                        }
                                        if(!isset($egg_type_map[$si["server"]])) continue;
                                        $type_total = count($egg_type_map[$si["server"]]);
                                        $type_index = 0;
                                        foreach ($egg_type_map[$si["server"]] as $tie){
                                            $type_key = (string)$tie["id"];
                                            $type_sort_order = $type_total - $type_index;
                                            $type_index++;
                                            if(isset($type_sub_map[$type_key])) continue;
                                            $type_sub_id = Db::name('product_config_options_sub')->insertGetId([
                                                "config_id"=>$type_opt_id,
                                                "sort_order"=>$type_sort_order,
                                                "option_name"=>$tie["id"]."|".$tie["env"]["SERVER_VERSION"],
                                            ]);
                                            Db::name('product_config_options_sub')
                                                ->where('id', $type_sub_id)
                                                ->update([
                                                    'linkage_level' => '0-'.$group_sub_id.'-'.$egg_sub_id.'-'.$server_sub_id.'-'.$type_sub_id,
                                                    'linkage_pid' => $server_sub_id,
                                                    'linkage_top_pid' => $egg_sub_id,
                                                ]);
                                            Db::name('pricing')->insert([
                                                "type"=>"configoptions",
                                                "currency"=>1,
                                                "relid"=>$type_sub_id,
                                            ]);
                                            $type_sub_map[$type_key] = ["id"=>$type_sub_id];
                                        }
                                        // 在线模式：只有我的世界 Java版/基岩版需要选，挂在版本（联动最后一级）后面
                                        if(strpos($it["name"], "mc_") === 0){
                                            $online_type_sub_ids = [];
                                            foreach ($type_sub_map as $online_type_sub){
                                                if(!is_array($online_type_sub)) continue;
                                                if(!empty($online_type_sub["hidden"])) continue;
                                                $online_type_sub_ids[] = $online_type_sub["id"];
                                            }
                                            if(!empty($online_type_sub_ids)){
                                                $this->rgsEnsureOnlineMode($config_link["gid"], $egg_option_id, $group_opt_id, $server_opt_id, $type_opt_id, $group_sub_id, $egg_sub_id, $server_sub_id, $online_type_sub_ids);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // 更新产品主价格（CPU最低价偏移后的价格）
                    if(isset($config_array[0])){
                        $product_price = $config_array[0]["base_price"] + $nn;
                        $pricing_data = [
                            "monthly"=>$product_price*($default["1"]?:1),//月付
                            "quarterly"=>$product_price*($default["3"]?:1)*3,//季付
                            "semiannually"=>$product_price*($default["6"]?:1)*6,//半年付
                            "annually"=>$product_price*($default["12"]?:1)*12,//年付
                        ];
                        $pricing_exist = Db::name('pricing')
                            ->where('type', "product")
                            ->where('relid', $pid)
                            ->find();
                        if($pricing_exist){
                            $need_update = false;
                            foreach ($pricing_data as $k => $v){
                                if(!isset($pricing_exist[$k]) || abs((float)$pricing_exist[$k] - (float)$v) > 0.001){
                                    $need_update = true;
                                    break;
                                }
                            }
                            if($need_update){
                                Db::name('pricing')
                                    ->where('id', $pricing_exist["id"])
                                    ->update($pricing_data);
                            }
                        }else{
                            $pricing_data["type"] = "product";
                            $pricing_data["currency"] = 1;
                            $pricing_data["relid"] = $pid;
                            Db::name('pricing')->insert($pricing_data);
                        }
                    }
                    $data = ["code"=>200,"msg"=>"操作完成"];
                }else{
                    if($plan[$item]["subtype"] == "mcsm") continue;
                    $samegid = [];
                    foreach ($plan as $key=>$value){
                        if($value['region'] == $this_region && $value['line'] == $plan[$item]["line"]){
                            $samepid = $products[$key]["id"];
                            $gid = $products[$key]["gid"];
                            break;
                        }
                    }
                    if(empty($gid)){
                        //选定一级组
                        foreach ($products as $it){
                            $result = Db::name('product_groups')
                                ->where('id', $it["gid"])
                                ->find();
                            $firstgid = $result['gid'];
                            break;
                        }
                        if(empty($firstgid)){
                            $result = Db::name('product_groups')
                                ->where('name', "游戏云")
                                ->find();
                            if($result){
                                $firstgid = $result["id"];
                            }else{
                                $product_first_groups = [
                                        "name"=>"游戏云",
                                        "hidden"=>0,
                                        "create_time"=>time(),
                                        "update_time"=>time(),
                                        "is_upstream"=>0,
                                        "zjmf_api_id"=>0,
                                    ];
                                $firstgid = Db::name('product_first_groups')->insertGetId($product_first_groups);
                                if(!$firstgid){
                                    return json(["code"=>500,"msg"=>"插入数据库失败"], 500, ['Content-Type' => 'application/json; charset=UTF-8']);
                                }
                            }
                        } 
                        $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                        $product_groups = [
                                "name"=>$region_name,
                                "type"=>1,
                                "create_time"=>time(),
                                "update_time"=>time(),
                                "gid"=>$firstgid,
                                "tpl_type"=>"default",
                            ];
                        $gid = Db::name('product_groups')->insertGetId($product_groups);
                    }else{
                        $result = Db::name('product_groups')
                            ->where('id', $gid)
                            ->find();
                        $firstgid = $result['gid'];
                    }
                    if(!$this->updateUnknownRegionGroupName($gid, $this_region, $plan[$item]["line"], $region_list, $region_names)){
                        return json(["code"=>500,"msg"=>"更新未知分区商品分组名称失败"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                    }
                    $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                    $machine_info = $region_list["Rgs_machine"][$plan[$item]["machine"]]["info"] ?? "";
                    $new_products = [
                            "type"=>"cloud",
                            "gid"=>$gid,
                            "name"=>$region_name." ".$plan[$item]["chinese"]." ".($region_list["subtype"][$plan[$item]["subtype"]]?:$plan[$item]["subtype"]),
                            "description"=>$this->compressProductDescriptionToSingleLine($machine_info),
                            "host"=>'{"show":"1","modify":0,"prefix":"mcs","rule":{"upper":"1","lower":"1","num":"1","len_num":"10"}}',
                            "is_domain"=>0,
                            "hidden"=>0,
                            "password"=>'{"show":"0","modify":0,"rule":{"len_num":"20","upper":"1","lower":"1","num":"1","special":"1"}}',
                            "show_domain_options"=>0,
                            "welcome_email"=>0,
                            "stock_control"=>1,
                            "qty"=>$plan[$item]["available_stock"],
                            "prorata_billing"=>0,
                            "prorata_date"=>0,
                            "prorata_charge_next_month"=>0,
                            "pay_type"=>'{"pay_type":"recurring","pay_hour_cycle":"720","pay_day_cycle":"30","pay_ontrial_status":1,"pay_ontrial_cycle":"1","pay_ontrial_num":"999","pay_ontrial_condition":["email","phone","realname"],"pay_ontrial_cycle_type":"day","pay_ontrial_num_rule":"1","clientscount_rule":"0"}',
                            "pay_method"=>'prepayment',
                            "allow_qty"=>1,
                            "server_type"=>"",
                            "auto_setup"=>"payment",
                            "server_group"=>$servers_data[0]["gid"],
                            "config_option1"=>"",
                            "config_option2"=>"",
                            "config_option3"=>"",
                            "config_option4"=>"",
                            "config_option5"=>"",
                            "config_option6"=>"",
                            "config_option7"=>"",
                            "config_option8"=>$plan[$item]["subtype"],
                            "config_option9"=>$plan[$item]["id"],
                            "config_option10"=>"",
                            "config_option11"=>"",
                            "config_option12"=>"",
                            "config_option13"=>"",
                            "config_option14"=>"",
                            "config_option15"=>"",
                            "config_option16"=>"",
                            "config_option17"=>"",
                            "config_option18"=>"",
                            "config_option19"=>"",
                            "config_option20"=>"",
                            "config_option21"=>"",
                            "config_option22"=>"",
                            "config_option23"=>"",
                            "config_option24"=>"",
                            "billing_cycle_upgrade"=>"",
                            "overages_enabled"=>"",
                            "affiliate_pay_type"=>"default",
                            "create_time"=>time(),
                            "update_time"=>time(),
                            "auto_create_config_options"=>0,
                            "groupid"=>3,
                            "api_type"=>"normal",
                            "app_type"=>"",
                            "location_version"=>0,
                            "upstream_version"=>0,
                            "is_truename"=>$region_list["is_truename"][$this_region]?:true,
                            "product_shopping_url"=>"",
                            "product_group_url"=>"",
                            "cancel_control"=>1,
                            "order"=>100,
                            "upstream_auto_setup"=>"",
                            "upstream_cycle"=>"",
                        ];
                    $pid = Db::name('products')->insertGetId($new_products);
                    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) || (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https');
                    $protocol = $isHttps ? 'https://' : 'http://';
                    $url = $protocol . $_SERVER['HTTP_HOST'];
                    $data = [
                        "product_shopping_url"=>$url."/cart?action=configureproduct&amp;pid=".$pid,
                        "product_group_url"=>$url."/cart?gid=".$gid."&amp;fid=".$firstgid,
                    ];
                    $products[$plan[$item]["id"]] = array_merge($new_products,$data,["id"=>$pid]);
                    $result = Db::name('products')
                        ->where('id', $pid)
                        ->update($data);
                    $config_array = [];
                    foreach($plan[$item]["config"] as $i){
                        $config_array[$i["cpu"]] = $i;
                    }
                    if(!isset($config_array[0]["base_price"])) $config_array[0]["base_price"] = $config_array[0]["cpu"]*$plan[$item]["cpu_price"]+$config_array[0]["memory"]*$plan[$item]["memory_price"]+$config_array[0]["net_out"]*$plan[$item]["net_out_price"]+$config_array[0]["base_disk"]*$plan[$item]["base_disk_price"]+$config_array[0]["data_disk"]*$plan[$item]["data_disk_price"];
                    $new_pricing = ["type"=>"product","currency"=>1,"relid"=>$pid,
                        "monthly"=>$config_array[0]["base_price"]*($default["1"]?:1),//月付
                        "quarterly"=>$config_array[0]["base_price"]*($default["3"]?:1)*3,//季付
                        "semiannually"=>$config_array[0]["base_price"]*($default["6"]?:1)*6,//半年付
                        "annually"=>$config_array[0]["base_price"]*($default["12"]?:1)*12,//年付
                        "onetime"=>-1.00,"hour"=>-1.00,"day"=>-1.00,"biennially"=>-1.00,"triennially"=>-1.00,"fourly"=>-1.00,"fively"=>-1.00,"sixly"=>-1.00,"sevenly"=>-1.00,"eightly"=>-1.00,"ninely"=>-1.00,"tenly"=>-1.00,
                        
                    ];
                    Db::name('pricing')->insert($new_pricing);

                    $new_product_config_groups = [
                        "name"=>$new_products["name"],
                        "description"=>$new_products["name"],
                    ];
                    $product_config_groups_id = Db::name('product_config_groups')->insertGetId($new_product_config_groups);

                    $new_product_config_links = [
                        "gid"=>$product_config_groups_id,
                        "pid"=>$pid,
                    ];
                    $product_config_links_id = Db::name('product_config_links')->insertGetId($new_product_config_links);

                    $new_cpu_product_config_options = [
                            "gid"=>$product_config_groups_id,
                            "option_name"=>"cpu|CPU核心",
                            "option_type"=>6,
                            "upgrade"=>1,
                            "qty_stage"=>1,
                    ];
                    $product_config_options_cpuid = Db::name('product_config_options')->insertGetId($new_cpu_product_config_options);
                    $min = $config_array[0]["cpu_min"];
                    $max = $config_array[0]["cpu_max"];
                    $nn = 0;
                    $ids = [];
                    $condition_data =  [];
                    for ($i = $min; $i <= $max; $i++) {
                        $ontrial_pricing = 1;
                        if($i >= 6){
                            $ontrial_pricing = 6;
                        }elseif($i >= 4){
                            $ontrial_pricing = 2;
                        }elseif($i >= 1){
                            $ontrial_pricing = 1;
                        }
                        $new_product_config_options_sub = ["config_id"=>$product_config_options_cpuid,"option_name"=>$i."|".$i."C","sort_order"=>$i];
                        $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                        $j = $i;
                        while ($j >= 0) {
                            if (isset($config_array[$j])) {
                                $base_price = $config_array[$j]["base_price"];
                                $this_config_memory = $config_array[$j]["memory"];
                                $this_config_net_out = $config_array[$j]["net_out"];
                                if ($j != $i && isset($config_array[$j]["cpu"])) {
                                    $other_cpu_price = ($i - $config_array[$j]["cpu"]) * $plan[$item]["cpu_price"];
                                    $base_price = $config_array[$j]["base_price"] + $other_cpu_price;
                                }
                                if(!isset($condition_data[$j])){
                                    $condition_data[$j] = [
                                        "sub_id"=>[],
                                        "memory_max"=>$config_array[$j]["memory_max"],
                                        "memory_min"=>$config_array[$j]["memory_min"],
                                        "net_out_min"=>$config_array[$j]["net_out_min"],
                                    ];
                                }
                                $condition_data[$j]["sub_id"][$new_product_config_options_sub_id] = ["qty_minimum"=>"0","qty_maximum"=>"0"];
                                break;
                            }
                            $j--;
                        }
                        $prices = $base_price - $config_array[0]["base_price"] - ($this_config_memory-$config_array[0]["memory"]) * $plan[$item]["memory_price"] - ($this_config_net_out-$config_array[0]["net_out"]) * $plan[$item]["net_out_price"];
                        if ($prices < $nn) {
                            $nn = $prices;
                        }
                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                            "ontrial"=>$ontrial_pricing,
                            "monthly"=>$prices*($default["1"]?:1),//月付
                            "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                            "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                            "annually"=>$prices*($default["12"]?:1)*12,//年付
                        ];
                        $this_pricing_id = Db::name('pricing')->insertGetId($new_pricing);
                        $ids[] = $this_pricing_id;
                    }
                    if($nn < 0){
                        $abs_nn = abs($nn);
                        foreach($ids as $i){
                            Db::name('pricing')
                                ->where('id', $i)
                                ->inc('monthly', $abs_nn*($default["1"]?:1))
                                ->inc('quarterly', $abs_nn*($default["3"]?:1)*3)
                                ->inc('semiannually', $abs_nn*($default["6"]?:1)*6)
                                ->inc('annually', $abs_nn*($default["12"]?:1)*12)
                                ->update();
                        }
                        Db::name('pricing')
                            ->where('type', 'product')
                            ->where('relid', $pid)
                            ->inc('monthly', $nn*($default["1"]?:1))
                            ->inc('quarterly', $nn*($default["3"]?:1)*3)
                            ->inc('semiannually', $nn*($default["6"]?:1)*6)
                            ->inc('annually', $nn*($default["12"]?:1)*12)
                            ->update();
                    }

                    $new_memory_product_config_options = [
                            "gid"=>$product_config_groups_id,
                            "option_name"=>"memory|内存",
                            "option_type"=>8,
                            "upgrade"=>1,
                    ];
                    $product_config_options_memoryid = Db::name('product_config_options')->insertGetId($new_memory_product_config_options);
                    $min = $config_array[0]["memory_min"];
                    foreach($config_array as $i){
                        $max = $i["memory_max"];
                    }
                    $product_config_options_sub_ids = [];
                    for ($i = $min; $i <= $max; $i += 2){
                        $new_product_config_options_sub = ["config_id"=>$product_config_options_memoryid,"option_name"=>$i."|".$i."G","sort_order"=>$i];
                        $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                        $prices = ($i - $config_array[0]["memory"])*$plan[$item]["memory_price"];
                        $product_config_options_sub_ids[$i] = $new_product_config_options_sub_id;
                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                            "monthly"=>$prices*($default["1"]?:1),//月付
                            "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                            "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                            "annually"=>$prices*($default["12"]?:1)*12,//年付
                        ];
                        Db::name('pricing')->insert($new_pricing);
                    }

                    $new_base_disk_product_config_options = [
                            "gid"=>$product_config_groups_id,
                            "option_name"=>"base_disk|系统盘",
                            "option_type"=>13,
                            "upgrade"=>1,
                    ];
                    $product_config_options_base_diskid = Db::name('product_config_options')->insertGetId($new_base_disk_product_config_options);
                    $min = $config_array[0]["base_disk_min"];
                    $max = $config_array[0]["base_disk_max"];
                    for ($i = $min; $i <= $max; $i += 5){
                        $new_product_config_options_sub = ["config_id"=>$product_config_options_base_diskid,"option_name"=>$i."|".$i."G","sort_order"=>$i];
                        $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                        $prices = ($i - $config_array[0]["base_disk"])*$plan[$item]["base_disk_price"];
                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                            "monthly"=>$prices*($default["1"]?:1),//月付
                            "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                            "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                            "annually"=>$prices*($default["12"]?:1)*12,//年付
                        ];
                        Db::name('pricing')->insert($new_pricing);
                    }

                    if($plan[$item]["subtype"] == "kvm"){
                        $new_data_disk_product_config_options = [
                                "gid"=>$product_config_groups_id,
                                "option_name"=>"data_disk|数据盘",
                                "option_type"=>13,
                                "upgrade"=>1,
                        ];
                        $product_config_options_data_diskid = Db::name('product_config_options')->insertGetId($new_data_disk_product_config_options);
                        $min = $config_array[0]["data_disk_min"];
                        $max = $config_array[0]["data_disk_max"];
                        for ($i = $min; $i <= $max; $i += 5){
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_data_diskid,"option_name"=>$i."|".$i."G","sort_order"=>$i];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $prices = ($i - $config_array[0]["data_disk"])*$plan[$item]["data_disk_price"];
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                "monthly"=>$prices*($default["1"]?:1),//月付
                                "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                                "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                                "annually"=>$prices*($default["12"]?:1)*12,//年付
                            ];
                            Db::name('pricing')->insert($new_pricing);
                        }

                        $new_net_out_product_config_options = [
                                "gid"=>$product_config_groups_id,
                                "option_name"=>"net_out|上行宽带",
                                "option_type"=>10,
                                "upgrade"=>1,
                        ];
                        $product_config_options_net_outid = Db::name('product_config_options')->insertGetId($new_net_out_product_config_options);
                        $product_config_options_net_out_ids = [];
                        $min = $config_array[0]["net_out_min"];
                        $max = $config_array[0]["net_out_max"];
                        for ($i = $min; $i <= $max; $i += 5){
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_net_outid,"option_name"=>$i."|".$i."M","sort_order"=>$i];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $product_config_options_net_out_ids[$i] = $new_product_config_options_sub_id;
                            $prices = ($i - $config_array[0]["net_out"])*$plan[$item]["net_out_price"];
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                "monthly"=>$prices*($default["1"]?:1),//月付
                                "quarterly"=>$prices*($default["3"]?:1)*3,//季付
                                "semiannually"=>$prices*($default["6"]?:1)*6,//半年付
                                "annually"=>$prices*($default["12"]?:1)*12,//年付
                            ];
                            Db::name('pricing')->insert($new_pricing);
                        }

                        $new_eip_product_config_options = [
                            "gid"=>$product_config_groups_id,
                            "option_name"=>"with_eip_num|IP数量",
                            "option_type"=>13,
                            "upgrade"=>1,
                            "qty_stage"=>1,
                        ];
                        $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                        if($ip_type !== "") $new_eip_product_config_options["option_name"] = "with_eip_num_$ip_type|IP数量-".$this->getRegionIpTypeLabel($plan[$item]["region"], $ip_type, $region_list);
                        $product_config_options_eipid = Db::name('product_config_options')->insertGetId($new_eip_product_config_options);

                        $new_os_product_config_options = [
                            "gid"=>$product_config_groups_id,
                            "option_name"=>"os_id|操作系统",
                            "option_type"=>5,
                            "upgrade"=>0,
                            "qty_stage"=>1,
                        ];
                        $product_config_options_osid = Db::name('product_config_options')->insertGetId($new_os_product_config_options);

                        if($region_list["nat"][$this_region]){
                            if($plan[$item]["ip_prices"][""] >=80){
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"0|0个(NAT,10端口)","sort_order"=>0];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                Db::name('pricing')->insert($new_pricing);
                            }else{
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"0|0个(NAT,10端口)","sort_order"=>4];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                Db::name('pricing')->insert($new_pricing);
                            }
                        }
                        if(isset($region_list["ip_n"][$this_region])){
                            if($region_list["ip_n"][$this_region]>=1){
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"1|1个","sort_order"=>1];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                    "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1),//月付
                                    "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3,//季付
                                    "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6,//半年付
                                    "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12,//年付
                                ];
                                Db::name('pricing')->insert($new_pricing);
                            }
                            if($region_list["ip_n"][$this_region]>=2){
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"2|2个","sort_order"=>2];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                    "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*2,//月付
                                    "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*2,//季付
                                    "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*2,//半年付
                                    "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*2,//年付
                                ];
                                Db::name('pricing')->insert($new_pricing);
                            }
                            if($region_list["ip_n"][$this_region]>=3){
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"3|3个","sort_order"=>3];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                    "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*3,//月付
                                    "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*3,//季付
                                    "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*3,//半年付
                                    "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*3,//年付
                                ];
                                Db::name('pricing')->insert($new_pricing);
                            }
                        }else{
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"1|1个","sort_order"=>1];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                    "monthly"=>$plan[$item]["ip_prices"][""]*($default["1"]?:1),//月付
                                    "quarterly"=>$plan[$item]["ip_prices"][""]*($default["3"]?:1)*3,//季付
                                    "semiannually"=>$plan[$item]["ip_prices"][""]*($default["6"]?:1)*6,//半年付
                                    "annually"=>$plan[$item]["ip_prices"][""]*($default["12"]?:1)*12,//年付
                                ];
                                Db::name('pricing')->insert($new_pricing);
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"2|2个","sort_order"=>1];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                    "monthly"=>$plan[$item]["ip_prices"][""]*($default["1"]?:1)*2,//月付
                                    "quarterly"=>$plan[$item]["ip_prices"][""]*($default["3"]?:1)*3*2,//季付
                                    "semiannually"=>$plan[$item]["ip_prices"][""]*($default["6"]?:1)*6*2,//半年付
                                    "annually"=>$plan[$item]["ip_prices"][""]*($default["12"]?:1)*12*2,//年付
                                ];
                                Db::name('pricing')->insert($new_pricing);
                        }
                        foreach ($os_list as $it){
                            if($it["is_available"]){
                                if($it["region"] == $this_region && substr($it["name"], 0, 7) != "btpanel"){
                                    if($it["os_type"] == "windows"){
                                        if($plan[$item]["chinese"] == "KVM 入门版"){
                                            continue;
                                        }
                                    }
                                    if($it["os_type"] == "darwin"){
                                        if($plan[$item]["cpu"] < 4 || $plan[$item]["memory"] < 8192){
                                            continue;
                                        }
                                    }
                                    if($plan[$item]["machine"] == "G6P40"){
                                        if(substr($it["name"], -4) == "_gpu"){
                                            $new_product_config_options_sub = ["config_id"=>$product_config_options_osid,"sort_order"=>$region_list["OsOrder"][$it["icon"]]?:0,"option_name"=>$it["id"]."|".ucfirst($it["icon"])."^".$it["chinese_name"]];
                                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                            Db::name('pricing')->insert($new_pricing);
                                        }
                                    }elseif($plan[$item]["machine"] == "EPYC3080"){
                                        if(substr($it["name"], -7) == "gpu3080"){
                                            $new_product_config_options_sub = ["config_id"=>$product_config_options_osid,"sort_order"=>$region_list["OsOrder"][$it["icon"]]?:0,"option_name"=>$it["id"]."|".ucfirst($it["icon"])."^".$it["chinese_name"]];
                                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                            Db::name('pricing')->insert($new_pricing);
                                        }
                                    }else{
                                        if(substr($it["name"], -4) != "_gpu" && substr($it["name"], -7) != "gpu3080"){
                                            $new_product_config_options_sub = ["config_id"=>$product_config_options_osid,"sort_order"=>$region_list["OsOrder"][$it["icon"]]?:0,"option_name"=>$it["id"]."|".ucfirst($it["icon"])."^".$it["chinese_name"]];
                                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                            Db::name('pricing')->insert($new_pricing);
                                        }
                                    }
                                }
                            }
                        }
                    }else if($plan[$item]["subtype"] == "k8s_panel"){
                        $new_product_config_options = [
                                "gid"=>$product_config_groups_id,
                                "option_name"=>"egg|游戏",
                                "option_type"=>20,
                                "upgrade"=>1,
                        ];
                        $product_config_options_eggid = Db::name('product_config_options')->insertGetId($new_product_config_options);
                        Db::name('product_config_options')
                            ->where('id', $product_config_options_eggid)
                            ->update(['linkage_level' => '0-'.$product_config_options_eggid]);
                        $egg_group = $egg_group_map;
                        $egg_server_list = $egg_server_map;
                        $egg_type_list = $egg_type_map;
                        foreach ($egg_group as $k => $v){ 
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_eggid,"option_name"=>$k."|".$v[0]["title"],"sort_order"=>0 - intval($v[0]["order"])];
                            $new_product_config_options_sub_eggid = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            Db::name('product_config_options_sub')
                                ->where('id', $new_product_config_options_sub_eggid)
                                ->update(['linkage_level' => '0-'.$new_product_config_options_sub_eggid]);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_eggid];
                            Db::name('pricing')->insert($new_pricing);
                            $new_product_config_options = [
                                    "gid"=>$product_config_groups_id,
                                    "option_name"=>"egg_group|分类",
                                    "option_type"=>20,
                                    "upgrade"=>1,
                            ];
                            $product_config_options_egg_groupid = Db::name('product_config_options')->insertGetId($new_product_config_options);
                            Db::name('product_config_options')
                                ->where('id', $product_config_options_egg_groupid)
                                ->update([
                                    'linkage_level' => '0-'.$product_config_options_eggid."-".$product_config_options_egg_groupid,
                                    'linkage_pid'=>$product_config_options_eggid,
                                    'linkage_top_pid'=>$product_config_options_eggid,
                                ]);
                            foreach ($v as $it){ 
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_egg_groupid,"sort_order"=>0 - intval($it["order"]),"option_name"=>$it["name"]."|".$it["desc"]];
                                $new_product_config_options_sub_groupid = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                Db::name('product_config_options_sub')
                                    ->where('id', $new_product_config_options_sub_groupid)
                                    ->update([
                                        'linkage_level' => '0-'.$new_product_config_options_sub_eggid."-".$new_product_config_options_sub_groupid,
                                        'linkage_pid'=>$new_product_config_options_sub_eggid,
                                        'linkage_top_pid'=>$new_product_config_options_sub_eggid,
                                    ]);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_groupid];
                                Db::name('pricing')->insert($new_pricing);
                                $new_product_config_options = [
                                        "gid"=>$product_config_groups_id,
                                        "option_name"=>"egg_server|服务端",
                                        "option_type"=>20,
                                        "upgrade"=>1,
                                ];
                                $product_config_options_egg_serverid = Db::name('product_config_options')->insertGetId($new_product_config_options);
                                Db::name('product_config_options')
                                    ->where('id', $product_config_options_egg_serverid)
                                    ->update([
                                        'linkage_level' => '0-'.$product_config_options_eggid."-".$product_config_options_egg_groupid."-".$product_config_options_egg_serverid,
                                        'linkage_pid'=>$product_config_options_egg_groupid,
                                        'linkage_top_pid'=>$product_config_options_eggid,
                                    ]);
                                $server_create_index = 0;
                                foreach ($egg_server_list[$it["name"]] as $i){
                                    $new_product_config_options_sub = ["config_id"=>$product_config_options_egg_serverid,"sort_order"=>$this->rgsServerSortOrder($i["order"], $server_create_index),"option_name"=>$i["server"]];
                                    $server_create_index++;
                                    $new_product_config_options_sub_serverid = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                    Db::name('product_config_options_sub')
                                        ->where('id', $new_product_config_options_sub_serverid)
                                        ->update([
                                            'linkage_level' => '0-'.$new_product_config_options_sub_eggid."-".$new_product_config_options_sub_groupid."-".$new_product_config_options_sub_serverid,
                                            'linkage_pid'=>$new_product_config_options_sub_groupid,
                                            'linkage_top_pid'=>$new_product_config_options_sub_eggid,
                                        ]);
                                    $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_serverid];
                                    Db::name('pricing')->insert($new_pricing);
                                    $new_product_config_options = [
                                            "gid"=>$product_config_groups_id,
                                            "option_name"=>"egg_type_id|版本",
                                            "option_type"=>20,
                                            "upgrade"=>1,
                                    ];
                                    $product_config_options_egg_type_id = Db::name('product_config_options')->insertGetId($new_product_config_options);
                                    Db::name('product_config_options')
                                        ->where('id', $product_config_options_egg_type_id)
                                        ->update([
                                            'linkage_level' => '0-'.$product_config_options_eggid."-".$product_config_options_egg_groupid."-".$product_config_options_egg_serverid."-".$product_config_options_egg_type_id,
                                            'linkage_pid'=>$product_config_options_egg_serverid,
                                            'linkage_top_pid'=>$product_config_options_eggid,
                                        ]);
                                    $type_total = isset($egg_type_list[$i["server"]]) ? count($egg_type_list[$i["server"]]) : 0;
                                    $type_index = 0;
                                    $new_type_sub_ids = [];
                                    foreach ($egg_type_list[$i["server"]] as $ite){ 
                                        $new_product_config_options_sub = ["config_id"=>$product_config_options_egg_type_id,"sort_order"=>$type_total - $type_index,"option_name"=>$ite["id"]."|".$ite["env"]["SERVER_VERSION"]];
                                        $type_index++;
                                        $new_product_config_options_sub_type_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                        Db::name('product_config_options_sub')
                                            ->where('id', $new_product_config_options_sub_type_id)
                                            ->update([
                                                'linkage_level' => '0-'.$new_product_config_options_sub_eggid."-".$new_product_config_options_sub_groupid."-".$new_product_config_options_sub_serverid."-".$new_product_config_options_sub_type_id,
                                                'linkage_pid'=>$new_product_config_options_sub_serverid,
                                                'linkage_top_pid'=>$new_product_config_options_sub_eggid,
                                            ]);
                                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_type_id];
                                        Db::name('pricing')->insert($new_pricing);
                                        $new_type_sub_ids[] = $new_product_config_options_sub_type_id;
                                    }
                                    // 在线模式：只有我的世界 Java版/基岩版需要选，挂在版本后面
                                    if(strpos($it["name"], "mc_") === 0 && !empty($new_type_sub_ids)){
                                        $this->rgsEnsureOnlineMode($product_config_groups_id, $product_config_options_eggid, $product_config_options_egg_groupid, $product_config_options_egg_serverid, $product_config_options_egg_type_id, $new_product_config_options_sub_eggid, $new_product_config_options_sub_groupid, $new_product_config_options_sub_serverid, $new_type_sub_ids);
                                    }
                                }
                            }
                        }
                    }
                    foreach($condition_data as $k => $v){
                        $new_product_config_options_links = [
                            "config_id"=>$product_config_options_cpuid,
                            "sub_id"=>json_encode($v["sub_id"]),
                            "relation"=>"seq",
                            "type"=>"condition"
                        ];
                        $new_relation_id = Db::name('product_config_options_links')->insertGetId($new_product_config_options_links);
                        $thia_sub_id = [];
                        for ($i = $v["memory_min"]; $i <= $v["memory_max"]; $i += 2){
                            $thia_sub_id[$product_config_options_sub_ids[$i]] = ["qty_minimum"=>"0","qty_maximum"=>"0"];
                        }
                        $new_product_config_options_links = [
                            "config_id"=>$product_config_options_memoryid,
                            "sub_id"=>json_encode($thia_sub_id),
                            "relation"=>"seq",
                            "type"=>"result",
                            "relation_id"=>$new_relation_id
                        ];
                        Db::name('product_config_options_links')->insert($new_product_config_options_links);
                        if($plan[$item]["subtype"] == "kvm"){
                            $thia_sub_id = [];
                            for ($i = $config_array[0]["net_out_min"]; $i < $v["net_out_min"]; $i += 5){
                                $thia_sub_id[$product_config_options_net_out_ids[$i]] = ["qty_minimum"=>"0","qty_maximum"=>"0"];
                            }
                            $new_product_config_options_links = [
                                "config_id"=>$product_config_options_net_outid,
                                "sub_id"=>json_encode($thia_sub_id),
                                "relation"=>"sneq",
                                "type"=>"result",
                                "relation_id"=>$new_relation_id
                            ];
                            Db::name('product_config_options_links')->insert($new_product_config_options_links);
                        }
                    }
                    if(($navigation_selection["nav_item_id"] > 0 || !empty($navigation_selection["create_mode"]))
                        && !$this->addProductToNavigation($navigation_selection, $pid, "游戏云", '{"chinese":"游戏云","chinese_tw":"遊戲雲","english":"Game server"}', $url)){
                        $navigation_warning = true;
                    }
                    $data = ["code"=>200,"msg"=>"操作完成"];
                }
            }else{
                return json(["code"=>404,"msg"=>"ID不存在"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
        }
        if($navigation_warning && isset($data["code"]) && $data["code"] === 200){
            $data["msg"] = "操作完成，但未添加前台导航，请检查本次操作的导航选择";
        }
        return json($data, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
    
    public function rcslistapi()
    {
        $is_rgpu = input("post.is_rgpu", "") === "true";
	    $servers_data = \think\Db::name('servers')  
	                ->where('type', 'rainyunrcs')  
	                ->field('hostname, password, gid')  
	                ->select();
        $plans_url = $servers_data[0]["hostname"]."/product/rcs/plans".($is_rgpu ? "?is_rgpu=true" : "");
        $plan =  $this->mh_curl($plans_url,null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        
        return json($plan, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
    
    public function rcslistproducts()
    {
        $is_rgpu = input("post.is_rgpu", "") === "true";
        $gid = \think\Db::name('servers')  
            ->where('type', 'rainyunrcs')  
                    ->value('gid');
            $productsData = \think\Db::name('products')  
                ->where('server_group', $gid)  
                ->select()  
                ->toArray();
        $productsData = array_values(array_filter($productsData, function($product) use ($is_rgpu) {
            $is_product_rgpu = isset($product["config_option2"]) && $product["config_option2"] === "rgpu";
            return $is_rgpu ? $is_product_rgpu : !$is_product_rgpu;
        }));
        $data = ["code"=>200,"data"=>$productsData];
        return json($data, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
    
    public function rcsoperateapi()
    {
        $navigation_warning = false;
        $is_rgpu = input("post.is_rgpu", "") === "true";
        $product_group_name = $is_rgpu ? "显卡云" : "云服务器";
        $product_type_marker = $is_rgpu ? "rgpu" : "";
        $product_group_lang = $is_rgpu
            ? '{"chinese":"显卡云","chinese_tw":"顯卡雲","english":"GPU Cloud"}'
            : '{"chinese":"\u4e91\u670d\u52a1\u5668","chinese_tw":"\u96f2\u670d\u52d9\u5668","english":"Cloud server"}';
	    $servers_data = \think\Db::name('servers')  
	                ->where('type', 'rainyunrcs')  
	                ->field('hostname, password, gid')  
	                ->select();
        $plans_url = $servers_data[0]["hostname"]."/product/rcs/plans".($is_rgpu ? "?is_rgpu=true" : "");
        $res = $this->mh_curl($plans_url,null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(0)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $planres = $res["data"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rcs/discount-percent?type=create",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(1)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $default = $res["data"]["default"];
        $res = $this->mh_curl($servers_data[0]["hostname"]."/product/rcs/os-templates",null,30,"GET",["Content-Type: application/json; charset=utf-8", "x-api-key: " . $servers_data[0]["password"]]);
        if($res["code"] != 200) return json(["code"=>500,"msg"=>"连接雨云失败(2)"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $os_list = $res["data"];
        $productsData = \think\Db::name('products')  
            ->where('server_group', $servers_data[0]["gid"])  
            ->select()  
            ->toArray();
        $products = [];
        foreach ($productsData as $item){
            $is_product_rgpu = isset($item["config_option2"]) && $item["config_option2"] === "rgpu";
            if($is_product_rgpu !== $is_rgpu) continue;
            $products[$item["config_option3"]] = $item;
        }
        $plan = [];
        foreach ($planres as $item){
            if($item["is_selling"]) $plan[$item["id"]] = $item;
        }
        try{
            $region_list = $this->getRegionListData();
        }catch(\Exception $e){
            return json(["code"=>500,"msg"=>"读取分区数据失败：".$e->getMessage()], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }
        $IdList = input("post.id");
        if(!$this->hasValidSelectedIds($IdList)) return json(["code"=>400,"msg"=>"请选择有效的同步套餐"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $region_names = $this->getSubmittedRegionNames();
        $unknown_regions = $this->getUnknownPlanRegions($IdList, $plan, $region_list, $region_names);
        if(count($unknown_regions) > 0) return json(["code"=>400,"msg"=>"请先填写未识别分区名称","data"=>["unknown_regions"=>$unknown_regions]], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        $navigation_selection = $this->getRequestedNavigationSelection($IdList, $products, $product_group_name);
        if($navigation_selection === false) return json(["code"=>400,"msg"=>"新商品必须选择有效的会员中心导航选项"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        foreach ($IdList as $item){
            if(isset($plan[$item])){
                $this_region = $plan[$item]["region"];
                if(isset($products[$item])){
                    if(!$this->updateUnknownRegionGroupName($products[$item]["gid"], $this_region, $plan[$item]["line"], $region_list, $region_names)){
                        return json(["code"=>500,"msg"=>"更新未知分区商品分组名称失败"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                    }
                    $istraffic = "";
                    if(isset($plan[$item]["disk_price"]["hdd"])){
                        $diskmax = 2000;
                    }else{
                        $diskmax = 250;
                    }
                    $diskim = '';
                    $this_name = '';
                    if(count($plan[$item]["disk_price"]) == 1){
                        foreach ($plan[$item]["disk_price"] as $it){
                            $disk_price = $it;
                            break;
                        }
                        $diskim = "，".$disk_price."元/g/月（最高".$diskmax."G）";
                    }else{
                        foreach ($plan[$item]["disk_price"] as $key => $val){
                            if($key=="hdd"){
                                $this_diskmax = 2000;
                                $this_name = "高速机械";
                            }else{
                                $this_diskmax = 250;
                                if($key == "ssd"){
                                    $this_name = "高速固态";
                                }else if($key == "chdd"){
                                    $this_name = "系统机械";
                                }
                            }
                            $diskim = $diskim."，".$this_name.$val."元/g/月（最高".$this_diskmax."G）";
                        }
                    }
                    $backupi = "";
                    if($plan[$item]["no_backup"] == false){
                        $backupi = "，开启备份支持0.1元/g/月";
                    }
                    $diskib = '<div><b class="text-dark">30-'.$diskmax.'</b> GB <i data-container="body" data-toggle="popover" data-placement="top" data-content="可随时弹性增减，支持多盘'.$diskim.$backupi.'"><i class="bx bx-help-circle"></i></i></div>';
                    $trafficit = "";
                    $trafficib = "";
                    if($plan[$item]["traffic_base_gb"] != 0){
                        $istraffic = " 流量型";
                        $trafficit = '<div><svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-info feather feather-cloud-rain"><line x1="16" y1="13" x2="16" y2="21"></line><line x1="8" y1="13" x2="8" y2="21"></line><line x1="12" y1="15" x2="12" y2="23"></line><path d="M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"></path></svg> 流量 </div>';
                        $traffic_plan = [];
                        foreach ($plan[$item]["traffic_price"] as $key=>$value){
                            $traffic_plan[] = $key."G ".$value."元";
                        }
                        $trafficib = '<div><b class="text-dark">'.$plan[$item]["traffic_base_gb"].'</b> GB <i data-container="body" data-toggle="popover" data-placement="top" data-content="上下行公网共同流量，每月自动叠加，次月不清零，可自定义限流，流量用完后限至5M，额外流量可按'.implode('/', $traffic_plan).'充值。"><i class="bx bx-help-circle"></i></i></div>';
                    }
                    $gpuit = "";
                    $gpuib = "";
                    $gpu_memory_size = "";
                    $gpu_memory_unit = "";
                    if($plan[$item]["gpu_memory_size"] != 0){
                        $gpuit = '<div><svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-success feather feather-airplay"><path d="M5 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-1"></path><polygon points="12 15 17 21 7 21 12 15"></polygon></svg> 显存 </div>';
                        $gpu_memory_size = $is_rgpu ? $plan[$item]["gpu_memory_size"] / 1024 : $plan[$item]["gpu_memory_size"];
                        $gpu_memory_unit = $is_rgpu ? "GB" : "MB";
                        $gpuib = '<div><b class="text-dark">'.$gpu_memory_size.'</b> '.$gpu_memory_unit.' </div>';
                    }
                    $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                    $description_html = $this->renderProductDescription($description_html, $plan[$item], $region_name, [
                        "memory_unit" => "MB",
                        "gpu_memory" => $gpu_memory_size,
                        "gpu_memory_unit" => $gpu_memory_unit,
                        "machine_name" => $region_list["machine"][$plan[$item]["machine"]] ?? $plan[$item]["machine"],
                        "disk_info" => $diskib,
                    ]);
                    $order = $plan[$item]["price"];
                    if($plan[$item]["traffic_base_gb"] != 0) $order = $order - 1;
                    $data = [
                        "name"=>$region_name." ".$plan[$item]["chinese"]." ".($region_list["machine"][$plan[$item]["machine"]]?:$plan[$item]["machine"]).$istraffic,
                        "description"=>$this->compressProductDescriptionToSingleLine($description_html),
                        "qty"=>$plan[$item]["available_stock"],
                        "host"=>'{"show":"1","modify":0,"prefix":"mcs","rule":{"upper":"1","lower":"1","num":"1","len_num":"10"}}',
                        "password"=>'{"show":"0","modify":0,"rule":{"len_num":"20","upper":"1","lower":"1","num":"1","special":"1"}}',
                        "config_option1"=>"rcs",
                        "config_option2"=>$product_type_marker,
                        "config_option3"=>$plan[$item]["id"],
                        "config_option4"=>"false",
                        "config_option5"=>"0",
                        "config_option6"=>"0",
                        "config_option7"=>"",
                        "config_option8"=>"",
                        "config_option9"=>"",
                        "config_option10"=>"",
                        "config_option11"=>"",
                        "config_option12"=>"",
                        "config_option13"=>"",
                        "config_option14"=>"1",
                        "update_time"=>time(),
                        "order"=>$order,
                        "is_truename"=>$region_list["is_truename"][$this_region],
                        "hidden"=>$plan[$item]["is_selling"]?0:1,
                    ];
                    $result = Db::name('products')
                        ->where('id', $products[$item]["id"])
                        ->update($data);
                    $ontrial_pricing = 1;
                    if($plan[$item]["cpu"] <= 2){
                        $ontrial_pricing = 1;
                    }else if($plan[$item]["cpu"] <= 4){
                        $ontrial_pricing = 8;
                    }else if($plan[$item]["cpu"] <= 8){
                        $ontrial_pricing = 14;
                    }else{
                        $ontrial_pricing = 28;
                    }
                    $data = [
                        "ontrial"=>$ontrial_pricing,
                        "monthly"=>$plan[$item]["price"]*($default["1"]?:1),//月付
                        "quarterly"=>$plan[$item]["price"]*($default["3"]?:1)*3,//季付
                        "semiannually"=>$plan[$item]["price"]*($default["6"]?:1)*6,//半年付
                        "annually"=>$plan[$item]["price"]*($default["12"]?:1)*12,//年付
                    ];
                    $result = Db::name('pricing')
                        ->where('type', "product")
                        ->where('relid', $products[$item]["id"])
                        ->update($data);
                    $getpcgidres = Db::name('product_config_links')
                        ->where('pid', $products[$item]["id"])
                        ->find();
                    $result = Db::name('product_config_options')
                        ->where('gid', $getpcgidres["gid"])
                        ->select()
                        ->toArray();
                    foreach ($os_list as $os_item){
                        $os_map[$os_item["id"]] = $os_item;
                    }
                    $this_os_map = [];
                    $with_eip_num_map = [];
                    foreach ($result as $ite){
                        $optionParts = explode("|", $ite["option_name"]);
                        if(strpos($optionParts[0], 'with_eip_num') === 0){
                            if(count($plan[$item]["ip_prices"]) == 1){
                                $getpcosid = Db::name('product_config_options_sub')
                                    ->where('config_id', $ite["id"])
                                    ->select()
                                    ->toArray();
                                foreach ($getpcosid as $it){
                                    $option_name_parts = explode("|", $it["option_name"]);
                                    $n = (int)$option_name_parts[0];
                                    if($n == 0 && !$region_list["nat"][$this_region]) {
                                        $e = Db::name('product_config_options_sub')
                                        ->where('id', $it["id"])
                                        ->delete();
                                        $e = Db::name('pricing')
                                        ->where('type', "configoptions")
                                        ->where('relid', $it["id"])
                                        ->delete();
                                    }
                                    $ip_type = $this->getDefaultRegionIpType($this_region, $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                                    $data = [
                                        "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*$n,//月付
                                        "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*$n,//季付
                                        "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*$n,//半年付
                                        "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*$n,//年付
                                    ];
                                    $e = Db::name('pricing')
                                        ->where('type', "configoptions")
                                        ->where('relid', $it["id"])
                                        ->update($data);
                                }
                            }else if(count($plan[$item]["ip_prices"]) == 2){
                                $with_eip_num_map[$optionParts[0]] = $ite["id"];
                            }
                        }else if($optionParts[0] === "os_id"){
                            $product_config_options_osid = $ite["id"];
                            $this_os_list = Db::name('product_config_options_sub')
                                ->where('config_id', $ite["id"])
                                ->select()
                                ->toArray();
                            foreach ($this_os_list as $it){
                                $option_name_parts = explode("|", $it["option_name"]);
                                $n = (int)$option_name_parts[0];
                                $this_os_map[$n] = $n;
                                $os_info = $os_map[$n] ?? [];
                                $os_name = $os_info["name"] ?? "";
                                $is_gpu_os = stripos($os_name, "gpu") !== false;
                                if($is_gpu_os && !$is_rgpu){
                                    Db::name('product_config_options_sub')
                                        ->where('id', $it["id"])
                                        ->update(['hidden' => 1]);
                                    continue;
                                }
                                if(empty($os_info["is_available"]) || ($os_info["region"] ?? null) != $this_region || ($is_rgpu && !$is_gpu_os)){
                                    Db::name('product_config_options_sub')
                                        ->where('id', $it["id"])
                                        ->update(['hidden' => 1]);
                                    continue;
                                }
                                $data = ["sort_order"=>$region_list["OsOrder"][$os_info["icon"]]?:0];
                                $e = Db::name('product_config_options_sub')
                                    ->where('id', $it["id"])
                                    ->update($data);
                            }
                        }
                        
                    }
                    if(!empty($with_eip_num_map)){
                        $ExistingIPtypes = [];
                        foreach ($with_eip_num_map as $k=>$v){
                            $getpcosid = Db::name('product_config_options_sub')
                                ->where('config_id', $v)
                                ->select()
                                ->toArray();
                            $ip_type = "";
                            if($k != "with_eip_num"){
                                $ip_type = str_replace("with_eip_num_", "", $k);
                            }
                            if($ip_type === "" && isset($plan[$item]["ip_prices"])){
                                $ip_type = $this->getDefaultRegionIpType($this_region, $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                            }
                            $optionLabel = $this->getRegionIpTypeLabel($this_region, $ip_type, $region_list);
                            $optionKey = $ip_type === "" ? "with_eip_num" : "with_eip_num_$ip_type";
                            Db::name("product_config_options")
                                ->where("id", $v)
                                ->update(["option_name" => $optionKey."|IP数量-".$optionLabel]);
                            $ExistingIPtypes[$ip_type] = $v;
                            $last_ip_n = $region_list["ip_n"][$this_region]+1;
                            if(count($getpcosid) < $last_ip_n){
                                $ip_list = [];
                                foreach ($getpcosid as $it) {
                                    $option_name_parts = explode("|", $it["option_name"]);
                                    $n = (int)$option_name_parts[0];
                                    $ip_list[$n] = $it;
                                }
                                if(!isset($ip_list[0])){
                                    $new_product_config_options_sub = ["config_id"=>$v,"option_name"=>"0|0个","sort_order"=>4];
                                    $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                    $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                    Db::name('pricing')->insert($new_pricing);
                                }
                                if(!isset($ip_list[3]) && $region_list["ip_n"][$this_region]==3){
                                    $new_product_config_options_sub = ["config_id"=>$v,"option_name"=>"3|3个","sort_order"=>3];
                                    $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                    $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                        "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*3,//月付
                                        "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*3,//季付
                                        "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*3,//半年付
                                        "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*3,//年付
                                    ];
                                    Db::name('pricing')->insert($new_pricing);
                                }
                                $getpcosid = Db::name('product_config_options_sub')
                                    ->where('config_id', $v)
                                    ->select()
                                    ->toArray();
                            }
                            
                            foreach ($getpcosid as $it){ 
                                $option_name_parts = explode("|", $it["option_name"]);
                                $n = (int)$option_name_parts[0];
                                $data = [
                                    "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*$n,//月付
                                    "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*$n,//季付
                                    "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*$n,//半年付
                                    "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*$n,//年付
                                ];
                                $e = Db::name('pricing')
                                    ->where('type', "configoptions")
                                    ->where('relid', $it["id"])
                                    ->update($data);
                            }
                        }
                        foreach ($plan[$item]["ip_prices"] as $ip_type=>$ip_price){
                            if(!isset($ExistingIPtypes[$ip_type])){
                                $new_eip_product_config_options = [
                                    "gid"=>$getpcgidres["gid"],
                                    "option_name"=>"with_eip_num_$ip_type|IP数量-".$this->getRegionIpTypeLabel($this_region, $ip_type, $region_list),
                                    "option_type"=>13,
                                    "upgrade"=>1,
                                    "qty_stage"=>1,
                                ];
                                $product_config_options_eipid = Db::name('product_config_options')->insertGetId($new_eip_product_config_options);
                                $ExistingIPtypes[$ip_type] = $product_config_options_eipid;
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"0|0个","sort_order"=>0];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                Db::name('pricing')->insert($new_pricing);
                                if(isset($region_list["ip_n"][$this_region])){
                                    if($region_list["ip_n"][$this_region]>=1){
                                        $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"1|1个","sort_order"=>1];
                                        $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                            "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1),//月付
                                            "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3,//季付
                                            "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6,//半年付
                                            "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12,//年付
                                        ];
                                        Db::name('pricing')->insert($new_pricing);
                                    }
                                    if($region_list["ip_n"][$this_region]>=2){
                                        $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"2|2个","sort_order"=>2];
                                        $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                            "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*2,//月付
                                            "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*2,//季付
                                            "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*2,//半年付
                                            "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*2,//年付
                                        ];
                                        Db::name('pricing')->insert($new_pricing);
                                    }
                                    if($region_list["ip_n"][$this_region]>=3){
                                        $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"3|3个","sort_order"=>3];
                                        $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                            "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*3,//月付
                                            "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*3,//季付
                                            "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*3,//半年付
                                            "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*3,//年付
                                        ];
                                        Db::name('pricing')->insert($new_pricing);
                                    }
                                }else{
                                        $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"1|1个","sort_order"=>1];
                                        $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                            "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1),//月付
                                            "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3,//季付
                                            "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6,//半年付
                                            "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12,//年付
                                        ];
                                        Db::name('pricing')->insert($new_pricing);
                                        $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"2|2个","sort_order"=>1];
                                        $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                        $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                            "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*2,//月付
                                            "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*2,//季付
                                            "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*2,//半年付
                                            "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*2,//年付
                                        ];
                                        Db::name('pricing')->insert($new_pricing);
                                }
                            }
                        }
                        unset($ExistingIPtypes["ipv6"]);
                        foreach ($ExistingIPtypes as $ip_type=>$config_id){
                            $other_ExistingIPtypes = $ExistingIPtypes;
                            unset($other_ExistingIPtypes[$ip_type]);
                            $senior_config_id = Db::name('product_config_options_links')
                                ->where('config_id', $config_id)
                                ->where('type', 'condition')
                                ->select()
                                ->toArray();
                            if(!$senior_config_id) {
                                $getpcosid = Db::name('product_config_options_sub')
                                    ->where('config_id', $config_id)
                                    ->select()
                                    ->toArray();
                                foreach ($getpcosid as $pcosid) {
                                    $option_name_parts = explode("|", $pcosid["option_name"]);
                                    $this_n = (int)$option_name_parts[0];
                                    $sub_id = [$pcosid["id"]=>["qty_minimum"=>"0","qty_maximum"=>"0"]];
                                    $condition = [
                                        "config_id"=>$config_id,
                                        "sub_id"=>json_encode($sub_id),
                                        "relation"=>"seq",
                                        "type"=>"condition"
                                    ];
                                    $condition_id = Db::name('product_config_options_links')->insertGetId($condition);
                                    foreach ($other_ExistingIPtypes as $other_ip_type=>$other_config_id){ 
                                        $other_getpcosid = Db::name('product_config_options_sub')
                                            ->where('config_id', $other_config_id)
                                            ->select()
                                            ->toArray();
                                        $sub_id = [];
                                        foreach($other_getpcosid as $other_pcosid) {
                                            $option_name_parts = explode("|", $other_pcosid["option_name"]);
                                            $n = (int)$option_name_parts[0];
                                            if($this_n == 0){
                                                if($n == 0) {
                                                    $sub_id[$other_pcosid["id"]] = ["qty_minimum"=>"0","qty_maximum"=>"0"];
                                                    $relation = "sneq";
                                                }
                                            }elseif($this_n == 1){
                                                if($n == ($region_list["ip_n"][$this_region]?:2)){
                                                    $sub_id[$other_pcosid["id"]]= ["qty_minimum"=>"0","qty_maximum"=>"0"];
                                                    $relation = "sneq";
                                                }
                                            }elseif($this_n >= 2){
                                                if($n <= (($region_list["ip_n"][$this_region]?:2)-$this_n)){
                                                    $sub_id[$other_pcosid["id"]]= ["qty_minimum"=>"0","qty_maximum"=>"0"];
                                                    $relation = "seq";
                                                }
                                            }
                                        }
                                        if(!empty($sub_id) && !empty($relation)){
                                            $result = [
                                                "config_id"=>$other_config_id,
                                                "sub_id"=>json_encode($sub_id),
                                                "relation"=>$relation,
                                                "type"=>"result",
                                                "relation_id"=>$condition_id
                                            ];
                                            Db::name('product_config_options_links')->insert($result);
                                        }
                                    }
                                }
                                
                            }
                        }
                    }
                    
                    foreach ($os_list as $it){
                        if($it["is_available"]){
                            if(!array_key_exists($it["id"],$this_os_map)){
                                if($it["region"] == $this_region && substr($it["name"], 0, 7) != "btpanel"){
                                    if($it["os_type"] == "windows"){
                                        if($plan[$item]["chinese"] == "KVM 入门版"){
                                            continue;
                                        }
                                    }
                                    if($it["os_type"] == "darwin"){
                                        if($plan[$item]["cpu"] < 4 || $plan[$item]["memory"] < 8192){
                                            continue;
                                        }
                                    }
                                    $is_gpu_os = stripos($it["name"], "gpu") !== false;
                                    if($is_rgpu){
                                        if(!$is_gpu_os) continue;
                                    }elseif($is_gpu_os){
                                        continue;
                                    }
                                    $new_product_config_options_sub = ["config_id"=>$product_config_options_osid,"sort_order"=>$region_list["OsOrder"][$it["icon"]]?:0,"option_name"=>$it["id"]."|".ucfirst($it["icon"])."^".$it["chinese_name"]];
                                    $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                    $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                    Db::name('pricing')->insert($new_pricing);
                                }
                            }
                        }
                    }
                    $this_os_list = Db::name('product_config_options_sub')
                        ->where('config_id', $product_config_options_osid)
                        ->select()
                        ->toArray();
                    $this_os_map_t = [];
                    foreach ($this_os_list as $it){
                        $option_name_parts = explode("|", $it["option_name"]);
                        $n = (int)$option_name_parts[0];
                        if(array_key_exists($n, $this_os_map_t)){
                            Db::name('product_config_options_sub')
                                ->where('id', $it["id"])
                                ->update(['hidden' => 1]);
                        }else{
                            $this_os_map_t[$n] = strval($n);
                        }
                    }
                    $data = ["code"=>200,"msg"=>"操作完成"];
                }else{
                    if(!$plan[$item]["is_selling"]) continue;
                    $samegid = [];
                    foreach ($plan as $key=>$value){
                        if($value['region'] == $this_region && $value['line'] == $plan[$item]["line"] && isset($products[$key])){
                            $samepid = $products[$key]["id"];
                            $gid = $products[$key]["gid"];
                            break;
                        }
                    }
                    if(empty($gid)){
                        //选定一级组
                        foreach ($products as $it){
                            $result = Db::name('product_groups')
                                ->where('id', $it["gid"])
                                ->find();
                            $firstgid = $result['gid'];
                            break;
                        }
                        if(empty($firstgid)){
                            $result = Db::name('product_groups')
                                ->where('name', $product_group_name)
                                ->find();
                            if($result){
                                $firstgid = $result["id"];
                            }else{
                                $product_first_groups = [
                                        "name"=>$product_group_name,
                                        "hidden"=>0,
                                        "create_time"=>time(),
                                        "update_time"=>time(),
                                        "is_upstream"=>0,
                                        "zjmf_api_id"=>0,
                                    ];
                                $firstgid = Db::name('product_first_groups')->insertGetId($product_first_groups);
                                if(!$firstgid){
                                    return json(["code"=>500,"msg"=>"插入数据库失败"], 500, ['Content-Type' => 'application/json; charset=UTF-8']);
                                }
                            }
                        } 
                        $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                        $product_groups = [
                                "name"=>$region_name,
                                "type"=>1,
                                "create_time"=>time(),
                                "update_time"=>time(),
                                "gid"=>$firstgid,
                                "tpl_type"=>"default",
                            ];
                        $gid = Db::name('product_groups')->insertGetId($product_groups);
                    }else{
                        $result = Db::name('product_groups')
                            ->where('id', $gid)
                            ->find();
                        $firstgid = $result['gid'];
                    }
                    if(!$this->updateUnknownRegionGroupName($gid, $this_region, $plan[$item]["line"], $region_list, $region_names)){
                        return json(["code"=>500,"msg"=>"更新未知分区商品分组名称失败"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
                    }
                    $istraffic = "";
                    if(isset($plan[$item]["disk_price"]["hdd"])){
                        $diskmax = 2000;
                    }else{
                        $diskmax = 250;
                    }
                    $diskim = '';
                    if(count($plan[$item]["disk_price"]) == 1){
                        foreach ($plan[$item]["disk_price"] as $it){
                            $disk_price = $it;
                            break;
                        }
                        $diskim = "，".$disk_price."元/g/月（最高".$diskmax."G）";
                    }
                    $backupi = "";
                    if($plan[$item]["no_backup"] == false){
                        $backupi = "，开启备份支持0.1元/g/月";
                    }
                    $diskib = '<div><b class="text-dark">30-'.$diskmax.'</b> GB <i data-container="body" data-toggle="popover" data-placement="top" data-content="可随时弹性增减，支持多盘'.$diskim.$backupi.'"><i class="bx bx-help-circle"></i></i></div>';
                    $trafficit = "";
                    $trafficib = "";
                    if($plan[$item]["traffic_base_gb"] != 0){
                        $istraffic = " 流量型";
                        $trafficit = '<div><svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-info feather feather-cloud-rain"><line x1="16" y1="13" x2="16" y2="21"></line><line x1="8" y1="13" x2="8" y2="21"></line><line x1="12" y1="15" x2="12" y2="23"></line><path d="M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"></path></svg> 流量 </div>';
                        $traffic_plan = [];
                        foreach ($plan[$item]["traffic_price"] as $key=>$value){
                            $traffic_plan[] = $key."G ".$value."元";
                        }
                        $trafficib = '<div><b class="text-dark">'.$plan[$item]["traffic_base_gb"].'</b> GB <i data-container="body" data-toggle="popover" data-placement="top" data-content="上下行公网共同流量，每月自动叠加，次月不清零，可自定义限流，流量用完后限至5M，额外流量可按'.implode('/', $traffic_plan).'充值。"><i class="bx bx-help-circle"></i></i></div>';
                    }
                    $gpuit = "";
                    $gpuib = "";
                    $gpu_memory_size = "";
                    $gpu_memory_unit = "";
                    if($plan[$item]["gpu_memory_size"] != 0){
                        $gpuit = '<div><svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-success feather feather-airplay"><path d="M5 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-1"></path><polygon points="12 15 17 21 7 21 12 15"></polygon></svg> 显存 </div>';
                        $gpu_memory_size = $is_rgpu ? $plan[$item]["gpu_memory_size"] / 1024 : $plan[$item]["gpu_memory_size"];
                        $gpu_memory_unit = $is_rgpu ? "GB" : "MB";
                        $gpuib = '<div><b class="text-dark">'.$gpu_memory_size.'</b> '.$gpu_memory_unit.' </div>';
                    }
                    $region_name = $this->getRegionDisplayName($this_region, $plan[$item]["line"], $region_list, $region_names);
                    $description_html = $this->renderProductDescription($description_html, $plan[$item], $region_name, [
                        "memory_unit" => "MB",
                        "gpu_memory" => $gpu_memory_size,
                        "gpu_memory_unit" => $gpu_memory_unit,
                        "machine_name" => $region_list["machine"][$plan[$item]["machine"]] ?? $plan[$item]["machine"],
                        "disk_info" => $diskib,
                    ]);
                    $order = $plan[$item]["price"];
                    if($plan[$item]["traffic_base_gb"] != 0) $order = $order - 1;
                    $new_products = [
                            "type"=>"cloud",
                            "gid"=>$gid,
                            "name"=>$region_name." ".$plan[$item]["chinese"]." ".($region_list["machine"][$plan[$item]["machine"]]?:$plan[$item]["machine"]).$istraffic,
                            "description"=>$this->compressProductDescriptionToSingleLine($description_html),
                            "host"=>'{"show":"1","modify":0,"prefix":"mcs","rule":{"upper":"1","lower":"1","num":"1","len_num":"10"}}',
                            "is_domain"=>0,
                            "hidden"=>0,
                            "password"=>'{"show":"0","modify":0,"rule":{"len_num":"20","upper":"1","lower":"1","num":"1","special":"1"}}',
                            "show_domain_options"=>0,
                            "welcome_email"=>0,
                            "stock_control"=>1,
                            "qty"=>$plan[$item]["available_stock"],
                            "prorata_billing"=>0,
                            "prorata_date"=>0,
                            "prorata_charge_next_month"=>0,
                            "pay_type"=>'{"pay_type":"recurring","pay_hour_cycle":"720","pay_day_cycle":"30","pay_ontrial_status":1,"pay_ontrial_cycle":"1","pay_ontrial_num":"999","pay_ontrial_condition":["email","phone","realname"],"pay_ontrial_cycle_type":"day","pay_ontrial_num_rule":"1","clientscount_rule":"0"}',
                            "pay_method"=>'prepayment',
                            "allow_qty"=>1,
                            "server_type"=>"",
                            "auto_setup"=>"payment",
                            "server_group"=>$servers_data[0]["gid"],
                            "config_option1"=>"rcs",
                            "config_option2"=>$product_type_marker,
                            "config_option3"=>$plan[$item]["id"],
                            "config_option4"=>"false",
                            "config_option5"=>"0",
                            "config_option6"=>"0",
                            "config_option7"=>"",
                            "config_option8"=>"",
                            "config_option9"=>"",
                            "config_option10"=>"",
                            "config_option11"=>"",
                            "config_option12"=>"",
                            "config_option13"=>"",
                            "config_option14"=>"1",
                            "config_option15"=>"",
                            "config_option16"=>"",
                            "config_option17"=>"",
                            "config_option18"=>"",
                            "config_option19"=>"",
                            "config_option20"=>"",
                            "config_option21"=>"",
                            "config_option22"=>"",
                            "config_option23"=>"",
                            "config_option24"=>"",
                            "billing_cycle_upgrade"=>"",
                            "overages_enabled"=>"",
                            "affiliate_pay_type"=>"default",
                            "create_time"=>time(),
                            "update_time"=>time(),
                            "auto_create_config_options"=>0,
                            "groupid"=>3,
                            "api_type"=>"normal",
                            "app_type"=>"",
                            "location_version"=>0,
                            "upstream_version"=>0,
                            "is_truename"=>$region_list["is_truename"][$this_region]?:true,
                            "product_shopping_url"=>"",
                            "product_group_url"=>"",
                            "cancel_control"=>1,
                            "order"=>$order,
                            "upstream_auto_setup"=>"",
                            "upstream_cycle"=>"",
                        ];
                    $pid = Db::name('products')->insertGetId($new_products);
                    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) || (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https');
                    $protocol = $isHttps ? 'https://' : 'http://';
                    $url = $protocol . $_SERVER['HTTP_HOST'];
                    $data = [
                        "product_shopping_url"=>$url."/cart?action=configureproduct&amp;pid=".$pid,
                        "product_group_url"=>$url."/cart?gid=".$gid."&amp;fid=".$firstgid,
                    ];
                    $products[$plan[$item]["id"]] = array_merge($new_products,$data,["id"=>$pid]);
                    $result = Db::name('products')
                        ->where('id', $pid)
                        ->update($data);
                    $ontrial_pricing = 1;
                    if($plan[$item]["cpu"] <= 2){
                        $ontrial_pricing = 1;
                    }else if($plan[$item]["cpu"] <= 4){
                        $ontrial_pricing = 8;
                    }else if($plan[$item]["cpu"] <= 8){
                        $ontrial_pricing = 14;
                    }else{
                        $ontrial_pricing = 28;
                    }
                    $new_pricing = ["type"=>"product","currency"=>1,"relid"=>$pid,
                        "ontrial"=>$ontrial_pricing,//试用
                        "monthly"=>$plan[$item]["price"]*($default["1"]?:1),//月付
                        "quarterly"=>$plan[$item]["price"]*($default["3"]?:1)*3,//季付
                        "semiannually"=>$plan[$item]["price"]*($default["6"]?:1)*6,//半年付
                        "annually"=>$plan[$item]["price"]*($default["12"]?:1)*12,//年付
                        "onetime"=>-1.00,"hour"=>-1.00,"day"=>-1.00,"biennially"=>-1.00,"triennially"=>-1.00,"fourly"=>-1.00,"fively"=>-1.00,"sixly"=>-1.00,"sevenly"=>-1.00,"eightly"=>-1.00,"ninely"=>-1.00,"tenly"=>-1.00,
                        
                    ];
                    Db::name('pricing')->insert($new_pricing);
                    $new_product_config_groups = [
                        "name"=>$new_products["name"],
                        "description"=>$new_products["name"],
                    ];
                    $product_config_groups_id = Db::name('product_config_groups')->insertGetId($new_product_config_groups);
                    $new_product_config_links = [
                        "gid"=>$product_config_groups_id,
                        "pid"=>$pid,
                    ];
                    $product_config_links_id = Db::name('product_config_links')->insertGetId($new_product_config_links);
                    $new_eip_product_config_options = [
                        "gid"=>$product_config_groups_id,
                        "option_name"=>"with_eip_num|IP数量",
                        "option_type"=>13,
                        "upgrade"=>1,
                        "qty_stage"=>1,
                    ];
                    $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                    if($ip_type !== "") $new_eip_product_config_options["option_name"] = "with_eip_num_$ip_type|IP数量-".$this->getRegionIpTypeLabel($plan[$item]["region"], $ip_type, $region_list);
                    $product_config_options_eipid = Db::name('product_config_options')->insertGetId($new_eip_product_config_options);
                    $new_os_product_config_options = [
                        "gid"=>$product_config_groups_id,
                        "option_name"=>"os_id|操作系统",
                        "option_type"=>5,
                        "upgrade"=>0,
                        "qty_stage"=>1,
                    ];
                    $product_config_options_osid = Db::name('product_config_options')->insertGetId($new_os_product_config_options);
                    if($region_list["nat"][$this_region]){
                        if($plan[$item]["ip_prices"][""] >=80){
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"0|0个(NAT,10端口)","sort_order"=>0];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                            Db::name('pricing')->insert($new_pricing);
                        }else{
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"0|0个(NAT,10端口)","sort_order"=>4];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                            Db::name('pricing')->insert($new_pricing);
                        }
                    }
                    if(isset($region_list["ip_n"][$this_region])){
                        if($region_list["ip_n"][$this_region]>=1){
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"1|1个","sort_order"=>1];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1),//月付
                                "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3,//季付
                                "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6,//半年付
                                "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12,//年付
                            ];
                            Db::name('pricing')->insert($new_pricing);
                        }
                        if($region_list["ip_n"][$this_region]>=2){
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"2|2个","sort_order"=>2];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*2,//月付
                                "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*2,//季付
                                "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*2,//半年付
                                "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*2,//年付
                            ];
                            Db::name('pricing')->insert($new_pricing);
                        }
                        if($region_list["ip_n"][$this_region]>=3){
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"3|3个","sort_order"=>3];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $ip_type = $this->getDefaultRegionIpType($plan[$item]["region"], $plan[$item]["line"], $plan[$item]["ip_prices"], $region_list);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                "monthly"=>$plan[$item]["ip_prices"][$ip_type]*($default["1"]?:1)*3,//月付
                                "quarterly"=>$plan[$item]["ip_prices"][$ip_type]*($default["3"]?:1)*3*3,//季付
                                "semiannually"=>$plan[$item]["ip_prices"][$ip_type]*($default["6"]?:1)*6*3,//半年付
                                "annually"=>$plan[$item]["ip_prices"][$ip_type]*($default["12"]?:1)*12*3,//年付
                            ];
                            Db::name('pricing')->insert($new_pricing);
                        }
                    }else{
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"1|1个","sort_order"=>1];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                "monthly"=>$plan[$item]["ip_prices"][""]*($default["1"]?:1),//月付
                                "quarterly"=>$plan[$item]["ip_prices"][""]*($default["3"]?:1)*3,//季付
                                "semiannually"=>$plan[$item]["ip_prices"][""]*($default["6"]?:1)*6,//半年付
                                "annually"=>$plan[$item]["ip_prices"][""]*($default["12"]?:1)*12,//年付
                            ];
                            Db::name('pricing')->insert($new_pricing);
                            $new_product_config_options_sub = ["config_id"=>$product_config_options_eipid,"option_name"=>"2|2个","sort_order"=>1];
                            $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                            $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id,
                                "monthly"=>$plan[$item]["ip_prices"][""]*($default["1"]?:1)*2,//月付
                                "quarterly"=>$plan[$item]["ip_prices"][""]*($default["3"]?:1)*3*2,//季付
                                "semiannually"=>$plan[$item]["ip_prices"][""]*($default["6"]?:1)*6*2,//半年付
                                "annually"=>$plan[$item]["ip_prices"][""]*($default["12"]?:1)*12*2,//年付
                            ];
                            Db::name('pricing')->insert($new_pricing);
                    }
                    foreach ($os_list as $it){
                        if($it["is_available"]){
                            if($it["region"] == $this_region && substr($it["name"], 0, 7) != "btpanel"){
                                if($it["os_type"] == "windows"){
                                    if($plan[$item]["chinese"] == "KVM 入门版"){
                                        continue;
                                    }
                                }
                                if($it["os_type"] == "darwin"){
                                    if($plan[$item]["cpu"] < 4 || $plan[$item]["memory"] < 8192){
                                        continue;
                                    }
                                }
                                $is_gpu_os = stripos($it["name"], "gpu") !== false;
                                if($is_rgpu){
                                    if(!$is_gpu_os) continue;
                                }elseif($is_gpu_os){
                                    continue;
                                }
                                $new_product_config_options_sub = ["config_id"=>$product_config_options_osid,"sort_order"=>$region_list["OsOrder"][$it["icon"]]?:0,"option_name"=>$it["id"]."|".ucfirst($it["icon"])."^".$it["chinese_name"]];
                                $new_product_config_options_sub_id = Db::name('product_config_options_sub')->insertGetId($new_product_config_options_sub);
                                $new_pricing = ["type"=>"configoptions","currency"=>1,"relid"=>$new_product_config_options_sub_id];
                                Db::name('pricing')->insert($new_pricing);
                            }
                        }
                    }
                    if(($navigation_selection["nav_item_id"] > 0 || !empty($navigation_selection["create_mode"]))
                        && !$this->addProductToNavigation($navigation_selection, $pid, $product_group_name, $product_group_lang, $url)){
                        $navigation_warning = true;
                    }
                    $data = ["code"=>200,"msg"=>"操作完成"];
                }
            }else{
                return json(["code"=>404,"msg"=>"ID不存在"], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
        }
        if($navigation_warning && isset($data["code"]) && $data["code"] === 200){
            $data["msg"] = "操作完成，但未添加前台导航，请检查本次操作的导航选择";
        }
        return json($data, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }
    
    // 同步时使用的配置项缓存，避免逐条查询数据库
    private $_eggCache = null;

    /**
     * 在线模式选项：跟在版本（联动最后一级）后面，只给我的世界 Java版/基岩版
     * 一个版本子项对应一个在线模式选项，$type_sub_ids 为该服务端下所有（未隐藏的）版本子项ID
     */
    private function rgsEnsureOnlineMode($gid, $egg_option_id, $group_option_id, $server_option_id, $type_option_id, $group_sub_id, $egg_sub_id, $server_sub_id, $type_sub_ids)
    {
        if(empty($type_sub_ids)) return;

        // 一次读出该版本选项下已有的在线模式选项及其子项，建立 版本子项ID => 在线模式选项 映射
        $online_opt_by_type_sub = [];
        $candidate_opts = Db::name('product_config_options')
            ->where('linkage_pid', $type_option_id)
            ->where('option_name', 'like', 'online_mode|%')
            ->select()
            ->toArray();
        if(!empty($candidate_opts)){
            $candidate_ids = [];
            $candidate_by_id = [];
            foreach ($candidate_opts as $candidate_opt){
                $candidate_ids[] = $candidate_opt["id"];
                $candidate_by_id[$candidate_opt["id"]] = $candidate_opt;
            }
            $candidate_subs = Db::name('product_config_options_sub')
                ->whereIn('config_id', $candidate_ids)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($candidate_subs as $candidate_sub){
                $linked_type_sub_id = (string)$candidate_sub["linkage_pid"];
                if(!isset($online_opt_by_type_sub[$linked_type_sub_id])){
                    $online_opt_by_type_sub[$linked_type_sub_id] = $candidate_by_id[$candidate_sub["config_id"]];
                }
            }
            // 重复的或没有关联版本子项的在线模式选项直接删除
            foreach ($candidate_opts as $candidate_opt){
                $keep = false;
                foreach ($online_opt_by_type_sub as $kept_opt){
                    if((string)$kept_opt["id"] === (string)$candidate_opt["id"]){
                        $keep = true;
                        break;
                    }
                }
                if($keep) continue;
                $this->rgsDeleteConfigOption($candidate_opt["id"]);
            }
        }

        $online_defs = [
            ["true|在线模式(正版)", 0],
            ["false|离线模式", 1],
        ];
        foreach ($type_sub_ids as $type_sub_id){
            $linked_key = (string)$type_sub_id;
            if(isset($online_opt_by_type_sub[$linked_key])){
                // 已存在：选项类型必须是联动类型(20)，否则前台会把所有副本平铺渲染出来
                $online_opt_id = $online_opt_by_type_sub[$linked_key]["id"];
                if((int)$online_opt_by_type_sub[$linked_key]["option_type"] !== 20){
                    Db::name('product_config_options')
                        ->where('id', $online_opt_id)
                        ->update(['option_type' => 20]);
                }
                // 确保两个子项都在
                $exist_subs = [];
                $online_subs = Db::name('product_config_options_sub')
                    ->where('config_id', $online_opt_id)
                    ->select()
                    ->toArray();
                foreach ($online_subs as $online_sub){
                    $exist_subs[$online_sub["option_name"]] = true;
                }
            }else{
                $online_opt_id = Db::name('product_config_options')->insertGetId([
                    "gid"=>$gid,
                    "option_name"=>"online_mode|在线模式",
                    "option_type"=>20,
                    "upgrade"=>1,
                    "notes"=>"如果您选择了离线模式并且不安装登陆插件，则有可能会被机器人入侵",
                ]);
                Db::name('product_config_options')
                    ->where('id', $online_opt_id)
                    ->update([
                        'linkage_level' => '0-'.$egg_option_id.'-'.$group_option_id.'-'.$server_option_id.'-'.$type_option_id.'-'.$online_opt_id,
                        'linkage_pid' => $type_option_id,
                        'linkage_top_pid' => $egg_option_id,
                    ]);
                $exist_subs = [];
            }
            foreach ($online_defs as $online_def){
                if(isset($exist_subs[$online_def[0]])) continue;
                $online_sub_id = Db::name('product_config_options_sub')->insertGetId([
                    "config_id"=>$online_opt_id,
                    "option_name"=>$online_def[0],
                    "sort_order"=>$online_def[1],
                ]);
                Db::name('product_config_options_sub')
                    ->where('id', $online_sub_id)
                    ->update([
                        'linkage_level' => '0-'.$group_sub_id.'-'.$egg_sub_id.'-'.$server_sub_id.'-'.$type_sub_id.'-'.$online_sub_id,
                        'linkage_pid' => $type_sub_id,
                        'linkage_top_pid' => $group_sub_id,
                    ]);
                Db::name('pricing')->insert([
                    "type"=>"configoptions",
                    "currency"=>1,
                    "relid"=>$online_sub_id,
                ]);
            }
        }
    }

    /**
     * 服务端排序值：按 egg_server 的 order 升序，order<=0 视为未设置（排到最后）
     */
    private function rgsServerSortOrder($order, $index = 0)
    {
        $order = intval($order);
        if($order > 0) return $order;
        return 900000 + intval($index);
    }

    /**
     * 加载指定配置组的所有选项、子项与价格到内存
     */
    private function rgsLoadEggCache($gid, $options = null)
    {
        if($options === null){
            $options = Db::name('product_config_options')
                ->where('gid', $gid)
                ->select()
                ->toArray();
        }
        $option_ids = [];
        $options_by_id = [];
        $options_by_pid = [];
        foreach ($options as $opt){
            $option_ids[] = $opt["id"];
            $options_by_id[$opt["id"]] = $opt;
            if(!empty($opt["linkage_pid"])) $options_by_pid[$opt["linkage_pid"]][] = $opt;
        }
        $subs = [];
        if(!empty($option_ids)){
            $subs = Db::name('product_config_options_sub')
                ->whereIn('config_id', $option_ids)
                ->order('id', 'asc')
                ->select()
                ->toArray();
        }
        $subs_by_option = [];
        $subs_by_linkage = [];
        $subs_by_id = [];
        $sub_ids = [];
        foreach ($subs as $sub){
            $subs_by_option[$sub["config_id"]][] = $sub;
            $subs_by_id[$sub["id"]] = $sub;
            $sub_ids[] = $sub["id"];
            if(!empty($sub["linkage_pid"])) $subs_by_linkage[$sub["linkage_pid"]][] = $sub;
        }
        $pricing_by_relid = [];
        if(!empty($sub_ids)){
            $pricing_rows = Db::name('pricing')
                ->where('type', 'configoptions')
                ->whereIn('relid', $sub_ids)
                ->select()
                ->toArray();
            foreach ($pricing_rows as $row){
                $pricing_by_relid[$row["relid"]] = $row;
            }
        }
        $this->_eggCache = [
            "options" => $options,
            "options_by_id" => $options_by_id,
            "options_by_pid" => $options_by_pid,
            "subs_by_option" => $subs_by_option,
            "subs_by_linkage" => $subs_by_linkage,
            "subs_by_id" => $subs_by_id,
            "pricing_by_relid" => $pricing_by_relid,
        ];
    }

    /**
     * 读取某个选项的子项（优先走缓存）
     */
    private function rgsEggSubsOfOption($option_id)
    {
        if($this->_eggCache !== null){
            return isset($this->_eggCache["subs_by_option"][$option_id]) ? $this->_eggCache["subs_by_option"][$option_id] : [];
        }
        return Db::name('product_config_options_sub')
            ->where('config_id', $option_id)
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 读取挂载在某个子项下的子项（优先走缓存）
     */
    private function rgsEggSubsByLinkage($linkage_pid)
    {
        if($this->_eggCache !== null){
            return isset($this->_eggCache["subs_by_linkage"][$linkage_pid]) ? $this->_eggCache["subs_by_linkage"][$linkage_pid] : [];
        }
        return Db::name('product_config_options_sub')
            ->where('linkage_pid', $linkage_pid)
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 读取下级选项（优先走缓存）
     */
    private function rgsEggChildOptions($parent_id, $namePrefix)
    {
        if($this->_eggCache !== null){
            $list = isset($this->_eggCache["options_by_pid"][$parent_id]) ? $this->_eggCache["options_by_pid"][$parent_id] : [];
        }else{
            $list = Db::name('product_config_options')
                ->where('linkage_pid', $parent_id)
                ->select()
                ->toArray();
        }
        $result = [];
        foreach ($list as $opt){
            if($namePrefix === "" || strpos($opt["option_name"], $namePrefix) === 0){
                $result[] = $opt;
            }
        }
        return $result;
    }

    /**
     * 从缓存中移除子项
     */
    private function rgsCacheRemoveSub($sub_id)
    {
        if($this->_eggCache === null || !isset($this->_eggCache["subs_by_id"][$sub_id])) return;
        $sub = $this->_eggCache["subs_by_id"][$sub_id];
        unset($this->_eggCache["subs_by_id"][$sub_id]);
        $config_id = $sub["config_id"];
        if(isset($this->_eggCache["subs_by_option"][$config_id])){
            foreach ($this->_eggCache["subs_by_option"][$config_id] as $k => $v){
                if((string)$v["id"] === (string)$sub_id){
                    unset($this->_eggCache["subs_by_option"][$config_id][$k]);
                    break;
                }
            }
            $this->_eggCache["subs_by_option"][$config_id] = array_values($this->_eggCache["subs_by_option"][$config_id]);
        }
        $pid = $sub["linkage_pid"];
        if(!empty($pid) && isset($this->_eggCache["subs_by_linkage"][$pid])){
            foreach ($this->_eggCache["subs_by_linkage"][$pid] as $k => $v){
                if((string)$v["id"] === (string)$sub_id){
                    unset($this->_eggCache["subs_by_linkage"][$pid][$k]);
                    break;
                }
            }
            $this->_eggCache["subs_by_linkage"][$pid] = array_values($this->_eggCache["subs_by_linkage"][$pid]);
        }
    }

    /**
     * 从缓存中移除选项
     */
    private function rgsCacheRemoveOption($option_id)
    {
        if($this->_eggCache === null) return;
        if(isset($this->_eggCache["options_by_id"][$option_id])){
            $opt = $this->_eggCache["options_by_id"][$option_id];
            unset($this->_eggCache["options_by_id"][$option_id]);
            $pid = $opt["linkage_pid"];
            if(!empty($pid) && isset($this->_eggCache["options_by_pid"][$pid])){
                foreach ($this->_eggCache["options_by_pid"][$pid] as $k => $v){
                    if((string)$v["id"] === (string)$option_id){
                        unset($this->_eggCache["options_by_pid"][$pid][$k]);
                        break;
                    }
                }
                $this->_eggCache["options_by_pid"][$pid] = array_values($this->_eggCache["options_by_pid"][$pid]);
            }
        }
        unset($this->_eggCache["subs_by_option"][$option_id]);
    }

    /**
     * 写入配置项价格（值未变化则不写库）
     */
    private function rgsSaveSubPricing($sub_id, $data)
    {
        $cur = null;
        if($this->_eggCache !== null && isset($this->_eggCache["pricing_by_relid"][$sub_id])){
            $cur = $this->_eggCache["pricing_by_relid"][$sub_id];
        }
        if($cur){
            $need_update = false;
            foreach ($data as $k => $v){
                if(!isset($cur[$k]) || abs((float)$cur[$k] - (float)$v) > 0.001){
                    $need_update = true;
                    break;
                }
            }
            if(!$need_update) return;
            Db::name('pricing')
                ->where('id', $cur["id"])
                ->update($data);
            $this->_eggCache["pricing_by_relid"][$sub_id] = array_merge($cur, $data);
            return;
        }
        $data["type"] = "configoptions";
        $data["currency"] = 1;
        $data["relid"] = $sub_id;
        $insert_id = Db::name('pricing')->insertGetId($data);
        $data["id"] = $insert_id;
        if($this->_eggCache !== null){
            $this->_eggCache["pricing_by_relid"][$sub_id] = $data;
        }
    }

    /**
     * 删除配置项子项及其价格
     */
    private function rgsDeleteConfigOptionSub($sub_id)
    {
        Db::name('pricing')
            ->where('type', 'configoptions')
            ->where('relid', $sub_id)
            ->delete();
        Db::name('product_config_options_sub')
            ->where('id', $sub_id)
            ->delete();
        $this->rgsCacheRemoveSub($sub_id);
    }

    /**
     * 递归删除配置项（含子项、下级选项与价格）
     */
    private function rgsCollectTreeForDelete($option_id, &$option_ids, &$sub_ids)
    {
        if(isset($option_ids[$option_id])) return;
        $option_ids[$option_id] = $option_id;
        foreach ($this->rgsEggSubsOfOption($option_id) as $sub){
            $sub_ids[$sub["id"]] = $sub["id"];
        }
        foreach ($this->rgsEggChildOptions($option_id, "") as $child){
            $this->rgsCollectTreeForDelete($child["id"], $option_ids, $sub_ids);
        }
    }

    private function rgsDeleteConfigOption($option_id)
    {
        $option_ids = [];
        $sub_ids = [];
        $this->rgsCollectTreeForDelete($option_id, $option_ids, $sub_ids);

        if(!empty($sub_ids)){
            foreach (array_chunk(array_values($sub_ids), 500) as $chunk){
                Db::name('pricing')
                    ->where('type', 'configoptions')
                    ->whereIn('relid', $chunk)
                    ->delete();
                Db::name('product_config_options_sub')
                    ->whereIn('id', $chunk)
                    ->delete();
            }
            foreach (array_keys($sub_ids) as $sid){
                $this->rgsCacheRemoveSub($sid);
            }
        }
        if(!empty($option_ids)){
            foreach (array_chunk(array_values($option_ids), 500) as $chunk){
                Db::name('product_config_options')
                    ->whereIn('id', $chunk)
                    ->delete();
            }
            foreach (array_keys($option_ids) as $oid){
                $this->rgsCacheRemoveOption($oid);
            }
        }
    }

    /**
     * 查找某个游戏分类子项对应的 egg_group 选项（可能有重复）
     */
    private function rgsFindEggGroupOptions($egg_option_id, $group_sub_id)
    {
        $result = [];
        foreach ($this->rgsEggChildOptions($egg_option_id, 'egg_group|') as $opt){
            foreach ($this->rgsEggSubsOfOption($opt["id"]) as $sub){
                $level = explode("-", $sub["linkage_level"]);
                if(isset($level[1]) && (string)$level[1] === (string)$group_sub_id){
                    $result[] = $opt;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * 查找某个游戏子项对应的 egg_server 选项（可能有重复）
     */
    private function rgsFindEggServerOptions($group_option_id, $egg_sub_id)
    {
        $result = [];
        foreach ($this->rgsEggChildOptions($group_option_id, 'egg_server|') as $opt){
            foreach ($this->rgsEggSubsOfOption($opt["id"]) as $sub){
                $level = explode("-", $sub["linkage_level"]);
                if(isset($level[2]) && (string)$level[2] === (string)$egg_sub_id){
                    $result[] = $opt;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * 查找某个服务端子项对应的 egg_type_id 选项（可能有重复）
     */
    private function rgsFindEggTypeOptions($server_option_id, $server_sub_id)
    {
        $result = [];
        foreach ($this->rgsEggChildOptions($server_option_id, 'egg_type_id|') as $opt){
            foreach ($this->rgsEggSubsOfOption($opt["id"]) as $sub){
                $level = explode("-", $sub["linkage_level"]);
                if(isset($level[3]) && (string)$level[3] === (string)$server_sub_id){
                    $result[] = $opt;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * 统计配置项下的子项数量
     */
    private function rgsConfigOptionSubCount($option_id)
    {
        return (int)count($this->rgsEggSubsOfOption($option_id));
    }

    /**
     * 分类子项的完整度（用于去重时保留内容最完整的一支）
     */
    private function rgsEggGroupSubCount($egg_option_id, $group_sub_id)
    {
        $max = -1;
        foreach ($this->rgsFindEggGroupOptions($egg_option_id, $group_sub_id) as $opt){
            $c = $this->rgsConfigOptionSubCount($opt["id"]);
            if($c > $max) $max = $c;
        }
        return $max;
    }

    /**
     * 游戏子项的完整度（用于去重时保留内容最完整的一支）
     */
    private function rgsEggServerSubCount($group_option_id, $egg_sub_id)
    {
        $max = -1;
        foreach ($this->rgsFindEggServerOptions($group_option_id, $egg_sub_id) as $opt){
            $c = $this->rgsConfigOptionSubCount($opt["id"]);
            if($c > $max) $max = $c;
        }
        return $max;
    }

    /**
     * 服务端子项的完整度（用于去重时保留内容最完整的一支）
     */
    private function rgsEggTypeSubCount($server_option_id, $server_sub_id)
    {
        $max = -1;
        foreach ($this->rgsFindEggTypeOptions($server_option_id, $server_sub_id) as $opt){
            $c = $this->rgsConfigOptionSubCount($opt["id"]);
            if($c > $max) $max = $c;
        }
        return $max;
    }
    
    public function mh_curl($url = "", $data = [], $timeout = 30, $request = "POST", $header = [])
{
	$curl = curl_init();
	if ($request == "GET") {
		$s = "";
		if (!empty($data)) {
			foreach ($data as $k => $v) {
				$s .= $k . "=" . urlencode($v) . "&";
			}
		}
		if ($s) {
			$s = "?" . trim($s, "&");
		}
		curl_setopt($curl, CURLOPT_URL, $url . $s);
	} else {
		curl_setopt($curl, CURLOPT_URL, $url);
	}
	curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($curl, CURLOPT_USERAGENT, "MoHua");
	curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($curl, CURLOPT_HEADER, 0);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
	if (strtoupper($request) == "GET") {
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_HTTPGET, 1);
	}
	if (strtoupper($request) == "POST" || strtoupper($request) == "PATCH") {
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_POST, 1);
		if (is_array($data)) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
		} else {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		}
	}
	if (strtoupper($request) == "PUT" || strtoupper($request) == "DELETE") {
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($request));
		if (is_array($data)) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
		} else {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		}
	}
	if (!empty($header)) {
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
	}
	$res = curl_exec($curl);
	$error = curl_error($curl);
	$info = curl_getinfo($curl);
	curl_close($curl);
	if (!empty($error)) {
		return ["status" => 500, "message" => "CURL ERROR:" . $error];
	}
	return json_decode($res, true);
}
}