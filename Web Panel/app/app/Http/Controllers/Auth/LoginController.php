<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Schema;
use Route;
use App\Models\Admins;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;


class LoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest:admins', ['except' => ['logout']]);
    }

    public function showLoginForm()
    {
        // Do not create or overwrite the admin account on every login-page visit.
        // The installer is responsible for creating the initial admin account.
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $this->validate($request, [
            'username' => 'required',
            'password' => 'required'
        ]);

        if (Auth::guard('admins')->attempt([
            'username' => $request->username,
            'password' => $request->password,
            'status' => 'active'
        ])) {
            return redirect()->intended(route('dashboard'));
        }

        $admin = Admins::where('username', $request->username)->first();

        if (!$admin) {
            return redirect()->back()
                ->withInput($request->only('username', 'remember'))
                ->with('alert', __('login-error-password'));
        }

        if ($admin->status !== 'active') {
            return redirect()->back()
                ->withInput($request->only('username', 'remember'))
                ->with('alert', __('login-error-deactive'));
        }

        if (!$admin || !Hash::check($request->password, (string) $admin->password)) {
            return redirect()->back()
                ->withInput($request->only('username', 'remember'))
                ->with('alert', __('login-error-password'));
        }

        return redirect()->back()
            ->withInput($request->only('username', 'remember'))
            ->with('alert', __('login-error-password'));
    }

    public function logout()
    {
        Auth::guard('admins')->logout();
        return redirect('/login');
    }
}
