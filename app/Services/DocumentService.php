<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Services;

use App\Events\DocumentCreated;
use App\Events\DocumentDeleted;
use App\Events\DocumentMarkModified;
use App\Events\DocumentModified;
use App\Repositories\Document;
use App\Repositories\DocumentHistory;
use App\Repositories\DocumentScore;
use App\Repositories\PageShare;
use App\Repositories\Project;
use App\Repositories\User;
use App\Support\DocumentTypeHelper;
use App\Support\HtmlPurifierService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * 文档（Document）域业务服务
 *
 * 职责：
 *  - 文档 CRUD（创建、更新、软删除、状态变更、过期检测）
 *  - 文档移动（跨项目、跨父文档、级联更新 history/share）
 *  - 文档评分（写入、覆盖、删除）
 *  - 文档博客状态切换
 *  - 文档远程同步（拉取远端 swagger/json 内容并更新）
 *
 * 约定：
 *  - 入参已校验，授权由 Controller 调用 Policy 完成
 *  - 控制器负责：表单校验、SSRF URL 守卫拒绝时抛 ValidationException、并发冲突检查
 *  - 不写接口；plain class，Laravel 自动解析依赖
 *  - 领域事件由本服务触发
 */
class DocumentService
{
    /**
     * 创建新文档
     *
     * @param User    $user    创建者（已通过授权）
     * @param Project $project 所属项目（已通过授权）
     * @param array   $data    已校验数据：
     *                         - title (string, required)
     *                         - type  (string: html/markdown/swagger/table/sheet)
     *                         - pid   (int, optional, default 0)
     *                         - sort_level (int, optional, default 1000)
     *                         - sync_url (string|null)
     *                         - content (string, optional)
     *                         - content_html (string, optional, 仅 markdown 用于设置 html_code)
     *
     * @return Document
     */
    public function create(User $user, Project $project, array $data): Document
    {
        $type = $data['type'];
        $pid  = $data['pid'] ?? 0;
        $content      = $data['content'] ?? '';
        $contentHtml  = $data['content_html'] ?? '';
        $htmlCode     = '';
        $description  = '';

        if ($type === 'markdown') {
            if ($contentHtml) {
                $htmlCode    = $contentHtml;
                $description = mb_substr(strip_tags($contentHtml), 0, 300);
            }
        } elseif ($type === 'html') {
            // XSS 净化（AD5:写入时净化,渲染时直接输出）
            $content     = HtmlPurifierService::clean($content);
            $description = mb_substr(strip_tags($content), 0, 300);
            $content     = formatHtml($content);
        }

        $document = Document::create([
            'pid'               => $pid,
            'title'             => $data['title'],
            'description'       => $description,
            'content'           => $content,
            'html_code'         => $htmlCode,
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'last_modified_uid' => $user->id,
            'type'              => DocumentTypeHelper::convert($type, true),
            'status'            => Document::STATUS_NORMAL,
            'sort_level'        => $data['sort_level'] ?? 1000,
            'sync_url'          => $data['sync_url'] ?? null,
        ]);

        DocumentHistory::write($document);

        event(new DocumentCreated($document));

        return $document;
    }

    /**
     * 更新文档
     *
     * 假设：sync_url 已通过 SyncUrlGuard 校验；表格类型的 content 已通过 JSON 校验；
     *       并发冲突（updated_at / history_id）已由 Controller 提前拦截。
     *
     * @param Document $document
     * @param User     $user      当前操作用户
     * @param array    $data      已校验字段：
     *                            - pid, project_id, title, content, sort_level, sync_url
     *                            - content_html (string, optional, markdown 用于更新 html_code/description)
     *
     * @return Document
     */
    public function update(Document $document, User $user, array $data): Document
    {
        // 记录原始 content，便于忽略 LEANOTE 迁移遗留的两个尾部空格
        $origContent = $document->isMarkdown() ? trim((string)$document->content) : '';

        $content     = $data['content'] ?? $document->content;
        $contentHtml = $data['content_html'] ?? '';

        if ($document->isMarkdown()) {
            if ($contentHtml) {
                $document->html_code   = $contentHtml;
                $document->description = mb_substr(strip_tags($contentHtml), 0, 300);
            }
        } elseif ($document->isHtml()) {
            // XSS 净化（AD5:写入时净化,渲染时直接输出）
            $content               = HtmlPurifierService::clean($content);
            $document->description = mb_substr(strip_tags($content), 0, 300);
            $content               = formatHtml($content);
        }

        $document->pid        = $data['pid'];
        $document->project_id = $data['project_id'];
        $document->title      = $data['title'];
        $document->content    = $content;
        $document->sort_level = $data['sort_level'];
        $document->sync_url   = $data['sync_url'];

        $changed = $document->getDirty();
        if ($changed) {
            // 从 LEANOTE 迁移遗留的两个尾部空格，content 视为未变化
            if (isset($changed['content']) && $changed['content'] == $origContent) {
                unset($changed['content']);
            }
            // html_code / description 是渲染产物，不参与版本控制
            unset($changed['html_code'], $changed['description']);
        }

        if ($document->isDirty()) {
            $document->last_modified_uid = $user->id;
            $document->save();

            if ($changed) {
                DocumentHistory::write($document);
                event(new DocumentModified($document));
            }
        }

        return $document;
    }

