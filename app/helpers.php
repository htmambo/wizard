<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

use App\Repositories\Catalog;
use App\Repositories\Document;
use App\Repositories\Project;
use App\Repositories\Template;
use App\Repositories\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

/**
 * 生成路由url
 *
 * @param string $name
 * @param array $parameters
 * @param bool $absolute
 *
 * @return string
 */
function wzRoute($name, $parameters = [], $absolute = false)
{
    foreach ($parameters as $k => $v) {
        $parameters[$k] = urlencode($v);
    }
    return route($name, $parameters, $absolute);
}

/**
 * 文档类型标识转换
 *
 * @param $type
 *
 * @return string
 */
function documentType($type, $flip = false): string
{
    $types = [
        Document::TYPE_HTML    => 'html',
        Document::TYPE_DOC     => 'markdown',
        Document::TYPE_SWAGGER => 'swagger',
        Document::TYPE_TABLE   => 'table',
    ];
    if($flip) {
        $types = array_flip($types);
    }
    return $types[$type] ?? '';
}

/**
 * 将页面集合转换为层级结构的菜单
 *
 * 必须保证pages是按照pid进行asc排序的，否则可能会出现菜单丢失
 *
 * @param int   $projectID 当前项目ID
 * @param int   $pageID    选中的文档ID
 * @param array $exclude   排除的文档ID列表
 *
 * @return array
 */
function navigator(
    int $projectID,
    int $pageID = 0,
    $exclude = []
) {
    static $cached = [];

    $key = "{$projectID}:{$pageID}:" . implode(':', $exclude);
    if (isset($cached[$key])) {
        return $cached[$key];
    }

    $pages = Document::where('project_id', $projectID)->select(
        'id',
        'pid',
        'title',
        'project_id',
        'type',
        'status',
        'created_at',
        'sort_level'
    )->orderBy('pid')->get();

    $navigators = [];
    /** @var Document $page */
    foreach ($pages as $page) {
        if (in_array((int)$page->id, $exclude)) {
            continue;
        }

        $navigators[$page->id] = [
            'id'         => (int)$page->id,
            'name'       => $page->title,
            'pid'        => (int)$page->pid,
            'url'        => wzRoute('project:home', ['id' => $projectID, 'p' => $page->id]),
            'selected'   => $pageID === (int)$page->id,
            'type'       => documentType($page->type),
            'status'     => $page->status,
            'created_at' => $page->created_at,
            'sort_level' => $page->sort_level ?? 1000,
        ];
    }

    foreach ($navigators as &$nav) {
        if ($nav['pid'] === 0 || in_array($nav['id'], $exclude)) {
            continue;
        }

        if (isset($navigators[$nav['pid']])) {
            $navigators[$nav['pid']]['nodes'][] = &$nav;
        }
    }

    $res = array_filter(
        $navigators,
        function ($nav) {
            return $nav['pid'] === 0;
        }
    );

    $cached[$key] = $res;

    return $res;
}

/**
 * 导航排序，排序后，文件夹靠前，普通文件靠后
 *
 * @param array $navItems
 * @param int   $sortStyle
 *
 * @return array
 */
function navigatorSort($navItems, $sortStyle = Project::SORT_STYLE_DIR_FIRST)
{
    $sortItem = function ($a, $b) {
        try {
            if ($a['sort_level'] > $b['sort_level']) {
                return 1;
            } else {
                if ($a['sort_level'] < $b['sort_level']) {
                    return -1;
                } else {
                    return $a['created_at']->greaterThan($b['created_at']);
                }
            }
        } catch (Exception $e) {
            return 0;
        }
    };

    usort(
        $navItems,
        function ($a, $b) use ($sortItem, $sortStyle) {
            if ($sortStyle == Project::SORT_STYLE_FREE) {
                return $sortItem($a, $b);
            }

            $aIsFolder = !empty($a['nodes']);
            $bIsFolder = !empty($b['nodes']);

            $bothIsFolder  = $aIsFolder && $bIsFolder;
            $bothNotFolder = !$aIsFolder && !$bIsFolder;

            if ($bothIsFolder || $bothNotFolder) {
                return $sortItem($a, $b);
            } else {
                if ($aIsFolder) {
                    return -1;
                }

                return 1;
            }
        }
    );

    return $navItems;
}

