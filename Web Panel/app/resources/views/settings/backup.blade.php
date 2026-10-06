@extends('layouts.master')
@section('title','XPanel - Backup')
@section('content')
<div class="pc-container"><div class="pc-content">
    <div class="page-header"><div class="page-block"><div class="row"><div class="col-md-12"><h2 class="mb-0">Backups</h2></div></div></div></div>
    <div class="row"><div class="col-sm-12"><div class="card">
        @include('layouts.setting_menu')
        <div class="card-body">
            <div class="d-flex gap-2 flex-wrap mb-4">
                <form action="{{ route('settings.backup.make') }}" method="post">@csrf
                    <button class="btn btn-primary" type="submit">Create backup</button>
                </form>
                <form action="{{ route('settings.backup.upload') }}" method="post" enctype="multipart/form-data" class="d-flex gap-2">
                    @csrf
                    <input class="form-control" type="file" name="file" accept=".sql,.gz" required>
                    <button class="btn btn-secondary" type="submit">Upload</button>
                </form>
            </div>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Name</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                @forelse($lists as $list)
                    <tr>
                        <td>{{ $list }}</td>
                        <td class="text-center">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('settings.backup.dl',['name'=>$list]) }}">Download</a>
                            <form class="d-inline" method="post" action="{{ route('settings.backup.restore',['name'=>$list]) }}" onsubmit="return confirm('Restore this backup?')">@csrf
                                <button class="btn btn-sm btn-outline-warning" type="submit">Restore</button>
                            </form>
                            <form class="d-inline" method="post" action="{{ route('settings.backup.delete',['name'=>$list]) }}" onsubmit="return confirm('Delete this backup?')">@csrf
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="text-center">No backups</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
    </div></div></div>
</div></div>
@endsection
