<?php
/**
 * 视频去重合并 —— 独立执行入口
 *
 * 本文件是 addons/mergevod/ 下的物理 PHP 文件，nginx 会直接交给 PHP 执行，
 * 不依赖 thinkphp 的 /index.php/addons/ 伪静态路由（该路由可能被 nginx try_files 拦截）。
 *
 * 用法（宝塔定时任务 / 手动）：
 *   curl "http://你的域名/addons/mergevod/run.php?key=你的鉴权密钥"
 *
 * 输出为纯文本，便于 cron 日志记录与 grep 匹配：
 *   - 无重复：   没有发现可合并的重复记录
 *   - 有执行：   执行完成：合并 N 组，新增合并播放源 N 个，删除 N 条重复记录
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Content-Type: text/plain; charset=utf-8');

/* ============ 路径 ============ */
$addonRoot = __DIR__;                   // .../addons/mergevod
$siteRoot  = dirname(__DIR__, 2);       // .../addons 的上级 = 站点根

/* ============ 读取插件配置 ============ */
$cfg = array();
$cfgFile = $addonRoot . '/config.php';
if (is_file($cfgFile)) {
    $items = include $cfgFile;
    foreach ((array)$items as $it) {
        if (isset($it['name'], $it['value'])) $cfg[$it['name']] = $it['value'];
    }
}
$cfgKey = isset($cfg['key']) ? trim($cfg['key']) : '';

/* ============ 鉴权 ============ */
$given = isset($_GET['key']) ? (string)$_GET['key'] : '';
if ($cfgKey === '' || $cfgKey === 'please-change-me') {
    http_response_code(403);
    echo '请先在【应用-应用市场-视频去重合并-配置】中设置鉴权密钥。';
    exit;
}
if (!hash_equals($cfgKey, $given)) {
    http_response_code(403);
    echo 'Key 错误，禁止访问。';
    exit;
}

/* ============ 读取数据库配置并连接 ============ */
$dbCfg = null;
foreach (array($siteRoot . '/application/database.php', $siteRoot . '/config/database.php') as $p) {
    if (is_file($p)) {
        $c = include $p;
        if (is_array($c)) {
            $dbCfg = (isset($c['database']) && is_array($c['database'])) ? $c['database'] : $c;
            break;
        }
    }
}
if (!$dbCfg || empty($dbCfg['hostname']) || empty($dbCfg['database'])) {
    http_response_code(500);
    echo '未找到数据库配置（application/database.php），请确认插件已放入苹果cms根目录的 addons 下。';
    exit;
}
$host = $dbCfg['hostname'];
$port = isset($dbCfg['hostport']) ? $dbCfg['hostport'] : 3306;
$db   = $dbCfg['database'];
$user = isset($dbCfg['username']) ? $dbCfg['username'] : '';
$pass = isset($dbCfg['password']) ? $dbCfg['password'] : '';
$pre  = isset($dbCfg['prefix'])   ? $dbCfg['prefix']   : 'mac_';
$chr  = isset($dbCfg['charset'])  ? $dbCfg['charset']  : 'utf8';
$table = $pre . 'vod';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset={$chr}",
        $user, $pass,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );
} catch (Exception $e) {
    http_response_code(500);
    echo '数据库连接失败：' . $e->getMessage();
    exit;
}

/* ============ 去重核心（与插件控制器一致） ============ */

/** 规范化片名：去全空白（含零宽空格） + 去末尾年份 */
function mv_normalize($name)
{
    $n = trim((string)$name);
    $n = preg_replace('/[\s\x{200B}\x{FEFF}\p{Zs}]+/u', '', $n);
    $n = preg_replace('/(?:[（(]?(?:19|20)\d{2}[）)]?)$/u', '', $n);
    return trim($n);
}

/** 拆分 $$$ 播放源字段 */
function mv_split($str)
{
    return ($str === '' || $str === null) ? array() : explode('$$$', $str);
}

/** 统计该记录的播放源个数 */
function mv_srcCount($r)
{
    return count(array_filter(mv_split(isset($r['vod_play_from']) ? $r['vod_play_from'] : ''), function ($x) { return $x !== ''; }));
}

/* ============ 检测重复 ============ */
$matchByType = (!isset($cfg['match_by_type']) || $cfg['match_by_type'] === 'on');
$rows = $pdo->query("SELECT * FROM `{$table}` ORDER BY vod_id ASC")->fetchAll();
$groups = array();
foreach ($rows as $r) {
    $name = mv_normalize(isset($r['vod_name']) ? $r['vod_name'] : '');
    if ($name === '') continue;
    $k = $matchByType ? ($name . '||' . (int)$r['type_id']) : $name;
    $groups[$k][] = $r;
}
$dupGroups = array();
foreach ($groups as $k => $g) {
    if (count($g) > 1) $dupGroups[] = $g;
}

/* ============ 执行合并 ============ */
if (count($dupGroups) === 0) {
    echo '没有发现可合并的重复记录';
    exit;
}

$doBackup = (!isset($cfg['backup']) || $cfg['backup'] === 'on');
$backupDir = $siteRoot . '/runtime';
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

foreach ($dupGroups as $group) {
    usort($group, function ($a, $b) {
        $ca = mv_srcCount($a); $cb = mv_srcCount($b);
        if ($ca !== $cb) return $ca > $cb ? -1 : 1;
        return (int)$a['vod_id'] <=> (int)$b['vod_id'];
    });
    $keeper = $group[0];
    $dups   = array_slice($group, 1);

    $froms   = mv_split(isset($keeper['vod_play_from']) ? $keeper['vod_play_from'] : '');
    $servers = mv_split(isset($keeper['vod_play_server']) ? $keeper['vod_play_server'] : '');
    $notes   = mv_split(isset($keeper['vod_play_note']) ? $keeper['vod_play_note'] : '');
    $urls    = mv_split(isset($keeper['vod_play_url']) ? $keeper['vod_play_url'] : '');

    foreach ($dups as $d) {
        $df = mv_split(isset($d['vod_play_from']) ? $d['vod_play_from'] : '');
        $ds = mv_split(isset($d['vod_play_server']) ? $d['vod_play_server'] : '');
        $dn = mv_split(isset($d['vod_play_note']) ? $d['vod_play_note'] : '');
        $du = mv_split(isset($d['vod_play_url']) ? $d['vod_play_url'] : '');
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

/* ============ 输出 ============ */
echo '执行完成：合并 ' . $mergedCnt . ' 组，新增合并播放源 ' . $addedSrc . ' 个，删除 '
   . count(array_unique($toDelete)) . ' 条重复记录';
