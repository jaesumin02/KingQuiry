<?php
/**
 * KingQuiry System Security & Activity Audit Logs Modal
 * Reusable modal for the Administrator Control Room across all views.
 */
if (!isset($recentAuditLogs) || !is_array($recentAuditLogs)) {
    $recentAuditLogs = [];
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $auditLogsRes = $conn->query("
            SELECT a.logID, a.userID, a.action, a.timestamp, u.fullname, u.email, u.role
            FROM auditlogs a
            LEFT JOIN users u ON u.userID = a.userID
            ORDER BY a.logID DESC
            LIMIT 50
        ");
        if ($auditLogsRes) {
            $recentAuditLogs = $auditLogsRes->fetch_all(MYSQLI_ASSOC);
        }
    }
}
?>
<!-- ══════════ SYSTEM AUDIT LOGS MODAL (SIR SADD) ══════════ -->
<div class="modal fade" id="auditLogsModal" tabindex="-1" aria-labelledby="auditLogsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #142d4e 0%, #174a70 100%); padding: 1.25rem 1.5rem;">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="auditLogsModalLabel">System Security &amp; Activity Audit Logs</h5>
                        <div class="small text-white-50">Immutable trail of administrative updates, role changes, and system modifications</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 p-md-4" style="background: #f8fafc;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="small text-muted">Showing latest 50 logged security events</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle bg-white small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Timestamp</th>
                                <th>User / Actor</th>
                                <th>Role</th>
                                <th>Action Taken</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentAuditLogs)): ?>
                                <?php foreach ($recentAuditLogs as $log): ?>
                                    <tr>
                                        <td class="text-muted fw-bold">#<?= (int) ($log['logID'] ?? 0) ?></td>
                                        <td class="text-nowrap"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['timestamp'] ?? 'now')), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($log['fullname'] ?? 'System / Anonymous', ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php if (!empty($log['email'])): ?><div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($log['email'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                        </td>
                                        <td><span class="badge text-bg-light border"><?= htmlspecialchars($log['role'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="text-break"><?= htmlspecialchars($log['action'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No audit log records found yet. All administrative actions are automatically recorded here.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
