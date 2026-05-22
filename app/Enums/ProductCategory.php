<?php

namespace App\Enums;

enum ProductCategory: string
{
    case Apparel = 'APPAREL';
    case Mug = 'MUG';
    case Accessory = 'ACCESSORY';
    case Poster = 'POSTER';
    case Other = 'OTHER';
}