/**
 * 文档模板
 *
 * @param int       $type
 * @param User|null $user
 *
 * @return array
 */
function wzTemplates($type = Template::TYPE_DOC, User $user = null): array
{
    if ($user == null && !Auth::guest()) {
        $user = Auth::user();
    }

    return Template::queryForShow($type, $user);
}

/**
 * 转换json为markdown table
 *
 * @param string $json
 *
 * @return string
 */
function convertJsonToMarkdownTable(string $json): string
{
    $markdowns = [
        ['参数名', '类型', '是否必须', '说明'],
        ['---', '---', '---', '---'],
    ];

    foreach (jsonFlatten($json) as $key => $type) {
        $markdowns[] = [$key, $type, '', ''];
    }

    $html = '';
    foreach ($markdowns as $line) {
        $html .= '| ' . implode(' | ', $line) . ' | ' . "\n";
    }

    return $html;
}

/**
 * Json扁平化为一维数组
 *
 * @param string $json
 *
 * @return array
 */
function jsonFlatten(string $json): array
{
    $object = json_decode(trim($json));
    $result = [];

    $setCurrent = function ($prefix, $type) use (&$result) {
        if (ends_with($prefix, '.[]')) {
            $result[substr($prefix, 0, -3)] = "array({$type})";
        } else {
            $result[$prefix] = $type;
        }
    };

    $flatten = function ($object, $prefix = '') use (&$flatten, &$result, $setCurrent) {
        $setCurrent($prefix, gettype($object));
        if (is_array($object)) {
            foreach ($object as $o) {
                $flatten($o, "{$prefix}.[]");
            }
        } else {
            if (is_object($object)) {
                foreach (get_object_vars($object) as $key => $obj) {
                    $flatten($obj, "{$prefix}.{$key}");
                }
            } else {
                $setCurrent($prefix, gettype($object));
            }
        }
    };

    if (is_array($object)) {
        $flatten($object, '');
    } else {
        if (is_object($object)) {
            foreach (get_object_vars($object) as $key => $obj) {
                $flatten($obj, $key);
            }
        }
    }

    return $result;
}

/**
 * 判断用户是否有通知
 *
 * @return bool
 */
function userHasNotifications()
{
    if (Auth::guest()) {
        return false;
    }

    return userNotificationCount() > 0;
}

/**
 * 用户通知消息数
 *
 * @param int $limit 显示限制数量，如果提供了，则返回string类型的数量展示，最大值为$limit，超过数量显示为"$limit+"
 *
 * @return int|string
 */
function userNotificationCount($limit = 0)
{
    if (Auth::guest()) {
        return 0;
    }

    $unreadCount = count(Auth::user()->unreadNotifications);
    if ($limit > 0) {
        return $unreadCount > $limit ? "{$limit}+" : $unreadCount;
    }

    return $unreadCount;
}

/**
 * 子文档列表
 *
 * @param $pid
 *
 * @return Collection
 */
function subDocuments($pid)
{
    return Document::where('pid', $pid)->select('id', 'title')->get();
}

/**
 * 静态资源版本
 *
 * @return string
 */
function resourceVersion()
{
    static $version = null;
    if (is_null($version)) {
        $version = 'v=' . config('wizard.resource_version');
    }

    return $version;
}


/**
 * 创建一个JWT Token
 *
 * @param array $payloads
 * @param int   $expire
 *
 * @return \Lcobucci\JWT\Token
 */
function jwt_create_token(array $payloads, $expire = 3600 * 2)
{
    $builder = new \Lcobucci\JWT\Builder();
    foreach ($payloads as $key => $payload) {
        $builder->set($key, $payload);
    }

    return $builder->setIssuedAt(time())
                   ->setExpiration(time() + $expire)
                   ->sign(new \Lcobucci\JWT\Signer\Hmac\Sha256(), config('wizard.jwt_secret'))
                   ->getToken();
}

/**
 * 解析Jwt Token
 *
 * @param string $token
 *
 * @return \Lcobucci\JWT\Token
 */
function jwt_parse_token(string $token)
{
    $token = (new \Lcobucci\JWT\Parser())->parse($token);

    if (!$token->verify(new \Lcobucci\JWT\Signer\Hmac\Sha256(), config('wizard.jwt_secret'))) {
        throw new \App\Exceptions\ValidationException('页面Token无效，请刷新后重试');
    }

    $validation = new \Lcobucci\JWT\ValidationData();
    if (!$token->validate($validation)) {
        throw new \App\Exceptions\ValidationException('页面已过期，请刷新页面后重新提交');
    }

    return $token;
}

