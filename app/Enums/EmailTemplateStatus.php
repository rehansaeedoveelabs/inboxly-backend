<?php

namespace App\Enums;

enum EmailTemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
