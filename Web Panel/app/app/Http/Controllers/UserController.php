<?php

namespace App\Http\Controllers;

use App\Models\Admins;
use App\Models\LogConnection;
use App\Models\Settings;
use App\Models\Traffic;
use App\Models\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:admins');
    }

    private function assertLinuxUsername(string $username): void
    {
        abort_unless((bool) preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username), 422, 'Invalid Linux username');
    }

    private function canManage(Users $user): bool
    {
        $admin = Auth::user();
        return $admin && ($admin->permission === 'admin' || $user->customer_user === $admin->username);
    }

    private function trafficValue(Request $request): int
    {
        $traffic = (int) $request->input('traffic', 0);
        return $request->input('type_traffic') === 'gb' ? $traffic * 1024 : $traffic;
    }

    private function activateSystemUser(Users $user): void
    {
        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'add', $user->username, $user->password, (string) max(0, (int) $user->multiuser)]);
        if (env('STATUS_LOG', 'deactive') === 'active') {
            Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'banner', $user->username]);
        }
    }

    public function generateQRCode(string $data)
    {
        return response(QrCode::size(300)->margin(5)->generate(base64_decode($data, true) ?: ''));
    }

    public function index()
    {
        $admin = Auth::user();
        $query = Users::with(['traffics', 'conections'])->orderByDesc('id');
        if ($admin->permission !== 'admin') {
            $query->where('customer_user', $admin->username);
        }

        $users = $query->paginate(25);
        $settings = Settings::first();
        $websiteaddress = parse_url(request()->getHost(), PHP_URL_HOST);
        $sshaddress = $websiteaddress;
        $port_ssh = (int) env('PORT_SSH', 22);
        $password_auto = Str::random(8);
        $detail_admin = Admins::where('username', $admin->username)->first();

        return view('users.home', compact(
            'users', 'settings', 'password_auto', 'websiteaddress',
            'port_ssh', 'sshaddress', 'detail_admin'
        ));
    }

    public function index_sort(string $status)
    {
        abort_unless(in_array($status, ['active', 'deactive', 'expired', 'traffic'], true), 404);
        $admin = Auth::user();
        $query = Users::with(['traffics', 'conections'])->where('status', $status)->orderByDesc('id');
        if ($admin->permission !== 'admin') {
            $query->where('customer_user', $admin->username);
        }

        $users = $query->paginate(25);
        $settings = Settings::first();
        $websiteaddress = parse_url(request()->getHost(), PHP_URL_HOST);
        $sshaddress = $websiteaddress;
        $port_ssh = (int) env('PORT_SSH', 22);
        $password_auto = Str::random(8);
        $detail_admin = Admins::where('username', $admin->username)->first();

        return view('users.home', compact(
            'users', 'settings', 'password_auto', 'websiteaddress',
            'port_ssh', 'sshaddress', 'detail_admin'
        ));
    }

    public function search(Request $request)
    {
        $request->validate([
            'keyword' => 'nullable|string|max:100',
            'search_by' => 'nullable|in:username,email,mobile',
            'status' => 'nullable|in:all,active,deactive,expired,traffic',
        ]);

        $admin = Auth::user();
        $query = Users::with(['traffics', 'conections'])->orderByDesc('id');
        if ($admin->permission !== 'admin') {
            $query->where('customer_user', $admin->username);
        }
        if ($request->filled('keyword')) {
            $field = $request->input('search_by', 'username');
            $query->where($field, 'like', '%' . $request->input('keyword') . '%');
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $users = $query->paginate(25)->withQueryString();
        $settings = Settings::first();
        $websiteaddress = parse_url(request()->getHost(), PHP_URL_HOST);
        $sshaddress = $websiteaddress;
        $port_ssh = (int) env('PORT_SSH', 22);
        $password_auto = Str::random(8);
        $detail_admin = Admins::where('username', $admin->username)->first();

        return view('users.home', compact(
            'users', 'settings', 'password_auto', 'websiteaddress',
            'port_ssh', 'sshaddress', 'detail_admin'
        ));
    }

    public function create()
    {
        return redirect()->route('users');
    }

    public function newuser(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'password' => ['required', 'string', 'max:255'],
            'email' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:64',
            'multiuser' => 'required|integer|min:0|max:1000',
            'connection_start' => 'nullable|integer|min:0|max:3650',
            'traffic' => 'required|integer|min:0',
            'type_traffic' => 'required|in:mb,gb',
            'expdate' => 'nullable|date',
            'desc' => 'nullable|string|max:1000',
        ]);

        $admin = Auth::user();
        $customer = $admin->permission === 'admin' ? $admin->username : $admin->username;
        $username = strtolower($request->username);
        $this->assertLinuxUsername($username);

        if (Users::where('username', $username)->exists() || Process::run(['id', '-u', $username])->successful()) {
            return back()->with('alert', 'Username already exists.');
        }

        $days = (int) $request->input('connection_start', 0);
        $start = $days > 0 ? now()->toDateString() : null;
        $end = $request->filled('expdate')
            ? $request->expdate
            : ($days > 0 ? now()->addDays($days)->toDateString() : null);

        DB::transaction(function () use ($request, $username, $customer, $start, $end, $days) {
            $user = Users::create([
                'username' => $username,
                'password' => $request->password,
                'email' => $request->email,
                'mobile' => $request->mobile,
                'multiuser' => $request->multiuser,
                'start_date' => $start,
                'end_date' => $end,
                'date_one_connect' => $days,
                'customer_user' => $customer,
                'status' => 'active',
                'traffic' => $this->trafficValue($request),
                'referral' => '',
                'desc' => $request->desc,
            ]);

            Traffic::create(['username' => $user->username, 'download' => 0, 'upload' => 0, 'total' => 0]);
            $this->activateSystemUser($user);
        });

        return back()->with('success', 'User created.');
    }

    public function bulkuser(Request $request)
    {
        $request->validate([
            'count_user' => 'required|integer|min:1|max:500',
            'start_user' => ['required', 'string', 'max:24', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'start_number' => 'required|integer|min:0|max:999999',
            'password' => 'nullable|string|max:255',
            'pass_random' => 'nullable|in:number,nmuber_az',
            'char_pass' => 'nullable|integer|min:4|max:32',
            'multiuser' => 'required|integer|min:0|max:1000',
            'connection_start' => 'nullable|integer|min:0|max:3650',
            'traffic' => 'required|integer|min:0',
            'type_traffic' => 'required|in:mb,gb',
        ]);

        for ($i = 0; $i < (int) $request->count_user; $i++) {
            $username = strtolower($request->start_user . ($request->start_number + $i));
            $password = $request->password ?: ($request->pass_random === 'nmuber_az'
                ? Str::random((int) ($request->char_pass ?: 8))
                : (string) random_int(100000, 999999));

            $clone = $request->duplicate();
            $clone->merge([
                'username' => $username,
                'password' => $password,
            ]);
            $this->newuser($clone);
        }

        return back()->with('success', 'Users created.');
    }

    public function activeuser(string $username)
    {
        $this->assertLinuxUsername($username);
        $user = Users::where('username', $username)->firstOrFail();
        abort_unless($this->canManage($user), 403);
        $user->update(['status' => 'active']);
        $this->activateSystemUser($user);
        return back()->with('success', 'Activated.');
    }

    public function deactiveuser(string $username)
    {
        $this->assertLinuxUsername($username);
        $user = Users::where('username', $username)->firstOrFail();
        abort_unless($this->canManage($user), 403);
        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'unbanner', $username]);
        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'kill-user', $username]);
        $user->update(['status' => 'deactive']);
        return back()->with('success', 'Deactivated.');
    }

    public function reset_traffic(string $username)
    {
        $this->assertLinuxUsername($username);
        $user = Users::where('username', $username)->firstOrFail();
        abort_unless($this->canManage($user), 403);
        Traffic::where('username', $username)->update(['download' => 0, 'upload' => 0, 'total' => 0]);
        return back()->with('success', 'Traffic reset.');
    }

    public function delete(string $username)
    {
        $this->assertLinuxUsername($username);
        $user = Users::where('username', $username)->firstOrFail();
        abort_unless($this->canManage($user), 403);
        Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'delete', $username]);
        Traffic::where('username', $username)->delete();
        LogConnection::where('username', $username)->delete();
        $user->delete();
        return back()->with('success', 'User deleted.');
    }

    public function user_all_delete()
    {
        $admin = Auth::user();
        $query = Users::query();
        if ($admin->permission !== 'admin') {
            $query->where('customer_user', $admin->username);
        }
        foreach ($query->get() as $user) {
            Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'delete', $user->username]);
            Traffic::where('username', $user->username)->delete();
            LogConnection::where('username', $user->username)->delete();
            $user->delete();
        }
        return back()->with('success', 'Users deleted.');
    }

    public function delete_bulk(Request $request)
    {
        $request->validate([
            'action' => 'required|in:delete,active,deactive,retraffic',
            'usernamed' => 'required|array|max:500',
            'usernamed.*' => ['string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
        ]);

        foreach ($request->usernamed as $username) {
            $user = Users::where('username', $username)->first();
            if (!$user || !$this->canManage($user)) {
                continue;
            }
            match ($request->action) {
                'delete' => $this->delete($username),
                'active' => $this->activeuser($username),
                'deactive' => $this->deactiveuser($username),
                'retraffic' => $this->reset_traffic($username),
            };
        }
        return back()->with('success', 'Bulk action completed.');
    }

    public function renewal(Request $request)
    {
        $request->validate([
            'username_re' => ['required', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'day_date' => 'required|integer|min:1|max:3650',
            're_date' => 'required|in:yes,no',
            're_traffic' => 'required|in:yes,no',
            'renewal_date' => 'nullable|date',
        ]);

        $user = Users::where('username', $request->username_re)->firstOrFail();
        abort_unless($this->canManage($user), 403);

        if ($request->re_date === 'yes') {
            $base = $request->renewal_date ?: ($user->end_date && now()->lt($user->end_date) ? $user->end_date : now()->toDateString());
            $user->end_date = CarbonCarbon::parse($base)->addDays((int) $request->day_date)->toDateString();
            $user->status = 'active';
            $this->activateSystemUser($user);
        }
        if ($request->re_traffic === 'yes') {
            $user->traffic = $user->traffic;
            Traffic::where('username', $user->username)->update(['download' => 0, 'upload' => 0, 'total' => 0]);
        }
        $user->save();

        return back()->with('success', 'Renewed.');
    }

    public function renew_bulk(Request $request)
    {
        $request->validate([
            'bulkrenew' => 'required|array|max:500',
            'bulkrenew.*' => 'integer',
            'day_date' => 'required|integer|min:1|max:3650',
            're_date' => 'required|in:yes,no',
            're_traffic' => 'required|in:yes,no',
        ]);

        foreach ($request->bulkrenew as $id) {
            $user = Users::find($id);
            if (!$user || !$this->canManage($user)) {
                continue;
            }
            $sub = Request::create('/user/renewal', 'POST', [
                'username_re' => $user->username,
                'day_date' => $request->day_date,
                're_date' => $request->re_date,
                're_traffic' => $request->re_traffic,
            ]);
            $sub->setUserResolver(fn () => Auth::user());
            $this->renewal($sub);
        }
        return back()->with('success', 'Bulk renewal completed.');
    }

    public function edit(string $username)
    {
        $this->assertLinuxUsername($username);
        $show = Users::where('username', $username)->firstOrFail();
        abort_unless($this->canManage($show), 403);
        $end_date = $show->end_date;
        return view('users.edit', compact('show', 'end_date'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'password' => 'required|string|max:255',
            'email' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:64',
            'multiuser' => 'required|integer|min:0|max:1000',
            'traffic' => 'required|integer|min:0',
            'type_traffic' => 'required|in:mb,gb',
            'expdate' => 'nullable|date',
            'activate' => 'required|in:active,deactive',
            'desc' => 'nullable|string|max:1000',
        ]);

        $this->assertLinuxUsername($request->username);
        $user = Users::where('username', $request->username)->firstOrFail();
        abort_unless($this->canManage($user), 403);

        $user->update([
            'password' => $request->password,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'multiuser' => $request->multiuser,
            'traffic' => $this->trafficValue($request),
            'end_date' => $request->expdate,
            'status' => $request->activate,
            'desc' => $request->desc,
        ]);

        if ($request->activate === 'active') {
            $this->activateSystemUser($user);
        } else {
            Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'unbanner', $user->username]);
            Process::run(['sudo', '/usr/local/sbin/xpanel-userctl', 'kill-user', $user->username]);
        }

        return back()->with('success', 'User updated.');
    }

    public function process_active_user()
    {
        $admin = Auth::user();
        $query = Users::where('status', 'active');
        if ($admin->permission !== 'admin') {
            $query->where('customer_user', $admin->username);
        }
        foreach ($query->get() as $user) {
            $this->activateSystemUser($user);
        }
        return back()->with('success', 'Active users repaired.');
    }
}
