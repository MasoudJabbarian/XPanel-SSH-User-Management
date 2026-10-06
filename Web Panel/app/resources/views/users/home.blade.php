@extends('layouts.master')
@section('title','XPanel SSH Users')
@section('content')
<div class="pc-container"><div class="pc-content">
    <div class="page-header"><div class="page-block"><div class="row align-items-center">
        <div class="col"><h2 class="mb-0">SSH Users</h2></div>
        <div class="col-auto">
            <form method="post" action="{{ route('user.all.delete') }}" onsubmit="return confirm('Delete all managed users?')">@csrf
                <button class="btn btn-outline-danger" type="submit">Delete all</button>
            </form>
        </div>
    </div></div></div>

    <div class="card mb-3"><div class="card-body">
        <div class="row g-2">
            <div class="col-md-5">
                <form class="d-flex gap-2" method="get" action="{{ route('users.search') }}">
                    <input class="form-control" name="keyword" value="{{ request('keyword') }}" placeholder="Search username">
                    <input type="hidden" name="search_by" value="username">
                    <button class="btn btn-primary" type="submit">Search</button>
                </form>
            </div>
            <div class="col-md-7 d-flex gap-2 flex-wrap">
                @foreach(['all','active','deactive','expired','traffic'] as $state)
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $state === 'all' ? route('users') : route('users.sort',['status'=>$state]) }}">{{ ucfirst($state) }}</a>
                @endforeach
            </div>
        </div>
    </div></div>

    <div class="card"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Users</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#new-user-modal">New user</button>
        </div>

        <form id="bulk-form" method="post" action="{{ route('user.action.bulk') }}">
            @csrf
            <div class="d-flex gap-2 mb-3">
                <select class="form-select" name="action" style="max-width:220px" required>
                    <option value="">Bulk action</option>
                    <option value="active">Activate</option>
                    <option value="deactive">Deactivate</option>
                    <option value="retraffic">Reset traffic</option>
                    <option value="delete">Delete</option>
                </select>
                <button class="btn btn-secondary" type="submit">Apply</button>
            </div>
        </form>

            <div class="table-responsive"><table class="table table-hover align-middle">
                <thead><tr>
                    <th><input type="checkbox" onclick="document.querySelectorAll('.user-check').forEach(x=>x.checked=this.checked)"></th>
                    <th>Username / Password</th><th>Traffic</th><th>Connections</th><th>Expires</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                @forelse($users as $user)
                    @php
                        $used = (int) optional($user->traffics->first())->total;
                        $quota = (int) $user->traffic;
                        $statusClass = match($user->status) {
                            'active' => 'success',
                            'deactive' => 'danger',
                            'expired' => 'warning',
                            'traffic' => 'primary',
                            default => 'secondary'
                        };
                    @endphp
                    <tr>
                        <td><input form="bulk-form" class="user-check" type="checkbox" name="usernamed[]" value="{{ $user->username }}"></td>
                        <td><strong>{{ $user->username }}</strong><br><small class="text-muted">{{ $user->password }}</small></td>
                        <td>{{ $used }} MB @if($quota > 0) / {{ $quota }} MB @endif</td>
                        <td>{{ optional($user->conections)->connection ?? 0 }} / {{ $user->multiuser }}</td>
                        <td>{{ $user->end_date ?: 'Unlimited' }}</td>
                        <td><span class="badge bg-light-{{ $statusClass }}">{{ ucfirst($user->status) }}</span></td>
                        <td class="text-end">
                            <div class="d-flex justify-content-end gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(@js('ssh://'.$user->username.':'.$user->password.'@'.$websiteaddress.':'.$port_ssh.'/'))">Copy SSH</button>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('user.edit',['username'=>$user->username]) }}">Edit</a>
                                <form method="post" action="{{ route('user.active',['username'=>$user->username]) }}">@csrf<button class="btn btn-sm btn-outline-success" type="submit">On</button></form>
                                <form method="post" action="{{ route('user.deactive',['username'=>$user->username]) }}">@csrf<button class="btn btn-sm btn-outline-warning" type="submit">Off</button></form>
                                <form method="post" action="{{ route('user.reset',['username'=>$user->username]) }}">@csrf<button class="btn btn-sm btn-outline-secondary" type="submit">Reset</button></form>
                                <form method="post" action="{{ route('user.delete',['username'=>$user->username]) }}" onsubmit="return confirm('Delete this user?')">@csrf<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center">No SSH users.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </form>
        {{ $users->links() }}
    </div></div>
</div></div>

<div class="modal fade" id="new-user-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="{{ route('new.user') }}">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Create SSH user</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="username" required pattern="[a-z_][a-z0-9_-]{0,31}"></div>
            <div class="col-md-6"><label class="form-label">Password</label><input class="form-control" name="password" value="{{ $password_auto }}" required></div>
            <div class="col-md-4"><label class="form-label">Max connections</label><input class="form-control" name="multiuser" type="number" min="0" value="1" required></div>
            <div class="col-md-4"><label class="form-label">Traffic (MB)</label><input class="form-control" name="traffic" type="number" min="0" value="0" required></div>
            <div class="col-md-4"><label class="form-label">Duration (days)</label><input class="form-control" name="connection_start" type="number" min="0" value="0"></div>
            <div class="col-md-6"><label class="form-label">Expiry date</label><input class="form-control" name="expdate" type="date"></div>
            <div class="col-md-6"><label class="form-label">Description</label><input class="form-control" name="desc"></div>
            <input type="hidden" name="type_traffic" value="mb">
        </div></div>
        <div class="modal-footer"><button class="btn btn-primary" type="submit">Create</button></div>
    </form>
</div></div></div>
@endsection
