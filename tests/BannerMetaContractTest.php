<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
}

class BannerMetaQuery
{
    public $fields;
    public $limitValue;
    public $operation;
    public $orderBy;
    public $rowsValue;
    public $table;
    public $whereValues = array();

    public function __construct($operation, $fields = array())
    {
        $this->operation = $operation;
        $this->fields = $fields;
    }

    public function from($table)
    {
        $this->table = $table;
        return $this;
    }

    public function where($condition, $value, $secondValue = null)
    {
        $this->whereValues[] = array($condition, $value, $secondValue);
        return $this;
    }

    public function order($column, $direction)
    {
        $this->orderBy = array($column, $direction);
        return $this;
    }

    public function limit($limit)
    {
        $this->limitValue = (int)$limit;
        return $this;
    }

    public function rows($rows)
    {
        $this->rowsValue = $rows;
        return $this;
    }
}

class Typecho_Db
{
    const SORT_DESC = 'DESC';

    public static $instance;
    public $contents = array();
    public $fields = array();

    public static function get()
    {
        return self::$instance;
    }

    public function select()
    {
        return new BannerMetaQuery('select', func_get_args());
    }

    public function update($table)
    {
        return (new BannerMetaQuery('update'))->from($table);
    }

    public function insert($table)
    {
        return (new BannerMetaQuery('insert'))->from($table);
    }

    public function delete($table)
    {
        return (new BannerMetaQuery('delete'))->from($table);
    }

    private function whereValue($query, $needle, $default = null)
    {
        foreach ($query->whereValues as $where) {
            if (strpos($where[0], $needle) !== false) {
                if ($needle === 'name = ?' && strpos($where[0], 'cid = ? AND name = ?') !== false) {
                    return $where[2];
                }
                return $where[1];
            }
        }
        return $default;
    }

    public function fetchRow($query)
    {
        if ($query->table !== 'table.fields') {
            return array();
        }

        $cid = (int)$this->whereValue($query, 'cid = ?', 0);
        $name = (string)$this->whereValue($query, 'name = ?', '');
        return isset($this->fields[$cid][$name]) ? $this->fields[$cid][$name] : array();
    }

    public function fetchAll($query)
    {
        if ($query->table !== 'table.contents') {
            return array();
        }

        $beforeCid = (int)$this->whereValue($query, 'cid < ?', PHP_INT_MAX);
        $rows = array();
        foreach ($this->contents as $cid => $type) {
            if ($cid < $beforeCid && in_array($type, array('post', 'page'), true)) {
                $rows[] = array('cid' => $cid);
            }
        }
        usort($rows, function ($left, $right) {
            return $right['cid'] - $left['cid'];
        });
        return array_slice($rows, 0, $query->limitValue);
    }

    public function query($query)
    {
        $cid = (int)$this->whereValue($query, 'cid = ?', 0);
        $name = (string)$this->whereValue($query, 'name = ?', '');

        if ($query->operation === 'delete') {
            unset($this->fields[$cid][$name]);
            return 1;
        }

        if ($query->operation === 'insert') {
            $cid = (int)$query->rowsValue['cid'];
            $name = (string)$query->rowsValue['name'];
        }

        $this->fields[$cid][$name] = array(
            'type' => $query->rowsValue['type'],
            'str_value' => $query->rowsValue['str_value'],
            'int_value' => $query->rowsValue['int_value'],
            'float_value' => $query->rowsValue['float_value']
        );
        return 1;
    }
}

function bannerMetaField($value)
{
    return array(
        'type' => 'str',
        'str_value' => $value,
        'int_value' => 0,
        'float_value' => 0
    );
}

function bannerMetaJson($source, $width, $height)
{
    return json_encode(array(
        'version' => 1,
        'source' => $source,
        'width' => $width,
        'height' => $height
    ), JSON_UNESCAPED_SLASHES);
}

require_once dirname(__DIR__) . '/libs/services/ParseImg.php';

