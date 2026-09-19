<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Presentation;

use Sabri\AnalyticsIntelligence\Domain\DashboardService;
use Sabri\AnalyticsIntelligence\Infrastructure\Database;

final class InsightsShortcode
{
    public function __construct(private Database $db)
    {
    }

    public function register(): void
    {
        add_shortcode('sabri_institutional_insights', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        if (!is_singular()) {
            return;
        }
        global $post;
        if ($post instanceof \WP_Post && has_shortcode((string) $post->post_content, 'sabri_institutional_insights')) {
            wp_enqueue_style('dashicons');
            wp_enqueue_style('smai-insights', SMAI_URL . 'assets/css/insights.css', [], SMAI_VERSION);
        }
    }

    public function render(array|string $attributes = []): string
    {
        if (!is_user_logged_in() || !current_user_can('smai_view_insights')) {
            return '<div class="smai-state smai-state--restricted" role="status"><span class="dashicons dashicons-lock" aria-hidden="true"></span> '
                . esc_html__('You are not authorized to view institutional insights.', 'sabri-analytics-institutional-intelligence')
                . '</div>';
        }
        $attributes = shortcode_atts(['dashboard' => '', 'version' => ''], is_array($attributes) ? $attributes : [], 'sabri_institutional_insights');
        if ($attributes['dashboard'] === '' || $attributes['version'] === '') {
            return '<div class="smai-state" role="status"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> '
                . esc_html__('No approved dashboard was selected.', 'sabri-analytics-institutional-intelligence')
                . '</div>';
        }
        $bundle = (new DashboardService($this->db))->bundle(
            sanitize_key((string) $attributes['dashboard']),
            sanitize_text_field((string) $attributes['version']),
            get_current_user_id()
        );
        if (is_wp_error($bundle)) {
            return '<div class="smai-state smai-state--warning" role="alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span> '
                . esc_html($bundle->get_error_message())
                . '</div>';
        }

        nocache_headers();
        $titleId = wp_unique_id('smai-dashboard-title-');
        ob_start();
        ?>
        <section class="smai-insights" dir="auto" aria-labelledby="<?php echo esc_attr($titleId); ?>">
            <header class="smai-insights__header">
                <div>
                    <p class="smai-insights__eyebrow"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span> <?php echo esc_html__('Institutional Intelligence', 'sabri-analytics-institutional-intelligence'); ?></p>
                    <h1 id="<?php echo esc_attr($titleId); ?>"><?php echo esc_html((string) $bundle['name']); ?></h1>
                    <p class="smai-insights__meta"><?php echo esc_html(sprintf(__('Generated %s. Every value is version-pinned and subject to its displayed quality and caveats.', 'sabri-analytics-institutional-intelligence'), (string) $bundle['generated_at'])); ?></p>
                </div>
                <span class="smai-insights__status" role="status"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><?php echo esc_html__('Privacy-governed aggregates', 'sabri-analytics-institutional-intelligence'); ?></span>
            </header>
            <div class="smai-insights__grid" aria-live="polite">
                <?php foreach ((array) $bundle['widgets'] as $widget) :
                    $widgetId = wp_unique_id('smai-widget-'); ?>
                    <article class="smai-insights__card" aria-labelledby="<?php echo esc_attr($widgetId); ?>">
                        <div class="smai-insights__card-heading">
                            <span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
                            <h2 id="<?php echo esc_attr($widgetId); ?>"><?php echo esc_html((string) $widget['label']); ?></h2>
                        </div>
                        <p class="smai-insights__value">
                            <?php echo $widget['value'] === null ? esc_html__('Unavailable', 'sabri-analytics-institutional-intelligence') : esc_html((string) $widget['value']); ?>
                        </p>
                        <dl class="smai-insights__definition-list">
                            <div><dt><?php echo esc_html__('Metric', 'sabri-analytics-institutional-intelligence'); ?></dt><dd><?php echo esc_html((string) $widget['metric_id'] . '@' . (string) $widget['metric_version']); ?></dd></div>
                            <div><dt><?php echo esc_html__('Status', 'sabri-analytics-institutional-intelligence'); ?></dt><dd><?php echo esc_html((string) $widget['status']); ?></dd></div>
                            <div><dt><?php echo esc_html__('Window', 'sabri-analytics-institutional-intelligence'); ?></dt><dd><?php echo esc_html((string) ($widget['window_start'] ?? '—') . ' — ' . (string) ($widget['window_end'] ?? '—')); ?></dd></div>
                            <div><dt><?php echo esc_html__('Data through', 'sabri-analytics-institutional-intelligence'); ?></dt><dd><?php echo esc_html((string) ($widget['data_through'] ?? '—')); ?></dd></div>
                        </dl>
                        <?php if (!empty($widget['caveats'])) : ?>
                            <details class="smai-insights__caveats">
                                <summary><?php echo esc_html__('Caveats', 'sabri-analytics-institutional-intelligence'); ?></summary>
                                <ul>
                                    <?php foreach ((array) $widget['caveats'] as $caveat) : ?>
                                        <li><?php echo esc_html((string) $caveat); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
