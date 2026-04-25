<?php

namespace App\Service\AI\Llm;

enum ModelSize: string
{
    case Big = 'big';
    case Mid = 'mid';
    case Small = 'small';
    case Free = 'free';
}
