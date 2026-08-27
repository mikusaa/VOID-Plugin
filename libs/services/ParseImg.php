<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 为图片获取长款信息
 * 
 * @author AlanDecode | 熊猫小A
 */

Class VOID_ParseImgInfo
{
    const BANNER_META_FIELD = 'bannerMeta';

    private static function hasDomSupport()
    {
        return class_exists('DOMDocument') && class_exists('DOMXPath');
    }

    private static function contentToHtml($content)
    {
        $content = (string)$content;

        if (0 === strpos($content, '<!--html-->')) {
            return $content;
        }

        if (class_exists('Markdown') && method_exists('Markdown', 'convert')) {
            return (string)Markdown::convert($content);
        }

        return $content;
    }

    private static function collectImageSources($html)
    {
        if (!self::hasDomSupport()) {
            return array();
        }

        $html = trim((string)$html);
        if ($html === '') {
            return array();
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrappedHtml = '<?xml encoding="UTF-8"><!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        $options = 0;
        foreach (array('LIBXML_COMPACT', 'LIBXML_NOERROR', 'LIBXML_NOWARNING') as $flag) {
            if (defined($flag)) {
                $options |= constant($flag);
            }
        }

        $previousErrorHandling = libxml_use_internal_errors(true);
        $loaded = $options
            ? $dom->loadHTML($wrappedHtml, $options)
            : $dom->loadHTML($wrappedHtml);

        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
            return array();
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//img');
        $sources = array();

        if ($nodes instanceof DOMNodeList) {
            foreach ($nodes as $node) {
                $sources[] = $node instanceof DOMElement ? trim((string)$node->getAttribute('src')) : '';
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        return $sources;
    }

    private static function updateContentText($cid, $content)
    {
        $db = Typecho_Db::get();
        $db->query($db->update('table.contents')
            ->rows(array('text' => (string)$content))
            ->where('cid = ?', $cid));
    }

    private static function getFieldRow($cid, $name)
    {
        $db = Typecho_Db::get();
        return $db->fetchRow($db->select('type', 'str_value', 'int_value', 'float_value')
            ->from('table.fields')
            ->where('cid = ? AND name = ?', (int)$cid, (string)$name)
            ->limit(1));
    }

    private static function getFieldValue($cid, $name)
    {
        $row = self::getFieldRow($cid, $name);
        if (!is_array($row) || !isset($row['type'])) {
            return '';
        }

        $key = $row['type'] . '_value';
        return isset($row[$key]) ? (string)$row[$key] : '';
    }

    private static function deleteField($cid, $name)
    {
        $db = Typecho_Db::get();
        return $db->query($db->delete('table.fields')
            ->where('cid = ? AND name = ?', (int)$cid, (string)$name));
    }

    private static function setStringField($cid, $name, $value)
    {
        $db = Typecho_Db::get();
        $rows = array(
            'type' => 'str',
            'str_value' => (string)$value,
            'int_value' => 0,
            'float_value' => 0
        );
        $exists = self::getFieldRow($cid, $name);

        if ($exists) {
            return $db->query($db->update('table.fields')
                ->rows($rows)
                ->where('cid = ? AND name = ?', (int)$cid, (string)$name));
        }

        $rows['cid'] = (int)$cid;
        $rows['name'] = (string)$name;
        return $db->query($db->insert('table.fields')->rows($rows));
    }

    private static function normalizeDimension($value)
    {
        if (is_int($value)) {
            $dimension = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $dimension = (int)$value;
        } else {
            return null;
        }

        return $dimension >= 1 && $dimension <= 100000 ? $dimension : null;
    }

    /**
     * 从封面 URL 的 query 或 fragment 中读取可信尺寸。
     */
    public static function getUrlDimensions($source)
    {
        if (!is_string($source) || trim($source) === '') {
            return null;
        }

        $parts = parse_url($source);
        if (false === $parts || !is_array($parts)) {
            return null;
        }

        $parameters = array();
        foreach (array('query', 'fragment') as $partName) {
            if (!isset($parts[$partName]) || !is_string($parts[$partName])) {
                continue;
            }

            $current = array();
            parse_str(html_entity_decode($parts[$partName], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $current);
            $parameters = array_merge($parameters, $current);
        }

        if (!isset($parameters['vwid'], $parameters['vhei'])
            || !is_scalar($parameters['vwid'])
            || !is_scalar($parameters['vhei'])) {
            return null;
        }

        $width = self::normalizeDimension((string)$parameters['vwid']);
        $height = self::normalizeDimension((string)$parameters['vhei']);
        return null !== $width && null !== $height ? array($width, $height) : null;
    }

    /**
     * 校验 bannerMeta，并返回尺寸或 null。
     */
    public static function getBannerMetaDimensions($value, $source)
    {
        if (!is_string($value) || !is_string($source) || trim($source) === '') {
            return null;
        }

        $source = trim($source);
        $meta = json_decode($value, true);
        if (!is_array($meta)
            || !isset($meta['version'], $meta['source'], $meta['width'], $meta['height'])
            || $meta['version'] !== 1
            || !is_string($meta['source'])
            || $meta['source'] !== $source
            || !is_int($meta['width'])
            || !is_int($meta['height'])) {
            return null;
        }

        $width = self::normalizeDimension($meta['width']);
        $height = self::normalizeDimension($meta['height']);
        return null !== $width && null !== $height ? array($width, $height) : null;
    }

    public static function buildBannerMeta($source, $width, $height)
    {
        $source = trim((string)$source);
        $width = self::normalizeDimension($width);
        $height = self::normalizeDimension($height);
        if ($source === '' || null === $width || null === $height) {
            return null;
        }

        return json_encode(array(
            'version' => 1,
            'source' => $source,
            'width' => $width,
            'height' => $height
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 补全单篇内容的封面尺寸元数据。
     *
     * @return string success|skipped|failed
     */
    public static function updateBannerMeta($cid, $force = false, $sizeResolver = null)
    {
        $cid = (int)$cid;
        if ($cid <= 0) {
            return 'failed';
        }

        $source = trim(self::getFieldValue($cid, 'banner'));
        $currentMetaRow = self::getFieldRow($cid, self::BANNER_META_FIELD);
        $currentMeta = '';
        if (is_array($currentMetaRow) && isset($currentMetaRow['type'])) {
            $currentMetaKey = $currentMetaRow['type'] . '_value';
            $currentMeta = isset($currentMetaRow[$currentMetaKey])
                ? (string)$currentMetaRow[$currentMetaKey]
                : '';
        }
        if ($source === '') {
            if ($currentMetaRow) {
                self::deleteField($cid, self::BANNER_META_FIELD);
                return 'success';
            }
            return 'skipped';
        }

        if (!$force && null !== self::getBannerMetaDimensions($currentMeta, $source)) {
            return 'skipped';
        }

        $dimensions = $force ? null : self::getUrlDimensions($source);
        if (null === $dimensions) {
            $size = is_callable($sizeResolver)
                ? call_user_func($sizeResolver, $source)
                : self::GetImageSize($source);
            if (!is_array($size) || !isset($size['width'], $size['height'])) {
                return 'failed';
            }

            $width = self::normalizeDimension($size['width']);
            $height = self::normalizeDimension($size['height']);
            if (null === $width || null === $height) {
                return 'failed';
            }
            $dimensions = array($width, $height);
        }

        $meta = self::buildBannerMeta($source, $dimensions[0], $dimensions[1]);
        if (null === $meta) {
            return 'failed';
        }

        self::setStringField($cid, self::BANNER_META_FIELD, $meta);
        return 'success';
    }

    public static function getParseImgLimit()
    {
        $limit = 10;
        try {
            $configured = Helper::options()->plugin('VOID')->parseImgLimit;
            if (is_scalar($configured) && preg_match('/^[1-9][0-9]*$/D', (string)$configured)) {
                $limit = (int)$configured;
            }
        } catch (Exception $e) {
        }

        return max(1, min(1000, $limit));
    }

    /**
     * 按 cid 倒序处理一批历史封面，单条失败不终止游标。
     */
    public static function backfillBannerMeta($beforeCid = 0, $force = false, $limit = null, $sizeResolver = null)
    {
        $db = Typecho_Db::get();
        $limit = null === $limit ? self::getParseImgLimit() : max(1, min(1000, (int)$limit));
        $query = $db->select('cid')
            ->from('table.contents')
            ->where('type IN ?', array('post', 'page'))
            ->order('cid', Typecho_Db::SORT_DESC)
            ->limit($limit + 1);

        $beforeCid = (int)$beforeCid;
        if ($beforeCid > 0) {
            $query->where('cid < ?', $beforeCid);
        }

        $rows = $db->fetchAll($query);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $result = array(
            'success' => 0,
            'skipped' => 0,
            'failed' => 0,
            'processed' => 0,
            'beforeCid' => 0,
            'hasMore' => $hasMore
        );

        foreach ($rows as $row) {
            $cid = isset($row['cid']) ? (int)$row['cid'] : 0;
            if ($cid <= 0) {
                continue;
            }

            $status = 'failed';
            try {
                $status = self::updateBannerMeta($cid, (bool)$force, $sizeResolver);
            } catch (Exception $e) {
                $status = 'failed';
            } catch (Throwable $e) {
                $status = 'failed';
            }

            if (!isset($result[$status])) {
                $status = 'failed';
            }
            $result[$status]++;
            $result['processed']++;
            $result['beforeCid'] = $cid;
        }

        if (!$result['processed']) {
            $result['hasMore'] = false;
        }

        return $result;
    }

    /**
     * 解析 $cid 指定文章中的图片数据
     * 
     * @return array (图片总数 | 执行解析数 | 跳过数 | 失败数)
     */
    public static function parse($cid)
    {
        $db = Typecho_Db::get();

        $content = $db->fetchRow($db->select('text')
                ->from('table.contents')
                ->where('cid = ?', $cid));
        $content = isset($content['text']) ? (string)$content['text'] : '';

        $html = self::contentToHtml($content);
        $imgArr = self::collectImageSources($html);

        if (!count($imgArr)) return array(0, 0, 0, 0);

        $limit = self::getParseImgLimit();

        $result = array(0, 0, 0, 0);
        $result[0] = count($imgArr);
        $updatedContent = $content;
        $contentChanged = false;

        foreach ($imgArr as $src) {
            $src = trim((string)$src);

            if ($src === '') {
                $result[3]++;
                continue;
            }

            if (strpos($src, 'vwid') !== false) {
                $result[2]++;
                continue; // 已经处理过该图片
            }

            $size = self::GetImageSize($src);
            if ($size == false) {
                $result[3]++;
                continue; // 该图片获取失败
            }

            $src_new = $src.'#vwid='.$size['width'].'&vhei='.$size['height'];
            echo $src .' => '. $src_new.'<br>'.PHP_EOL;

            $updatedContent = str_replace($src, $src_new, $updatedContent, $replaceCount);
            if ($replaceCount > 0) {
                $contentChanged = true;
            }
            $result[1]++;

            if (++$GLOBALS['ImgParsed'] >= $limit) {
                if ($contentChanged) {
                    self::updateContentText($cid, $updatedContent);
                }
                return $result;
            }
        }

        if ($contentChanged) {
            self::updateContentText($cid, $updatedContent);
        }

        return $result;
    }

    /**
     * 清理连接中包含的长宽信息
     * 
     * @return int 清理图片数
     */
    public static function clean($cid)
    {
        $db = Typecho_Db::get();

        $content = $db->fetchRow($db->select('text')
                ->from('table.contents')
                ->where('cid = ?', $cid));
        $content = $content['text'] ?? null;
        if ($content === null) return 0;

        $count = 0;
        $content = preg_replace("/#vwid=\d{0,5}&vhei=\d{0,5}/i", '', $content, -1, $count);
        
        if ($count) {
            self::updateContentText($cid, $content);
        }

        return $count;
    }

    /**
     * 获取远程图片的宽高和体积大小
     *
     * @param string $url 远程图片的链接
     * @return false|array
     */
    public static function GetImageSize($url) 
    {
        $meta = @getimagesize($url);
        if ($meta == false) {
            // 尝试另一种方式
            $meta = self::GetImageSizeCURL($url);
            if ($meta == false) return false;
        }

        return array('width'=>$meta[0],'height'=>$meta[1]);
    }

    /**
     * 通过 CURL 方式获取
     */
    private static function GetImageSizeCURL($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RANGE, '0-167');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_REFERER, Helper::options()->siteUrl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $dataBlock = curl_exec($ch);
        curl_close($ch);

        if (!$dataBlock) return false;

        return getimagesize('data://image/jpeg;base64,'. base64_encode($dataBlock));
    }
}
