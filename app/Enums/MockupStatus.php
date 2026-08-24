<?php

namespace App\Enums;

enum MockupStatus: string
{
    case Pending = 'PENDING';
    case Ready = 'READY';
    case Failed = 'FAILED';
}