/**
 * 生成用户头像
 *
 * @param string $id
 *
 * @return string
 */
function user_face($id)
{
    $identicon = new Identicon\Identicon();
    return $identicon->getImageDataUri($id);
}

/**
 * 获取所有用户列表
 *
 * @return Collection
 */
function users()
{
    static $users = null;
    if (is_null($users)) {
        $users = User::all();
    }

    return $users;
}

/**
 * 用户名列表（js数组）
 *
 * @param Collection $users
 * @param bool       $actived
 *
 * @return string
 */
function ui_usernames(Collection $users, $actived = true)
{
    return $users->filter(
        function (User $user) use ($actived) {
            return $actived ? $user->isActivated() : true;
        }
    )->map(
        function (User $user) {
            return "'{$user->name}'";
        }
    )->implode(',');
}

/**
 * 从内容中解析出用户
 *
 * @param string $content
 *
 * @return Collection|null
 */
function comment_filter_users($content)
{
    preg_match_all('/@{uid:(\d+)}/', $content, $matches);
    if (!empty($matches[1])) {
        $users = User::whereIn('id', $matches[1])->select('id', 'name', 'email')->get();
        return $users;
    }

    return null;
}


/**
 * 对评论信息预处理
 *
 * @param string $comment
 *
 * @return string
 */
function comment_filter(string $comment): string
{
    $matchRegexp = '/@(.*?)(?:\s|$)/';

    $users = (function ($content) use ($matchRegexp) {
        preg_match_all($matchRegexp, $content, $matches);
        if (!empty($matches[1])) {
            $users = User::whereIn('name', $matches[1])->select('id', 'name', 'email')->get();
            return $users;
        }

        return null;
    })(
        $comment
    );
    if (is_null($users) || $users->isEmpty()) {
        return $comment;
    }

    return preg_replace_callback(
        $matchRegexp,
        function ($matches) use ($users) {
            if (count($matches) < 2) {
                return $matches[0];
            }

            $user = $users->firstWhere('name', '=', $matches[1]);
            if (!empty($user)) {
                return "@{uid:{$user->id}} ";
            }

            return $matches[0];
        },
        $comment
    );
}


/**
 * 是否启用了LDAP支持
 *
 * @return bool
 */
function ldap_enabled(): bool
{
    static $enabled = null;
    if (is_null($enabled)) {
        $enabled = (bool)config('wizard.ldap.enabled');
    }

    return $enabled;
}

/**
 * 是否启用注册功能支持
 *
 * @return bool
 */
function register_enabled(): bool
{
    static $enabled = null;
    if (is_null($enabled)) {
        $enabled = (bool)config('wizard.register_enabled');
    }

    return $enabled;
}

/**
 * 站长统计代码区域
 *
 * @return string
 */
function statistics(): string
{
    $customFile = base_path('custom');
    if (file_exists("{$customFile}/statistics.html")) {
        return file_get_contents("{$customFile}/statistics.html");
    }

    return '';
}

/**
 * 判断内容是否为json格式
 *
 * @param string $content
 *
 * @return bool
 */
function isJson($content): bool
{
    // 尝试解析为json
    json_decode($content);
    return json_last_error() === JSON_ERROR_NONE;
}

/**
 * 转换 SQL 为 Markdown 表格
 *
 * @param string $sql
 *
 * @return string
 */
function convertSqlToMarkdownTable(string $sql)
{
    return convertSqlTo(
        $sql,
        function ($markdowns, $tableName, $tableComment) {
            if (empty($markdowns)) {
                return '';
            }

            $headers = [
                ['字段', '类型', '空', '说明'],
                ['---', '---', '---', '---',],
            ];

            array_unshift($markdowns, ...$headers);

            $html = '';
            foreach ($markdowns as $line) {
                $html .= '| ' . implode(' | ', $line) . ' | ' . "\n";
            }

            return "\n表名：**{$tableName}**   备注：*{$tableComment}*\n\n{$html}\n";
        }
    );
}

/**
 * 转换 SQL 为 HTML 表格
 *
 * @param string $sql
 *
 * @return string
 */
