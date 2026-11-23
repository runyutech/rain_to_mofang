<?php
namespace addons\rainyun;

use app\admin\lib\Plugin;
use think\Db;

class RainyunPlugin extends Plugin
{
    private static $settingsTableEnsured = false;

    const DEFAULT_DESCRIPTION_TEMPLATE = '<div class="d-flex">
  <div class="mb-25 font-medium-2 mr-1">
    <div class="mb-50"><i class="fas fa-microchip text-primary"></i> CPU </div>
    {{#if memory}}<div class="mb-50"><i class="fas fa-memory text-success"></i> 内存 </div>{{/if}}
    {{#if gpu_memory}}<div class="mb-50"><i class="fas fa-desktop text-info"></i> 显存 </div>{{/if}}
    <div class="mb-50"><i class="fas fa-network-wired text-info"></i> 带宽 </div>
    {{#if traffic_base_gb}}<div class="mb-50"><i class="fas fa-cloud-rain text-info"></i> 流量 </div>{{/if}}
    {{#if storage}}<div class="mb-50"><i class="fas fa-database text-dark"></i> 存储 </div>{{/if}}
    {{#if disk_info}}<div class="mb-50"><i class="fas fa-database text-dark"></i> 磁盘 </div>{{/if}}
  </div>

  <div class="mb-25 font-medium-2">
    <div class="mb-50"><b class="text-dark">{{cpu}}</b> 核 {{cpu_name}}</div>
    {{#if memory}}<div class="mb-50"><b class="text-dark">{{memory}}</b> {{memory_unit}} {{memory_type}}</div>{{/if}}
    {{#if gpu_memory}}<div class="mb-50"><b class="text-dark">{{gpu_memory}}</b> {{gpu_memory_unit}}</div>{{/if}}
    <div class="mb-50"><b class="text-dark">{{net_out}} M</b> 上传 <b class="text-dark">{{net_in}} M</b> 下载</div>
    {{#if traffic_base_gb}}<div class="mb-50"><b class="text-dark">{{traffic_base_gb}}</b> GB {{traffic_price}}</div>{{/if}}
    {{#if storage}}<div class="mb-50"><b class="text-dark">{{storage}}</b> GB</div>{{/if}}
    {{#if disk_info}}<div class="mb-50">{{disk_info}}</div>{{/if}}
  </div>
</div>';

    public $info = array(
        'name'        => 'Rainyun',
        'title'       => '雨云产品对接',
        'description' => '对接雨云产品',
        'status'      => 1,
        'author'      => '雨云',
        'version'     => '1.0',
        'module'      => 'addons',
        'lang'        => [ 
            'chinese' => '雨云产品对接', 
            'chinese_tw' => '雨雲產品對接', 
            'english' => 'Rainyun product docking', 
        ]
    );
    # 插件安装
    public function install()
    {
        $tableName = config('database.prefix') . 'whitelist';
        Db::execute("
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键',
                `uid` int(11) NOT NULL COMMENT '用户ID',
                `domain` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '域名',
                `region` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '区域',
                `add_time` int(11) NOT NULL COMMENT '添加时间',
                `status` int(11) NOT NULL COMMENT '状态',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='白名单表';
        "); 
        self::ensureSettingsTable();
        return true;
    }

    public static function ensureSettingsTable($legacyTemplate = "")
    {
        if(self::$settingsTableEnsured) return;
        $settingsTableName = config('database.prefix') . 'rainyun_settings';
        Db::execute("
            CREATE TABLE IF NOT EXISTS `{$settingsTableName}` (
                `id` tinyint(3) unsigned NOT NULL,
                `description_template` mediumtext NOT NULL COMMENT '商品介绍模板',
                `auto_update_region_data` tinyint(1) NOT NULL DEFAULT '1' COMMENT '拉取前自动更新分区数据',
                `update_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='雨云插件设置';
        ");
        $columns = Db::query("SHOW COLUMNS FROM `{$settingsTableName}` LIKE 'auto_update_region_data'");
        if(empty($columns)){
            Db::execute("ALTER TABLE `{$settingsTableName}` ADD COLUMN `auto_update_region_data` tinyint(1) NOT NULL DEFAULT '1' COMMENT '拉取前自动更新分区数据' AFTER `description_template`");
        }
        $settings = Db::name('rainyun_settings')->where('id', 1)->find();
        if (!$settings) {
            $descriptionTemplate = is_string($legacyTemplate) && trim($legacyTemplate) !== "" && strlen($legacyTemplate) <= 20000
                ? $legacyTemplate
                : self::DEFAULT_DESCRIPTION_TEMPLATE;
            Db::name('rainyun_settings')->insert([
                'id' => 1,
                'description_template' => $descriptionTemplate,
                'auto_update_region_data' => 1,
                'update_time' => time(),
            ]);
        } elseif (trim((string)$settings['description_template']) === '') {
            Db::name('rainyun_settings')->where('id', 1)->update([
                'description_template' => self::DEFAULT_DESCRIPTION_TEMPLATE,
                'update_time' => time(),
            ]);
        }
        self::$settingsTableEnsured = true;
    }
    # 插件卸载
    public function uninstall()
    {
        $tableName = config('database.prefix') . 'whitelist';
        Db::execute("DROP TABLE IF EXISTS `{$tableName}`");
        $settingsTableName = config('database.prefix') . 'rainyun_settings';
        Db::execute("DROP TABLE IF EXISTS `{$settingsTableName}`");
        return true;
    }
}