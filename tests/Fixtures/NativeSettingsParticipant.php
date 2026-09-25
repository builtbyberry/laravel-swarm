<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class NativeSettingsParticipant extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'native_settings_participants';
}
