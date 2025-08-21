<?php

namespace App\Http\Controllers;

use App\Repositories\Catalog;
use App\Repositories\Project;
use App\Repositories\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class BlogController extends Controller
{

    /**
     * 公共首页
     *
     * 数据来源：
     *
     * - 普通用户：显示属性为public，同时用户含有分组权限的项目以及当前用户的项目
     * - 管理员：显示所有项目
     *
     * @param Request $request
     * @param int $catalog
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function home(Request $request, $catalog = 0)
    {
        exit('博客首页');
    }
}
