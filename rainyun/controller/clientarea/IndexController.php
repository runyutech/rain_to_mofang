<?php
namespace addons\rainyun\controller\clientarea;

use app\home\controller\PluginHomeBaseController;
use think\Db;
use think\Session;
class IndexController extends PluginHomeBaseController
{
    public function whitelist()
    {
        $this->assign('Title','域名过白');
        return $this->fetch('/whitelist');
    }
    
    public function whitelistapi()
    {
        header('Content-type: application/json; charset=UTF-8');
        $uid = session('user_id');
        if(empty($uid)) exit(json_encode(["code"=>400,"msg"=>"获取不到UID，请刷新页面重试"]));
        if ($_SERVER['REQUEST_METHOD'] == 'POST'){
            $domain = trim((string)($_POST["domain"] ?? ""));
            $region = trim((string)($_POST["region"] ?? ""));
            if($domain === "") exit(json_encode(["code"=>400,"msg"=>"请输入域名"]));
            if($region === "") exit(json_encode(["code"=>400,"msg"=>"请选择区域"]));
            $payload = ["domain" => $domain, "region" => $region];
            if($region === "cn-sy1"){
                // 湖北十堰需要额外提交服务器 IP 与用途
                $ip = trim((string)($_POST["ip"] ?? ""));
                $purpose = trim((string)($_POST["purpose"] ?? ""));
                if($ip === "") exit(json_encode(["code"=>400,"msg"=>"请输入服务器 IP"]));
                if(!filter_var($ip, FILTER_VALIDATE_IP)) exit(json_encode(["code"=>400,"msg"=>"服务器 IP 格式不正确"]));
                if($purpose === "") exit(json_encode(["code"=>400,"msg"=>"请输入用途"]));
                $purposeTooLong = function_exists("mb_strlen")
                    ? mb_strlen($purpose, "UTF-8") > 500
                    : strlen($purpose) > 1500;
                if($purposeTooLong) exit(json_encode(["code"=>400,"msg"=>"用途内容过长"]));
                $payload["ip"] = $ip;
                $payload["purpose"] = $purpose;
            }
        	$servers_data = Db::name('servers')  
        	            ->where('type', 'rainyunrcs')  
        	            ->field('hostname, password, gid')  
        	            ->select();
            if(empty($servers_data)) exit(json_encode(["code"=>500,"msg"=>"未找到雨云服务器配置"]));
            $res = $this->mh_curl($servers_data[0]["hostname"]."/product/domain/whitelist",json_encode($payload),30,"POST",["Content-Type: application/json; charset=utf-8", "x-api-key: " . aesPasswordDecode($servers_data[0]["password"])]);
            if(isset($res["code"]) && $res["code"] == 200){
                $data = [  
                    'uid' => $uid,  
                    'domain' => $domain,  
                    'region' => $region,  
                    'add_time' => time(),  
                    'status' => 1,  
                ]; 
                $result = Db::name('whitelist')->insert($data);
            }
            echo json_encode($res);
            exit;
        }else{
            exit(json_encode(["code"=>404,"msg"=>"找不到请求的对象/资源"]));
        }
        return;
    }
    public function test(){
        print_r(cmf_get_current_user_id());
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