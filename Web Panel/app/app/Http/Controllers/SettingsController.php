<?php

namespace App\Http\Controllers;

use App\Models\Users;
use App\Models\Admins;
use App\Models\Api;
use Illuminate\Http\Request;
use Auth;
use App\Models\Settings;
use App\Models\Traffic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Illuminate\Support\Process\ProcessResult;


class SettingsController extends Controller
{
    private function safeBackupPath(string $name): string
    {
        if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            abort(422, 'Invalid backup filename');
        }

        return '/var/www/html/app/storage/backup/' . $name;
    }

    private function setEnvValue(string $key, string $value): void
    {
        $path = '/var/www/html/app/.env';
        $contents = file_exists($path) ? file_get_contents($path) : '';
        $line = $key . '=' . str_replace(["\r", "\n"], '', $value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\r\n") . "\n" . $line . "\n";
        file_put_contents($path, $contents, LOCK_EX);
    }

    public function __construct() {
        $this->middleware('auth:admins');

    }
    public function check()
    {
        $user = Auth::user();
        $check_admin = Admins::where('id', $user->id)->get();
        if($check_admin[0]->permission=='reseller')
        {
            exit(view('access'));
        }
    }
    public function defualt()
    {
        $this->check();
        return redirect()->intended(route('settings', ['name' => 'general']));
    }
    public function mod(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        if($name=='night' OR $name=='light')
        {
            $this->setEnvValue('APP_MODE', $name);;
        }
        return redirect()->back()->with('success', 'success');
    }
    public function lang(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        if($name=='fa' OR $name=='en' OR $name=='ru')
        {
            $this->setEnvValue('APP_LOCALE', $name);;
        }

        return redirect()->back()->with('success', 'success');
    }
    public function index(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Invalid settings section');
        }
        $setting = Settings::first();
        $apis = Api::all();
        if ($name === 'general') {
            $status = $setting?->multiuser ?? 'deactive';
            $tls_port = $setting?->tls_port ?? null;
            $traffic_base = env('TRAFFIC_BASE', 12);
            return view('settings.general', compact('traffic_base','status','tls_port'));
        }
        if ($name === 'backup') {
            $backupDir = '/var/www/html/app/storage/backup';
            $lists = is_dir($backupDir) ? array_values(array_diff(scandir($backupDir), ['.','..'])) : [];
            return view('settings.backup', compact('lists'));
        }
        if ($name === 'api') {
            return view('settings.api', compact('apis'));
        }
        if ($name === 'block') {
            $check_status = Process::run(['sudo','iptables','-L','OUTPUT']);
            $output = preg_split("/\\r\\n|\\n|\\r/", trim($check_status->output()));
            $status = max(0, count($output) - 3);
            return view('settings.block', compact('status'));
        }
        abort(404);
    }
    public function change_port_ssh(Request $request)
    {
        $this->check();
        $request->validate([
            'port_ssh' => 'required|integer|min:1|max:65535',
        ]);

        $port = (int) $request->port_ssh;
        $result = Process::run(['sudo', 'sed', '-i', "s/^\\s*Port\\s.*/Port {$port}/", '/etc/ssh/sshd_config']);
        if ($result->successful()) {
            $this->setEnvValue('PORT_SSH', (string) $port);
            Process::run(['sudo', 'sed', '-i', "s/DEFAULT_HOST =.*/DEFAULT_HOST = '127.0.0.1:{$port}'/g", '/usr/local/bin/wss']);
            Process::run(['sudo', 'sed', '-i', "s/connect =.*/connect = 0.0.0.0:{$port}/g", '/etc/stunnel/stunnel.conf']);
            Process::run(['sudo', 'systemctl', 'daemon-reload']);
            Process::run(['sudo', 'systemctl', 'enable', '--now', 'wss']);
        }
        return response()->json(['message' => __('settings-port-alert-success')]);

    }

    public function update_general(Request $request)
    {
        $this->check();
        $request->validate([
            'trafficbase'=>'required|numeric',
            'direct_login'=>'required|string',
            'lang'=>'required|string',
            'mode'=>'required|string',
            'status_traffic'=>'string',
            'status_multiuser'=>'string',
            'status_day'=>'string',
            'status_log'=>'string',
            'anti_user'=>'string',
        ]);
        $traffic_base_old=env('TRAFFIC_BASE');
        $traffic_base_new=$request->trafficbase;
        $fileContents = file_get_contents('/var/www/html/app/.env');
        $newContents = str_replace("TRAFFIC_BASE=".$traffic_base_old, "TRAFFIC_BASE=".$traffic_base_new, $fileContents);
        file_put_contents('/var/www/html/app/.env', $newContents);
        if($request->lang=='fa' OR $request->lang=='en' OR $request->lang=='ru')
        {
            $this->setEnvValue('APP_LOCALE', $request->lang);;
        }
        if($request->mode=='night' OR $request->mode=='light')
        {
            $this->setEnvValue('APP_MODE', $request->mode);;
        }

        $this->setEnvValue('PANEL_DIRECT', $request->direct_login);;
        if (empty($request->status_day) or $request->status_day=='deactive')
        {
            $status_day='deactive';
        }
        else
        {
            $status_day='active';
        }

        if (empty($request->status_traffic) or $request->status_traffic=='deactive')
        {
            $status_traffic='deactive';
        }
        else
        {
            $status_traffic='active';
        }

        if (empty($request->status_multiuser) or $request->status_multiuser=='deactive')
        {
            $status_multiuser='deactive';
        }
        else
        {
            $status_multiuser='active';
        }

        if (empty($request->status_log) or $request->status_log=='deactive')
        {
            $status_log='deactive';
        }
        else
        {
            $status_log='active';
        }
        if (empty($request->anti_user) or $request->anti_user=='deactive')
        {
            $anti_user='deactive';
        }
        else
        {
            $anti_user='active';
        }
        $this->setEnvValue('ANTI_USER', $anti_user);;
        $this->setEnvValue('STATUS_LOG', $status_log);;
        $this->setEnvValue('CRON_TRAFFIC', $status_traffic);;
        $this->setEnvValue('DAY', $status_day);;
        $check_setting = Settings::where('id', '1')->count();
        if ($check_setting > 0) {
            Settings::where('id', 1)->update(['multiuser' => $status_multiuser]);
        }

        return redirect()->intended(route('settings', ['name' => 'general']));
    }