$failures = 0;

function bannerMetaAssertSame($expected, $actual, $message)
{
    global $failures;
    if ($expected === $actual) {
        echo "ok - {$message}\n";
        return;
    }

    ++$failures;
    echo "not ok - {$message}\n";
    echo '  expected: ' . var_export($expected, true) . "\n";
    echo '  actual:   ' . var_export($actual, true) . "\n";
}

$source = 'https://example.test/cover.jpg';
bannerMetaAssertSame(
    array(1920, 1080),
    VOID_ParseImgInfo::getBannerMetaDimensions(bannerMetaJson($source, 1920, 1080), $source),
    '有效同源元数据通过校验'
);
bannerMetaAssertSame(null, VOID_ParseImgInfo::getBannerMetaDimensions(bannerMetaJson(' ' . $source, 1920, 1080), $source), '来源必须完全一致');
bannerMetaAssertSame(null, VOID_ParseImgInfo::getBannerMetaDimensions(bannerMetaJson('other', 1, 1), $source), '来源不匹配被拒绝');
bannerMetaAssertSame(null, VOID_ParseImgInfo::getBannerMetaDimensions('{"version":1,"source":"x","width":"1","height":1}', 'x'), '字符串宽度被拒绝');
bannerMetaAssertSame(null, VOID_ParseImgInfo::getBannerMetaDimensions(bannerMetaJson('x', 100001, 1), 'x'), '超界宽度被拒绝');
bannerMetaAssertSame(array(800, 600), VOID_ParseImgInfo::getUrlDimensions($source . '?vwid=1#vwid=800&vhei=600'), 'fragment 尺寸兼容读取');
bannerMetaAssertSame(null, VOID_ParseImgInfo::getUrlDimensions($source . '#vwid=0&vhei=600'), '非法 URL 尺寸被拒绝');

$db = new Typecho_Db();
Typecho_Db::$instance = $db;
$db->contents = array(5 => 'post', 4 => 'page', 3 => 'post', 2 => 'post', 1 => 'post');
$db->fields = array(
    5 => array('bannerMeta' => bannerMetaField(bannerMetaJson('old', 1, 1))),
    4 => array(
        'banner' => bannerMetaField('https://example.test/four.jpg'),
        'bannerMeta' => bannerMetaField(bannerMetaJson('https://example.test/four.jpg', 400, 300))
    ),
    3 => array('banner' => bannerMetaField('https://example.test/three.jpg#vwid=300&vhei=200')),
    2 => array('banner' => bannerMetaField('https://example.test/two.jpg')),
    1 => array('banner' => bannerMetaField('https://example.test/fail.jpg'))
);
$resolved = array();
$resolver = function ($url) use (&$resolved) {
    $resolved[] = $url;
    if (strpos($url, 'fail.jpg') !== false) {
        return false;
    }
    return array('width' => 1600, 'height' => 900);
};

$first = VOID_ParseImgInfo::backfillBannerMeta(0, false, 2, $resolver);
bannerMetaAssertSame(
    array('success' => 1, 'skipped' => 1, 'failed' => 0, 'processed' => 2, 'beforeCid' => 4, 'hasMore' => true),
    $first,
    '首批清理空封面并跳过有效数据'
);
bannerMetaAssertSame(false, isset($db->fields[5]['bannerMeta']), '空封面清理旧元数据');
bannerMetaAssertSame(array(), $resolved, '跳过和清理不触发远程探测');

$second = VOID_ParseImgInfo::backfillBannerMeta($first['beforeCid'], false, 2, $resolver);
bannerMetaAssertSame(2, $second['success'], '第二批同时处理 URL 尺寸和远程尺寸');
bannerMetaAssertSame(2, $second['beforeCid'], '第二批游标推进到最后一篇');
bannerMetaAssertSame(array('https://example.test/two.jpg'), $resolved, 'URL 自带尺寸不调用解析器');
bannerMetaAssertSame(
    bannerMetaJson('https://example.test/three.jpg#vwid=300&vhei=200', 300, 200),
    $db->fields[3]['bannerMeta']['str_value'],
    'URL 尺寸写入独立元数据且不修改 banner'
);

