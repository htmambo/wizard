<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Exceptions;

use RuntimeException;

/**
 * JWT Token 过期/失效异常(AD2)。
 *
 * 替代原 helpers.php::jwt_parse_token() 中的 exit('页面已过期...') 调用,
 * 让异常走 Laravel 全局 Handler,Web → redirect,API → 401 JSON。
 */
class TokenExpiredException extends RuntimeException
{
    public function __construct(string $message = '登录链接已失效,请重新登录', int $code = 401)
    {
        parent::__construct($message, $code);
    }
}