$request)
    {
        $this->check();
        $request->validate([
            'tokenbot'=>'required|string',
            'idtelegram'=>'required|string'
        ]);
        $check_setting = Settings::where('id','1')->count();
        if ($check_setting > 0) {
            Settings::where('id', 1)->update(['t_token' => $request->tokenbot,'t_id' => $request->idtelegram]);
        } else {
            Settings::create([
                't_token' => $request->tokenbot,'t_id' => $request->idtelegram
            ]);
        }
        return redirect()->intended(route('settings', ['name' => 'telegram']));
    }
 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_exec($ch);
            curl_close($ch);
            $user = Auth::user();
            $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890";
            $token = substr(str_shuffle($chars), 0, 15);
            $bot_api_access=time().$token;
            $check_bot_access = Api::where('description','Backup Bot v1')->count();
            if($check_bot_access>0)
            {
                Api::where('description','Backup Bot v1')->update(['token' => $bot_api_access]);
                exec("(crontab -l ; echo '*/5 * * * * wget -q -O /dev/null \"$webhookUrl\" > /dev/null 2>&1') | crontab -");
            }
            else {
                Api::create([
                    'username' => $user->username,
                    'token' => $bot_api_access,
                    'description' => 'Backup Bot v1',
                    'allow_ip' => '0.0.0.0/0',
                    'status' => 'active'
                ]);
                //exec("(crontab -l ; echo '0 */12 * * * wget -q -O /dev/null \"$webhookUrl\" > /dev/null 2>&1') | crontab -");
                exec("(crontab -l ; echo '*/5 * * * * wget -q -O /dev/null \"$webhookUrl\" > /dev/null 2>&1') | crontab -");
            }
            $current_time = time();
            //Process::run("sed -i \"s/BOT_LOG=.*/BOT_LOG=$current_time/g\" /var/www/html/app/.env");
            $this->setEnvValue('BOT_TOKEN', $request->token_bot);;
            $this->setEnvValue('BOT_ID_ADMIN', $request->id_admin);;
            $this->setEnvValue('BOT_API_ACCESS', $bot_api_access);;
            sleep(1);

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_exec($ch);
            curl_close($ch);
            return redirect()->intended(route('settings', ['name' => 'backup']));
        } else {
            return redirect()->back()->with('success', __('setting-backup-bot_error_ssl'));
        }
    }
    public function upload_backup(Request $request)
    {
        $this->check();
        $request->validate([
            'file'=>'required|mimetypes:text/plain'
        ]);
        if($request->file('file')) {
            $file = $request->file('file');
            $filename = basename($file->getClientOriginalName());
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
                abort(422, 'Invalid backup filename');
            }
            $file->move('/var/www/html/app/storage/backup/', $filename);

        }
        return redirect()->intended(route('settings', ['name' => 'backup']));
    }

    public function delete_backup(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        $path = $this->safeBackupPath($name);
        if (is_file($path)) {
            unlink($path);
        }
        return redirect()->intended(route('settings', ['name' => 'backup']));

    }

    public function restore_backup(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        Process::run("mysql -u '" . env('DB_USERNAME') . "' --password='" . env('DB_PASSWORD') . "' XPanel_plus < /var/www/html/app/storage/backup/" . $name);
        $users = Users::where('status', 'active')->get();
        $users_sb = Singbox::where('status', 'active')->get();
        $batchSize = 10;
        $userBatches = array_chunk($users->toArray(), $batchSize);
        $userBatches_sb = array_chunk($users_sb->toArray(), $batchSize);

        foreach ($userBatches as $userBatch) {
            foreach ($userBatch as $user) {
                $username=$user['username'];
                $password=$user['password'];
                Process::run("sudo adduser --disabled-password --gecos '' --shell /usr/sbin/nologin {$username}");
                Process::input($password. "\n" .$password. "\n")->timeout(120)->run("sudo passwd {$username}");
                $check_traffic = Traffic::where('username', $username)->count();
                if ($check_traffic < 1) {
                    Traffic::create([
                        'username' => $username,
                        'download' => '0',
                        'upload' => '0',
                        'total' => '0'
                    ]);
                }
            }
        }
        foreach ($userBatches_sb as $userBatch) {
            foreach ($userBatch as $user) {
                $port=$user['port_sb'];
                $protocol=$user['protocol_sb'];
                $detail_sb=$user['detail_sb'];
                $name=$user['name'];
                $multiuser=$user['multiuser'];
                $check_user = Singbox::where('port_sb',$port)->count();
                if ($check_user > 0) {
                    $jsonData = json_decode($detail_sb, true);
                    $sid=$jsonData['sid'];
                    $uuid=$jsonData['uuid'];
                    $validatedData = [
                        'port'=>$port,
                        'protocol'=>$protocol,
                        'sid'=>$sid,
                        'uuid'=>$uuid,
                        'name'=>$name,
                        'multiuser'=>$multiuser,
                    ];

                    ProController::active_singbox($validatedData);
                }
                $check_traffic = Trafficsb::where('port_sb', $port)->count();
                if ($check_traffic < 1) {
                    Trafficsb::create([
                        'port_sb' => $port,
                        'sent_sb' => '0',
                        'received_sb' => '0',
                        'total_sb' => '0'
                    ]);
                }
            }
        }
        return redirect()->intended(route('settings', ['name' => 'backup']));

    }

    public function make_backup()
    {
        $this->check();
        $date = date("Y-m-d---h-i-s");
        Process::run("mysqldump -u '" .env('DB_USERNAME'). "' --password='" .env('DB_PASSWORD'). "' XPanel_plus > /var/www/html/app/storage/backup/XPanel-".$date.".sql");
        return redirect()->intended(route('settings', ['name' => 'backup']));
    }
    public function download_backup(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        $fileName = basename($name);
        $filePath = $this->safeBackupPath($fileName);

        if (file_exists('/var/www/html/app/storage/backup/'.$fileName)) {
            return response()->download($filePath, $fileName, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => 'attachment',
            ])->deleteFileAfterSend(true);
        }

        abort(404);
        return redirect()->intended(route('settings', ['name' => 'backup']));
    }

    public function insert_api(Request $request)
    {
        $this->check();
        $user = Auth::user();
        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890";
        $token = substr(str_shuffle($chars), 0, 15);
        $request->validate([
            'desc'=>'required|string',
            'allowip'=>'required|string'
        ]);
        Api::create([
            'username' => $user->username,
            'token' => time().$token,
            'description' => $request->desc,
            'allow_ip' => $request->allowip,
            'status' => 'active'
        ]);
        return redirect()->intended(route('settings', ['name' => 'api']));
    }

    public function renew_api(Request $request,$id)
    {
        $this->check();
        if (!is_numeric($id)) {
            abort(400, 'Not Valid Username');
        }
        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890";
        $token_new = substr(str_shuffle($chars), 0, 15);
        Api::where('id', $id)->update(['token' => time().$token_new]);
        return redirect()->intended(route('settings', ['name' => 'api']));
    }

    public function delete_api(Request $request,$id)
    {
        $this->check();
        if (!is_numeric($id)) {
            abort(400, 'Not Valid Username');
        }
        Api::where('id', $id)->delete();
        return redirect()->intended(route('settings', ['name' => 'api']));
    }

    public function block(Request $request)
    {
        $this->check();
        $request->validate([
            'status'=>'required|string'
        ]);
        if($request->status=='active')
        {
            Process::run("sudo iptables -A OUTPUT -m geoip -p tcp --destination-port 80 --dst-cc IR -j DROP");
            Process::run("sudo iptables -A OUTPUT -m geoip -p tcp --destination-port 443 --dst-cc IR -j DROP");
        }
        else
        {
            Process::run("sudo iptables -F");

        }

        return redirect()->intended(route('settings', ['name' => 'block']));
    }
