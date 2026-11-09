<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use PHPUnit\Framework\Attributes\Test;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AnalyticsAreaResources;
use Splicewire\Beam\Analytics\Tests\Support\Areas\EmptyArea;
use Splicewire\Beam\Doctor\DashboardTierAudit;
use Splicewire\Beam\Doctor\ModelLessReadGateAudit;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\UnrealmedResourceAudit;
use Splicewire\Beam\Surgeon\UnseatedNavSectionAudit;

/**
 * Doctor passes each of the five checks ticket 04 names on the section's population, and a second probe shows each check
 * reads that population: it either lists the package's entry, or it flags a misdeclared sibling planted beside it. A check
 * that never read the area would pass both ways, so the probe is what makes its pass mean something.
 */
class DoctorCoverageTest extends SectionTestCase
{
    /** @param  list<Finding>  $findings */
    private function assertClean(array $findings, string $check): void
    {
        $bad = array_filter($findings, fn (Finding $f): bool => $f->check === $check && $f->status !== DoctorStatus::Pass && str_contains($f->detail, 'analytics'));
        $this->assertSame([], array_map(fn (Finding $f) => $f->detail, array_values($bad)), $check);
    }

    /** @param  list<Finding>  $findings */
    private function assertFlags(array $findings, string $check, string $needle): void
    {
        $hits = array_filter($findings, fn (Finding $f): bool => $f->check === $check && $f->status === DoctorStatus::Warn && str_contains($f->detail, $needle));
        $this->assertNotSame([], $hits, "{$check} should flag {$needle}: ".implode(' | ', array_map(fn (Finding $f) => $f->status->value.' '.$f->detail, $findings)));
    }

    private function plant(ParticleResource $resource, array $realms): void
    {
        app(ParticleResourceRegistry::class)->register($resource, $realms, by: 'probe');
    }

    private function measurementCopy(string $key, array $overrides = []): ParticleResource
    {
        $source = app(ParticleResourceRegistry::class)->resolve('analytics-measurement');

        return new ParticleResource(...array_merge([
            'key' => $key,
            'backing' => $source->backing,
            'data' => $source->data,
            'input' => false,
            'label' => 'Probe',
            'policy' => $source->policy,
            'icon' => 'Box',
            'section' => $source->section,
            'readOnly' => true,
            'editable' => false,
            'showable' => false,
            'frame' => true,
            'summaryProvider' => $source->summaryProvider,
        ], $overrides));
    }

    #[Test]
    public function registry_conformance_passes_and_governs_the_areas_registry(): void
    {
        $audit = app(RegistryConformanceAudit::class);

        $this->assertClean($audit->run(), RegistryConformanceAudit::CHECK);
        $this->assertContains(AnalyticsAreaRegistry::class, $audit->population());
        $this->assertStringNotContainsString('AnalyticsAreaRegistry', json_encode($audit->nonConforming()));
    }

    #[Test]
    public function every_area_resource_belongs_to_a_realm_and_an_unrealmed_one_would_be_flagged(): void
    {
        $this->assertClean(app(UnrealmedResourceAudit::class)->run(), UnrealmedResourceAudit::CHECK_UNREALMED);

        $this->plant($this->measurementCopy('analytics-probe-unrealmed'), []);
        $this->assertFlags(app(UnrealmedResourceAudit::class)->run(), UnrealmedResourceAudit::CHECK_UNREALMED, 'analytics-probe-unrealmed');
    }

    #[Test]
    public function the_areas_section_is_seated(): void
    {
        $audit = app(UnseatedNavSectionAudit::class);

        $this->assertClean($audit->run(), UnseatedNavSectionAudit::CHECK);
        $this->assertStringContainsString('analytics', json_encode($audit->declared()));
        $this->assertContains('analytics', $audit->seatedKeys());
        $this->assertStringNotContainsString('analytics', json_encode($audit->unseated()));
    }

    #[Test]
    public function every_area_declares_a_read_gate_and_one_without_would_be_flagged(): void
    {
        $this->assertClean(app(ModelLessReadGateAudit::class)->run(), ModelLessReadGateAudit::CHECK);

        $this->plant($this->measurementCopy('analytics-probe-ungated', ['policy' => null]), ['operator']);
        $this->assertFlags(app(ModelLessReadGateAudit::class)->run(), ModelLessReadGateAudit::CHECK, 'analytics-probe-ungated');
    }

    #[Test]
    public function every_area_card_is_on_the_declared_tier_and_a_declining_one_would_be_flagged(): void
    {
        $this->assertClean(app(DashboardTierAudit::class)->run(), DashboardTierAudit::CHECK);

        app(AnalyticsAreaRegistry::class)->add(EmptyArea::class, 'probe');
        app(AnalyticsAreaResources::class)->register();
        $this->assertFlags(app(DashboardTierAudit::class)->run(), DashboardTierAudit::CHECK, 'analytics-empty');
    }
}
