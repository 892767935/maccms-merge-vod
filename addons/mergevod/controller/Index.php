<?php
/**
 * 视频去重合并 插件控制器
 *
 * 提供三个入口：
 *   index()  —— 管理页（预览重复、展示统计与执行按钮）
 *   merge()  —— 后台点按钮执行合并
 *   run()    —— 定时任务接口（输出纯文本，可被宝塔 cron 调用）
 *
 * 所有入口均需带 ?key=鉴权密钥（在插件【配置】中设置）。
 */
namespace addons\mergevod\controller;

use think\addons\Controller;

class Index extends Controller
{
    // 站点根目录（addons/mergevod/controller -> 站点根，上 3 级）
    private $root;

    // 插件配置（含 key / match_by_type / backup）
    private $cfg = array();

    public function __construct()
    {
        parent::__construct();
        $this->root = dirname(__DIR__, 3);
        $this->cfg  = $this->loadConfig();
    }

    /* ================= 三个入口 ================= */

    public function index()
    {
        $this->auth();
        $data = $this->detect();
        $this->assign('total',   $data['total']);
        $this->assign('groups',  $data['groups']);
        $this->assign('dupCount', count($data['groups']));
        $this->assign('runUrl',  $this->addonUrl('mergevod://Index/run'));
        $this->assign('manageUrl', $this->addonUrl('mergevod://Index/index'));
        return $this->fetch();
    }

    public function merge()
    {
        $this->auth();
        $detect = $this->detect();
        $result = $this->doMerge($detect);
        // 用 HTML 展示执行结果
        header('Content-Type: text/html; charset=utf-8');
        echo $this->htmlResult($detect, $result);
        exit;
    }

    public function run()
    {
        $this->auth();
        $detect = $this->detect();
        $result = $this->doMerge($detect);
        // 纯文本输出，便于宝塔 cron 日志与 grep
        header('Content-Type: text/plain; charset=utf-8');
        if (count($detect['groups']) === 0) {
            echo '没有发现可合并的重复记录';
        } else {
            echo '执行完成：合并 ' . $result['merged_groups'] . ' 组，新增合并播放源 ' . $result['added_sources']
               . ' 个，删除 ' . $result['deleted'] . ' 条重复记录';
        }
        exit;
    }

    /* ================= 鉴权 ================= */

    private function auth()
    {
        $key = isset($this->cfg['key']) ? trim($this->cfg['key']) : '';
        $given = $this->request->param('key', '');
        if ($key === '' || $key === 'please-change-me') {
            http_response_code(403);
            die('请先在【应用-应用市场-视频去重合并-配置】中设置鉴权密钥。');
        }
        if (!hash_equals($key, (string)$given)) {
            http_response_code(403);
            die('Key 错误，禁止访问。');
        }
    }

    /* ================= 配置 ================= */

    private function loadConfig()
    {
        if (function_exists('get_addon_config')) {
            $cfg = get_addon_config('mergevod');
            if (is_array($cfg) && $cfg) return $cfg;
        }
        // 兜底：直接解析 config.php 的 value 字段
        $file = __DIR__ . '/../config.php';
        if (is_file($file)) {
            $items = include $file;
            $out = array();
            foreach ((array)$items as $it) {
                if (isset($it['name'], $it['value'])) $out[$it['name']] = $it['value'];
            }
            return $out;
        }
        return array();
    }

    /* ================= 数据库 ================= */

    private function loadDbConfig()
    {
        $paths = array($this->root . '/application/database.php', $this->root . '/config/database.php');
        foreach ($paths as $p) {
            if (is_file($p)) {
                $c = include $p;
                if (is_array($c)) {
                    if (isset($c['database']) && is_array($c['database'])) return $c['database'];
                    return $c;
                }
            }
        }
        return null;
    }

