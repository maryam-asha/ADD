<?php

namespace Add\Inventory\Assets\Enums;

enum DisposalMethod: string
{
    case Sold = 'sold';
    case Scrapped = 'scrapped';
    case Donated = 'donated';
}
