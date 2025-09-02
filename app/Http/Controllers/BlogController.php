<?php

namespace App\Http\Controllers;

use App\Repositories\Tag;
use App\Repositories\Catalog;
use App\Repositories\Project as ProjectModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Repositories\Document as Page;
use League\CommonMark\CommonMarkConverter;

class BlogController extends Controller
{
    protected $tagRepo;

    public function __construct(Tag $tagRepo)
    {
        $this->tagRepo = $tagRepo;
    }

    /**
     * 博客首页
     *
     * @param Request $request
     * @param int $project 项目ID
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function home(Request $request, $project = 0)
    {
        $user = Auth::user();
        $perPage = 15;

        // 获取博客文章，预加载关联数据避免N+1查询
        $pageQuery = Page::with(['project.catalog', 'user', 'tags'])
            ->where('is_blog', true)
            ->whereHas('project', function($projectQuery) use ($user) {
                $this->applyVisibilityFilter($projectQuery, $user);
            });

        $pageQuery->orderBy('sort_level', 'desc')
                  ->orderBy('updated_at', 'desc');

        // 如果指定了项目，过滤项目
        if (!empty($project)) {
            $pageQuery->where('project_id', $project);
        }

        // 获取博客文章列表
        $pages = $pageQuery->paginate($perPage);
        $sql = $pageQuery->toSql();

        // 获取最新发布的博客文章（优化后）
        $recentPosts = $this->getRecentBlogPosts($user, 10);

        // 获取热门标签（添加使用统计）
        $popularTags = null;//$this->getPopularTags(20);
        $catalogs = null;
        $catalog = null;
        $projects = null;
        $currentCatalog = null;
        return view('blog.home', compact(
            'pages',
            'recentPosts',
            'popularTags',
            'currentCatalog',
            'catalogs',
            'projects',
            'catalog'
        ));
    }

    /**
     * 应用可见性过滤器
     */
    protected function applyVisibilityFilter($query, $user)
    {
        if (!$user || !$user->isAdmin()) {
            $query->where(function($q) use ($user) {
                $q->where('visibility', ProjectModel::VISIBILITY_PUBLIC);

                if ($user) {
                    $q->orWhere('user_id', $user->id)
                      ->orWhereHas('groups.users', function($groupQuery) use ($user) {
                          $groupQuery->where('users.id', $user->id);
                      });
                }
            });
        }

        return $query;
    }

    /**
     * 获取可见的项目（优化版本）
     */
    protected function getVisibleProjects($user, $catalogFilter = 0)
    {
        $query = ProjectModel::with(['catalog', 'pages' => function($pageQuery) {
            $pageQuery->where('is_blog', true)
                     ->orderBy('updated_at', 'desc')
                     ->limit(5); // 每个项目最多显示5篇文章
        }])->withCount(['pages as blog_pages_count' => function($pageQuery) {
            $pageQuery->where('is_blog', true);
        }]);

        $this->applyVisibilityFilter($query, $user);

        if ($catalogFilter) {
            $query->where('catalog_id', $catalogFilter);
        }

        return $query->orderBy('sort_level', 'desc')
                     ->orderBy('updated_at', 'desc')
                     ->paginate(12);
    }

    /**
     * 获取可见的目录
     */
    protected function getVisibleCatalogs($user)
    {
        return Catalog::whereHas('projects', function($projectQuery) use ($user) {
            $this->applyVisibilityFilter($projectQuery, $user);
        })->orderBy('sort_level', 'asc')
          ->orderBy('name', 'asc')
          ->get();
    }

    /**
     * 获取最新博客文章（优化版本）
     */
    protected function getRecentBlogPosts($user, $limit = 10)
    {
        $query = Page::with(['project', 'user'])
                    ->where('is_blog', true)
                    ->whereHas('project', function($projectQuery) use ($user) {
                        $this->applyVisibilityFilter($projectQuery, $user);
                    });

        return $query->orderBy('updated_at', 'desc')
                     ->limit($limit)
                     ->get();
    }

    /**
     * 获取热门标签（添加使用统计）
     */
    protected function getPopularTags($limit = 20)
    {
        return $this->tagRepo->select('tags.*')
                             ->join('page_tag', 'tags.id', '=', 'page_tag.tag_id')
                             ->join('pages', 'page_tag.page_id', '=', 'pages.id')
                             ->where('pages.is_blog', true)
                             ->groupBy('tags.id', 'tags.name')
                             ->orderByRaw('COUNT(page_tag.tag_id) DESC')
                             ->limit($limit)
                             ->get();
    }

    /**
     * 显示博客文章详情
     *
     * @param Request $request
     * @param int $id 文章ID
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function post(Request $request, $project = 0, $id = 0, $alias = '')
    {
        // 获取文章
        $post = Page::with(['project', 'user', 'comments.user'])
                   ->where('id', $id)
                   ->where('is_blog', true)
                   ->first();

        if (!$post) {
            abort(404, '文章不存在');
        }

        // 增加阅读量
        // $post->increment('view_count');
        if(!$post->html_code) {
            if($post->isMarkdown()) {
                $parser = new CommonMarkConverter([
                                                      'html_input' => 'strip',
                                                      'allow_unsafe_links' => false,
                                                  ]);
                $post->html_code = $parser->convert($post->content)->getContent();

            } else {
                $post->html_code = $post->content;
            }
        }

        // 获取相关文章
        $relatedPosts = $this->getRelatedPosts($post, 5);

        // 获取文章标签
        $tags = $post->tags;

        // 获取同项目的其他文章
        $projectPosts = Page::where('project_id', $post->project_id)
                           ->where('is_blog', true)
                           ->where('id', '!=', $id)
                           ->orderBy('updated_at', 'desc')
                           ->limit(5)
                           ->get();
        $type = $post->type;
        return view('blog.post', compact(
            'post',
            'relatedPosts',
            'tags',
            'projectPosts',
            'type'
        ));
    }

    /**
     * 检查是否可以查看文章
     *
     * @param Page $post
     * @param $user
     * @return bool
     */
    protected function canViewPost($post, $user)
    {
        // 如果是管理员，可以查看所有文章
        if ($user && $user->isAdmin()) {
            return true;
        }

        $project = $post->project;

        // 公开项目的文章任何人都可以查看
        if ($project->visibility == ProjectModel::VISIBILITY_PUBLIC) {
            return true;
        }

        // 如果用户未登录，不能查看私有项目文章
        if (!$user) {
            return false;
        }

        // 检查是否是项目创建者
        if ($project->user_id == $user->id) {
            return true;
        }

        // 检查是否通过用户组有权限
        return $project->groups()->whereHas('users', function($query) use ($user) {
            $query->where('users.id', $user->id);
        })->exists();
    }

    /**
     * 获取相关文章
     *
     * @param Page $post
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getRelatedPosts($post, $limit = 5)
    {
        return Page::where('is_blog', true)
                   ->where('id', '!=', $post->id)
                   ->whereHas('project', function($query) {
                       $query->where('visibility', ProjectModel::VISIBILITY_PUBLIC);
                   })
                   ->whereHas('tags', function($query) use ($post) {
                       $query->whereIn('tags.id', $post->tags->pluck('id'));
                   })
                   ->orderBy('updated_at', 'desc')
                   ->limit($limit)
                   ->get();
    }
}