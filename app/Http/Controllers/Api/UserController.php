<?php
namespace App\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

#[Group('用户相关')]
class UserController extends Controller
{

    /**
     * 获取用户信息
     */
    public function profile(Request $request){
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