function convertSqlToHTMLTable(string $sql)
{
    return convertSqlTo(
        $sql,
        function ($markdowns, $tableName, $tableComment) {
            if (empty($markdowns)) {
                return '';
            }

            $html = '';
            foreach ($markdowns as $line) {
                $html .= '<tr><td>' . implode('</td><td>', $line) . "</td></tr>";
            }

            return <<<HEADER
<p><div style="float: left;" class="wz-table-name">❖ 表名： <b>{$tableName}</b></div>
<div style="float: right;" class="wz-table-desc">❖ 备注：<i>{$tableComment}</i></div>
</p>
<table class="table table-hover">
    <thead>
        <tr>
           <th>字段</th> 
           <th>类型</th> 
           <th>空</th> 
           <th>说明</th> 
        </tr>
    </thead>
    <tbody>{$html}</tbody>
</thead>
</table>
HEADER;

        }
    );
}

/**
 * SQL 格式转换
 *
 * @param string $sql
 * @param        $callback
 *
 * @return string
 */
function convertSqlTo(string $sql, $callback)
{
    try {
        $sql .= "\n";
        if(preg_match_all('@\)\s*ENGINE\s*=.+COMMENT\s*=\s*[^\n]+\n@i', $sql, $match)) {
            foreach($match as $v) {
                $str = $v[0];
                $rep = preg_replace('@\s+COMMENT\s*=\s*@', ' COMMENT ', $str);
                $sql = str_replace($str, $rep, $sql);
            }
        }
        $sql = trim($sql);
        $parser = new PHPSQLParser\PHPSQLParser();
        $parsed = $parser->parse($sql);

        if (!isset($parsed['CREATE'])) {
            return null;
        }

//        \Log::error('xxx', ['struct' => $parsed]);
        if ($parsed['CREATE']['expr_type'] === 'table') {
            $fields = $parsed['TABLE']['create-def']['sub_tree'];
            $tableName = $parsed['TABLE']['base_expr'];

            $markdowns = [];

            foreach ($fields as $field) {
                if ($field['sub_tree'][0]['expr_type'] == 'constraint') {
                    continue;
                }

                // 如果当前行不是列定义，则没有 sub_tree，比如 PRIMARY KEY(id)
                if (!isset($field['sub_tree'][1]['sub_tree'])) {
                    continue;
                }

                $type = $length = '';
                foreach ($field['sub_tree'][1]['sub_tree'] as $item) {
                    if ($item['expr_type'] == 'data-type') {
                        $type = $item['base_expr'] ?? '';
                        $length = $item['length'] ?? '';
                    }
                }

                $name = $field['sub_tree'][0]['base_expr'];
                $comment = trim($field['sub_tree'][1]['comment'] ?? '', "'");
                $nullable = $field['sub_tree'][1]['nullable'] ?? false;

//        $autoInc      = $field['sub_tree'][1]['auto_inc'] ?? false;
//        $primary      = $field['sub_tree'][1]['primary'] ?? false;
//        $defaultValue = $field['sub_tree'][1]['default'] ?? '-';

                $type = empty($length) ? $type : "{$type} ($length)";
                $markdowns[] = [trim($name, '`'), $type, $nullable ? 'Y' : 'N', $comment];
            }


            $tableComment = '-';
            $options = $parsed['TABLE']['options'] ?? [];
            if (!$options || empty($options)) {
                $options = [];
            }

            foreach ($options as $option) {
                $type = strtoupper($option['sub_tree'][0]['base_expr'] ?? '');
                if ($type === 'COMMENT') {
                    $tableComment = trim($option['sub_tree'][1]['base_expr'] ?? '', "'");
                    break;
                }
            }
            return $callback($markdowns, trim($tableName, '`'), $tableComment);
        }

        return '';
    } catch (Exception $ex) {
        return "{$ex->getMessage()} @{$ex->getFile()}:{$ex->getLine()}";
    }
}

/**
 * Markdown 预处理
 *
 * @param string $markdown
 *
 * @return string
 */
function processMarkdown(string $markdown): string
{
    $defaultTOC = config('wizard.markdown.default_toc');
    if (!in_array($defaultTOC, ['TOC', 'TOCM'])) {
        return $markdown;
    }

    if (Str::contains($markdown, ['[TOC]', '[TOCM]'])) {
        return $markdown;
    }

    return "[{$defaultTOC}]\n\n{$markdown}";
}

