<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use App\Exceptions\TokenExpiredException;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;

/**
 * JWT Token 编解码服务(T7 + T5)。
 *
 * 替代 helpers.php 中 jwt_create_token / jwt_parse_token。
 * 解析失败抛 TokenExpiredException(AD2),走 Laravel 全局 Handler 渲染。
 */
class JwtFactory
{
    /**
     * 创建 JWT Token。
     */
    public static function create(array $payloads, int $expire = 7200): \Lcobucci\JWT\UnencryptedToken
    {
        $builder = Builder::new(new JoseEncoder(), ChainedFormatter::default());
        foreach ($payloads as $key => $payload) {
            $builder = $builder->withClaim($key, $payload);
        }
        $now        = new DateTimeImmutable();
        $algorithm  = new Sha256();
        $signingKey = InMemory::plainText(config('wizard.jwt_secret'));

        return $builder->issuedAt($now)
            ->expiresAt($now->modify("+{$expire} seconds"))
            ->getToken($algorithm, $signingKey);
    }

    /**
     * 解析并验证 JWT Token。
     *
     * 签名/过期校验失败抛 TokenExpiredException(AD2,替代原 exit())。
     */
    public static function parse(string $token): \Lcobucci\JWT\Token
    {
        $parsed     = (new Parser(new JoseEncoder()))->parse($token);
        $algorithm  = new Sha256();
        $signingKey = InMemory::plainText(config('wizard.jwt_secret'));
        $validator  = new Validator();

        if (!$validator->validate($parsed, new SignedWith($algorithm, $signingKey))) {
            throw new TokenExpiredException('页面已过期，请刷新页面后重新提交');
        }
        return $parsed;
    }
}
