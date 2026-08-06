<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// How the admin UI renders a settings field from getSettingsFields().
enum FieldType: string
{
    case Number            = 'number';
    case Select            = 'select';
    case Toggle            = 'toggle';
    case OrderedMultiSelect = 'ordered_multiselect';
    case KeyedNumberList   = 'keyed_number_list';
}