/**
 * 预处理 X-spreadsheet 表格内容
 *
 * @param string $content
 *
 * @return string
 */
function processSpreedSheet(string $content): string
{
    if (empty($content)) {
        $content = '[{"name":"sheet1","cols":{"len":25},"rows":{"len":100}}]';
    }

    $minRow = config('wizard.spreedsheet.min_rows');
    $minCol = config('wizard.spreedsheet.min_cols');

    $contentArray = json_decode($content, true);
    if (Str::startsWith($content, '[')) {
        $maxColsLen = $maxRowsLen = 0;
        foreach ($contentArray as $k => $arr) {
            $cur = processSpreedSheetSingle($arr, $minRow, $minCol);
            $contentArray[$k] = $cur;
            if ($cur['cols']['len'] > $maxColsLen) {
                $maxColsLen = $cur['cols']['len'];
            }

            if ($cur['rows']['len'] > $maxRowsLen) {
                $maxRowsLen = $cur['rows']['len'];
            }
        }

        foreach ($contentArray as $k => $arr) {
            $contentArray[$k]['cols']['len'] = $maxColsLen;
            $contentArray[$k]['rows']['len'] = $maxRowsLen;
        }
    } else {
        $contentArray = [processSpreedSheetSingle($contentArray, $minRow, $minCol)];
    }

    $content = json_encode($contentArray, JSON_UNESCAPED_UNICODE);
    return $content;
}

/**
 * 处理单一 sheet 的表格
 *
 * @param $contentArray
 * @param int $minRow
 * @param int $minCol
 *
 * @return mixed
 */
function processSpreedSheetSingle($contentArray, $minRow, $minCol)
{
    // 获取最大列号
    $maxColNum = collect($contentArray['rows'])
        // 只处理key为列的对象
        ->filter(function ($item, $key) {
            return is_numeric($key);
        })
        // 列过滤，去掉每一行最后多余的空列
        ->map(function ($item) {
            $cells = $item['cells'] ?? [];
            $lastIndex = count($cells);
            if ($lastIndex === 0) {
                return $item;
            }

            for ($i = $lastIndex; $i > 0; $i--) {
                if (!empty($cells[$i]['text'])) {
                    break;
                }

                $lastIndex = $i;
            }

            return $lastIndex - 1;
        })->max();

    // 行数据过滤，去掉多余的空行
    $contentArray['rows'] = processSpreedSheetRows($contentArray['rows']);

    // 获取最大行号
    $maxRowNum = collect(array_keys($contentArray['rows']))
        ->filter(function ($item) {
            return is_numeric($item);
        })->map(function ($item) {
            return (int)$item;
        })->max();

    $contentArray['cols']['len'] = ($maxColNum > $minCol ? $maxColNum : $minCol) + 1;
    $contentArray['rows']['len'] = ($maxRowNum > $minRow ? $maxRowNum : $minRow) + 1;
    return $contentArray;
}

/**
 * 行数据过滤，去掉多余的空行
 *
 * 只移除尾部的空行
 *
 * @param $originalRows
 *
 * @return array
 */
function processSpreedSheetRows($originalRows): array
{
    // 提取行的元信息
    $rows = collect($originalRows)->filter(function ($item, $key) {
        return !is_numeric($key);
    })->toArray();
    // 每一行的数据
    $rowsForCol = collect($originalRows)->filter(function ($item, $key) {
        return is_numeric($key);
    })->sortKeys()->toArray();

    // 逆序遍历行，直到遇到第一个存在非空列的行未知，截取从第一行开始到当前行
    $lastIndex = count($rowsForCol);
    if ($lastIndex > 0) {
        for ($i = $lastIndex; $i > 0; $i--) {
            $colCount = collect($rowsForCol[$i]['cells'] ?? [])->filter(function ($cell) {
                return !empty($cell['text']);
            })->count();

            if ($colCount > 0) {
                foreach (array_slice($rowsForCol, 0, $i + 1) as $index => $v) {
                    $rows["{$index}"] = $v;
                }

                break;
            }
        }
    }
    return $rows;
}

/**
 * 遍历导航项
 *
 * @param array $navigators
 * @param Closure $callback
 * @param array $parents
 * @param bool $callbackWithFullNavItem 是否在回调函数中传递完整的nav对象
 */
