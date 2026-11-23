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
						<div class="help-block">
							查看模块状态，可在此一键安装或更新雨云对接模块
						</div>
						<div class="table-body table-responsive">
							<table class="table table-bordered table-hover">
								<thead class="thead-light">
									<tr>
										<th class="center t1">模块 </th>
										<th class="center">版本</th>
										<th class="center">状态</th>
										<th class="center t5">操作</th>
									</tr>
								</thead>
								<tbody>
									{foreach $ModuleList as $v}
									<tr>
										<td class="center">{$v.name}</td>
										<td class="center">{if $v.version}{$v.version}{else/}-{/if}</td>
										<td class="center">
											{if $v.installed}
												{if $v.configured}
													<i class="fa fa-check-circle text-success"></i> 已安装
												{else/}
													<i class="fa fa-exclamation-circle text-warning"></i> 已安装（未配置服务器）
												{/if}
											{else/}
												<i class="fa fa-times-circle text-danger"></i> 未安装
											{/if}
										</td>
										<td class="center">
											<button type="button" class="btn btn-link module-install-btn"
												data-type="{$v.key}"
												data-name="{$v.name}"
												data-installed="{if $v.installed}1{else/}0{/if}"
												data-configured="{if $v.configured}1{else/}0{/if}">
												<i class="fas fa-edit"></i> {if $v.installed}更新{else/}下载安装{/if}
											</button>
										</td>
									</tr>
									{/foreach}
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>

	<!-- 安装/更新弹窗 -->
	<div class="modal fade" id="moduleInstallModal" tabindex="-1" role="dialog" aria-labelledby="moduleInstallModalLabel"
		aria-hidden="true">
		<div class="modal-dialog " role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="moduleInstallTitle">安装模块</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<div id="module-install-tip" class="alert alert-info" style="display:none;"></div>
					<div class="form-group" id="module-api-key-group">
						<label class="require">雨云 API 密钥</label>
						<input type="text" class="form-control" id="module-api-key" autocomplete="off" placeholder="请输入你的雨云 API 密钥">
						<small class="form-text text-muted">仅首次安装需要填写，用于自动创建服务器配置。</small>
					</div>
					<div id="module-install-result" class="mt-2"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-outline-secondary" data-dismiss="modal">关闭</button>
					<button type="button" class="btn btn-primary" id="module-install-confirm">确定</button>
				</div>
			</div>
		</div>
	</div>
	<script>
		$(function () {
			var currentType = '';
			var running = false;

			$('.module-install-btn').on('click', function () {
				if (running) return;
				var $this = $(this);
				currentType = $this.attr('data-type') || '';
				var name = $this.attr('data-name') || '';
				var installed = $this.attr('data-installed') === '1';
				var configured = $this.attr('data-configured') === '1';
				$('#moduleInstallTitle').text((installed ? '更新' : '安装') + name + ' 对接模块');
				$('#module-api-key').val('');
				$('#module-install-result').removeClass('text-danger text-success').text('');
				$('#module-install-confirm').prop('disabled', false).text('确定');
				$('#module-api-key-group').toggle(!configured);
				$('#module-install-tip').toggle(!configured).text(
					installed
						? '将下载最新版本并覆盖插件目录中的文件，随后使用填写的密钥创建服务器配置。'
						: '将从 GitHub 下载最新版本到 public/plugins/servers 并解压，随后使用填写的密钥创建服务器配置。'
				);
				$('#moduleInstallModal').modal('show');
			});

			$('#module-install-confirm').on('click', function () {
				if (running || currentType === '') return;
				var needApiKey = $('#module-api-key-group').is(':visible');
				var apiKey = $.trim($('#module-api-key').val());
				if (needApiKey && apiKey === '') {
					$('#module-install-result').removeClass('text-success').addClass('text-danger').text('请填写雨云 API 密钥');
					return;
				}
				running = true;
				var $btn = $(this).prop('disabled', true).text('处理中...');
				$('#module-install-result').removeClass('text-danger text-success').text('正在下载并安装，请稍候……');
				$.ajax({
					url: '{:shd_addon_url('Rainyun://AdminIndex/installmodule')}',
					type: 'POST',
					dataType: 'json',
					data: {type: currentType, api_key: apiKey}
				}).done(function (response) {
					running = false;
					$btn.prop('disabled', false).text('确定');
					if (!response || response.code !== 200) {
						$('#module-install-result').removeClass('text-success').addClass('text-danger')
							.text((response && response.msg) || '操作失败');
						return;
					}
					$('#module-install-result').removeClass('text-danger').addClass('text-success')
						.text(response.msg || '操作完成');
					setTimeout(function () { window.location.reload(); }, 1200);
				}).fail(function (xhr) {
					running = false;
					$btn.prop('disabled', false).text('确定');
					$('#module-install-result').removeClass('text-success').addClass('text-danger')
						.text((xhr.responseJSON && xhr.responseJSON.msg) || xhr.statusText || '请求失败');
				});
			});
		});
	</script>