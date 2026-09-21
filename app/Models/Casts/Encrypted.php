<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * encrypted / encrypted:array / encrypted:object / encrypted:collection
 * Castable 工厂:让模型 $casts 数组直接写 'field' => 'encrypted' 即可。
 */
class Encrypted implements Castable
{
    /**
     * @param  array{0?: string}  $arguments
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        $mode = $arguments[0] ?? 'string';
        return new EncryptedCast($mode);
    }
}
