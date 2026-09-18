<?php

namespace Unit\app\Views\Components;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Unit\TestCase;

/**
 * x-global::statTile renders its delta (the period-over-period pill) through
 * Illuminate\Support\Number::format(), which throws a RuntimeException whenever ext-intl is
 * missing. Leantime does not require intl and its Docker images do not install it, so the
 * project report page 500'd in production the moment a tile had a non-zero delta.
 *
 * The tile now formats via the intl-free format_number() helper. This test renders the real
 * component with a delta in a process that lacks intl, pinning the regression. (The companion
 * FormatNumberHelperTest covers the formatting semantics directly.)
 */
class StatTileDeltaFormattingTest extends TestCase
{
    /**
     * Renders through an isolated Blade stack (same rationale as StatTileEscapingTest):
     * going through the app's view factory drags in composers that need a database, and this
     * contract is about number formatting, not app bootstrapping.
     */
    private function renderDelta(array $delta): string
    {
        $files = new Filesystem;
        $cache = sys_get_temp_dir().'/lt-stattile-delta-blade-'.getmypid();
        $files->ensureDirectoryExists($cache);

        $compiler = new BladeCompiler($files, $cache);
        $compiler->anonymousComponentNamespace('global::components', 'global');

        $resolver = new EngineResolver;
        $resolver->register('blade', fn () => new CompilerEngine($compiler, $files));

        $finder = new FileViewFinder($files, [APP_ROOT.'/app/Views/Templates']);
        $finder->addNamespace('global', APP_ROOT.'/app/Views/Templates');

        $factory = new Factory($resolver, $finder, new Dispatcher);

        app()->instance(\Illuminate\Contracts\View\Factory::class, $factory);
        app()->instance('view', $factory);

        $template = $cache.'/tile.blade.php';
        $files->put($template, '<x-global::statTile :value="$value" :label="$label" :delta="$delta" />');

        return (string) $factory->file($template, [
            'value' => 10,
            'label' => 'Hours logged',
            'delta' => $delta,
        ])->render();
    }

    public function test_positive_delta_renders_without_intl(): void
    {
        $html = $this->renderDelta(['value' => 12.34, 'goodWhenUp' => true]);

        $this->assertStringContainsString('+12.3', $html);
    }

    public function test_negative_delta_renders_without_intl(): void
    {
        $html = $this->renderDelta(['value' => -3.5, 'goodWhenUp' => false]);

        $this->assertStringContainsString('−3.5', $html);
    }

    public function test_zero_delta_short_circuits_before_formatting(): void
    {
        $html = $this->renderDelta(['value' => 0.0, 'goodWhenUp' => null]);

        $this->assertStringContainsString('±0', $html);
    }
}
