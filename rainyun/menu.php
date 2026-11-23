<?php
/*
 *  自定义菜单
 */
return [
    [
        'name' => '模块状态', # 链接名称
        'url'  => 'Rainyun://AdminIndex/status', # 链接格式   插件名://控制器名/方法
        'custom' => 0, # 是否为自定义路由
        'lang' => [ # 菜单多语言
            'chinese' => '模块状态', # 中文
            'chinese_tw' => '模塊狀態', # 台湾
            'english' => 'Module Status', # 英文
        ],
    ],
    [
        'name' => '插件设置',
        'url'  => 'Rainyun://AdminIndex/settings',
        'custom' => 0,
        'lang' => [
            'chinese' => '插件设置',
            'chinese_tw' => '插件設定',
            'english' => 'Plugin Settings',
        ],
    ],
    [
        'name' => '云服务器列表', # 链接名称
        'url'  => 'Rainyun://AdminIndex/rcslist', # 链接格式   插件名://控制器名/方法
        'custom' => 0, # 是否为自定义路由
        'lang' => [ # 菜单多语言
            'chinese' => '云服务器列表', # 中文
            'chinese_tw' => '雲伺服器列表', # 台湾
            'english' => 'Rcs List', # 英文
        ],
    ],
    [
        'name' => '显卡云列表',
        'url'  => 'Rainyun://AdminIndex/rgpulist',
        'custom' => 0,
        'lang' => [
            'chinese' => '显卡云列表',
            'chinese_tw' => '顯卡雲列表',
            'english' => 'GPU Cloud List',
        ],
    ],
    [
        'name' => '游戏云列表', # 链接名称
        'url'  => 'Rainyun://AdminIndex/rgslist', # 链接格式   插件名://控制器名/方法
        'custom' => 0, # 是否为自定义路由
        'lang' => [ # 菜单多语言
            'chinese' => '游戏云列表', # 中文
            'chinese_tw' => '遊戲雲清單', # 台湾
            'english' => 'Rgs List', # 英文
        ],
    ],
    [
        'name' => '裸金属物理机列表', # 链接名称
        'url'  => 'Rainyun://AdminIndex/rbmlist', # 链接格式   插件名://控制器名/方法
        'custom' => 0, # 是否为自定义路由
        'lang' => [ # 菜单多语言
            'chinese' => '裸金属物理机列表', # 中文
            'chinese_tw' => '裸金屬物理機', # 台湾
            'english' => 'Rbm List', # 英文
        ],
    ]
];