function traverseNavigators(
    array $navigators,
    \Closure $callback,
    array $parents = [],
    $callbackWithFullNavItem = false
) {
    foreach ($navigators as $nav) {
        $callback($callbackWithFullNavItem ? $nav : $nav['id'], $parents);

        if (!empty($nav['nodes'])) {
            array_push($parents, ['id' => $nav['id'], 'name' => $nav['name']]);
            traverseNavigators($nav['nodes'], $callback, $parents, $callbackWithFullNavItem);
            array_pop($parents);
        }
    }
}

/**
 * 资源地址 CDN 加速
 *
 * @param string $resourceUrl
 *
 * @return string
 */
function cdn_resource(string $resourceUrl)
{
    static $enabled = null;
    static $cdnUrl = null;

    if (is_null($enabled)) {
        $enabled = config('wizard.cdn.enabled', false);
    }

    if (!$enabled) {
        return $resourceUrl;
    }

    if (is_null($cdnUrl)) {
        $cdnUrl = rtrim(config('wizard.cdn.url'), '/');
    }

    // 这里替换的目的是，如果使用 七牛 CDN，是无法自己配置跨域的，必须提交工单才行
    // 这里用公共 CDN，可以避免字体跨域
    $replace = [
        '/assets/vendor/font-awesome4/css/font-awesome.min.css'   => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css',
        '/assets/vendor/material-design-icons/material-icons.css' => 'https://cdnjs.cloudflare.com/ajax/libs/material-design-icons/3.0.1/iconfont/material-icons.min.css',
    ];

    if (isset($replace[$resourceUrl])) {
        return $replace[$resourceUrl];
    }

    return "{$cdnUrl}{$resourceUrl}";
}

/**
 * Return all catalogs
 *
 * @return Catalog[]|Collection
 */
function allCatalogs()
{
    static $catalogs = null;
    if (is_null($catalogs)) {
        $catalogs = Catalog::all();
    }

    return $catalogs;
}

/**
 * 返回扮演者基本信息
 *
 * @return array|null
 */
function impersonateUser()
{
    /** @var User $user */
    $user = Auth::user();
    if (!$user->isImpersonated()) {
        return null;
    }

    $impersonateUser = $user->impersonator();
    return ['id' => $impersonateUser->id, 'name' => $impersonateUser->name];
}

/**
 * 文档按照指定 ID 顺序排列
 *
 * @param LengthAwarePaginator $docs
 * @param array|null           $sortIds
 *
 * @return mixed
 */
function sortDocumentBySortIds(LengthAwarePaginator $docs, array $sortIds = null)
{
    if (empty($sortIds)) {
        return $docs;
    }

    return $docs->sortBy(function ($doc) use ($sortIds) {
        return array_search($doc->id, $sortIds);
    });
}

/**
 * 从Cookie里获取已经设置的样式
 * @return string
 */
function getThemeByCookie()
{
    $name = \Illuminate\Support\Facades\Cookie::get('wizard-theme');
    if($name == 'dark') {
        $name = 'wz-dark-theme';
    } else {
        $name = '';
    }
    return $name;
}
function to_unicode($string)
{
    $str = mb_convert_encoding($string, 'UCS-2', 'UTF-8');
    $arrstr = str_split($str, 2);
    $unistr = '';
    foreach ($arrstr as $n) {
        $dec = hexdec(bin2hex($n));
        $unistr .= '&#' . $dec . ';';
    }
    return $unistr;
}

/**
 * 给图片添加水印
 * @param $file1 图片文件
 */
