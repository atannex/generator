<?php

declare(strict_types=1);

namespace Atannex\Generator;

use Atannex\Generator\Console\BackfillCodesCommand;
use Atannex\Generator\Sequences\SequenceRepository;
use Illuminate\Support\ServiceProvider;

class GeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/generator.php', 'generator');

        $this->app->singleton(SequenceRepository::class, fn ($app) => new SequenceRepository(
            $app['db'],
            (string) $app['config']->get('generator.sequences.table', 'generator_sequences'),
            $app['config']->get('generator.sequences.connection'),
        ));

        $this->app->singleton(CodeGenerator::class, fn ($app) => new CodeGenerator(
            $app->make(SequenceRepository::class),
            (int) $app['config']->get('generator.max_attempts', 15),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/generator.php' => config_path('generator.php')], 'generator-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'generator-migrations');

            $this->commands([BackfillCodesCommand::class]);
        }
    }
}
