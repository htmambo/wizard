<?php

namespace App\Repositories;

class ApiToken extends Repository
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
