<?php
/** Footer "Report a problem" control + dialog. Hidden when relay is disabled. */
$reportsEnabled = false;
try {
    $reportsEnabled = app(\Socly\Services\GitHubIssueService::class)->isConfigured();
} catch (\Throwable) {
    $reportsEnabled = false;
}
if (!$reportsEnabled) {
    return;
}

$reportUrl = url('/api/report-problem');
?>
<button type="button"
        class="report-problem-btn"
        data-report-problem
        data-report-url="<?= e($reportUrl) ?>"
        aria-haspopup="dialog">
    <svg class="report-problem-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2Zm0 5a1.25 1.25 0 1 1 0 2.5A1.25 1.25 0 0 1 12 7Zm1.5 9.5h-3v-6h3v6Z"/>
    </svg>
    <span data-i18n="report.button"><?= e(__('report.button')) ?></span>
</button>

<dialog class="setup-exit-dialog report-problem-dialog" data-report-problem-dialog>
    <form class="setup-exit-shell report-problem-shell" data-report-problem-form>
        <h2 class="report-problem-title" data-i18n="report.title"><?= e(__('report.title')) ?></h2>
        <p class="setup-exit-text report-problem-lede muted" data-i18n="report.lede"><?= e(__('report.lede')) ?></p>
        <label class="report-problem-field">
            <span data-i18n="report.description"><?= e(__('report.description')) ?></span>
            <?= field('textarea', [
                'name' => 'description',
                'label' => '',
                'context' => 'inline',
                'required' => true,
                'rows' => 5,
                'maxlength' => 4000,
                'placeholder' => (string) __('report.description_placeholder'),
                'attrs' => [
                    'data-report-problem-description' => true,
                    'data-i18n-placeholder' => 'report.description_placeholder',
                ],
            ]) ?>
        </label>
        <p class="report-problem-status muted" data-report-problem-status hidden></p>
        <div class="setup-exit-actions">
            <button type="button" class="btn btn-ghost" data-report-problem-cancel data-i18n="report.cancel"><?= e(__('report.cancel')) ?></button>
            <button type="submit" class="btn" data-report-problem-submit data-i18n="report.send"><?= e(__('report.send')) ?></button>
        </div>
    </form>
</dialog>
