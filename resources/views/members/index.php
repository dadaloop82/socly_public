<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.title')) ?></h1>
        <p class="page-lede"><?= e(__('members.lede')) ?></p>
    </div>
    <div class="actions">
        <?php if (can('members.manage')): ?>
            <a class="btn" href="<?= e(url('/members/create')) ?>"><?= e(__('members.create')) ?></a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/members/export?' . http_build_query(array_filter($filters)))) ?>"><?= e(__('members.export')) ?></a>
        <a class="btn btn-ghost" href="<?= e(url('/members/export/registry?' . http_build_query(array_filter($filters)))) ?>" target="_blank" rel="noopener"><?= e(__('members.registry_export')) ?></a>
    </div>
</div>

<?php if (!empty($hasAnyMembers)): ?>
<form class="panel filter-bar members-filter" method="get">
    <div class="panel-header members-filter-head">
        <div>
            <h2 class="section-title"><?= e(__('members.search')) ?></h2>
            <p class="section-lede"><?= e(__('members.filter_lede')) ?></p>
        </div>
        <button class="btn btn-sm members-filter-submit-desktop" type="submit"><?= e(__('members.search')) ?></button>
    </div>
    <div class="members-filter-q">
        <label class="members-filter-q-label" for="members-q"><?= e(__('members.search')) ?></label>
        <div class="members-filter-q-row">
            <input
                id="members-q"
                type="search"
                name="q"
                value="<?= e($filters['q']) ?>"
                placeholder="<?= e(__('members.search_placeholder')) ?>"
                data-placeholder-desktop="<?= e(__('members.search_placeholder')) ?>"
                data-placeholder-mobile="<?= e(__('members.search_placeholder_mobile')) ?>"
                enterkeyhint="search"
                autocomplete="off"
            >
            <button class="btn btn-sm members-filter-submit-mobile" type="submit"><?= e(__('members.search')) ?></button>
        </div>
    </div>
    <div class="members-filter-extra grid-3">
        <div>
            <label><?= e(__('members.status')) ?></label>
            <select name="status">
                <option value="">—</option>
                <?php foreach (\Socly\Services\MemberService::memberStatuses() as $st): ?>
                    <option value="<?= $st ?>" <?= $filters['status']===$st?'selected':'' ?>><?= e(__('members.status_'.$st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?= e(__('members.payment')) ?></label>
            <select name="payment">
                <option value="">—</option>
                <?php foreach (['paid','partial','due'] as $p): ?>
                    <option value="<?= $p ?>" <?= $filters['payment']===$p?'selected':'' ?>><?= e(__('members.payment_'.$p)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?= e(__('members.type')) ?></label>
            <select name="member_type_id">
                <option value="">—</option>
                <?php foreach ($types as $type): ?>
                    <option value="<?= (int)$type['id'] ?>" <?= (string)$filters['member_type_id']===(string)$type['id']?'selected':'' ?>><?= e(localized($type['name_json'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</form>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.results')) ?></h2>
            <p class="section-lede"><?= (int)$result['total'] ?> · <?= e(__('members.page')) ?> <?= (int)$page ?></p>
        </div>
    </div>
    <?php if (empty($result['items'])): ?>
        <div class="empty-state">
            <strong><?= e(__('members.empty_title')) ?></strong>
            <?= e(__('members.empty_text')) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded members-table-wrap">
            <table class="members-table">
                <thead>
                <tr>
                    <th><?= e(__('members.member_number')) ?></th>
                    <th><?= e(__('members.type')) ?></th>
                    <th><?= e(__('members.status')) ?></th>
                    <?php if (!empty($gdprEnabled)): ?>
                        <th><?= e(__('members.gdpr_column')) ?></th>
                    <?php endif; ?>
                    <th><?= e(__('members.payment')) ?></th>
                    <th><?= e(__('members.balance_due')) ?></th>
                    <th><?= e(__('members.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $item): ?>
                    <?php
                    $issues = is_array($item['compliance_issues'] ?? null) ? $item['compliance_issues'] : [];
                    $issueLabels = array_values(array_filter(array_map(
                        static fn ($i) => trim((string) ($i['label'] ?? '')),
                        $issues
                    )));
                    $issueText = implode("\n", $issueLabels);
                    $fullName = trim(($item['fields']['first_name'] ?? '') . ' ' . ($item['fields']['last_name'] ?? ''));
                    ?>
                    <tr<?= $issueLabels !== [] ? ' class="member-row-anomaly"' : '' ?>>
                        <td data-label="<?= e(__('members.member_number')) ?>">
                            <div class="member-list-identity">
                                <?php if ($issueLabels !== []): ?>
                                    <span
                                        class="member-anomaly-mark"
                                        tabindex="0"
                                        role="img"
                                        aria-label="<?= e(__('members.anomaly_aria') . ': ' . implode('; ', $issueLabels)) ?>"
                                        title="<?= e($issueText) ?>"
                                    >
                                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                                            <path fill="currentColor" d="M12 3.2 22 20.5H2L12 3.2zm0 5.3c-.7 0-1.2.5-1.1 1.2l.4 5.1h1.4l.4-5.1c.1-.7-.4-1.2-1.1-1.2zm0 8.3a1.15 1.15 0 1 0 0 2.3 1.15 1.15 0 0 0 0-2.3z"/>
                                        </svg>
                                        <span class="member-anomaly-tooltip"><?= e(implode(' · ', $issueLabels)) ?></span>
                                    </span>
                                <?php endif; ?>
                                <div>
                                    <a class="member-list-name" href="<?= e(url('/members/'.$item['id'])) ?>">
                                        <?= e($fullName !== '' ? $fullName : $item['member_number']) ?>
                                    </a>
                                    <div class="muted member-list-meta">#<?= e($item['member_number']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="<?= e(__('members.type')) ?>"><?= e(localized($item['type_name_json'])) ?></td>
                        <td data-label="<?= e(__('members.status')) ?>">
                            <span class="badge<?= ($item['status'] ?? '') === 'pending' ? ' badge-warn' : '' ?>"><?= e(__('members.status_'.$item['status'])) ?></span>
                        </td>
                        <?php
                        $gdprBadges = is_array($item['gdpr_badges'] ?? null) ? $item['gdpr_badges'] : [];
                        if (!empty($gdprEnabled)): ?>
                        <td data-label="<?= e(__('members.gdpr_column')) ?>">
                            <?php if ($gdprBadges !== []): ?>
                                <div class="member-gdpr-badges">
                                    <?php foreach ($gdprBadges as $badge): ?>
                                        <span class="badge <?= !empty($badge['ok']) ? 'badge-ok' : 'badge-due' ?>" title="<?= e((string) ($badge['label'] ?? '')) ?>">
                                            <?= e((string) ($badge['label'] ?? '')) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td data-label="<?= e(__('members.payment')) ?>"><span class="badge <?= $item['payment_status']==='paid'?'badge-ok':($item['payment_status']==='due'?'badge-due':'badge-warn') ?>"><?= e(__('members.payment_'.$item['payment_status'])) ?></span></td>
                        <td data-label="<?= e(__('members.balance_due')) ?>"><?= e(number_format((float)$item['balance_due'], 2, ',', '.')) ?> €</td>
                        <td data-label="<?= e(__('members.actions')) ?>">
                            <div class="member-row-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/members/'.$item['id'])) ?>"><?= e(__('members.scheda')) ?></a>
                                <?php if (can('members.manage')): ?>
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/members/'.$item['id'].'/edit')) ?>"><?= e(__('members.edit_short')) ?></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
