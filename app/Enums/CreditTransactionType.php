<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    /** The user bought credits (+). */
    case Purchase = 'purchase';

    /** The most a generation can cost, set aside when it is submitted (-). */
    case Hold = 'hold';

    /** The hold given back once the generation finishes, whatever happened (+). */
    case Release = 'release';

    /** What the generation actually cost, only when it succeeded (-). */
    case Charge = 'charge';
}
