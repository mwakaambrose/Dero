<?php

namespace App\Providers;

use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\LocalFilesystemBackend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerStorageBackend();
    }

    /**
     * Bind the object storage backend.
     *
     * The configuration is read at resolution time rather than at registration,
     * so a test that overrides dero.storage_root must resolve the backend after
     * setting it.
     */
    protected function registerStorageBackend(): void
    {
        $this->app->singleton(ObjectStorageBackend::class, fn (): LocalFilesystemBackend => new LocalFilesystemBackend(
            root: (string) config('dero.storage_root'),
            chunkSize: (int) config('dero.stream_chunk_size'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
