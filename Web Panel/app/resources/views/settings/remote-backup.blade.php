@extends('layouts.master')
@section('title','XPanel - Remote Backup')
@section('content')
<div class="pc-container">
  <div class="pc-content">
    <div class="page-header">
      <div class="page-block">
        <div class="row align-items-center">
          <div class="col-md-12"><div class="page-header-title"><h2 class="mb-0">Remote Backup</h2></div></div>
        </div>
      </div>
    </div>
    <div class="row"><div class="col-sm-12"><div class="card">
      @include('layouts.setting_menu')
      <div class="card-body">
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if($errors->any())
          <div class="alert alert-danger">
            <div class="fw-bold mb-2">خطا در Remote Backup</div>
            <pre class="mb-0" style="white-space:pre-wrap;word-break:break-word;">{{ $errors->first() }}</pre>
          </div>
        @endif
        <div class="alert alert-info">
          Backups are transferred over SSH/SFTP. The backup server only needs SSH access; no FTP service is required.<br><strong>مسیر ثابت روی سرور بکاپ:</strong> <code>/var/backups/xpanel</code>
        </div>
        <form method="post" action="{{ route('settings.remote-backup.save') }}">
          @csrf
          <div class="row">
            <div class="col-lg-6 mb-3"><label class="form-label">Backup server (IP / hostname)</label><input name="remote_backup_host" class="form-control" value="{{ old('remote_backup_host',$settings->remote_backup_host) }}" required></div>
            <div class="col-lg-3 mb-3"><label class="form-label">SSH port</label><input type="number" name="remote_backup_port" class="form-control" value="{{ old('remote_backup_port',$settings->remote_backup_port ?: 22) }}" min="1" max="65535" required></div>
            <div class="col-lg-3 mb-3"><label class="form-label">Interval (hours)</label><input type="number" name="remote_backup_interval_hours" class="form-control" value="{{ old('remote_backup_interval_hours',$settings->remote_backup_interval_hours ?: 24) }}" min="1" max="8760" required></div>
            <div class="col-lg-6 mb-3"><label class="form-label">مسیر ثابت ذخیره روی سرور بکاپ</label><input class="form-control" value="/var/backups/xpanel" readonly><small class="text-muted">این مسیر استاندارد و ثابت است و قابل ویرایش نیست.</small></div>
            <div class="col-lg-6 mb-3"><label class="form-label">SSH username</label><input name="remote_backup_username" class="form-control" value="{{ old('remote_backup_username',$settings->remote_backup_username) }}" required></div>
            <div class="col-lg-6 mb-3"><label class="form-label">SSH password</label><input type="password" name="remote_backup_password" class="form-control" placeholder="Leave blank to keep current password"></div>
            <div class="col-lg-6 mb-3 mt-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="remote_backup_enabled" value="1" id="remote_backup_enabled" {{ old('remote_backup_enabled',$settings->remote_backup_enabled) ? 'checked' : '' }}><label class="form-check-label" for="remote_backup_enabled">Enable automatic backup</label></div></div>
          </div>
          <button class="btn btn-primary" type="submit">Save settings</button>
        </form>
        <hr>
        <div class="card border mb-3">
          <div class="card-body">
            <h5 class="mb-2">اجرای دستی بکاپ</h5>
            <p class="text-muted mb-3">
              با اجرای این عملیات، ابتدا از دیتابیس بکاپ گرفته می‌شود و سپس اتصال SSH/SFTP، احراز هویت، مسیر مقصد و انتقال کامل فایل بررسی می‌شود.
              نتیجه دقیق خطا نیز در همین صفحه ثبت خواهد شد.
            </p>
            <form method="post" action="{{ route('settings.remote-backup.run') }}" onsubmit="return confirm('بکاپ جدید ساخته و به سرور بکاپ ارسال شود؟');">
              @csrf
              <button class="btn btn-success" type="submit">
                اجرای بکاپ و ارسال الآن
              </button>
            </form>
          </div>
        </div>
        <hr>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <form method="post" action="{{ route('settings.remote-backup.restore') }}" onsubmit="return confirm('پنج فایل آخر بکاپ از سرور بکاپ خوانده و روی سرور اصلی کپی شوند؟');">
            @csrf
            <button class="btn btn-outline-primary" type="submit">بازیابی فایل های سرور بکاپ</button>
          </form>
          <small class="text-muted">۵ فایل آخر با همان فرمت اصلی (.sql) دریافت می‌شوند و محتوای آنها تغییر نمی‌کند.</small>
        </div>
        @if($settings->remote_backup_last_status)
          <hr><div><strong>Last status:</strong> {{ $settings->remote_backup_last_status }}</div>
          <div><strong>Last run:</strong> {{ $settings->remote_backup_last_at }}</div>
          <div><strong>Message:</strong> {{ $settings->remote_backup_last_message }}</div>
        @endif
      </div>
    </div></div></div>
  </div>
</div>
@endsection
