<?php
namespace App\Http\Api;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use SoapBox\Formatter\Formatter;

class UserController extends ApiController
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * 获取用户信息
     */
    protected function getProfile(Request $request){
        $user = Auth::user();
        return $this->success([
                                  'id'         => $user->id,
                                  'name'       => $user->name,
                                  'email'      => $user->email,
                                  'created_at' => $user->created_at,
                                  'updated_at' => $user->updated_at,
                              ]);
    }
}