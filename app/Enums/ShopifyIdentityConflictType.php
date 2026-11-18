<?php

namespace App\Enums;

enum ShopifyIdentityConflictType: string
{
    case EmailMatch = 'email_match';
    case PhoneMatch = 'phone_match';
    case EmailAndPhone = 'email_and_phone';
    case MultipleCandidates = 'multiple_candidates';
    case ContactAlreadyLinked = 'contact_already_linked';
}
