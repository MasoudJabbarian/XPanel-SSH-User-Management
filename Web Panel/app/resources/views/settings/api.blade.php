@extends('layouts.master')
@section('title','XPanel - API')
@section('content')
<div class="pc-container"><div class="pc-content">
<div class="page-header"><div class="page-block"><h2 class="mb-0">SSH API</h2></div></div>
<div class="card"><div class="card-body">
    <form action="{{ route('settings.api') }}" method="post" class="row g-2 mb-4">@csrf
        <div class="col-md-4"><input class="form-control" name="description" placeholder="Description" value="SSH management API"></div>
        <div class="col-md-4"><input class="form-control" name="allow_ip" placeholder="Allowed IP" value="0.0.0.0/0"></div>
        <div class="col-md-2"><button class="btn btn-primary" type="submit">Create token</button></div>
    </form>
    <div class="table-responsive"><table class="table"><thead><tr><th>Description</th><th>Allowed IP</th><th>Token</th><th>Actions</th></tr></thead><tbody>
    @forelse($apis as $api)
        <tr><td>{{ $api->description }}</td><td>{{ $api->allow_ip }}</td><td><code>{{ $api->token }}</code></td><td>
            <form class="d-inline" method="post" action="{{ route('settings.token.renew',['id'=>$api->id]) }}">@csrf<button class="btn btn-sm btn-outline-warning" type="submit">Renew</button></form>
            <form class="d-inline" method="post" action="{{ route('settings.token.delete',['id'=>$api->id]) }}" onsubmit="return confirm('Delete token?')">@csrf<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>
        </td></tr>
    @empty
        <tr><td colspan="4" class="text-center">No API tokens.</td></tr>
    @endforelse
    </tbody></table></div>
</div></div>
</div></div>
@endsection
