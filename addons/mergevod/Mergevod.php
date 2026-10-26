<?php
/**
 * 视频去重合并 插件核心文件
 *
 * 功能：合并"不同播放器下的相同资源"。
 *   同一影视（规范化片名相同 + 分类相同，可配置）因多个采集源各生成一条记录时，
 *   自动合并为一组，把多个播放源/播放器汇聚到保留记录上，删除重复记录。
 *
 * 后台入口：应用 -> 应用市场 -> 本地应用 -> 视频去重合并 -> 配置 / 管理
 * 定时任务：<code>curl -s "http://你的域名/addons/mergevod/Index/run?key=你的密钥"</code>
 */
namespace addons\mergevod;

use think\Addons;

class Mergevod extends Addons
{
    // 插件基础信息
    public $info = array(
        'name'        => 'mergevod',
        'title'       => '视频去重合并',
        'description' => '合并不同播放器下的相同资源：同一影视因多采集源生成多条记录时合并为一组，汇聚所有播放源，删除重复记录。',
        'status'      => 1,
        'author'      => 'QingChuan',
        'version'     => '1.0.0',
    );

    /**
     * 插件安装方法
     */
    public function install()
    {
        return true;
    }

    /**
     * 插件卸载方法
     */
    public function uninstall()
    {
        return true;
    }
}
