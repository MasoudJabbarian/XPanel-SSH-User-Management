@extends('layouts.master')
@section('title','XPanel - Remote Backup')
@section('content')
<div class="pc-container">
    <div class="pc-content">
        <div class="page-header">
            <div class="page-block">
                <div class="row align-items-center">
                    <div class="col-md-12">
                        <div class="page-header-title"><h2 class="mb-0">Remote Backup</h2></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-12">
                <div class="card">
                    @include('layouts.setting_menu')
                    <div class="card-body">
                        @if(session('success'))
                            <div class="alert alert-success">{{ session('success') }}</div>
                        @endif
                        @if($errors->any())
                            <div class="alert alert-danger">{{ $errors->first() }}</div>
                        @endif

                        <form action="{{ route('settings.remote-backup.save') }}" method="post">
                            @csrf
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Remote server / domain</label>
                                    <input class="form-control" name="remote_backup_host" value="{{ old('remote_backup_host', $settings->remote_backup_host) }}" placeholder="ftp.example.com" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Port</label>
                                    <input class="form-control" type="number" name="remote_backup_port" value="{{ old('remote_backup_port', $settings->remote_backup_port ?: 21) }}" min="1" max="65535" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Interval (hours)</label>
                                    <input class="form-control" type="number" name="remote_backup_interval_hours" value="{{ old('remote_backup_interval_hours', $settings->remote_backup_interval_hours ?: 24) }}" min="1" max="8760" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Remote folder</label>
                                    <input class="form-control" name="remote_backup_folder" value="{{ old('remote_backup_folder', $settings->remote_backup_folder) }}" placeholder="backups/xpanel">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Username</label>
                                    <input class="form-control" name="remote_backup_username" value="{{ old('remote_backup_username', $settings->remote_backup_username) }}" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Password</label>
                                    <input class="form-control" type="password" name="remote_backup_password" placeholder="Leave blank to keep current">
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" name="remote_backup_ssl" value="1" @checked(old('remote_backup_ssl', $settings->remote_backup_ssl))>
                                        <label class="form-check-label">Use FTPS</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" name="remote_backup_enabled" value="1" @checked(old('remote_backup_enabled', $settings->remote_backup_enabled))>
                                        <label class="form-check-label">Enable automatic backup</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4">
                                <button class="btn btn-primary" type="submit">Save backup settings</button>
                            </div>
                        </form>

                        <hr class="my-4">
                        <div>
                            <strong>Status:</strong>
                            @if($settings->remote_backup_last_status === 'success')
                                <span class="text-success">Success</span>
                            @elseif($settings->remote_backup_last_status === 'failed')
                                <span class="text-danger">Failed</span>
                            @elseif($settings->remote_backup_last_status === 'running')
                                <span class="text-warning">Running</span>
                            @else
                                <span>Not run yet</span>
                            @endif
                        </div>
                        @if($settings->remote_backup_last_at)
                            <div class="mt-2">Last attempt: {{ $settings->remote_backup_last_at }}</div>
                        @endif
                        @if($settings->remote_backup_last_message)
                            <div class="mt-2 text-muted">{{ $settings->remote_backup_last_message }}</div>
                        @endif
                        <div class="mt-2 text-muted">Backups are created from the XPanel database and uploaded over FTP/FTPS.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
