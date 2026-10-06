@extends('layouts.master')
@section('title','XPanel SSH Dashboard')
@section('content')
<div class="pc-container"><div class="pc-content">
<div class="page-header"><div class="page-block"><h2 class="mb-0">SSH Dashboard</h2></div></div>
<div class="row g-3">
@foreach([['Users',$alluser,'primary'],['Active',$active_user,'success'],['Offline',$deactive_user,'secondary'],['Expired',$expired_user,'warning'],['Traffic limit',$traffic_user,'danger'],['Online',$online_user,'info']] as $card)
<div class="col-md-4 col-xl-2"><div class="card"><div class="card-body"><span class="text-muted">{{ $card[0] }}</span><h3 class="mb-0 text-{{ $card[2] }}">{{ $card[1] }}</h3></div></div></div>
@endforeach
</div>
<div class="card"><div class="card-body">
    <h5>SSH service</h5>
    <p class="mb-1">Port: <strong>{{ env('PORT_SSH',22) }}</strong></p>
    <p class="mb-0">Recorded traffic: <strong>{{ $traffic_total }} MB</strong></p>
</div></div>
</div></div>
@endsection
