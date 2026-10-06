<?php

namespace App\Http\Controllers;

use App\Models\LogConnection;
use App\Models\Traffic;
use App\Models\Users;
use App\Models\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class ApiController extends Controller
{
    private function token(Request $request): Api
    {
        $header = (string) $request->header('Authorization');
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : (string) $request->header('X-XPanel-Token');
        abort_if($token === '', 401, 'Missing API token');

        $api = Api::where('token', hash('sha256', $token))->orWhere('token', $token)->first();
        abort_unless($api && $api->status === 'active', 401, 'Invalid API token');

        if ($api->allow_ip && $api->allow_ip !== '0.0.0.0/0') {
            abort_unless($api->allow_ip === $request->ip(), 403, 'IP not allowed');
        }
        return $api;
    }

    private function user(string $username): Users
    {
        abort_unless((bool) preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username), 422, 'Invalid Linux username');
        return Users::where('username', $username)->firstOrFail();
    }

    public function listuser(Request $request): JsonResponse
    {
        $this->token($request);
        return response()->json(Users::with('traffics')->orderByDesc('id')->get());
    }

    public function sort_listuser(Request $request, string $sort): JsonResponse
    {
        $this->token($request);
        abort_unless(in_array($sort, ['active','deactive','expired','traffic'], true), 422);
        return response()->json(Users::where('status', $sort)->with('traffics')->orderByDesc('id')->get());
    }

    public function add_user(Request $request): JsonResponse
    {
        $this->token($request);
        $request->validate([
            'username' => ['required','string','max:32','regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'password' => 'required|string|max:255',
            'email' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:64',
            'multiuser' => 'required|integer|min:0|max:1000',
            'connection_start' => 'nullable|integer|min:0|max:3650',
            'traffic' => 'required|integer|min:0',
            'type_traffic' => 'required|in:mb,gb',
            'expdate' => 'nullable|date',
            'desc' => 'nullable|string|max:1000',
        ]);
        $username = strtolower($request->username);
        if (Users::where('username', $username)->exists() || Process::run(['id','-u',$username])->successful()) {
            return response()->json(['message'=>'User exists'], 409);
        }
        $traffic = (int) $request->traffic * ($request->type_traffic === 'gb' ? 1024 : 1);
        $days = (int) $request->connection_start;
        $user = Users::create([
            'username'=>$username,'password'=>$request->password,'email'=>$request->email,'mobile'=>$request->mobile,
            'multiuser'=>$request->multiuser,'start_date'=>$days ? now()->toDateString() : null,
            'end_date'=>$request->expdate ?: ($days ? now()->addDays($days)->toDateString() : null),
            'date_one_connect'=>$days,'customer_user'=>'API','status'=>'active','traffic'=>$traffic,
            'referral'=>'','desc'=>$request->desc
        ]);
        Traffic::create(['username'=>$username,'download'=>0,'upload'=>0,'total'=>0]);
        Process::run(['sudo','/usr/local/sbin/xpanel-userctl','add',$username,$request->password,(string)$request->multiuser]);
        return response()->json(['message'=>'User created','user'=>$user], 201);
    }

    public function show_detail(Request $request, string $username): JsonResponse
    {
        $this->token($request);
        return response()->json($this->user($username)->load('traffics'));
    }

    public function edit(Request $request): JsonResponse
    {
        $this->token($request);
        $request->validate([
            'username'=>['required','regex:/^[a-z_][a-z0-9_-]{0,31}$/'],'password'=>'nullable|string|max:255',
            'multiuser'=>'nullable|integer|min:0|max:1000','traffic'=>'nullable|integer|min:0',
            'type_traffic'=>'nullable|in:mb,gb','expdate'=>'nullable|date','status'=>'nullable|in:active,deactive',
            'email'=>'nullable|string|max:255','mobile'=>'nullable|string|max:64','desc'=>'nullable|string|max:1000'
        ]);
        $user=$this->user($request->username);
        $data=$request->only(['email','mobile','desc','multiuser','expdate']);
        if($request->filled('password'))$data['password']=$request->password;
        if($request->filled('traffic'))$data['traffic']=(int)$request->traffic*($request->input('type_traffic','mb')==='gb'?1024:1);
        if($request->filled('status'))$data['status']=$request->status;
        $user->update($data);
        if($user->status==='active'){
            Process::run(['sudo','/usr/local/sbin/xpanel-userctl','add',$user->username,$user->password,(string)$user->multiuser]);
        }else{
            Process::run(['sudo','/usr/local/sbin/xpanel-userctl','kill-user',$user->username]);
        }
        return response()->json(['message'=>'User updated','user'=>$user->fresh()]);
    }

    public function delete_user(Request $request): JsonResponse
    {
        $this->token($request); $request->validate(['username'=>'required|string']);
        $user=$this->user($request->username);
        Process::run(['sudo','/usr/local/sbin/xpanel-userctl','delete',$user->username]);
        Traffic::where('username',$user->username)->delete(); LogConnection::where('username',$user->username)->delete(); $user->delete();
        return response()->json(['message'=>'User deleted']);
    }

    public function active_user(Request $request): JsonResponse
    {
        $this->token($request); $request->validate(['username'=>'required|string']); $user=$this->user($request->username);
        $user->update(['status'=>'active']);
        Process::run(['sudo','/usr/local/sbin/xpanel-userctl','add',$user->username,$user->password,(string)$user->multiuser]);
        return response()->json(['message'=>'User activated']);
    }

    public function deactive_user(Request $request): JsonResponse
    {
        $this->token($request); $request->validate(['username'=>'required|string']); $user=$this->user($request->username);
        Process::run(['sudo','/usr/local/sbin/xpanel-userctl','kill-user',$user->username]);
        Process::run(['sudo','/usr/local/sbin/xpanel-userctl','unbanner',$user->username]);
        $user->update(['status'=>'deactive']);
        return response()->json(['message'=>'User deactivated']);
    }

    public function retrafic_user(Request $request): JsonResponse
    {
        $this->token($request); $request->validate(['username'=>'required|string']); $user=$this->user($request->username);
        Traffic::where('username',$user->username)->update(['download'=>0,'upload'=>0,'total'=>0]);
        return response()->json(['message'=>'Traffic reset']);
    }

    public function renewal_user(Request $request): JsonResponse
    {
        $this->token($request); $request->validate(['username'=>'required|string','days'=>'required|integer|min:1|max:3650']);
        $user=$this->user($request->username); $base=$user->end_date && now()->lt($user->end_date)?$user->end_date:now();
        $user->update(['end_date'=>\Carbon\Carbon::parse($base)->addDays((int)$request->days)->toDateString(),'status'=>'active']);
        Process::run(['sudo','/usr/local/sbin/xpanel-userctl','add',$user->username,$user->password,(string)$user->multiuser]);
        return response()->json(['message'=>'User renewed','end_date'=>$user->end_date]);
    }

    public function traffic_user(Request $request): JsonResponse
    {
        $this->token($request); $request->validate(['username'=>'required|string']); $user=$this->user($request->username);
        return response()->json(['quota'=>$user->traffic,'traffic'=>Traffic::where('username',$user->username)->first()]);
    }

    public function online_user(Request $request): JsonResponse
    {
        $this->token($request);
        $result=Process::run(['sudo','/usr/local/sbin/xpanel-userctl','online-port',(string)env('PORT_SSH',22)]);
        return response()->json(['output'=>$result->output()]);
    }

    public function kill(Request $request): JsonResponse
    {
        $this->token($request);
        $request->validate(['method'=>'required|in:user,pid','param'=>'required|string|max:32']);
        if($request->method==='user'){
            $user=$this->user($request->param);
            Process::run(['sudo','/usr/local/sbin/xpanel-userctl','kill-user',$user->username]);
        }else{
            abort_unless(ctype_digit($request->param),422,'Invalid PID');
            Process::run(['sudo','/usr/local/sbin/xpanel-userctl','kill-pid',$request->param]);
        }
        return response()->json(['message'=>'Process terminated']);
    }
}
