<?php

namespace Add\Hr\Core\Enums;

enum WorkMode: string
{
    case OnSite = 'on_site';
    case Remote = 'remote';
    case Hybrid = 'hybrid';
}
