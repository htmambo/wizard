<?php

declare(strict_types=1);

return [
    'reset'     => '密码重置成功！',
    'sent'      => '密码重置邮件已发送！',
    'throttled' => '请稍候再试。',
    'token'     => '无效的 token',
    'user'      => '找不到该邮箱对应的用户。',
    'password' => '密码至少应为6个字符并且必须与重复密码相同。',
    'original_password'       => '原始密码',
    'new_password'            => '新密码',
    'new_password_confirm'    => '重复新密码',
    'change_password_success' => '密码修改成功',

    'validation' => [
        'original_password_required'  => '原始密码不能为空',
        'original_password_unmatch'   => '原始密码不匹配',
        'new_password_required'       => '新密码不能为空',
        'new_password_invalidate'     => '新密码不合法',
        'new_password_at_least'       => '新密码至少为6位字符',
        'new_password_confirm_failed' => '两次输入的密码不匹配',
    ]

];
