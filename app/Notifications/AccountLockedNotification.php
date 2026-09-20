<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Notifications;

use App\Repositories\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 账号登录失败次数过多、触发了 1 小时账号级锁定时下发此通知。
 *
 * 同时投递到 database(站内消息)与 mail(用户邮箱)两个通道;
 * 通道选择不写硬编码到 service,通过 Notification::via() 返回数组,
 * 后续运维可在 config 中关闭 mail 通道以避免生产环境产生真实邮件
 * (database 通道始终保留用于审计)。
 */
class AccountLockedNotification extends Notification
{
    use Queueable;

    /**
     * 被锁定的用户(由 toMail/toArray 引用)
     */
    public User $user;

    /**
     * 锁定到期时间(绝对时间戳)
     */
    private Carbon $lockedUntil;

    /**
     * @param User   $user        被锁定的用户
     * @param Carbon $lockedUntil 锁定到期时间(由 LoginAttemptService 在调用前计算)
     */
    public function __construct(User $user, Carbon $lockedUntil)
    {
        $this->user = $user;
        $this->lockedUntil = $lockedUntil;
    }

    /**
     * 投递通道:数据库(站内信)+邮件(可选)
     */
    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * 邮件视图
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(sprintf('[%s] 账号已锁定', config('app.name', 'Wizard')))
            ->line(sprintf(
                '账号 %s 在 1 小时内累计登录失败 %d 次,已自动锁定至 %s。',
                $this->user->name,
                \App\Services\LoginAttemptService::MAX_ATTEMPTS,
                $this->lockedUntil->format('Y-m-d H:i:s')
            ))
            ->line('在此期间即使输入正确密码也无法登录,请稍后再试或联系管理员。')
            ->line('如果不是您本人的操作,建议尽快修改密码并启用两步验证。');
    }

    /**
     * 数据库(站内消息)载荷
     */
    public function toArray($notifiable): array
    {
        return [
            'type'         => 'account-locked',
            'message'      => sprintf(
                '账号已锁定至 %s,请稍后再试',
                $this->lockedUntil->format('Y-m-d H:i:s')
            ),
            'user'         => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ],
            'locked_until' => $this->lockedUntil->toIso8601String(),
        ];
    }
}