er[0] = "Accept: text/xml,application/xml,application/xhtml+xml,font/woff,font/woff2,";
    $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5,application/font-woff,*";
    $header[] = "Access-Control-Allow-Origin: *";
    $header[] = "Connection: keep-alive";
    $header[] = "Keep-Alive: 300";
    $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
    $header[] = "Accept-Language: en-us,en;q=0.5";
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $header );

    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);

    // I have added below two lines
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $data = curl_exec($ch);
    curl_close($ch);

    return $data;
}
$site = "' . $request->fake_address . '";
echo curl_get_contents("$site");
        ';
        file_put_contents("/var/www/html/example/index.php", $txt);
        return redirect()->intended(route('settings', ['name' => 'fakeaddress']));
    }
red|string',
            'port'=>'required|string',
            'username'=>'required|string',
            'password'=>'required|string',
            'email'=>'required|string',
            'name'=>'required|string',
            'status_service'=>'required|string',
        ]);

        ProController::setting_mail($validatedData);
        return redirect()->intended(route('settings', ['name' => 'mail']))->with('alert', __('allert-success'));
    }
p,
                'status_active' => 'pending',
                'status_service' => 'access'
            ]);
            DB::commit();
            $msg=__('ip-adapter-change-popup-ip-add');
        }
        return redirect()->intended(route('settings', ['name' => 'ip-adapter']))->with('alert', $msg);
    }

        ]);
        DB::commit();
        return redirect()->intended(route('settings', ['name' => 'ip-adapter']))->with('alert', __('allert-success'));
    }
  {
        $this->check();
        if (!is_numeric($id)) {
            abort(400, 'Not Valid Username');
        }
        Adapterlist::where('id', $id)->delete();
        return redirect()->intended(route('settings', ['name' => 'ip-adapter']))->with('alert', __('allert-success'));
    }