$third = VOID_ParseImgInfo::backfillBannerMeta($second['beforeCid'], false, 2, $resolver);
bannerMetaAssertSame(1, $third['failed'], '解析失败降级为失败计数');
bannerMetaAssertSame(1, $third['beforeCid'], '失败项仍推进游标');
bannerMetaAssertSame(false, $third['hasMore'], '末批正确结束');

$forceCalls = array();
$forceStatus = VOID_ParseImgInfo::updateBannerMeta(4, true, function ($url) use (&$forceCalls) {
    $forceCalls[] = $url;
    return array('width' => 800, 'height' => 600);
});
bannerMetaAssertSame('success', $forceStatus, '强制模式重新检测有效同源数据');
bannerMetaAssertSame(array('https://example.test/four.jpg'), $forceCalls, '强制模式调用实际尺寸解析器');
bannerMetaAssertSame(
    bannerMetaJson('https://example.test/four.jpg', 800, 600),
    $db->fields[4]['bannerMeta']['str_value'],
    '强制模式覆盖旧尺寸'
);

$pluginSource = file_get_contents(dirname(__DIR__) . '/Plugin.php');
$actionSource = file_get_contents(dirname(__DIR__) . '/Action.php');
bannerMetaAssertSame(2, substr_count($pluginSource, "->finishPublish = array('VOID_Plugin', 'updateContent')"), '文章和页面只在发布完成后处理');
bannerMetaAssertSame(0, substr_count($pluginSource, 'finishSave'), '不注册 finishSave');
bannerMetaAssertSame(1, preg_match('/VOID_ParseImgInfo::parse\(\$widget->cid\);[\s\S]*VOID_ParseImgInfo::updateBannerMeta\(\$widget->cid\)/', $pluginSource), '封面处理位于既有发布链路后部');
bannerMetaAssertSame(1, preg_match('/banner_meta_backfill[\s\S]*REQUEST_METHOD[\s\S]*POST[\s\S]*require_admin_user[\s\S]*getToken/', $actionSource), '回填 action 要求 POST、管理员和 token');
bannerMetaAssertSame(1, preg_match('/开始历史回填/', $pluginSource), 'VOID 设置页提供历史回填入口');
bannerMetaAssertSame(1, preg_match('/<section class="typecho-option void-banner-meta-settings">[\s\S]*<p class="description">/', $pluginSource), '封面元数据区域复用 Typecho 原生表单排版');
bannerMetaAssertSame(1, preg_match('/method="get"[\s\S]*name="bannerMetaBackfill"[\s\S]*<button class="btn" type="submit">开始历史回填<\/button>/', $pluginSource), '历史回填入口使用原生 GET 按钮进入确认态');
bannerMetaAssertSame(0, substr_count($pluginSource, '<a class="btn"'), '历史回填入口不使用链接模拟按钮');
bannerMetaAssertSame(0, substr_count($pluginSource, '返回 VOID 设置'), '回填操作区不显示冗余返回按钮');
bannerMetaAssertSame(0, substr_count($pluginSource, 'background:#fff'), '封面元数据区域不使用独立白色卡片');
bannerMetaAssertSame(0, substr_count($pluginSource, '历史回填按 <code>cid</code>'), '设置页不重复 README 中的批处理细节');
bannerMetaAssertSame(1, preg_match('/message[^\"]*void-banner-meta-result/', $pluginSource), '回填结果使用 Typecho 原生消息样式');
bannerMetaAssertSame(1, preg_match('/!\$force && \(!\$hasResult \|\| !\$hasMore\)/', $pluginSource), '强制检测只在首次确认或全部完成后显示');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} banner metadata contract test(s) failed.\n");
    exit(1);
}

echo "All banner metadata contract tests passed.\n";
