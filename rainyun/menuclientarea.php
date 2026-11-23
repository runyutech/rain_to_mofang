<?php
/*
 *  前台自定义菜单
 */
return [
    [
        'name' => '域名过白', # 菜单名称 默认为一级菜单
        'url'  => 'Rainyun://Index/whitelist', # 菜单路由 (若有子菜单,此值留空)
        'fa_icon' => 'bx bx-task', # 菜单图标 支持bootstrap
        'lang' => [ # 菜单多语言
            'chinese' => '域名过白', # 中文
            'chinese_tw' => '功能變數名稱過白', # 台湾
            'english' => 'Domain name whitelist', # 英文
        ],
        'child' => []
    ]
];
