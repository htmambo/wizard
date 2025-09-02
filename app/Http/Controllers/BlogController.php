<?php

namespace App\Http\Controllers;

use App\Repositories\Tag;
use Illuminate\Http\Request;
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
        $perPage = 15;

        // 获取博客文章，预加载关联数据避免N+1查询
        $pageQuery = Page::with(['project.catalog', 'user', 'tags'])
            ->where('is_blog', true);

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
        $recentPosts = $this->getRecentBlogPosts(null, 10);

        // 获取热门标签（添加使用统计）
        $popularTags = $this->getPopularTags(20);
        return view('blog.home', compact(
            'pages',
            'recentPosts',
            'popularTags'
        ));
    }

    /**
     * 获取最新博客文章（优化版本）
     */
    protected function getRecentBlogPosts($user, $limit = 10)
    {
        $query = Page::with(['project', 'user'])
                    ->where('is_blog', true);

        return $query->orderBy('updated_at', 'desc')
                     ->limit($limit)
                     ->get();
    }

    /**
     * 获取热门标签（添加使用统计）
     */
    protected function getPopularTags($limit = 20)
    {
        return $this->tagRepo
            ->select('tags.id', 'tags.name')
            ->selectRaw('count(wz_pt.page_id) as tag_count')
            ->join('page_tag as pt', 'tags.id', '=', 'pt.tag_id')
            ->join('pages as p', 'pt.page_id', '=', 'p.id')
            ->where('p.is_blog', true)
            ->groupBy('tags.id', 'tags.name')
            // ->orderByDesc('tag_count')
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
                   ->whereHas('tags', function($query) use ($post) {
                       $query->whereIn('tags.id', $post->tags->pluck('id'));
                   })
                   ->orderBy('updated_at', 'desc')
                   ->limit($limit)
                   ->get();
    }
}