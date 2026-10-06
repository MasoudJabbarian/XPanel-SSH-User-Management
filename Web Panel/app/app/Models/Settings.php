<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Settings extends Model
{
    use HasFactory;

    protected $fillable = [
        'ssh_port','tls_port','t_token','t_id','language','multiuser','ststus_multiuser','home_url',
        'remote_backup_host','remote_backup_folder','remote_backup_username','remote_backup_password',
        'remote_backup_port','remote_backup_ssl','remote_backup_enabled','remote_backup_interval_hours',
        'remote_backup_last_at','remote_backup_last_status','remote_backup_last_message'
    ];

    protected $casts = [
        'remote_backup_password' => 'encrypted',
        'remote_backup_ssl' => 'boolean',
        'remote_backup_enabled' => 'boolean',
        'remote_backup_last_at' => 'datetime',
    ];
}
