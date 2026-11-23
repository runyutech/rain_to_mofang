<section class="admin-main">
  <div class="container-fluid">
    <div class="page-container">
      <div class="card">
        <div class="card-body">
          <div class="card-title row">
            <div style="padding:0 15px;">{$Title}</div>
            <div class="col-lg-8 col-md-12 col-sm-12">
              {foreach $PluginsAdminMenu as $v}
                {if $v['custom']}
                  <span class="ml-2"><a class="h5" href="{$v.url}" target="_blank">{$v.name}</a></span>
                {else/}
                  <span class="ml-2"><a class="h5" href="{$v.url}">{$v.name}</a></span>
                {/if}
              {/foreach}
            </div>
          </div>
          <div id="settings-message" class="alert" style="display:none;"></div>
          <form id="rainyun-settings">
            <div class="form-group">
              <label for="description_template">商品介绍模板</label>
              <textarea id="description_template" name="description_template" class="form-control" rows="8">{$DescriptionTemplate|raw}</textarea>
              <small class="form-text text-muted">
                默认模板已包含 CPU、内存、带宽、流量型、显存和存储信息。留空会恢复默认模板。支持 HTML、变量和简单条件块：
                <code>{{region}}</code>、<code>{{line}}</code>、<code>{{plan}}</code>、
                <code>{{cpu}}</code>、<code>{{cpu_name}}</code>、<code>{{memory}}</code>、
                <code>{{memory_unit}}</code>、<code>{{memory_type}}</code>、<code>{{gpu_memory}}</code>、
                <code>{{gpu_memory_unit}}</code>、<code>{{net_in}}</code>、<code>{{net_out}}</code>、
                <code>{{traffic_type}}</code>、<code>{{traffic_base_gb}}</code>、<code>{{traffic_price}}</code>、
                <code>{{storage}}</code>、<code>{{disk_info}}</code>、<code>{{machine}}</code>、
                <code>{{machine_name}}</code>、<code>{{machine_info}}</code>。
                条件块格式：<code>{{#if gpu_memory}}...{{/if}}</code>。模板保存在插件设置表中，下次同步时应用。
              </small>
            </div>
            <div class="form-group">
              <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input" id="auto_update_region_data"
                       name="auto_update_region_data" value="1" {if $AutoUpdateRegionData}checked{/if}>
                <label class="custom-control-label" for="auto_update_region_data">每次拉取商品前自动更新分区数据</label>
              </div>
              <small class="form-text text-muted">
                开启后会先从 <code>rain_to_mofang</code> 下载最新的 <code>data.json</code> 覆盖本地数据；下载失败时继续使用本地数据。
                本地数据最后更新时间：{$RegionDataUpdateTime}
              </small>
            </div>
            <button type="submit" class="btn btn-primary" id="save-settings">
              <i class="fas fa-save"></i> 保存设置
            </button>
            <button type="button" class="btn btn-outline-primary ml-1" id="pull-region-data">
              <i class="fas fa-sync"></i> 手动更新分区数据
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
$(function() {
  $('#rainyun-settings').on('submit', function(event) {
    event.preventDefault();
    var $button = $('#save-settings').prop('disabled', true);
    var $message = $('#settings-message').removeClass('alert-success alert-danger').hide();
    $.ajax({
      url: '{:shd_addon_url("Rainyun://AdminIndex/saveSettings")}',
      type: 'POST',
      dataType: 'json',
      data: $(this).serialize()
    }).done(function(response) {
      var saved = response && response.code === 200;
      $message.addClass(saved ? 'alert-success' : 'alert-danger')
        .text((response && response.msg) || '保存失败').show();
    }).fail(function(xhr) {
      $message.addClass('alert-danger')
        .text((xhr.responseJSON && xhr.responseJSON.msg) || '保存设置请求失败').show();
    }).always(function() {
      $button.prop('disabled', false);
    });
  });

  $('#pull-region-data').on('click', function() {
    var $button = $(this).prop('disabled', true);
    var $message = $('#settings-message').removeClass('alert-success alert-danger').hide();
    $.ajax({
      url: '{:shd_addon_url("Rainyun://AdminIndex/pullregiondata")}',
      type: 'POST',
      dataType: 'json'
    }).done(function(response) {
      var ok = response && response.code === 200;
      var text = (response && response.msg) || (ok ? '更新完成' : '更新失败');
      if (ok && response.data && response.data.update_time) {
        text += '（' + response.data.update_time + '）';
      }
      $message.addClass(ok ? 'alert-success' : 'alert-danger').text(text).show();
      if (ok) setTimeout(function() { window.location.reload(); }, 1200);
    }).fail(function(xhr) {
      $message.addClass('alert-danger')
        .text((xhr.responseJSON && xhr.responseJSON.msg) || '更新分区数据请求失败').show();
    }).always(function() {
      $button.prop('disabled', false);
    });
  });
});
</script>