<?php

namespace Splicewire\Beam\Analytics;

use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;

/**
 * The Analytics section: one seat per realm it is seated in, flagged `overview`, so it lands on an overview of one card
 * per Analytics Area (ADR-0222 decision 9, ticket 03). Every area resource sits in this section.
 *
 * The overview is beam-ux's: its `policy:` is the realm dashboard ability plus the seat's entitlement lock, and its cards
 * are the section's projected rail subtree. The boundary for the data is each area resource's own `policy:`, which always
 * includes `analytics.view` ({@see Areas\AreaDefinition::ability()}).
 */
final class AnalyticsSection
{
    /** The seat key, and every area resource's `section:`. */
    public const KEY = 'analytics';

    /** The one entitlement that opens the section. A declared key, so `entitlement:analytics.view` is an ability. */
    public const ENTITLEMENT = 'analytics.view';

    /** The realms the section is seated in. Ticket 05 adds `tenant`. */
    public const REALMS = ['operator'];

    public const LABEL = 'Analytics';

    /**
     * A name the console's icon map draws (beam-inertia `src/frame/icons.tsx`). A name it does not carry draws as an empty
     * circle, which is what `ChartLine` did on the realm dashboard's tile.
     */
    public const ICON = 'Activity';

    public const ORDER = 70;

    /** The registrant this package writes under, in every registry it fills. */
    public const REGISTRANT = 'splicewire/laravel-beam-analytics';

    /** The seat in `$realm`. Its href is a section path, never a leaf's: the overview flag lands it on the overview. */
    public static function seat(string $realm): NavSection
    {
        return new NavSection(
            key: self::KEY,
            realm: $realm,
            label: self::LABEL,
            icon: self::ICON,
            href: '/'.$realm.'/'.self::KEY,
            order: self::ORDER,
            entitlement: [self::ENTITLEMENT],
            permission: null,
            audience: NavAudience::Product,
            overview: true,
        );
    }
}
