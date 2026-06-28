<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

/**
 * 杂项工具(T7 续)。
 *
 * 替代 helpers.php 中 statistics() / isJson() / traverseNavigators() / navigatorSort() / sortDocumentBySortIds()。
 */
class MiscHelper
{
    /**
     * 站长统计代码区域:读取 custom/statistics.html(若存在)。
     */
    public static function statistics(): string
    {
        $customFile = base_path('custom');
        if (file_exists("{$customFile}/statistics.html")) {
            return file_get_contents("{$customFile}/statistics.html");
        }
        return '';
    }

    /**
     * 判断内容是否为合法 JSON。
     */
    public static function isJson($content): bool
    {
        json_decode((string) $content);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * 递归遍历导航树,对每个节点执行 callback。
     *
     * @param  array          $navigators
     * @param  \Closure        $callback  签名: function($navOrId, array $parents, ?array $fullNav)
     * @param  array          $parents   内部递归用,外部调用传 []
     * @param  bool           $callbackWithFullNavItem  true: callback 接收完整 nav 数组;false: 仅接收 id
     */
    public static function traverseNavigators(
        array $navigators,
        \Closure $callback,
        array $parents = [],
        bool $callbackWithFullNavItem = false
    ): void {
        foreach ($navigators as $nav) {
            $callback($callbackWithFullNavItem ? $nav : $nav['id'], $parents);

            if (!empty($nav['nodes'])) {
                $parents[] = ['id' => $nav['id'], 'name' => $nav['name']];
                self::traverseNavigators($nav['nodes'], $callback, $parents, $callbackWithFullNavItem);
                array_pop($parents);
            }
        }
    }

    /**
     * 导航排序:sort_level 降序,文件夹靠前(同 sort_level 时文件夹优先)。
     */
    public static function navigatorSort(array $navItems, int $sortStyle = 0): array
    {
        $sortItem = function ($a, $b) {
            try {
                if ($a['sort_level'] > $b['sort_level']) return 1;
                if ($a['sort_level'] < $b['sort_level']) return -1;
                return $b['updated_at']->greaterThan($a['updated_at']);
            } catch (\Exception $e) {
                return 0;
            }
        };

        usort($navItems, function ($a, $b) use ($sortItem, $sortStyle) {
            if ($sortStyle === 1) { // Project::SORT_STYLE_FREE
                return $sortItem($a, $b);
            }
            $aIsFolder = !empty($a['nodes']);
            $bIsFolder = !empty($b['nodes']);
            $bothFolder   = $aIsFolder && $bIsFolder;
            $bothNotFolder = !$aIsFolder && !$bIsFolder;
            if ($bothFolder || $bothNotFolder) {
                return $sortItem($a, $b);
            }
            return $aIsFolder ? -1 : 1;
        });

        return $navItems;
    }

    /**
     * 按 ID 列表对分页结果排序。
     */
    public static function sortDocumentBySortIds(\Illuminate\Pagination\LengthAwarePaginator $docs, ?array $sortIds = null)
    {
        if (empty($sortIds)) {
            return $docs;
        }
        return $docs->sortBy(fn ($doc) => array_search($doc->id, $sortIds));
    }

    /**
     * 从 cookie 读取当前主题(dark → wz-dark-theme,其他 → '')。
     */
    public static function getThemeByCookie(): string
    {
        $name = \Illuminate\Support\Facades\Cookie::get('wizard-theme');
        if ($name === 'dark') {
            return 'wz-dark-theme';
        }
        return '';
    }
}
