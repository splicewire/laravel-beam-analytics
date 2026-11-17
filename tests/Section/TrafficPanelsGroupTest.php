<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Analytics\AnalyticsSection;
use Splicewire\Beam\Analytics\Traffic\TrafficPanels;
use Splicewire\Beam\Nav\NavGroupMatch;

/**
 * analytics-area 22: the section declares ONE nav group, which folds the three Traffic panels into one tab, in both realms.
 * It must take every panel's page and NOT the Traffic area's own page, `analytics-traffic` (root ruling f46b2b53: a
 * trailing `*` is a raw prefix).
 */
class TrafficPanelsGroupTest extends TestCase
{
    public function test_the_seat_declares_the_traffic_panels_group_in_every_realm(): void
    {
        foreach (['operator' => '/operator', 'tenant' => '/'] as $realm => $routeBase) {
            $this->assertSame(
                [['label' => AnalyticsSection::TRAFFIC_PANELS_GROUP, 'match' => TrafficPanels::NAV_MATCH]],
                AnalyticsSection::seat($realm, $routeBase)->groups,
            );
        }
    }

    public function test_the_group_takes_the_three_panels_and_leaves_the_traffic_area_its_own_tab(): void
    {
        foreach (['/operator', '/'] as $routeBase) {
            $group = NavGroupMatch::resolve(TrafficPanels::NAV_MATCH, $routeBase);
            $base = rtrim($routeBase, '/');

            foreach (array_keys(TrafficPanels::PANELS) as $panel) {
                $this->assertTrue($group->takes($base.'/'.TrafficPanels::resourceKey($panel)), $panel);
            }
            $this->assertFalse($group->takes($base.'/analytics-traffic'), 'the Traffic area keeps its own tab');
            $this->assertFalse($group->takes($base.'/analytics-events'));
            $this->assertFalse($group->takes($base.'/analytics-measurement'));
        }
    }
}
