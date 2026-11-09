<?php

namespace Splicewire\Beam\Analytics\Tests\Section;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Rushing\DataNav\ServiceProvider as DataNavServiceProvider;
use Rushing\Doctor\ServiceProvider as DoctorServiceProvider;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\JsonNs\Laravel\JsonNsServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider;
use Splicewire\Beam\BeamServiceProvider;

/**
 * The Analytics section over beam's frame socket: this package's provider, then beam and the providers beam's own harness
 * lists, frame for the manifest, list and summary routes, and the doctor for its audits. No beam-ux, because analytics must
 * not require it (ADR-0242): the seat's projection and the section overview are proven on a worktree host.
 *
 * Grants are plain Gate definitions per reader, as exec's tests do. `os.operate` is the operator realm's own door, declared
 * here the way a host's config declares it; `analytics.view` is not declared here, because the package must declare it.
 */
abstract class SectionTestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BeamAnalyticsServiceProvider::class,
            BeamServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            VersioningServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            DataNavServiceProvider::class,
            JsonNsServiceProvider::class,
            PopcornServiceProvider::class,
            DataFiltersServiceProvider::class,
            FrameServiceProvider::class,
            DoctorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', SectionUser::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('beam.core.entitlements.keys', array_values(array_unique([
            ...(array) $app['config']->get('beam.core.entitlements.keys', []),
            'os.operate',
        ])));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('email');
            $t->timestamps();
        });
        Schema::create('sessions', function (Blueprint $t): void {
            $t->string('id')->primary();
        });
    }

    /**
     * A reader holding exactly `$keys` among the entitlements this test grants. Abilities that are not entitlements (an
     * area's narrower ability) are granted the same way.
     *
     * @param  list<string>  $keys
     */
    protected function reader(string $email, array $keys): SectionUser
    {
        $user = SectionUser::create(['email' => $email]);
        $this->grants[$email] = $keys;

        return $user;
    }

    /** @var array<string, list<string>> */
    protected array $grants = [];

    /**
     * Define each named ability from {@see $grants}. Entitlements are `entitlement:{key}`; anything else is the ability as
     * named. Defined after boot, so these replace the resolver-backed definitions beam made for declared keys.
     *
     * @param  list<string>  $entitlements
     * @param  list<string>  $abilities
     */
    protected function defineGrants(array $entitlements, array $abilities = []): void
    {
        foreach ($entitlements as $key) {
            Gate::define("entitlement:{$key}", fn (?Authenticatable $user): bool => $user !== null && in_array($key, $this->grants[$user->email] ?? [], true));
        }
        foreach ($abilities as $ability) {
            Gate::define($ability, fn (?Authenticatable $user): bool => $user !== null && in_array($ability, $this->grants[$user->email] ?? [], true));
        }
    }
}

class SectionUser extends AuthUser
{
    protected $table = 'users';

    protected $guarded = [];
}
