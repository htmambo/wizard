<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string|null $name
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Repositories\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereUserId($value)
 * @mixin \Eloquent
 */
class ApiToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'name',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
