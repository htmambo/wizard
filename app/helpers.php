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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

if (!function_exists('wzRoute')) {
    /**
     * 生成路由url
     *
     * @param string $name
     * @param array  $parameters
     * @param bool   $absolute
     *
     * @return string
     */
    function wzRoute($name, $parameters = [], $absolute = false){
        // T7:委托给 App\Support\RouteHelper(渐进 shim,函数签名完全不变)
        return \App\Support\RouteHelper::url($name, $parameters, $absolute);
    }

    /**
     * 文档类型标识转换
     *
     * @param      $type
     * @param bool $flip
     *
     * @return string
     */
    function documentType($type, $flip = false): string{
        // T7:委托给 App\Support\DocumentTypeHelper
        return \App\Support\DocumentTypeHelper::convert($type, $flip);
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
    ){
        // 走 NavigatorCache,配置项关闭时退化到无缓存模式
        // (T3 加固:替代原进程级 static 缓存,避免 PHP-FPM worker 间漂移)
        return \App\Support\NavigatorCache::get($projectID, $pageID, $exclude);
    }

    /**
     * 导航排序，排序后，文件夹靠前，普通文件靠后
     *
     * @param array $navItems
     * @param int   $sortStyle
     *
     * @return array
     */
    function navigatorSort($navItems, $sortStyle = Project::SORT_STYLE_DIR_FIRST){
        // T7 续:委托给 App\Support\MiscHelper
        return \App\Support\MiscHelper::navigatorSort($navItems, (int) $sortStyle);
    }

    /**
     * 文档模板
     *
     * @param int       $type
     * @param User|null $user
     *
     * @return array
     */
    function wzTemplates($type = Template::TYPE_DOC, ?User $user = NULL): array{
        // T7:委托给 App\Support\TemplateHelper
        return \App\Support\TemplateHelper::list($type, $user);
    }

    /**
     * 转换json为markdown table
     *
     * @param string $json
     *
     * @return string
     */
    function convertJsonToMarkdownTable(string $json): string{
        // T7:委托给 App\Support\SqlConvertHelper
        return \App\Support\SqlConvertHelper::jsonToMarkdownTable($json);
    }

    /**
     * Json扁平化为一维数组
     *
     * @param string $json
     *
     * @return array
     */
    function jsonFlatten(string $json): array{
        // T7:委托给 App\Support\JsonFlattenHelper
        return \App\Support\JsonFlattenHelper::flatten($json);
    }

    /**
     * 判断用户是否有通知
     *
     * @return bool
     */
    function userHasNotifications(){
        // T7:委托给 App\Support\ConfigHelper
        return \App\Support\ConfigHelper::userHasNotifications();
    }

    /**
     * 用户通知消息数
     *
     * @param int $limit 显示限制数量，如果提供了，则返回string类型的数量展示，最大值为$limit，超过数量显示为"$limit+"
     *
     * @return int|string
     */
    function userNotificationCount($limit = 0){
        // T7:委托给 App\Support\ConfigHelper
        return \App\Support\ConfigHelper::userNotificationCount($limit);
    }

    /**
     * 子文档列表
     *
     * @param $pid
     *
     * @return Collection
     */
    function subDocuments($pid){
        // T7:委托给 App\Support\UserHelper
        return \App\Support\UserHelper::subDocuments($pid);
    }

    /**
     * 静态资源版本
     *
     * @return string
     */
    function resourceVersion(){
        // T7:委托给 App\Support\ConfigHelper
        return \App\Support\ConfigHelper::resourceVersion();
    }


    /**
     * 创建一个JWT Token
     *
     * @param array $payloads
     * @param int   $expire
     *
     * @return \Lcobucci\JWT\UnencryptedToken
     */
    function jwt_create_token(array $payloads, $expire = 3600 * 2){
        // T7 续:委托给 App\Support\JwtFactory
        return \App\Support\JwtFactory::create($payloads, (int) $expire);
    }

    /**
     * 解析Jwt Token
     *
     * @param string $token
     *
     * @return \Lcobucci\JWT\Token
     */
    function jwt_parse_token(string $token){
        // T7 续:委托给 App\Support\JwtFactory
        return \App\Support\JwtFactory::parse($token);
    }

    /**
     * 生成用户头像
     *
     * @param string $id
     *
     * @return string
     */
    function user_face($id){
        // T7:委托给 App\Support\AvatarHelper
        return \App\Support\AvatarHelper::userFace($id);
    }

    /**
     * 获取所有用户列表
     *
     * @return Collection
     */
    function users(){
        // T7:委托给 App\Support\UserHelper
        return \App\Support\UserHelper::users();
    }

    /**
     * 用户名列表（js数组）
     *
     * @param Collection $users
     * @param bool       $actived
     *
     * @return string
     */
    function ui_usernames($users, $actived = true){
        // T7:委托给 App\Support\CommentHelper
        // 接受 Eloquent|Support Collection(原签名只支持 Eloquent,T7 审核改进)
        return \App\Support\CommentHelper::uiUsernames($users, $actived);
    }

    /**
     * 从内容中解析出用户
     *
     * @param string $content
     *
     * @return Collection|null
     */
    function comment_filter_users($content){
        // T7:委托给 App\Support\CommentHelper
        return \App\Support\CommentHelper::filterUsers($content);
    }


    /**
     * 对评论信息预处理
     *
     * @param string $comment
     *
     * @return string
     */
    function comment_filter(string $comment): string{
        // T7:委托给 App\Support\CommentHelper
        return \App\Support\CommentHelper::filter($comment);
    }

    /**
     * 是否启用注册功能支持
     *
     * @return bool
     */
    function register_enabled(): bool{
        // T7:委托给 App\Support\ConfigHelper
        return \App\Support\ConfigHelper::registerEnabled();
    }

    /**
     * 站长统计代码区域
     *
     * @return string
     */
    function statistics(): string{
        // T7 续:委托给 App\Support\MiscHelper
        return \App\Support\MiscHelper::statistics();
    }

    /**
     * 判断内容是否为json格式
     *
     * @param string $content
     *
     * @return bool
     */
    function isJson($content): bool{
        // T7 续:委托给 App\Support\MiscHelper
        return \App\Support\MiscHelper::isJson($content);
    }

    /**
     * 转换 SQL 为 Markdown 表格
     *
     * @param string $sql
     *
     * @return string
     */
    function convertSqlToMarkdownTable(string $sql){
        // T7:委托给 App\Support\SqlConvertHelper
        return \App\Support\SqlConvertHelper::sqlToMarkdownTable($sql);
    }

    /**
     * 转换 SQL 为 HTML 表格
     *
     * @param string $sql
     *
     * @return string
     */
    function convertSqlToHTMLTable(string $sql){
        // T7:委托给 App\Support\SqlConvertHelper
        return \App\Support\SqlConvertHelper::sqlToHTMLTable($sql);
    }

    /**
     * SQL 格式转换
     *
     * @param string $sql
     * @param        $callback
     *
     * @return string
     */
    function convertSqlTo(string $sql, $callback){
        // T7 审核改进:委托给 SqlConvertHelper::sqlTo(类内聚)
        return \App\Support\SqlConvertHelper::sqlTo($sql, $callback);
    }

    /**
     * Markdown 预处理
     *
     * @param string $markdown
     *
     * @return string
     */
    function processMarkdown($markdown = NULL): string{
        // T7:委托给 App\Support\FormatHelper
        return \App\Support\FormatHelper::processMarkdown($markdown);
    }

    /**
     * 遍历导航项
     *
     * @param array   $navigators
     * @param Closure $callback
     * @param array   $parents
     * @param bool    $callbackWithFullNavItem 是否在回调函数中传递完整的nav对象
     */
    function traverseNavigators(
        array    $navigators,
        \Closure $callback,
        array    $parents = [],
                 $callbackWithFullNavItem = false
    ){
        // T7 续:委托给 App\Support\MiscHelper
        \App\Support\MiscHelper::traverseNavigators($navigators, $callback, $parents, $callbackWithFullNavItem);
    }

    /**
     * 资源地址 CDN 加速
     *
     * @param string $resourceUrl
     *
     * @return string
     */
    function cdn_resource(string $resourceUrl){
        // T7:委托给 App\Support\CdnHelper
        return \App\Support\CdnHelper::resource($resourceUrl);
    }

    /**
     * Return all catalogs
     *
     * @return Catalog[]|Collection
     */
    function allCatalogs(){
        // T7:委托给 App\Support\UserHelper
        return \App\Support\UserHelper::allCatalogs();
    }

    /**
     * 返回扮演者基本信息
     *
     * @return array|null
     */
    function impersonateUser(){
        // T7:委托给 App\Support\UserHelper
        return \App\Support\UserHelper::impersonateUser();
    }

    /**
     * 文档按照指定 ID 顺序排列
     *
     * @param LengthAwarePaginator $docs
     * @param array|null           $sortIds
     *
     * @return mixed
     */
    function sortDocumentBySortIds(LengthAwarePaginator $docs, $sortIds = NULL){
        // T7 续:委托给 App\Support\MiscHelper
        return \App\Support\MiscHelper::sortDocumentBySortIds($docs, $sortIds);
    }

    /**
     * 从Cookie里获取已经设置的样式
     *
     * @return string
     */
    function getThemeByCookie(){
        // T7 续:委托给 App\Support\MiscHelper
        return \App\Support\MiscHelper::getThemeByCookie();
    }

    /**
     * 保留:to_unicode 原为死代码(0 处调用),T7 审核建议删除
     * 如有遗留调用方,请改用 PHP 内置 mb_convert_encoding 或 \voku\helper\UTF8
     */

    /**
     * 给图片添加水印
     *
     * @param string $file1 图片文件
     */
    function watermark($file1){
        // T7:委托给 App\Support\Watermarker
        \App\Support\Watermarker::apply($file1);
    }


    /**
     * 格式化HTML代码以方便前端进行对比
     *
     * @param string $content
     *
     * @return mixed|string|string[]|null
     */
    function formatHtml($content = ''){
        // T7:委托给 App\Support\FormatHelper
        return \App\Support\FormatHelper::formatHtml($content);
    }

    /**
     * 生成read页面的token
     * 在生成前需要自行判断当前用户对$id和$page_id的权限
     *
     * @param $id
     * @param $page_id
     *
     * @return false|string
     */
    function genReadToken($id, $page_id){
        $pre = date('ymdHis');
//    echo $pre . '<br />';
//    echo dechex($pre) . '<br />';
        return shulz($id, $page_id, $pre);
    }

    function shulz($id, $page_id, $pre){
        $key = config('app.key');
        if (!$key) {
            return false;
        }
        $tmp = md5($key . '_' . $id . '_' . $page_id . '_' . $pre);
        $key = config('app.key');
        if (!$key) {
            return false;
        }
        for ($i = 0; $i < strlen($pre); $i++) {
            $pos = $i * 2 + 1;
            $tmp = substr($tmp, 0, $pos) . substr($pre, $i, 1) . substr($tmp, $pos + 1);
        }
        return $tmp;
    }

    function checkReadToken($id, $page_id, $token){
        $life = 60;         //TOKEN的有效期，单位为秒
        $pre  = '';
        for ($i = 0; $i < 12; $i++) {
            $pos = $i * 2 + 1;
            $pre .= substr($token, $pos, 1);
        }
        $tmp = shulz($id, $page_id, $pre);
        return $token === $tmp && $pre >= date('ymdHis') - $life;
    }
}
