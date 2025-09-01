<?php

namespace App\Http\Controllers;

use App\Repositories\Catalog;
use App\Repositories\Project;
use App\Repositories\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use App\Repositories\Document as Page;
use App\Repositories\Project as ProjectModel;

class BlogController extends Controller
{
    protected $projectRepo;
    protected $catalogRepo;
    protected $tagRepo;

    public function __construct(Project $projectRepo, Catalog $catalogRepo, Tag $tagRepo)
    {
        $this->projectRepo = $projectRepo;
        $this->catalogRepo = $catalogRepo;
        $this->tagRepo = $tagRepo;
    }

    /**
     * 公共首页
     *
     * 数据来源：
     * - 普通用户：显示属性为public，同时用户含有分组权限的项目以及当前用户的项目
     * - 管理员：显示所有项目
     *
     * @param Request $request
     * @param int $catalog 目录ID或项目ID
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function home(Request $request, $catalog = 0)
    {
        $user = Auth::user();
        $perPage = 15; // 每页显示数量
        $currentPage = $request->input('page', 1);
        $catalogs = $this->catalogRepo->all();
        // 获取可见的项目
        $projectsQuery = $this->getVisibleProjects($user);
        
        // 如果指定了目录，过滤项目
        if (!empty($catalog)) {
            $projectsQuery->where('catalog_id', $catalog);
        }
        
        // 获取项目列表
        $projects = $projectsQuery->with(['pages' => function($query) {
            $query->where('is_blog', true)
                  ->orderBy('updated_at', 'desc');
        }])->paginate($perPage);
        
        // 获取最新发布的博客文章
        $recentPosts = $this->getRecentBlogPosts($user, 10);
        
        // 获取热门标签
        $popularTags = [];
        $popularTags = $this->tagRepo->all();

        // 当前目录信息
        $currentCatalog = null;
        if (!empty($catalog)) {
            $currentCatalog = $this->catalogRepo->find($catalog);
        }
        
        return view('blog.home', compact(
            'projects', 
            'recentPosts', 
            'popularTags', 
            'catalogs',
            'currentCatalog',
            'catalog'
        ));
    }
    
    /**
     * 显示博客文章详情
     *
     * @param Request $request
     * @param int $id 文章ID
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function post(Request $request, $id)
    {
        $user = Auth::user();
        
        // 获取文章
        $post = Page::with(['project', 'user', 'comments.user'])
                   ->where('id', $id)
                   ->where('is_blog', true)
                   ->first();
                   
        if (!$post) {
            abort(404, '文章不存在');
        }
        
        // 检查访问权限
        if (!$this->canViewPost($post, $user)) {
            abort(403, '没有权限访问此文章');
        }
        
        // 增加阅读量
        $post->increment('view_count');
        
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
        
        return view('blog.post', compact(
            'post', 
            'relatedPosts', 
            'tags', 
            'projectPosts'
        ));
    }
    
    /**
     * 获取可见的项目
     *
     * @param $user
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function getVisibleProjects($user)
    {
        $query = ProjectModel::query();
        
        if (!$user || !$user->isAdmin()) {
            // 普通用户只能看到公开项目和自己有权限的项目
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
        
        return $query->orderBy('sort_level', 'desc')
                     ->orderBy('updated_at', 'desc');
    }
    
    /**
     * 获取最新博客文章
     *
     * @param $user
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getRecentBlogPosts($user, $limit = 10)
    {
        $query = Page::with(['project', 'user'])
                    ->where('is_blog', true)
                    ->whereHas('project', function($projectQuery) use ($user) {
                        if (!$user || !$user->isAdmin()) {
                            $projectQuery->where('visibility', ProjectModel::VISIBILITY_PUBLIC);
                            
                            if ($user) {
                                $projectQuery->orWhere('user_id', $user->id)
                                           ->orWhereHas('groups.users', function($groupQuery) use ($user) {
                                               $groupQuery->where('users.id', $user->id);
                                           });
                            }
                        }
                    });
                    
        return $query->orderBy('updated_at', 'desc')
                     ->limit($limit)
                     ->get();
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