{php}
session_start();
use think\Session;
session('user_id', $Userinfo["user"]["id"]);
$whitelist = Db::name('whitelist')
    ->where('uid', $Userinfo['user']['id'])
    ->select()
    ->toArray();
$region_data_file = 'plugins/addons/rainyun/data/data.json';
$region_data = is_file($region_data_file) ? json_decode(file_get_contents($region_data_file), true) : null;
$auto_white = is_array($region_data) ? ($region_data["auto_white"] ?? "") : "";
if(empty($auto_white)) exit("系统出错，请提交工单联系客服");
{/php}
<section class="admin-main">
    <div class="card-body">
        <div class="tbheader">
            <h5 class="mb-1 mt-1 border-left-primary border-left-2 pl-1"> 添加域名白名单 </h5>
            <div class="row">
                <div class="col-xl-7">
                    <div role="group" class="input-group input-group-merge mb-2">
                        <input type="text" placeholder="已备案域名（无http://或https://开头）" class="form-control" id="domain">
                        <div class="input-group-append">
                            <div class="dropdown btn-group" id="">
                                <button class="btn btn-primary dropdown-toggle" type="button" id="dropdownButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"></button>
                                <ul role="menu" tabindex="-1" class="dropdown-menu" aria-labelledby="" style="" id="region-item">
                                    {foreach $auto_white as $k => $v}
                                    {if !$v}
                                    <li role="presentation"><a role="menuitem" href="#" target="_self" class="dropdown-item" data-region="{$k}"> {$region_data.name[$k][""]} </a></li>
                                    {/if}
                                    {/foreach}
                                </ul>
                            </div>
                        </div>
                        <div class="input-group-append"><button type="button" class="btn btn-outline-primary" style="position: relative;" id="_add_"><i class="bx bx-add-to-queue"></i> 添加域名过白 </button></div>

                    </div>
                    <div id="shiyan-fields" class="mt-2" style="display:none;">
                        <div class="form-group mb-2">
                            <label for="whitelist-ip">服务器 IP（必填）</label>
                            <input type="text" class="form-control" id="whitelist-ip" placeholder="请输入域名使用的服务器 IP">
                        </div>
                        <div class="form-group mb-2">
                            <label for="whitelist-purpose">用途（必填）</label>
                            <textarea class="form-control" id="whitelist-purpose" rows="3" placeholder="请说明域名的具体用途"></textarea>
                        </div>
                    </div>
                    <small>{$region_data.white}</small>
                </div>
            </div>
        </div>
        <div class="d-flex mt-2 mb-1 align-items-center">
            <h5 class="border-left-primary border-left-2 pl-1 mb-0">已过白域名</h5>
        </div>
        <div class="b-overlay-wrap position-relative" style="max-height: 290px;">
            <div>
                <div class="vgt-wrap  table_mode ">
                    <div class="vgt-inner-wrap">
                        <div class="vgt-fixed-header">
                        </div>
                        <div class="vgt-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th scope="col">ID</th>
                                        <th scope="col">域名</th>
                                        <th scope="col">区域</th>
                                        <th scope="col">添加时间</th>
                                        <th scope="col">状态</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {foreach $whitelist as $item}
                                    <tr>
                                        <th scope="row">{$item.id}</th>
                                        <td>{$item.domain}</td>
                                        <td>{$region_data.name[$item.region][""]}</td>
                                        <td>{php}echo date('Y年m月d日 H:i',$item["add_time"]){/php}</td>
                                        <td>{if $item.status == "1"}已处理{else/}未处理{/if}</td>
                                    </tr>
                                    {/foreach}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
<script>  
var this_region;
function isValidServerIp(value) {
    var ipv4 = /^(25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)){3}$/;
    var ipv6 = /^[0-9a-fA-F:]+$/;
    return ipv4.test(value) || (value.indexOf(':') !== -1 && ipv6.test(value));
}
function toggleShiyanFields() {
    var isShiyan = this_region === 'cn-sy1';
    $('#shiyan-fields').toggle(isShiyan);
    if (!isShiyan) {
        $('#whitelist-ip').val('');
        $('#whitelist-purpose').val('');
    }
}
$(document).ready(function() {  
    $('#region-item .dropdown-item').click(function(event) {  
        event.preventDefault();  
        var selectedText = $(this).text();  
        $('#dropdownButton').text(selectedText);  
        this_region = $(this).data("region");
        toggleShiyanFields();
    });  
    $('#region-item .dropdown-item:first').trigger('click');
            $('#_add_').click(function() {  
                var domain = $.trim($('#domain').val());  
                if (!domain) {  
                    toastr.warning('请输入域名');  
                    return;  
                }  
                var ip = $.trim($('#whitelist-ip').val());  
                var purpose = $.trim($('#whitelist-purpose').val());  
                if (this_region === 'cn-sy1') {  
                    if (!ip) {  
                        toastr.warning('请输入服务器 IP');  
                        return;  
                    }  
                    if (!isValidServerIp(ip)) {  
                        toastr.warning('请输入正确的服务器 IP');  
                        return;  
                    }  
                    if (!purpose) {  
                        toastr.warning('请输入用途');  
                        return;  
                    }  
                }  
  
                // 发送 POST 请求  
                $.ajax({  
                    url: "{:shd_addon_url('Rainyun://Index/whitelistapi',[],true)}", 
                    type: 'POST',  
                    dataType: 'json',  
                    data: {  
                        domain: domain,  
                        region: this_region,  
                        ip: ip,  
                        purpose: purpose  
                    },  
                    success: function(response) {  
                        console.log(response);  
                        if (response.code == 200){
                            location.reload();
                        }else{
                            toastr.warning(response.message || response.msg || '添加失败');
                        }
                    },  
                    error: function(xhr, status, error) {  
                        // 请求失败后的处理  
                    }  
                });  
            });
});  
</script>
</section>