    private function connect()
    {
        $c = $this->loadDbConfig();
        if (!$c || empty($c['hostname']) || empty($c['database'])) {
            throw new \Exception('未找到数据库配置（application/database.php），请确认插件已放入苹果cms根目录的 addons 下。');
        }
        $host = $c['hostname'];
        $port = isset($c['hostport']) ? $c['hostport'] : 3306;
        $db   = $c['database'];
        $user = isset($c['username']) ? $c['username'] : '';
        $pass = isset($c['password']) ? $c['password'] : '';
        $pre  = isset($c['prefix'])   ? $c['prefix']   : 'mac_';
        $chr  = isset($c['charset'])  ? $c['charset']  : 'utf8';
        $pdo = new \PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset={$chr}",
            $user, $pass,
            array(
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            )
        );
        return array($pdo, $pre . 'vod', $pre);
    }

    /* ================= 去重核心 ================= */

    private function normalizeName($name)
    {
        $n = trim((string)$name);
        $n = preg_replace('/[\s\x{200B}\x{FEFF}\p{Zs}]+/u', '', $n);
        $n = preg_replace('/(?:[（(]?(?:19|20)\d{2}[）)]?)$/u', '', $n);
        return trim($n);
    }

    private function splitSources($str)
    {
        return ($str === '' || $str === null) ? array() : explode('$$$', $str);
    }

    private function srcCount($r)
    {
        return count(array_filter($this->splitSources(isset($r['vod_play_from']) ? $r['vod_play_from'] : ''), function ($x) { return $x !== ''; }));
    }

    /** 检测重复，返回 ['total'=>N, 'groups'=>[...]] */
    private function detect()
    {
        list($pdo, $table) = $this->connect();
        $rows = $pdo->query("SELECT * FROM `{$table}` ORDER BY vod_id ASC")->fetchAll();
        $matchByType = (!isset($this->cfg['match_by_type']) || $this->cfg['match_by_type'] === 'on');

        $groups = array();
        foreach ($rows as $r) {
            $name = $this->normalizeName(isset($r['vod_name']) ? $r['vod_name'] : '');
            if ($name === '') continue;
            $key = $matchByType ? ($name . '||' . (int)$r['type_id']) : $name;
            $groups[$key][] = $r;
        }
        $dupGroups = array();
        foreach ($groups as $k => $g) {
            if (count($g) > 1) $dupGroups[] = $g;
        }
        return array('total' => count($rows), 'groups' => $dupGroups, 'matchByType' => $matchByType);
    }

    /**
     * 执行合并。每组保留播放源最多者（平局取小 id），把其他记录的播放源汇入，删除重复记录。
     * 返回统计数组。备份行为由 config.backup 决定。
     */
    private function doMerge($detect)
    {
        if (count($detect['groups']) === 0) {
            return array('merged_groups' => 0, 'added_sources' => 0, 'deleted' => 0, 'backup_file' => '');
        }
        list($pdo, $table, $prefix) = $this->connect();
        $doBackup = (!isset($this->cfg['backup']) || $this->cfg['backup'] === 'on');

        $backupDir = $this->root . '/runtime';
        $backupSql = '';
        $backupFile = '';
        if ($doBackup) {
            if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
            $backupSql = "-- maccms10 去重合并备份 " . date('Y-m-d H:i:s') . "\n"
                       . "-- 还原：在 phpMyAdmin 执行本文件即可恢复合并前状态\n\n";
            $backupFile = $backupDir . '/merge_backup_' . date('Ymd_His') . '.sql';
        }

        $esc = function ($v) { return $v === null ? 'NULL' : "'" . addslashes($v) . "'"; };

        $toDelete = array();
        $mergedCnt = 0;
        $addedSrc = 0;
        $execPlan = array();

        foreach ($detect['groups'] as $group) {
            usort($group, function ($a, $b) {
                $ca = $this->srcCount($a); $cb = $this->srcCount($b);
                if ($ca !== $cb) return $ca > $cb ? -1 : 1;
                return (int)$a['vod_id'] <=> (int)$b['vod_id'];
            });
            $keeper = $group[0];
            $dups   = array_slice($group, 1);

            $froms   = $this->splitSources(isset($keeper['vod_play_from']) ? $keeper['vod_play_from'] : '');
            $servers = $this->splitSources(isset($keeper['vod_play_server']) ? $keeper['vod_play_server'] : '');
            $notes   = $this->splitSources(isset($keeper['vod_play_note']) ? $keeper['vod_play_note'] : '');
            $urls    = $this->splitSources(isset($keeper['vod_play_url']) ? $keeper['vod_play_url'] : '');

            foreach ($dups as $d) {
                $df = $this->splitSources(isset($d['vod_play_from']) ? $d['vod_play_from'] : '');
                $ds = $this->splitSources(isset($d['vod_play_server']) ? $d['vod_play_server'] : '');
                $dn = $this->splitSources(isset($d['vod_play_note']) ? $d['vod_play_note'] : '');
                $du = $this->splitSources(isset($d['vod_play_url']) ? $d['vod_play_url'] : '');
                $n  = max(count($df), count($ds), count($dn), count($du));
                for ($i = 0; $i < $n; $i++) {
                    $f = isset($df[$i]) ? $df[$i] : '';
                    if ($f === '') continue;
                    if (in_array($f, $froms, true)) continue;
                    $froms[]   = $f;
                    $servers[] = isset($ds[$i]) ? $ds[$i] : '';
                    $notes[]   = isset($dn[$i]) ? $dn[$i] : '';
                    $urls[]    = isset($du[$i]) ? $du[$i] : '';
                    $addedSrc++;
                }
                $toDelete[] = $d['vod_id'];
            }

            $newFrom = implode('$$$', $froms);
            $newSrv  = implode('$$$', $servers);
            $newNote = implode('$$$', $notes);
            $newUrl  = implode('$$$', $urls);

            if ($doBackup) {
                $cols = array_keys($keeper);
                $backupSql .= "-- 组：{$keeper['vod_name']} (id={$keeper['vod_id']})\n";
                $backupSql .= "UPDATE `{$table}` SET vod_play_from=" . $esc($keeper['vod_play_from'])
                    . ", vod_play_server=" . $esc($keeper['vod_play_server'])
                    . ", vod_play_note=" . $esc($keeper['vod_play_note'])
                    . ", vod_play_url=" . $esc($keeper['vod_play_url'])
                    . " WHERE vod_id={$keeper['vod_id']};\n";
                foreach ($dups as $d) {
                    $backupSql .= "INSERT INTO `{$table}` (" . implode(',', array_map(function ($c) { return "`$c`"; }, $cols))
                        . ") VALUES (" . implode(',', array_map($esc, array_values($d))) . ");\n";
                }
                $backupSql .= "\n";
            }

            $execPlan[] = array($keeper, $newFrom, $newSrv, $newNote, $newUrl);
        }

        if ($doBackup) {
            @file_put_contents($backupFile, $backupSql);
        }
        foreach ($execPlan as $ep) {
            $st = $pdo->prepare("UPDATE `{$table}` SET vod_play_from=:f, vod_play_server=:s, vod_play_note=:n, vod_play_url=:u WHERE vod_id=:id");
            $st->execute(array(':f' => $ep[1], ':s' => $ep[2], ':n' => $ep[3], ':u' => $ep[4], ':id' => $ep[0]['vod_id']));
            $mergedCnt++;
        }
        if ($toDelete) {
            $ids = implode(',', array_map('intval', array_unique($toDelete)));
            $pdo->exec("DELETE FROM `{$table}` WHERE vod_id IN ({$ids})");
        }

        return array(
            'merged_groups' => $mergedCnt,
            'added_sources' => $addedSrc,
            'deleted'       => count(array_unique($toDelete)),
            'backup_file'   => $backupFile,
        );
    }

    /* ================= 输出 ================= */

    private function htmlResult($detect, $result)
    {
        $h = '<meta charset="utf-8"><style>body{font-family:system-ui;background:#f5f6f8;padding:24px;line-height:1.7}'
           . '.card{background:#fff;border:1px solid #e6e6e6;border-radius:12px;padding:20px 24px;margin-bottom:14px}'
           . 'h3{margin:0 0 6px}.ok{color:#16a34a;font-weight:600}code{background:#f1f5f9;padding:2px 6px;border-radius:4px}</style>';
        $h .= '<h3>视频去重合并 · 执行结果</h3>';
        $h .= '<div class="card">数据库表 mac_vod，共读取 <b>' . $detect['total'] . '</b> 条记录，发现 <b>'
            . count($detect['groups']) . '</b> 组重复。</div>';
        if (count($detect['groups']) === 0) {
            $h .= '<div class="card"><span class="ok">没有发现可合并的重复记录，无需合并。</span></div>';
            return $h;
        }
        $h .= '<div class="card"><span class="ok">执行完成：合并 ' . $result['merged_groups'] . ' 组，新增合并播放源 '
            . $result['added_sources'] . ' 个，删除 ' . $result['deleted'] . ' 条重复记录。</span><br>';
        $h .= ($this->cfg['backup'] === 'on')
            ? '备份文件：<code>' . htmlspecialchars($result['backup_file']) . '</code>（可用 phpMyAdmin 导入还原）'
            : '本次按配置直接删除，未生成备份文件。';
        $h .= '</div>';
        return $h;
    }

    private function addonUrl($url)
    {
        if (function_exists('addon_url')) return addon_url($url);
        return $this->root . '/addons/mergevod/Index/index';
    }
}
