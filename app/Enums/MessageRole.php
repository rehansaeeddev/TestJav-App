<?php

namespace App\Enums;

enum MessageRole: string
{
    case Merchant = 'merchant';
    case Assistant = 'assistant';
}
