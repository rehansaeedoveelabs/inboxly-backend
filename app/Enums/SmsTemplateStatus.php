<?php

namespace App\Enums;

enum SmsTemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
