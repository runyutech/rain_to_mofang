  <section class="admin-main">
    <div class="container-fluid">
      <div class="page-container">
        <div class="card">
          <div class="card-body">
            <div class="card-title row"> <div style="padding:0 15px;">{$Title}</div>
              <div class="col-lg-8 col-md-12 col-sm-12">
                {foreach $PluginsAdminMenu as $v}
                  {if $v['custom']}
                    <span  class="ml-2"><a  class="h5" href="{$v.url}" target="_blank">{$v.name}</a></span>
                  {else/}
                    <span  class="ml-2"> <a  class="h5" href="{$v.url}">{$v.name}</a></span>
                  {/if}
                {/foreach}
              </div>
            </div>
            <div class="tab-content mt-4">
              <div class="table-body table-responsive">
                <table class="table table-bordered table-hover">
                  <caption>选中的项目： <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#operate">批量操作</button></caption>
                  <thead class="thead-light">
                    <tr>
                      <th class="checkbox">
                        <div class="custom-control custom-checkbox">
                          <input type="checkbox" class="custom-control-input" id="customCheckHead" name="headCheckbox">
                          <label class="custom-control-label" for="customCheckHead">&nbsp;</label>
                        </div>
                      </th>
                      <th class="center t1">ID </th>
                      <th>区域</th>
                      <th>配置</th>
                      <th class="t4">中文名</th>
                      <th>库存</th>
                      <th class="center t5">操作</th>
                      <th class="center t1">ID </th>
                      <th>分组</th>
                      <th class="t4">名称</th>
                      <th>库存</th>
                    </tr>
                  </thead>
                  <tbody id="table-body">
                    
                  </tbody>
                </table>
              </div>
              <div id="list-loading" class="text-center py-4">
                <div class="spinner-border text-primary" role="status"><span class="sr-only">加载中</span></div>
                <div class="mt-2 text-muted">正在加载雨云套餐数据，请稍候……</div>
              </div>
              <div id="list-load-error" class="alert alert-danger" style="display:none;"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
 	<div class="modal fade" id="operate" tabindex="-1" role="dialog" aria-labelledby="operateModalLabel"
		aria-hidden="true">
		<div class="modal-dialog " role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="exampleModalLabel">执行操作</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<form class="form">
						<div class="form-group row invalid">
							确认后先检查未知分区，再开始同步；每批最多处理 5 项。
						</div>
					</form>
					<div id="navigation-option-group" class="form-group" style="display:none;">
						<label for="nav_item_id">会员中心导航选项</label>
						<select id="nav_item_id" class="form-control" disabled>
							<option value="" selected disabled>请选择导航选项</option>
						</select>
						<small id="nav-menu-help" class="form-text text-muted">正在读取会员中心导航……</small>
					</div>
					<div id="unknown-region-inputs" class="form-group" style="display:none;"></div>
					<div id="operation-progress" style="display:none;">
						<div class="progress">
							<div id="operation-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
						</div>
						<div id="operation-progress-text" class="mt-2 text-muted"></div>
					</div>
					<div id="operation-result" class="mt-2"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-outline-secondary" data-dismiss="modal">关闭</button>
					<button type="button" class="btn btn-primary" id="confirm_operation">确定</button>
				</div>
			</div>
		</div>
	</div>
    <script>
	    var isRgpu = '{$IsRgpu}' === 'true';
        $(document).ready(function() {
            $('#customCheckHead').change(function() {
                $('.row-checkbox').prop('checked', $(this).is(':checked'));
            });
            var operationRunning = false;
            var preflightDone = false;
            var navigationLoaded = false;
            var navigationRequired = false;
            var navigationLoading = false;
            var navigationOptionsAvailable = false;
            var selectedIds = [];
            var unknownRegions = [];
            var regionNames = [];
            var $navMenu = $('#nav_item_id');
            var clientMenuId = 0;
            var navigationProductName = isRgpu ? '显卡云' : '云服务器';

            function unwrapResponse(response) {
                if(response && response.status === 200) return response.data;
                if(response && response.code === 200) return response.data;
                throw new Error((response && response.msg) || '读取导航数据失败');
            }
            function asArray(value) {
                if(Array.isArray(value)) return value;
                if(value && typeof value === 'object') return Object.keys(value).map(function(key) {
                    var item = value[key];
                    return item && typeof item === 'object' && item.id == null
                        ? Object.assign({}, item, {id: key}) : item;
                });
                return [];
            }
            function menuRows(data) {
                if(Array.isArray(data)) return data;
                if(!data || typeof data !== 'object') return [];
                if(data.list) return asArray(data.list);
                if(data.data) {
                    if(Array.isArray(data.data)) return data.data;
                    if(data.data.list) return asArray(data.data.list);
                }
                return [];
            }
            function getNavigationOptions(navList) {
                if(typeof navList === 'string') {
                    try {
                        navList = JSON.parse(navList);
                    } catch(error) {
                        return [];
                    }
                }
                var options = [];
                var hasProductServices = false;
                function visit(nodes, parents) {
                    asArray(nodes).forEach(function(node) {
                        if(!node || typeof node !== 'object') return;
                        var path = parents.concat(node.name ? [node.name] : []);
                        if(Number(node.nav_type) === 0 && node.nav_type_name === '系统页面' && node.name === '产品与服务') {
                            hasProductServices = true;
                        }
                        if(Number(node.nav_type) === 2 && node.id != null) {
                            options.push({id:node.id, name:path.join(' / ') || ('导航 #' + node.id), itemName:node.name});
                        }
                        if(node.son) visit(node.son, path);
                    });
                }
                visit(navList, []);
                return {options:options, hasProductServices:hasProductServices};
            }
            function loadNavigation() {
                if(navigationLoading || (navigationLoaded && navigationOptionsAvailable)) return;
                navigationLoading = true;
                navigationLoaded = false;
                navigationOptionsAvailable = false;
                $navMenu.prop('disabled', true).empty().append($('<option>').val('').text('请选择导航选项'));
                var timestamp = Date.now();
                $.when(
                    $.ajax({
                        url: 'menus/getMenuList?request_time=' + timestamp + '&languagesys=CN',
                        type: 'POST',
                        dataType: 'json',
                        data: {limit:50, page:1}
                    }),
                    $.ajax({
                        url: 'menus/getMenuType?request_time=' + timestamp + '&languagesys=CN',
                        type: 'POST',
                        dataType: 'json'
                    })
                ).done(function(menuListResult, menuTypeResult) {
                    try {
                        var allMenus = menuRows(unwrapResponse(menuListResult[0]));
                        var menuTypes = asArray(unwrapResponse(menuTypeResult[0]));
                        var clientType = menuTypes.find(function(item) { return item && item.type === 'client'; });
                        if(!clientType) throw new Error('没有配置会员中心（client）导航');
                        clientMenuId = Number(clientType.menuid) || 0;
                        var clientMenu = allMenus.find(function(item) {
                            return item && String(item.id) === String(clientType.menuid);
                        });
                        if(!clientMenu) throw new Error('没有找到会员中心导航数据');
                        var navigation = getNavigationOptions(clientMenu.nav_list);
                        var options = navigation.options;
                        var seen = {};
                        $navMenu.empty().append($('<option>').val('').text('请选择导航选项'));
                        options.forEach(function(item) {
                            if(!item || item.id == null || seen[String(item.id)]) return;
                            seen[String(item.id)] = true;
                            $navMenu.append($('<option>').val(item.id).text(item.name));
                        });
                        var existingOption = options.find(function(item) { return item.itemName === navigationProductName; });
                        if(existingOption) {
                            $navMenu.val(String(existingOption.id));
                        } else {
                            var createMode = navigation.hasProductServices ? 'auto_service' : 'auto_root';
                            var createLabel = navigation.hasProductServices
                                ? '产品与服务 / ' + navigationProductName + '（自动创建）'
                                : navigationProductName + '（自动创建）';
                            $navMenu.append($('<option>').val(createMode).text(createLabel)).val(createMode);
                        }
                        navigationOptionsAvailable = true;
                        $('#nav-menu-help').text(existingOption
                            ? '已自动选择“' + navigationProductName + '”导航选项。'
                            : '未找到同名选项，已选择自动创建导航。');
                    } catch(error) {
                        navigationOptionsAvailable = false;
                        clientMenuId = 0;
                        $navMenu.empty().append($('<option>').val('').text('请选择导航选项'));
                        $('#nav-menu-help').text(error.message || '读取导航失败，请检查配置后重试。');
                    }
                    navigationLoading = false;
                    navigationLoaded = true;
                    $navMenu.prop('disabled', !navigationOptionsAvailable);
                }).fail(function() {
                    navigationLoading = false;
                    navigationOptionsAvailable = false;
                    clientMenuId = 0;
                    $navMenu.empty().append($('<option>').val('').text('请选择导航选项'));
                    $('#nav-menu-help').text('获取会员中心导航失败，请检查配置后重试。');
                    navigationLoaded = true;
                    $navMenu.prop('disabled', true);
                });
            }
            function updateProgress(done, text) {
                var percent = selectedIds.length ? Math.floor(done * 100 / selectedIds.length) : 0;
                $('#operation-progress-bar').css('width', percent + '%').attr('aria-valuenow', percent);
                $('#operation-progress-text').text(text);
            }
            function getCheckedPlanIds() {
                var ids = [];
                $('.row-checkbox:checked').each(function() {
                    ids.push($(this).closest('tr').find('td.center:eq(0)').text().trim());
                });
                return ids;
            }
            function needsNavigation(ids) {
                return ids.some(function(id) {
                    return !Object.prototype.hasOwnProperty.call(this_products, id);
                });
            }
            function validateNavigationSelection() {
                if(!navigationRequired) return true;
                var message = '';
                if(!navigationLoaded) message = '正在读取会员中心导航，请稍候。';
                else if(!navigationOptionsAvailable) message = '读取会员中心导航失败或没有可用选项，请检查导航配置后重试。';
                else if(!$navMenu.val()) message = '新增商品必须选择一个会员中心导航选项。';
                if(message) {
                    $('#operation-result').removeClass('text-success').addClass('text-danger').text(message);
                    return false;
                }
                return true;
            }
            function stopWithError(message, start, end) {
                operationRunning = false;
                $('#confirm_operation').prop('disabled', false).text('重试同步');
                $('#operation-result').removeClass('text-success').addClass('text-danger')
                    .text('批次 ' + start + '-' + end + ' 失败：' + message + '。当前批次可能部分完成；重试会重新执行全部已选项目。');
            }
            function startSync(names) {
                regionNames = names;
                operationRunning = true;
                $('#confirm_operation').prop('disabled', true).text('同步中...');
                $('#operation-result').removeClass('text-danger text-success').text('');
                $('#operation-progress').show();
                var batchSize = 5;
                var navigationWarning = false;
                function runBatch(offset) {
                    if(offset >= selectedIds.length) {
                        updateProgress(selectedIds.length, '全部完成：' + selectedIds.length + '/' + selectedIds.length);
                        operationRunning = false;
                        $('#operation-result').removeClass('text-danger').addClass('text-success')
                            .text(navigationWarning ? '操作完成，但有商品未加入所选导航。' : '操作完成');
                        return;
                    }
                    var batch = selectedIds.slice(offset, offset + batchSize);
                    var end = offset + batch.length;
                    updateProgress(offset, '正在处理 ' + (offset + 1) + '-' + end + '/' + selectedIds.length);
                    $.ajax({
                        url: '{:shd_addon_url('Rainyun://AdminIndex/rcsoperateapi')}',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            id:batch,
                            region_names:JSON.stringify(regionNames),
                            nav_menu_id:navigationRequired ? clientMenuId : 0,
                            nav_item_id:navigationRequired ? ($navMenu.val() || '0') : '0',
                            is_rgpu:isRgpu ? 'true' : 'false'
                        }
                    }).done(function(response) {
                        if(!response || response.code !== 200) {
                            stopWithError((response && response.msg) || '服务器返回了无效结果', offset + 1, end);
                            return;
                        }
                        if((response.msg || '').indexOf('未添加前台导航') !== -1) navigationWarning = true;
                        updateProgress(end, '已完成 ' + end + '/' + selectedIds.length);
                        runBatch(end);
                    }).fail(function(xhr) {
                        stopWithError((xhr.responseJSON && xhr.responseJSON.msg) || xhr.statusText || '请求失败', offset + 1, end);
                    });
                }
                runBatch(0);
            }
            function renderUnknownRegions(regions) {
                var $container = $('#unknown-region-inputs').empty().show();
                $('<div class="alert alert-warning">').text('发现以下接口未识别的分区，请填写前台显示名称后继续同步。如果不认识，可以提交工单咨询。').appendTo($container);
                regions.forEach(function(region, index) {
                    var label = region.label || [region.region, region.line].filter(Boolean).join(' / ') || '未知分区';
                    var $group = $('<div class="form-group">');
                    $('<label>').text(label).appendTo($group);
                    $('<input type="text" class="form-control" maxlength="100">')
                        .attr('data-region-index', index).appendTo($group);
                    $container.append($group);
                });
            }
            function readRegionNames() {
                var names = [];
                for(var i = 0; i < unknownRegions.length; i++) {
                    var name = $('#unknown-region-inputs [data-region-index="' + i + '"]').val().trim();
                    if(!name) return null;
                    names.push({region:unknownRegions[i].region, line:unknownRegions[i].line, name:name});
                }
                return names;
            }

            $('#operate').on('show.bs.modal', function() {
                if(operationRunning) return;
                preflightDone = false;
                selectedIds = [];
                unknownRegions = [];
                regionNames = [];
                $('#unknown-region-inputs').empty().hide();
                $('#operation-progress').hide();
                $('#operation-result').removeClass('text-danger text-success').text('');
                $('#confirm_operation').prop('disabled', false).text('确定');
                navigationRequired = needsNavigation(getCheckedPlanIds());
                $('#navigation-option-group').toggle(navigationRequired);
                if(navigationRequired) loadNavigation();
                else $navMenu.val('');
            }).on('hide.bs.modal', function(event) {
                if(operationRunning) event.preventDefault();
            });

            $('#confirm_operation').click(function() {
                if(operationRunning) return;
                if(navigationRequired && !navigationLoaded) loadNavigation();
                if(!validateNavigationSelection()) return;
                if(preflightDone) {
                    var names = readRegionNames();
                    if(names === null) {
                        $('#operation-result').removeClass('text-success').addClass('text-danger').text('请填写所有未识别分区的名称。');
                        return;
                    }
                    startSync(names);
                    return;
                }
                selectedIds = getCheckedPlanIds();
                if(selectedIds.length === 0) {
                    $('#operation-result').removeClass('text-success').addClass('text-danger').text('请先选择要操作的项目');
                    return;
                }
                navigationRequired = needsNavigation(selectedIds);
                $('#navigation-option-group').toggle(navigationRequired);
                if(navigationRequired && !navigationLoaded) loadNavigation();
                if(!validateNavigationSelection()) return;
                operationRunning = true;
                $('#confirm_operation').prop('disabled', true).text('检查分区...');
                $('#operation-progress').show();
                updateProgress(0, '正在检查所选套餐的分区...');
                $.ajax({
                    url: '{:shd_addon_url('Rainyun://AdminIndex/checkregionsapi')}',
                    type: 'POST',
                    dataType: 'json',
                    data: {id:selectedIds, type:'rcs', is_rgpu:isRgpu ? 'true' : 'false'}
                }).done(function(response) {
                    if(!response || response.code !== 200 || !response.data || !Array.isArray(response.data.unknown_regions)) {
                        operationRunning = false;
                        $('#confirm_operation').prop('disabled', false).text('重新检查');
                        $('#operation-result').removeClass('text-success').addClass('text-danger')
                            .text((response && response.msg) || '分区检查失败或返回数据格式无效');
                        return;
                    }
                    unknownRegions = response.data.unknown_regions;
                    preflightDone = true;
                    if(unknownRegions.length) {
                        renderUnknownRegions(unknownRegions);
                        operationRunning = false;
                        $('#confirm_operation').prop('disabled', false).text('填写完成，开始同步');
                        $('#operation-progress').hide();
                    } else {
                        startSync([]);
                    }
                }).fail(function(xhr) {
                    operationRunning = false;
                    $('#confirm_operation').prop('disabled', false).text('重新检查');
                    $('#operation-result').removeClass('text-success').addClass('text-danger')
                        .text((xhr.responseJSON && xhr.responseJSON.msg) || xhr.statusText || '分区检查请求失败');
                });
            });
        });
    var this_products = {};
    function finishListLoading(error) {
        $('#list-loading').hide();
        if(error) $('#list-load-error').text(error).show();
    }
    $.when(
        $.ajax({
            url: '{:shd_addon_url('Rainyun://AdminIndex/rcslistproducts')}',
            type: 'POST',
            dataType: 'json',
            data: {is_rgpu: isRgpu ? 'true' : 'false'}
        }),
        $.ajax({
            url: '{:shd_addon_url('Rainyun://AdminIndex/rcslistapi')}',
            type: 'POST',
            dataType: 'json',
            data: {is_rgpu: isRgpu ? 'true' : 'false'}
        })
    ).done(function(productsResult, plansResult) {
        var productsResponse = productsResult[0];
        var response = plansResult[0];
        if(!productsResponse || productsResponse.code !== 200 || !Array.isArray(productsResponse.data)) {
            finishListLoading((productsResponse && productsResponse.msg) || '读取已同步商品失败');
            return;
        }
        productsResponse.data.forEach(function(product) {
            this_products[product.config_option3] = product;
        });
        if (response && response.code === 200 && response.data && typeof response.data === 'object') {
        var data = response.data;  
        var tableBody = $('#table-body');  
          
        // 清空表格体，以避免重复添加数据  
        tableBody.empty();  
          
        // 遍历数据数组并添加行到表格中  
        $.each(data, function(index, item) {  
        if(item.is_selling){
          var tr = $('<tr>');  
            
          // 添加复选框单元格  
          var checkboxCell = $('<td class="checkbox">');  
          var checkbox = $('<div class="custom-control custom-checkbox">');  
          var input = $(`<input type="checkbox" class="custom-control-input row-checkbox" id="customCheck${item.id}">`);  
          var label = $(`<label class="custom-control-label" for="customCheck${item.id}">&nbsp;</label>`);  
          checkbox.append(input).append(label);  
          checkboxCell.append(checkbox);  
          tr.append(checkboxCell);  
            
          // 添加ID单元格  
          var idCell = $('<td class="center">').text(item.id);  
          tr.append(idCell);  
          
          var regionCell = $('<td>').text(item.region);  
          tr.append(regionCell);  
            
          var machineCell = $('<td>').text(item.machine);  
          tr.append(machineCell);  
            
          var chineseCell = $('<td>').text(item.chinese);  
          tr.append(chineseCell);  
            
          var available_stockCell = $('<td>').text(item.available_stock);  
          tr.append(available_stockCell);  
          
          // 添加操作单元格  
          var isexist = false;
          var actionCell = $('<td>');  
          var GetBtn;
          if(item.id in this_products){
              GetBtn = $('<button type="button" class="btn btn-link green" data-toggle="modal" data-target="#operate"><i class="fas fa-sync"></i> 更新数据</button>');  
              isexist = true;
          }else{
              GetBtn = $('<button type="button" class="btn btn-link blue" data-toggle="modal" data-target="#operate"><i class="fas fa-arrow-down"></i> 拉取数据</button>');  
          }
            GetBtn.click(function(){
                console.log("ccc");
                var $row = $(this).closest('tr');
                $row.find('.row-checkbox').prop('checked', true);
                $row.siblings('tr').find('.row-checkbox').prop('checked', false);
            });
          actionCell.append(GetBtn);  
          tr.append(actionCell);  
          if(isexist){
              var this_data = this_products[item.id];
              
              var id_Cell = $('<td>').text(this_data.id);
              tr.append(id_Cell);
              
              var gid_Cell = $('<td>').text(this_data.gid);
              tr.append(gid_Cell);
              
              var name_Cell = $('<td>').text(this_data.name);
              tr.append(name_Cell);
              
              var qty_Cell = $('<td>').text(this_data.qty);
              tr.append(qty_Cell);
          }
          // 将行添加到表格体中  
          tableBody.append(tr);  
        }
        }); 
        } else {
            finishListLoading((response && response.msg) || '雨云套餐数据格式无效');
            return;
        }
        finishListLoading();
    }).fail(function(xhr) {
        finishListLoading((xhr.responseJSON && xhr.responseJSON.msg) || xhr.statusText || '读取雨云套餐数据失败');
    });
    </script>
  </section>
