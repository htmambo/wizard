<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;

/**
 * 注册邀请码
 *
 * @package App\Repositories
 * @property int $id
 * @property string $code 注册邀请码
 * @property string|null $expired_at 邀请码有效期限
 * @property int $user_id 创建用户ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode whereExpiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvitationCode whereUserId($value)
 * @mixin \Eloquent
 */
class InvitationCode extends Model
{
    protected $table = 'invitation_code';
    protected $fillable = [
        'code',
        'expired_at',
        'user_id',
    ];
}