    /**
     * 软删除文档
     *
     * 删除前将所有子页面的 pid 上移到当前文档的 pid，避免层级断裂。
     *
     * @return Document 已删除的文档（含 last_modified_uid 更新）
     */
    public function delete(Document $document, User $user): Document
    {
        // 页面删除后，所有下级页面全部移动到该页面的上级
        $document->subPages()->update(['pid' => $document->pid]);

        $document->last_modified_uid = $user->id;
        $document->save();

        $document->delete();

        event(new DocumentDeleted($document));

        return $document;
    }

    /**
     * 标记文档状态（正常 / 过时）
     *
     * @param Document $document
     * @param User     $user
     * @param string   $status '1' 正常 / '2' 过时
     *
     * @return Document
     */
    public function markStatus(Document $document, User $user, string $status): Document
    {
        $document->status = $status;

        if ($document->isDirty()) {
            $document->last_modified_uid = $user->id;
            $document->save();
            event(new DocumentMarkModified($document));
        }

        return $document;
    }

    /**
     * 检查文档是否已被他人修改（基于 updated_at 比较）
     *
     * @return array{message:string, expired:bool}
     */
    public function checkExpired(Document $document, CarbonInterface $lastModifiedAt): array
    {
        if (!$document->updated_at->equalTo($lastModifiedAt)) {
            return [
                'message' => __('document.validation.doc_modified_by_user', [
                    'username' => $document->lastModifiedUser->name,
                    'time'     => $document->updated_at,
                ]),
                'expired' => true,
            ];
        }

        return [
            'message' => 'ok',
            'expired' => false,
        ];
    }

