<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Repositories\User;
use App\Services\LoginAttemptService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * session 中暂存"已通过密码但尚未通过 2FA"的用户 id 的 key
     */
    public const SESSION_2FA_PENDING_USER = '2fa_pending_user_id';

    private TwoFactorService $twoFactor;
    private LoginAttemptService $loginAttempt;

    public function __construct(TwoFactorService $twoFactor, LoginAttemptService $loginAttempt)
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('throttle:web-login')->only('login');
        $this->twoFactor = $twoFactor;
        $this->loginAttempt = $loginAttempt;
    }

    /**
     * 重写登录主流程:
     *
     *   1. web-login 限流 (5/min/用户名+IP,Cache 计数)
     *   2. 账号级锁定检查 (login_locked_until > now,10 次/小时失败阈值)
     *   3. 校验密码(Auth::guard()->validate,不做实际登录)
     *   4. 密码错误 → 累加计数,触发锁定阈值时下发通知
     *   5. 密码正确 → 清零计数;
     *      若用户启用 2FA,先退出并将 user_id 写入 session,跳转 /auth/2fa
     *      否则直接放行
     */
    public function login(Request $request)
    {
        $this->validateLogin($request);

        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            return $this->sendLockoutResponse($request);
        }

        $credentials = $this->credentials($request);
        $user = $this->lookupUserByLoginField($credentials);

        // 账号级锁定:即便密码正确也拒绝
        if ($user !== null && $this->loginAttempt->isLocked($user)) {
            throw ValidationException::withMessages([
                $this->username() => ['账号已被锁定,请稍后再试'],
            ]);
        }

        if ($this->attemptLogin($request)) {
            return $this->sendLoginResponse($request);
        }

        // 登录失败
        $this->incrementLoginAttempts($request);
        if ($user !== null) {
            $this->loginAttempt->recordFailure($user);
        }

        return $this->sendFailedLoginResponse($request);
    }

    /**
     * 第二步:TOTP/备用码 输入表单
     */
    public function show2faForm(Request $request)
    {
        if (!$request->session()->has(self::SESSION_2FA_PENDING_USER)) {
            return redirect()->route('login');
        }

        return view('auth.2fa');
    }

    /**
     * 第二步:校验 TOTP/备用码,通过后完成登录
     */
    public function verify2fa(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:32',
        ], [
            'code.required' => '请输入验证码',
        ]);

        $pendingUserId = $request->session()->get(self::SESSION_2FA_PENDING_USER);
        if (empty($pendingUserId)) {
            return redirect()->route('login');
        }

        /** @var User|null $user */
        $user = User::find($pendingUserId);
        if ($user === null) {
            $request->session()->forget(self::SESSION_2FA_PENDING_USER);
            return redirect()->route('login');
        }

        // 锁定检查:2FA 校验本身也走账号级计数
        if ($this->loginAttempt->isLocked($user)) {
            $request->session()->forget(self::SESSION_2FA_PENDING_USER);
            throw ValidationException::withMessages([
                'code' => ['账号已被锁定,请稍后再试'],
            ]);
        }

        $code = $request->input('code');
        if (!$this->twoFactor->verify($user, $code)) {
            // 2FA 校验失败:累加失败计数
            $this->loginAttempt->recordFailure($user);
            throw ValidationException::withMessages([
                'code' => ['验证码错误'],
            ]);
        }

        // 2FA 通过:完成实际登录
        $request->session()->forget(self::SESSION_2FA_PENDING_USER);
        $request->session()->regenerate();
        $this->clearLoginAttempts($request);
        $this->guard()->login($user, $request->boolean('remember'));
        $this->loginAttempt->recordSuccess($user);

        return $request->wantsJson()
            ? new \Illuminate\Http\JsonResponse([], 204)
            : redirect()->intended($this->redirectPath());
    }

    /**
     * 第三步(启用 2FA)第一步:展示二维码 + 明文 secret + 备用码确认表单
     *
     * 仅在 totp_enabled_at 为空时渲染;
     * 已启用用户访问时引导回账号设置页。
     */
    public function showEnable2fa(Request $request)
    {
        $user = \Auth::user();
        if ($user->totp_enabled_at !== null) {
            return redirect()->route('user:basic');
        }

        // 每次都生成新的 secret 并保存(覆盖未启用的),保证 secret 不离开加密落盘环节
        $secret = $this->twoFactor->generateSecret($user);
        $uri = $this->twoFactor->getOtpAuthUri($user, $secret);

        return view('auth.2fa_enable', [
            'secret' => $secret,
            'uri'    => $uri,
        ]);
    }

    /**
     * 第三步(启用 2FA)第二步:校验 6 位 TOTP → 调用 enable() → 生成备用码
     */
    public function enable2fa(Request $request)
    {
        $user = \Auth::user();
        if ($user->totp_enabled_at !== null) {
            return redirect()->route('user:basic');
        }

        $request->validate([
            'code' => 'required|string|max:32',
        ], [
            'code.required' => '请输入验证器中显示的 6 位数字',
        ]);

        if (!$this->twoFactor->enable($user, $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => ['验证码错误,请确认与设置页显示的密钥一致'],
            ]);
        }

        // 备用码一次性展示:写入 session,渲染完成后丢弃
        $request->session()->flash('2fa_backup_codes', $this->twoFactor->getBackupCodes($user));
        $this->alertSuccess('2FA 已启用,请妥善保存备用码');

        return redirect()->route('user:basic');
    }

    /**
     * 禁用 2FA
     */
    public function disable2fa(Request $request)
    {
        $user = \Auth::user();
        $this->twoFactor->disable($user);
        $this->alertSuccess('2FA 已禁用');
        return redirect()->route('user:basic');
    }

    /**
     * The user has been authenticated by AuthenticatesUsers::sendLoginResponse().
     *
     * 这里在密码校验通过后、AuthenticatesUsers::authenticated() 被调用时介入:
     *   - 禁用用户 → 抛错退出
     *   - 启用 2FA → 临时登出,把 user_id 暂存到 session,跳到 /auth/2fa
     *
     * @throws ValidationException
     */
    protected function authenticated(Request $request, $user)
    {
        if ($user->isDisabled()) {
            $this->guard()->logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages(['email' => '用户已禁用']);
        }

        // 登录成功清零
        $this->loginAttempt->recordSuccess($user);

        if ($user->totp_enabled_at !== null) {
            // sendLoginResponse 已经 regenerate 过 session,这里直接 put 是安全的
            $this->guard()->logout();
            $request->session()->put(self::SESSION_2FA_PENDING_USER, $user->id);

            return redirect()->route('auth.2fa.show');
        }
    }

    /**
     * 按 username() 字段查找 User(仅用于锁定检查/失败计数,不参与鉴权)。
     */
    private function lookupUserByLoginField(array $credentials): ?User
    {
        $loginKey = $this->username();
        $loginValue = $credentials[$loginKey] ?? null;
        if (!is_string($loginValue) || $loginValue === '') {
            return null;
        }
        return User::where($loginKey, $loginValue)->first();
    }
}