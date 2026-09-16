<?php

namespace App\Enums;

/**
 * VMS-old ESSVisitorMaster.VisitorType 1–4 and 6. Type 5 ("Black List") is not
 * a type here: it becomes visitors.is_blacklisted.
 */
enum VisitorType: string
{
    case Visitor = 'visitor';
    case Crew = 'crew';
    case TemporaryWorker = 'temporary_worker';
    case Supplier = 'supplier';
    case Others = 'others';
}
