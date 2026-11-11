<?php

namespace Splicewire\Beam\Analytics\Areas;

use Splicewire\Beam\Analytics\Scope\AnalyticsPeriod;

/**
 * An {@see AnalyticsArea} whose figures depend on the reading's period (ticket 09). Its page offers a `period` facet
 * ({@see AnalyticsPeriod::facet()}): 7, 28 or 90 days, carried in the URL as `filter[period]`. An area whose figures do not
 * depend on the period (Measurement, Events) does not implement it, so its page offers no choice it would ignore.
 */
interface MeasuresOverPeriod extends AnalyticsArea {}
