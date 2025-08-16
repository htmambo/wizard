<?php

namespace App\Guards;

use App\Repositories\ApiToken;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class TokenGuard implements Guard
{
    use GuardHelpers;

    protected $request;
    protected $inputKey = 'api_token';

    public function __construct(UserProvider $provider, Request $request, $inputKey = 'api_token')
    {
        $this->provider = $provider;
        $this->request = $request;
        $this->inputKey = $inputKey;
    }

    public function user()
    {
        if (!is_null($this->user)) {
            return $this->user;
        }

        $token = $this->getTokenForRequest();

        if (!empty($token)) {
            // $token = hash('sha256', $token);
            $apiToken = ApiToken::where('token', $token)
                                ->where(function ($query) {
                                    $query->whereNull('expires_at')
                                          ->orWhere('expires_at', '>', now());
                                })
                                ->first();

            if ($apiToken) {
                // 更新最后使用时间
                $apiToken->update(['last_used_at' => now()]);
                $this->user = $apiToken->user;
            }
        }

        return $this->user;
    }

    public function validate(array $credentials = [])
    {
        if (empty($credentials[$this->inputKey])) {
            return false;
        }

        $token = $credentials[$this->inputKey];
        $apiToken = ApiToken::where('token', hash('sha256', $token))
                            ->where(function ($query) {
                                $query->whereNull('expires_at')
                                      ->orWhere('expires_at', '>', now());
                            })
                            ->first();

        if ($apiToken) {
            $this->setUser($apiToken->user);
            return true;
        }

        return false;
    }

    protected function getTokenForRequest()
    {
        $token = $this->request->query($this->inputKey);

        if (empty($token)) {
            $token = $this->request->input($this->inputKey);
        }

        if (empty($token)) {
            $token = $this->request->bearerToken();
        }

        if (empty($token)) {
            $token = $this->request->getPassword();
        }

        return $token;
    }
}