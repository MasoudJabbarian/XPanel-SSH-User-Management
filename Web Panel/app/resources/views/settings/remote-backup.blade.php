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
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <form method="post" action="{{ route('settings.remote-backup.save') }}">
          @csrf
          <div class="row">
            <div class="col-lg-6 mb-3"><label class="form-label">FTP / FTPS host</label><input name="remote_backup_host" class="form-control" value="{{ old('remote_backup_host',$settings->remote_backup_host) }}" required></div>
            <div class="col-lg-3 mb-3"><label class="form-label">Port</label><input type="number" name="remote_backup_port" class="form-control" value="{{ old('remote_backup_port',$settings->remote_backup_port ?: 21) }}" min="1" max="65535" required></div>
            <div class="col-lg-3 mb-3"><label class="form-label">Interval (hours)</label><input type="number" name="remote_backup_interval_hours" class="form-control" value="{{ old('remote_backup_interval_hours',$settings->remote_backup_interval_hours ?: 24) }}" min="1" max="8760" required></div>
            <div class="col-lg-6 mb-3"><label class="form-label">Remote folder</label><input name="remote_backup_folder" class="form-control" value="{{ old('remote_backup_folder',$settings->remote_backup_folder) }}" placeholder="backups"></div>
            <div class="col-lg-6 mb-3"><label class="form-label">Username</label><input name="remote_backup_username" class="form-control" value="{{ old('remote_backup_username',$settings->remote_backup_username) }}" required></div>
            <div class="col-lg-6 mb-3"><label class="form-label">Password</label><input type="password" name="remote_backup_password" class="form-control" placeholder="Leave blank to keep current password"></div>
            <div class="col-lg-3 mb-3 mt-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="remote_backup_ssl" value="1" id="remote_backup_ssl" {{ old('remote_backup_ssl',$settings->remote_backup_ssl) ? 'checked' : '' }}><label class="form-check-label" for="remote_backup_ssl">Use FTPS</label></div></div>
            <div class="col-lg-3 mb-3 mt-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="remote_backup_enabled" value="1" id="remote_backup_enabled" {{ old('remote_backup_enabled',$settings->remote_backup_enabled) ? 'checked' : '' }}><label class="form-check-label" for="remote_backup_enabled">Enable automatic backup</label></div></div>
          </div>
          <button class="btn btn-primary" type="submit">Save settings</button>
        </form>
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
