@extends('layouts.master')
@section('title','XPanel - General')
@section('content')
<div class="pc-container"><div class="pc-content">
<div class="page-header"><div class="page-block"><h2 class="mb-0">SSH Settings</h2></div></div>
<div class="card"><div class="card-body">
    @include('layouts.setting_menu')
    <div class="p-3">
        <h5>SSH port</h5>
        <form action="{{ route('settings.change.port.ssh') }}" method="post" class="row g-2 mb-4">
            @csrf
            <div class="col-md-4"><input class="form-control" name="port" type="number" min="1" max="65535" value="{{ env('PORT_SSH',22) }}" required></div>
            <div class="col-md-2"><button class="btn btn-primary" type="submit">Change</button></div>
        </form>

        <form action="{{ route('settings.general') }}" method="post">
            @csrf
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Traffic base</label><input class="form-control" type="number" min="1" name="traffic_base" value="{{ $traffic_base }}"></div>
                <div class="col-md-4"><label class="form-label">Traffic accounting</label><select class="form-select" name="status_traffic"><option value="active" @selected(env('CRON_TRAFFIC','active')==='active')>Enabled</option><option value="deactive" @selected(env('CRON_TRAFFIC','active')!=='active')>Disabled</option></select></div>
                <div class="col-md-4"><label class="form-label">Concurrent connection limit</label><select class="form-select" name="status_multiuser"><option value="active" @selected($status==='active')>Enabled</option><option value="deactive" @selected($status!=='active')>Disabled</option></select></div>
                <div class="col-md-4"><label class="form-label">Expiry day mode</label><select class="form-select" name="status_day"><option value="active" @selected(env('DAY','deactive')==='active')>Enabled</option><option value="deactive" @selected(env('DAY','deactive')!=='active')>Disabled</option></select></div>
                <div class="col-md-4"><label class="form-label">SSH login banner</label><select class="form-select" name="status_log"><option value="active" @selected(env('STATUS_LOG','deactive')==='active')>Enabled</option><option value="deactive" @selected(env('STATUS_LOG','deactive')!=='active')>Disabled</option></select></div>
                <div class="col-md-4"><label class="form-label">Remove unmanaged Linux users</label><select class="form-select" name="anti_user"><option value="active" @selected(env('ANTI_USER','active')==='active')>Enabled</option><option value="deactive" @selected(env('ANTI_USER','active')!=='active')>Disabled</option></select></div>
            </div>
            <button class="btn btn-primary mt-4" type="submit">Save settings</button>
        </form>
    </div>
</div></div>
</div></div>
@endsection
