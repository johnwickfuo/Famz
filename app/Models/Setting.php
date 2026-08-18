<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw storage for platform settings. Nothing outside SettingsService should
 * read or write this model directly — the cache lives in the service.
 */
#[Fillable(['key', 'group', 'type', 'value'])]
class Setting extends Model {}