function watermark($file1) {
    $cfg = config('wizard.watermark');
    if ($cfg['enabled'] && (in_array($cfg['type'], ['logo', 'text']))) {
        $img  = Image::make($file1);
        $base = $cfg['position'];
        if ($cfg['type'] == 'logo') {
            $img->insert(public_path($cfg['pic']), $base, 0, 10);
        }
        else if ($cfg['font']) {
            $fontpath = $cfg['font'];
            $fontpath = public_path($fontpath);
            if (file_exists($fontpath)) {
                $text       = $cfg['text'] ?: config('app.name');
                $fontsize   = $cfg['size'];
                $color      = $cfg['color'] ?: 'FF0000';
                $background = $cfg['background'] ?: 'FFFFFF';
                //需要计算一下水印文字的展示位置
                $fontclassname = sprintf('\Intervention\Image\%s\Font', $img->getDriver()->getDriverName());
                $font          = new $fontclassname($text);
                $font->file($fontpath);
                $font->size($fontsize);
                $size = $font->getBoxSize();

                $base_x = $base_y = 0;
                $width  = $img->width();
                $height = $img->height();
                // define base color position
                $ar = explode('-', $base);
                if (in_array('left', $ar)) {
                    $base_x = 10;
                }
                if (in_array('right', $ar)) {
                    $base_x = $width - 10 - $size['width'] - $size[6];
                }
                if (in_array('top', $ar)) {
                    $base_y = 10 - $size[7];
                }
                if (in_array('bottom', $ar)) {
                    $base_y = $height - 10 - $size['height'] - $size[7];
                }
                //位置计算完毕
                for ($i = 0; $i < 5; $i++) {
                    for ($j = 0; $j < 5; $j++) {
                        $img->text($text, $base_x + $i, $base_y + $j, function ($font) use ($fontpath, $fontsize, $background) {
                            $font->file($fontpath);
                            $font->size($fontsize);
                            $font->color($background);
                        });
                    }
                }
                $img->text($text, $base_x + 2, $base_y + 2, function ($font) use ($fontpath, $fontsize, $color) {
                    $font->file($fontpath);
                    $font->size($fontsize);
                    $font->color($color);
                });

            }
        }
        $img->save();
    }
}

/**
 * 格式化HTML代码以方便前端进行对比
 * @param string $content
 * @return mixed|string|string[]|null
 */
function formatHtml($content = '')
{
    if(!$content) return $content;
    //格式化一下以方便前端进行差异对比
    $content = preg_replace('@<\s+@i', '<', $content);
    $content = preg_replace('@\s+>@i', '>', $content);
    $content = preg_replace('@\s+/>@i', ' />', $content);

    $content = preg_replace('@<p([^>]*)>(\s|&nbsp;)+@i', '<p\\1>', $content);
    $content = preg_replace('@<p>(\s|&nbsp;)+@i', '<p>', $content);
    $content = preg_replace('@<br([^>]*)>(\s|&nbsp;)+@i', '<br\\1>' . PHP_EOL, $content);
    $content = preg_replace('@<br([^>]*)>\s*</p>@i', '</p>' . PHP_EOL, $content);

    $content = preg_replace('@<p>\s*<br([^>]*)>\s*@i', '<p>', $content);

    $content = preg_replace('@<p([^>]*)>\s*</p>@i', '' . PHP_EOL, $content);

    $content = preg_replace('@\s*<pre([^>]*)>@i', "\n<pre\\1>", $content);
    $content = preg_replace('@</pre>\s*@i', "</pre>\n", $content);

    $content = preg_replace('@\s*<p>@i', "\n<p>", $content);
    $content = preg_replace('@</p>\s*@i', "</p>\n", $content);
    $content = preg_replace('@</p>\s+<p>@i', "</p>\n<p>", $content);
    return $content;
}

/**
 * 生成read页面的token
 * 在生成前需要自行判断当前用户对$id和$page_id的权限
 * @param $id
 * @param $page_id
 * @return false|string
 */
function genReadToken($id, $page_id){
    $pre = date('ymdHis');
//    echo $pre . '<br />';
//    echo dechex($pre) . '<br />';
    return shulz($id, $page_id, $pre);
}
function shulz($id, $page_id, $pre) {
    $key = config('app.key');
    if(!$key) {
        return false;
    }
    $tmp = md5($key . '_' . $id . '_' . $page_id . '_' . $pre);
    $key = config('app.key');
    if(!$key) {
        return false;
    }
    for($i=0; $i<strlen($pre); $i++) {
        $pos = $i * 2 + 1;
        $tmp = substr($tmp, 0, $pos) . substr($pre, $i, 1) . substr($tmp, $pos+1);
    }
    return $tmp;
}
function checkReadToken($id, $page_id, $token) {
    $life = 60;         //TOKEN的有效期，单位为秒
    $pre = '';
    for($i=0;$i<12;$i++) {
        $pos = $i * 2 + 1;
        $pre .= substr($token, $pos, 1);
    }
    $tmp = shulz($id, $page_id, $pre);
    return $token === $tmp && $pre>=date('ymdHis') - $life;
}