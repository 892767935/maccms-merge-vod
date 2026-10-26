<?php
/**
 * 视频去重合并 插件配置
 * 后台【应用-应用市场-本地应用-视频去重合并-配置】中编辑保存。
 */
return array(
    0 => array(
        'name'    => 'key',
        'title'   => '鉴权密钥',
        'type'    => 'string',
        'content' => array(),
        'value'   => 'please-change-me',
        'rule'    => 'required',
        'msg'     => '',
        'tip'     => '管理页与定时任务接口使用此密钥鉴权，请务必改为随机长字符串，防止他人调用。',
        'ok'      => '',
        'extend'  => 'placeholder="请输入随机密钥"',
    ),
    1 => array(
        'name'    => 'match_by_type',
        'title'   => '按分类匹配',
        'type'    => 'radio',
        'content' => array(
            'on'  => '片名 + 分类都相同才算重复（推荐）',
            'off' => '只看片名相同',
        ),
        'value'   => 'on',
        'rule'    => 'required',
        'msg'     => '',
        'tip'     => '推荐开启，可避免不同分类下的同名资源被误合并。',
        'ok'      => '',
        'extend'  => '',
    ),
    2 => array(
        'name'    => 'backup',
        'title'   => '合并前备份',
        'type'    => 'radio',
        'content' => array(
            'on'  => '生成备份 SQL 到 runtime/（可还原）',
            'off' => '直接删除，不生成备份',
        ),
        'value'   => 'on',
        'rule'    => 'required',
        'msg'     => '',
        'tip'     => '首次使用建议开启备份；确认规则无误、用于定时任务时可关闭以省磁盘。',
        'ok'      => '',
        'extend'  => '',
    ),
);
