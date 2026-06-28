<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Services;

use InvalidArgumentException;

/**
 * SSRF 防护:对外部 sync_url 进行协议/内网黑名单校验,
 * 并通过 IP 锁定 + 禁重定向防止 DNS rebinding 绕过。
 */
class SyncUrlGuard
{
    /**
     * 黑名单 CIDR 段(IPv4)。
     * 含 loopback、RFC1918 私网、link-local、metadata、保留段。
     */
    public const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',         // 当前网络
        '10.0.0.0/8',         // RFC1918 私网
        '100.64.0.0/10',     // CGNAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',    // link-local(metadata 169.254.169.254)
        '172.16.0.0/12',     // RFC1918 私网
        '192.0.0.0/24',       // IETF 协议分配
        '192.168.0.0/16',    // RFC1918 私网
        // 注:198.18.0.0/15 是 IANA Benchmarking 段(RFC 6890),非 SSRF 攻击面。
        // 误判历史: 2026-06-28 V2 外部审核建议加入,实际误杀 docs.golaravel.com(198.18.2.74)。
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // 保留
    ];

    /**
     * 黑名单 CIDR 段(IPv6)。
     */
    public const BLOCKED_IPV6_CIDRS = [
        '::1/128',           // loopback
        'fc00::/7',          // 唯一本地地址(ULA)
        'fe80::/10',         // link-local
        'ff00::/8',          // multicast
        '::ffff:127.0.0.0/104', // IPv4-mapped loopback
        '::ffff:10.0.0.0/104',  // IPv4-mapped 私网
        '::ffff:172.16.0.0/108',
        '::ffff:192.168.0.0/112',
    ];

    /**
     * 校验 sync_url 是否安全。失败抛 InvalidArgumentException。
     *
     * AD1 强化:解析 host → 解析为 IP → 黑名单比对 → 返回真实 IP 供调用方锁定。
     * 调用方在 Guzzle 中使用 'force_ip_resolve' / CURLOPT_RESOLVE 锁定该 IP。
     */
    public static function validate(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new InvalidArgumentException('sync_url 格式不合法');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("sync_url 协议 {$scheme} 不被允许,仅支持 http/https");
        }

        $host = $parts['host'];
        // IPv6 字面量在 URL 中通常带方括号 [::1],parse_url 会保留方括号,需剥离
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        // 解析域名得到所有 IP,逐个黑名单校验
        $ips = self::resolveHost($host);
        if (empty($ips)) {
            throw new InvalidArgumentException("无法解析 sync_url 域名 {$host}");
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new InvalidArgumentException(
                    "sync_url 域名 {$host} 解析到被禁用的 IP {$ip}(内网/loopback/metadata)"
                );
            }
        }

        // 返回首个合法 IP,供调用方 CURLOPT_RESOLVE 锁定(DNS rebinding 防护)
        return $ips[0];
    }

    /**
     * 解析域名得到所有 IP(IPv4/IPv6)。
     *
     * @return string[]
     */
    private static function resolveHost(string $host): array
    {
        // 如果本身是 IP 字面量(IPv4 或 IPv6),直接返回
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // IPv6 字面量在 URL 中常以 [::1] 形式出现,parse_url 会剥离方括号,这里不需要处理

        $ips = [];
        // DNS A 记录
        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ips = array_merge($ips, $a);
        }
        // DNS AAAA 记录(dns_get_record 需要 AAAA hint)
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * 判断 IP 是否在黑名单 CIDR 段内。
     */
    public static function isBlockedIp(string $ip): bool
    {
        // 标准化 IPv4-mapped IPv6(::ffff:127.0.0.1 → 127.0.0.1)
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }

        $cidrs = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? self::BLOCKED_IPV4_CIDRS
            : self::BLOCKED_IPV6_CIDRS;

        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 标准 IPv4/IPv6 CIDR 包含判断。
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2) + [null, null];
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $ipLen = strlen($ipBin) * 8;
        // 字节级前缀比对
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        // 位级剩余前缀比对
        $remainder = $bits - $fullBytes * 8;
        if ($remainder > 0 && $fullBytes < strlen($ipBin)) {
            $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
            if ((($ipBin[$fullBytes] & $mask) !== ($subnetBin[$fullBytes] & $mask))) {
                return false;
            }
        }
        // bits 大于地址长度 → 不可能命中
        if ($bits > $ipLen) {
            return false;
        }
        return true;
    }
}