    /**
     * 从远程 sync_url 拉取文档内容并保存
     *
     * 行为：
     *  - 使用 SyncUrlGuard 解析并锁定 IP（防 DNS rebinding）
     *  - 禁重定向（防 302 绕过黑名单）
     *  - 内容变化时写历史 + 触发 DocumentModified；否则视为 noop
     *
     * @return array{document:Document, synced:bool} synced=true 表示内容发生变更
     *
     * @throws \Exception 远程同步失败（非 200 响应）
     */
    public function syncFromRemote(Document $document, User $user): array
    {
        $syncUrl = $document->sync_url;
        if (empty($syncUrl)) {
            return ['document' => $document, 'synced' => false];
        }

        $lockedIp  = SyncUrlGuard::validate($syncUrl);
        $syncHost  = parse_url($syncUrl, PHP_URL_HOST);
        // IPv6 字面量在锁定时需方括号
        $resolveHost = filter_var($syncHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? "[{$syncHost}]"
            : $syncHost;

        $client = new \GuzzleHttp\Client([
            'timeout'         => 10,
            'connect_timeout' => 5,
            // 禁重定向，避免 302 绕过黑名单
            'allow_redirects' => false,
            'http_errors'     => false,
            'headers'         => [
                'Accept' => 'application/json, application/yaml, text/yaml, text/plain',
            ],
            // 锁定 host → IP，防止 DNS rebinding
            'force_ip_resolve' => filter_var($lockedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4',
            'curl'             => [
                CURLOPT_RESOLVE => [
                    "{$resolveHost}:80:{$lockedIp}",
                    "{$resolveHost}:443:{$lockedIp}",
                ],
            ],
        ]);

        $resp     = $client->get($syncUrl);
        $respCode = $resp->getStatusCode();
        $respBody = $resp->getBody()->getContents();

        if ($respCode !== 200) {
            \Log::error('document_sync_failed', [
                'status_code' => $respCode,
                'resp_body'   => $respBody,
                'project_id'  => $document->project_id,
                'page_id'     => $document->id,
                'operator_id' => $user->id,
            ]);
            throw new \Exception('文档同步失败');
        }

        $document->content = $respBody;

        $synced = false;
        if ($document->isDirty()) {
            $document->last_modified_uid = $user->id;
            $document->last_sync_at      = Carbon::now();
            $document->save();

            DocumentHistory::write($document);
            event(new DocumentModified($document));

            $synced = true;
        }

        return ['document' => $document, 'synced' => $synced];
    }

    /**
     * 移动文档到目标项目 / 父文档
     *
     * 行为：
     *  - 当前文档的 project_id / pid 更新
     *  - 关联的 DocumentHistory / PageShare.project_id 同步更新
     *  - 递归子页面（navigators）的 project_id 同步更新
     *  - 不更新子页面文档的 pid（保留原父子关系在导航中可见）
     *
     * @param Document   $document         待移动的文档
     * @param User       $user
     * @param Project    $targetProject    目标项目
     * @param Document|null $targetPage    目标父文档（null 表示顶级）
     * @param bool       $dontSaveUpdated  true 时不更新最后修改时间（保留原始 updated_at）
     * @param array      $subNavigatorIds  子文档 id 列表（来自 controller 的 navigator 过滤）
     *
     * @return Document
     */
    public function move(
        Document $document,
        User $user,
        Project $targetProject,
        ?Document $targetPage,
        bool $dontSaveUpdated,
        array $subNavigatorIds
    ): Document {
        $newPid = $targetPage->id ?? 0;

        DB::transaction(function () use ($document, $targetProject, $newPid, $dontSaveUpdated, $subNavigatorIds) {
            $document->project_id = $targetProject->id;
            $document->pid        = $newPid;

            if ($dontSaveUpdated) {
                // 移动文档时不更新最后修改时间
                $document->timestamps = false;
            }

            $document->save();

            DocumentHistory::where('page_id', $document->id)->update([
                'project_id' => $targetProject->id,
                'pid'        => $newPid,
            ]);

            PageShare::where('page_id', $document->id)->update([
                'project_id' => $targetProject->id,
            ]);

            foreach ($subNavigatorIds as $childId) {
                Document::where('id', $childId)->update(['project_id' => $targetProject->id]);
                DocumentHistory::where('page_id', $childId)->update([
                    'project_id' => $targetProject->id,
                ]);
            }
        });

        return $document;
    }

    /**
     * 设置 / 切换 / 取消 文档评分
     *
     * 行为：
     *  - 当前用户未评过分：创建
     *  - 已评分且与新评分相同：删除（toggle off）
     *  - 已评分且不同：更新
     *
     * @return DocumentScore|null 新增或更新后的评分；toggle off 时返回 null
     */
    public function updateScore(Document $document, User $user, int $scoreType): ?DocumentScore
    {
        /** @var DocumentScore|null $existing */
        $existing = DocumentScore::where('page_id', $document->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ((int)$existing->score_type === $scoreType) {
                $existing->delete();
                return null;
            }

            $existing->score_type = $scoreType;
            $existing->save();
            return $existing;
        }

        return DocumentScore::create([
            'user_id'    => $user->id,
            'page_id'    => $document->id,
            'score_type' => $scoreType,
        ]);
    }

    /**
     * 设置文档博客状态（发布到博客 / 取消发布）
     *
     * 切换时支持传入别名别名；alias 已由 controller 计算好（可能基于 jieba/pscws/blt 分词）。
     *
     * @param Document  $document
     * @param User      $user
     * @param bool      $isBlog
     * @param string|null $alias 博客别名；null 表示不修改（unBlog 时使用）
     *
     * @return Document
     */
    public function setBlogStatus(Document $document, User $user, bool $isBlog, ?string $alias = null): Document
    {
        if ($alias !== null) {
            $document->alias = $alias;
        }
        $document->is_blog = $isBlog ? 1 : 0;

        if ($document->isDirty()) {
            $document->last_modified_uid = $user->id;
            $document->save();
            event(new DocumentMarkModified($document));
        }

        return $document;
    }
}
