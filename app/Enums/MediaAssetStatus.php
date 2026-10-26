<?php

namespace App\Enums;

enum MediaAssetStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
